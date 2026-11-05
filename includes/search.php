<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Unified Global Search Engine
 *
 * Core search routines with strict parameter binding, LIKE character escaping,
 * tenant isolation, permission gating, and safe entity highlighting.
 */

/**
 * Safely highlight search terms within text without XSS vulnerability.
 * Escapes both matching and non-matching characters with e().
 */
function search_highlight(string $text, string $query): string
{
    $cleanText = (string)$text;
    $trimQuery = trim($query);
    if ($trimQuery === '' || mb_strlen($trimQuery) < 2) {
        return e($cleanText);
    }

    $words = array_filter(preg_split('/\s+/', $trimQuery) ?: [], fn($w) => mb_strlen($w) >= 2);
    if (empty($words)) {
        return e($cleanText);
    }

    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $patternWords = array_map(fn($w) => preg_quote($w, '/'), $words);
    $regex = '/(' . implode('|', $patternWords) . ')/ui';

    $parts = preg_split($regex, $cleanText, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) <= 1) {
        return e($cleanText);
    }

    $out = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match($regex, $part)) {
            $out .= '<mark class="search-match">' . e($part) . '</mark>';
        } else {
            $out .= e($part);
        }
    }
    return $out;
}

/**
 * Perform a unified global search across permitted system entities.
 *
 * @param string $rawQuery Raw input search string (trimmed to 60 characters)
 * @param array{
 *     limit_per_group?: int,
 *     group?: string|null,
 *     page?: int,
 *     per_page?: int,
 *     filters?: array<string, mixed>
 * } $options
 * @return array{
 *     query: string,
 *     groups: array<string, array{
 *         key: string,
 *         label: string,
 *         icon: string,
 *         total: int,
 *         items: array<int, array{
 *             id: int,
 *             title: string,
 *             subtitle: string,
 *             url: string,
 *             icon: string,
 *             type: string,
 *             company_code?: string,
 *             status?: string
 *         }>
 *     }>,
 *     total_count: int
 * }
 */
function search_global(string $rawQuery, array $options = []): array
{
    $query = mb_substr(trim($rawQuery), 0, 60);
    $limitPerGroup = $options['limit_per_group'] ?? null;
    $targetGroup = $options['group'] ?? null;
    $page = max(1, (int)($options['page'] ?? 1));
    $perPage = max(1, (int)($options['per_page'] ?? 20));
    $filters = $options['filters'] ?? [];

    $pdo = db();
    $like = '%' . escape_like($query) . '%';
    $hasQuery = mb_strlen($query) >= 2;

    $results = [
        'query'       => $query,
        'groups'      => [],
        'total_count' => 0,
    ];

    if (!$hasQuery) {
        return $results;
    }

    $shouldSearch = fn(string $groupKey): bool => ($targetGroup === null || $targetGroup === 'all' || $targetGroup === $groupKey);

    // =========================================================================
    // 1. EMPLOYEES (employee.view)
    // =========================================================================
    if ($shouldSearch('employees') && user_can('employee.view')) {
        $empCompIds = scoped_company_ids('employee.view');
        if (!empty($empCompIds)) {
            [$compSql, $compParams] = company_in_sql($empCompIds, 'e.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $searchConds = [
                'e.employee_code LIKE ?',
                'e.first_name LIKE ?',
                'e.last_name LIKE ?',
                "CONCAT(e.first_name, ' ', e.last_name) LIKE ?",
                'e.email LIKE ?',
                'e.phone LIKE ?',
                'd.name LIKE ?',
                'des.title LIKE ?',
                'e.employment_status LIKE ?',
            ];
            $searchParams = array_fill(0, count($searchConds), $like);

            if (user_can('document.confidential')) {
                $searchConds[] = 'e.nic_passport LIKE ?';
                $searchParams[] = $like;
            }

            $where[] = '(' . implode(' OR ', $searchConds) . ')';
            $params = array_merge($params, $searchParams);

            // Filters
            if (!empty($filters['department_id'])) {
                $where[] = 'e.department_id = ?';
                $params[] = (int)$filters['department_id'];
            }
            if (!empty($filters['designation_id'])) {
                $where[] = 'e.designation_id = ?';
                $params[] = (int)$filters['designation_id'];
            }
            if (!empty($filters['employment_status'])) {
                $where[] = 'e.employment_status = ?';
                $params[] = (string)$filters['employment_status'];
            }

            $whereSql = implode(' AND ', $where);

            // Count
            $countSt = $pdo->prepare("SELECT COUNT(*) FROM employees e
                                        JOIN companies c ON c.id = e.company_id
                                   LEFT JOIN departments d ON d.id = e.department_id
                                   LEFT JOIN designations des ON des.id = e.designation_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            // Fetch
            $items = [];
            if ($total > 0) {
                $limitSql = '';
                $fetchParams = $params;
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email, e.phone,
                                                e.employment_status, e.company_id,
                                                c.name AS company_name, c.code AS company_code,
                                                d.name AS department_name, des.title AS designation_title
                                           FROM employees e
                                           JOIN companies c ON c.id = e.company_id
                                      LEFT JOIN departments d ON d.id = e.department_id
                                      LEFT JOIN designations des ON des.id = e.designation_id
                                          WHERE $whereSql
                                       ORDER BY e.first_name ASC, e.last_name ASC
                                          $limitSql");
                $dataSt->execute($fetchParams);
                foreach ($dataSt->fetchAll() as $row) {
                    $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $deptDesig = $row['department_name'] ?? '';
                    if (!empty($row['designation_title'])) {
                        $deptDesig .= ($deptDesig !== '' ? ' &bull; ' : '') . $row['designation_title'];
                    }
                    $badgeHtml = status_badge('employee', (string)$row['employment_status']);
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">' . e($row['employee_code']) . '</span>'
                             . ($deptDesig !== '' ? '<span class="text-body-secondary me-2">' . e($deptDesig) . '</span>' : '')
                             . $badgeHtml;

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => $fullName,
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/employees/view.php?id=' . (int)$row['id']),
                        'icon'         => 'bi-person',
                        'type'         => 'Employee',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['employment_status'],
                    ];
                }
            }

            $results['groups']['employees'] = [
                'key'   => 'employees',
                'label' => 'Employees',
                'icon'  => 'bi-person',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 2. CANDIDATES (candidate.view)
    // =========================================================================
    if ($shouldSearch('candidates') && user_can('candidate.view')) {
        $candScoped = scoped_company_ids('candidate.view');
        $hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');

        $candWhere = [];
        $candParams = [];

        if (!$hasCrossCompany) {
            if (empty($candScoped)) {
                $candWhere[] = 'c.created_by = ?';
                $candParams[] = current_user_id();
            } else {
                $inPh = implode(',', array_fill(0, count($candScoped), '?'));
                $candWhere[] = "(EXISTS (SELECT 1 FROM candidate_applications ca_sc WHERE ca_sc.candidate_id = c.id AND ca_sc.company_id IN ($inPh)) OR c.created_by = ?)";
                $candParams = array_merge($candParams, $candScoped, [current_user_id()]);
            }
        }

        $searchConds = [
            'c.candidate_code LIKE ?',
            'c.first_name LIKE ?',
            'c.last_name LIKE ?',
            "CONCAT(c.first_name, ' ', c.last_name) LIKE ?",
            'c.email LIKE ?',
            'c.phone LIKE ?',
            'c.source LIKE ?',
            'EXISTS (SELECT 1 FROM candidate_applications ca_s JOIN job_vacancies jv_s ON jv_s.id = ca_s.vacancy_id WHERE ca_s.candidate_id = c.id AND (jv_s.title LIKE ? OR ca_s.status LIKE ?))',
        ];
        $searchParams = [$like, $like, $like, $like, $like, $like, $like, $like, $like];

        if (user_can('document.confidential')) {
            $searchConds[] = 'c.nic_passport LIKE ?';
            $searchParams[] = $like;
        }

        $candWhere[] = '(' . implode(' OR ', $searchConds) . ')';
        $candParams = array_merge($candParams, $searchParams);

        // Filters
        if (!empty($filters['vacancy_id'])) {
            $candWhere[] = 'EXISTS (SELECT 1 FROM candidate_applications ca_f WHERE ca_f.candidate_id = c.id AND ca_f.vacancy_id = ?)';
            $candParams[] = (int)$filters['vacancy_id'];
        }
        if (!empty($filters['recruitment_status'])) {
            $candWhere[] = 'EXISTS (SELECT 1 FROM candidate_applications ca_f WHERE ca_f.candidate_id = c.id AND ca_f.status = ?)';
            $candParams[] = (string)$filters['recruitment_status'];
        }

        $candWhereSql = implode(' AND ', $candWhere);

        $countSt = $pdo->prepare("SELECT COUNT(*) FROM candidates c WHERE $candWhereSql");
        $countSt->execute($candParams);
        $total = (int)$countSt->fetchColumn();

        $items = [];
        if ($total > 0) {
            $limitSql = '';
            if ($limitPerGroup !== null) {
                $limitSql = 'LIMIT ' . (int)$limitPerGroup;
            } else {
                $offset = ($page - 1) * $perPage;
                $limitSql = "LIMIT $perPage OFFSET $offset";
            }

            $dataSt = $pdo->prepare("SELECT c.id, c.candidate_code, c.first_name, c.last_name, c.email, c.phone,
                                            (SELECT jv.title FROM candidate_applications ca JOIN job_vacancies jv ON jv.id = ca.vacancy_id WHERE ca.candidate_id = c.id ORDER BY ca.id DESC LIMIT 1) AS latest_vacancy_title,
                                            (SELECT ca.status FROM candidate_applications ca WHERE ca.candidate_id = c.id ORDER BY ca.id DESC LIMIT 1) AS latest_status
                                       FROM candidates c
                                      WHERE $candWhereSql
                                   ORDER BY c.first_name ASC, c.last_name ASC
                                      $limitSql");
            $dataSt->execute($candParams);
            foreach ($dataSt->fetchAll() as $row) {
                $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
                $vacTitle = (string)($row['latest_vacancy_title'] ?? 'Talent Pool');
                $statusBadge = !empty($row['latest_status']) ? status_badge('candidate', (string)$row['latest_status']) : '';
                $subHtml = '<span class="text-body-secondary me-2">' . e($row['candidate_code']) . '</span>'
                         . '<span class="text-body-secondary me-2">' . e($vacTitle) . '</span>'
                         . $statusBadge;

                $items[] = [
                    'id'       => (int)$row['id'],
                    'title'    => $fullName,
                    'subtitle' => $subHtml,
                    'url'      => url('modules/candidates/view.php?id=' . (int)$row['id']),
                    'icon'     => 'bi-person-badge',
                    'type'     => 'Candidate',
                    'status'   => (string)($row['latest_status'] ?? ''),
                ];
            }
        }

        $results['groups']['candidates'] = [
            'key'   => 'candidates',
            'label' => 'Candidates',
            'icon'  => 'bi-person-badge',
            'total' => $total,
            'items' => $items,
        ];
        $results['total_count'] += $total;
    }

    // =========================================================================
    // 3. VACANCIES (vacancy.view)
    // =========================================================================
    if ($shouldSearch('vacancies') && user_can('vacancy.view')) {
        $vacCompIds = scoped_company_ids('vacancy.view');
        if (!empty($vacCompIds)) {
            [$compSql, $compParams] = company_in_sql($vacCompIds, 'v.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $where[] = '(v.title LIKE ? OR v.vacancy_code LIKE ? OR d.name LIKE ?)';
            $params = array_merge($params, [$like, $like, $like]);

            if (!empty($filters['department_id'])) {
                $where[] = 'v.department_id = ?';
                $params[] = (int)$filters['department_id'];
            }
            if (!empty($filters['designation_id'])) {
                $where[] = 'v.designation_id = ?';
                $params[] = (int)$filters['designation_id'];
            }

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM job_vacancies v
                                        JOIN companies c ON c.id = v.company_id
                                   LEFT JOIN departments d ON d.id = v.department_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT v.id, v.vacancy_code, v.title, v.positions_count, v.status,
                                                c.name AS company_name, c.code AS company_code,
                                                d.name AS department_name
                                           FROM job_vacancies v
                                           JOIN companies c ON c.id = v.company_id
                                      LEFT JOIN departments d ON d.id = v.department_id
                                          WHERE $whereSql
                                       ORDER BY v.created_at DESC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">' . e($row['vacancy_code']) . '</span>'
                             . (!empty($row['department_name']) ? '<span class="text-body-secondary me-2">' . e($row['department_name']) . '</span>' : '')
                             . status_badge('vacancy', (string)$row['status']);

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => (string)$row['title'],
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/vacancies/view.php?id=' . (int)$row['id']),
                        'icon'         => 'bi-briefcase',
                        'type'         => 'Vacancy',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['status'],
                    ];
                }
            }

            $results['groups']['vacancies'] = [
                'key'   => 'vacancies',
                'label' => 'Vacancies',
                'icon'  => 'bi-briefcase',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 4. APPLICATIONS (candidate.view)
    // =========================================================================
    if ($shouldSearch('applications') && user_can('candidate.view')) {
        $appCompIds = scoped_company_ids('candidate.view');
        if (!empty($appCompIds)) {
            [$compSql, $compParams] = company_in_sql($appCompIds, 'ca.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.candidate_code LIKE ? OR v.title LIKE ? OR v.vacancy_code LIKE ? OR ca.status LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);

            if (!empty($filters['vacancy_id'])) {
                $where[] = 'ca.vacancy_id = ?';
                $params[] = (int)$filters['vacancy_id'];
            }
            if (!empty($filters['recruitment_status'])) {
                $where[] = 'ca.status = ?';
                $params[] = (string)$filters['recruitment_status'];
            }

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM candidate_applications ca
                                        JOIN candidates c ON c.id = ca.candidate_id
                                        JOIN job_vacancies v ON v.id = ca.vacancy_id
                                        JOIN companies comp ON comp.id = ca.company_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT ca.id, ca.candidate_id, ca.application_date, ca.status,
                                                c.first_name, c.last_name, c.candidate_code,
                                                v.title AS vacancy_title, v.vacancy_code,
                                                comp.name AS company_name, comp.code AS company_code
                                           FROM candidate_applications ca
                                           JOIN candidates c ON c.id = ca.candidate_id
                                           JOIN job_vacancies v ON v.id = ca.vacancy_id
                                           JOIN companies comp ON comp.id = ca.company_id
                                          WHERE $whereSql
                                       ORDER BY ca.application_date DESC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $candidateName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $appTitle = $candidateName . ' &ndash; ' . $row['vacancy_title'];
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">' . e($row['candidate_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">Applied: ' . date('M j, Y', strtotime((string)$row['application_date'])) . '</span>'
                             . status_badge('application', (string)$row['status']);

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => $appTitle,
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/candidates/view.php?id=' . (int)$row['candidate_id']),
                        'icon'         => 'bi-file-earmark-person',
                        'type'         => 'Application',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['status'],
                    ];
                }
            }

            $results['groups']['applications'] = [
                'key'   => 'applications',
                'label' => 'Applications',
                'icon'  => 'bi-file-earmark-person',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 5. INTERVIEWS (interview.view)
    // =========================================================================
    if ($shouldSearch('interviews') && user_can('interview.view')) {
        $intCompIds = scoped_company_ids('interview.view');
        if (!empty($intCompIds)) {
            [$compSql, $compParams] = company_in_sql($intCompIds, 'i.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR v.title LIKE ? OR i.location LIKE ? OR i.status LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);

            if (!empty($filters['interview_date_from'])) {
                $where[] = 'DATE(i.scheduled_at) >= ?';
                $params[] = (string)$filters['interview_date_from'];
            }
            if (!empty($filters['interview_date_to'])) {
                $where[] = 'DATE(i.scheduled_at) <= ?';
                $params[] = (string)$filters['interview_date_to'];
            }

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM interviews i
                                        JOIN candidate_applications ca ON ca.id = i.application_id
                                        JOIN candidates c ON c.id = ca.candidate_id
                                        JOIN job_vacancies v ON v.id = ca.vacancy_id
                                        JOIN companies comp ON comp.id = i.company_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT i.id, i.scheduled_at, i.round_no, i.interview_type, i.mode, i.status, i.location,
                                                c.first_name, c.last_name, c.candidate_code,
                                                v.title AS vacancy_title,
                                                comp.name AS company_name, comp.code AS company_code
                                           FROM interviews i
                                           JOIN candidate_applications ca ON ca.id = i.application_id
                                           JOIN candidates c ON c.id = ca.candidate_id
                                           JOIN job_vacancies v ON v.id = ca.vacancy_id
                                           JOIN companies comp ON comp.id = i.company_id
                                          WHERE $whereSql
                                       ORDER BY i.scheduled_at DESC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $candidateName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $intTitle = $candidateName . ' (' . $row['vacancy_title'] . ')';
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">Round ' . (int)$row['round_no'] . ' &bull; ' . date('M j, Y g:i A', strtotime((string)$row['scheduled_at'])) . '</span>'
                             . status_badge('interview', (string)$row['status']);

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => $intTitle,
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/interviews/view.php?id=' . (int)$row['id']),
                        'icon'         => 'bi-calendar-event',
                        'type'         => 'Interview',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['status'],
                    ];
                }
            }

            $results['groups']['interviews'] = [
                'key'   => 'interviews',
                'label' => 'Interviews',
                'icon'  => 'bi-calendar-event',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 6. OFFERS (offer.view) - Strictly NO salary fields returned!
    // =========================================================================
    if ($shouldSearch('offers') && user_can('offer.view')) {
        $offerCompIds = scoped_company_ids('offer.view');
        if (!empty($offerCompIds)) {
            [$compSql, $compParams] = company_in_sql($offerCompIds, 'jo.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR v.title LIKE ? OR des.title LIKE ? OR jo.status LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM job_offers jo
                                        JOIN candidate_applications ca ON ca.id = jo.application_id
                                        JOIN candidates c ON c.id = ca.candidate_id
                                        JOIN job_vacancies v ON v.id = ca.vacancy_id
                                        JOIN companies comp ON comp.id = jo.company_id
                                   LEFT JOIN designations des ON des.id = jo.designation_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT jo.id, jo.offer_date, jo.expiry_date, jo.status,
                                                c.first_name, c.last_name, c.candidate_code,
                                                v.title AS vacancy_title,
                                                des.title AS designation_title,
                                                comp.name AS company_name, comp.code AS company_code
                                           FROM job_offers jo
                                           JOIN candidate_applications ca ON ca.id = jo.application_id
                                           JOIN candidates c ON c.id = ca.candidate_id
                                           JOIN job_vacancies v ON v.id = ca.vacancy_id
                                           JOIN companies comp ON comp.id = jo.company_id
                                      LEFT JOIN designations des ON des.id = jo.designation_id
                                          WHERE $whereSql
                                       ORDER BY jo.offer_date DESC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $candidateName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $position = !empty($row['designation_title']) ? $row['designation_title'] : $row['vacancy_title'];
                    $offerTitle = $candidateName . ' &ndash; ' . $position;
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">Date: ' . date('M j, Y', strtotime((string)$row['offer_date'])) . '</span>'
                             . status_badge('offer', (string)$row['status']);

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => $offerTitle,
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/offers/view.php?id=' . (int)$row['id']),
                        'icon'         => 'bi-file-earmark-check',
                        'type'         => 'Offer',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['status'],
                    ];
                }
            }

            $results['groups']['offers'] = [
                'key'   => 'offers',
                'label' => 'Job Offers',
                'icon'  => 'bi-file-earmark-check',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 7. LEAVE REQUESTS (leave.view)
    // =========================================================================
    if ($shouldSearch('leave') && user_can('leave.view')) {
        $leaveCompIds = scoped_company_ids('leave.view');
        if (!empty($leaveCompIds)) {
            [$compSql, $compParams] = company_in_sql($leaveCompIds, 'lr.company_id');
            $where = ["($compSql)"];
            $params = $compParams;

            $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, " ", e.last_name) LIKE ? OR e.employee_code LIKE ? OR lt.name LIKE ? OR lr.reason LIKE ? OR lr.status LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr
                                        JOIN employees e ON e.id = lr.employee_id
                                        JOIN leave_types lt ON lt.id = lr.leave_type_id
                                        JOIN companies comp ON comp.id = lr.company_id
                                       WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT lr.id, lr.start_date, lr.end_date, lr.total_days, lr.status,
                                                e.first_name, e.last_name, e.employee_code,
                                                lt.name AS leave_type_name,
                                                comp.name AS company_name, comp.code AS company_code
                                           FROM leave_requests lr
                                           JOIN employees e ON e.id = lr.employee_id
                                           JOIN leave_types lt ON lt.id = lr.leave_type_id
                                           JOIN companies comp ON comp.id = lr.company_id
                                          WHERE $whereSql
                                       ORDER BY lr.start_date DESC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $empName = trim($row['first_name'] . ' ' . $row['last_name']);
                    $leaveTitle = $empName . ' (' . $row['employee_code'] . ') &ndash; ' . $row['leave_type_name'];
                    $dateRange = date('M j', strtotime((string)$row['start_date'])) . ' - ' . date('M j, Y', strtotime((string)$row['end_date'])) . ' (' . (float)$row['total_days'] . 'd)';
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['company_code']) . '</span>'
                             . '<span class="text-body-secondary me-2">' . e($dateRange) . '</span>'
                             . status_badge('leave', (string)$row['status']);

                    $items[] = [
                        'id'           => (int)$row['id'],
                        'title'        => $leaveTitle,
                        'subtitle'     => $subHtml,
                        'url'          => url('modules/leave/view.php?id=' . (int)$row['id']),
                        'icon'         => 'bi-calendar2-range',
                        'type'         => 'Leave',
                        'company_code' => (string)$row['company_code'],
                        'status'       => (string)$row['status'],
                    ];
                }
            }

            $results['groups']['leave'] = [
                'key'   => 'leave',
                'label' => 'Leave Requests',
                'icon'  => 'bi-calendar2-range',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 8. COMPANIES (company.view or is_group_admin)
    // =========================================================================
    if ($shouldSearch('companies') && (is_group_admin() || user_can('company.view'))) {
        $accessibleCompIds = accessible_company_ids();
        $activeCid = active_company_id();

        if (is_group_admin()) {
            if ($activeCid !== null) {
                $compFilter = ['c.id = ?', [$activeCid]];
            } else {
                $compFilter = ['1 = 1', []];
            }
        } else {
            if ($activeCid !== null && in_array($activeCid, $accessibleCompIds, true)) {
                $compFilter = ['c.id = ?', [$activeCid]];
            } else {
                $compFilter = company_in_sql($accessibleCompIds, 'c.id');
            }
        }

        if ($compFilter[0] !== '1 = 0') {
            $where = [$compFilter[0]];
            $params = $compFilter[1];

            $where[] = '(c.name LIKE ? OR c.code LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like]);

            $whereSql = implode(' AND ', $where);

            $countSt = $pdo->prepare("SELECT COUNT(*) FROM companies c WHERE $whereSql");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $items = [];
            if ($total > 0) {
                $limitSql = '';
                if ($limitPerGroup !== null) {
                    $limitSql = 'LIMIT ' . (int)$limitPerGroup;
                } else {
                    $offset = ($page - 1) * $perPage;
                    $limitSql = "LIMIT $perPage OFFSET $offset";
                }

                $dataSt = $pdo->prepare("SELECT c.id, c.code, c.name, c.email, c.phone, c.is_active
                                           FROM companies c
                                          WHERE $whereSql
                                       ORDER BY c.name ASC
                                          $limitSql");
                $dataSt->execute($params);
                foreach ($dataSt->fetchAll() as $row) {
                    $subHtml = '<span class="badge badge-soft-secondary me-1">' . e($row['code']) . '</span>'
                             . (!empty($row['email']) ? '<span class="text-body-secondary me-2">' . e($row['email']) . '</span>' : '')
                             . status_badge('company', $row['is_active'] ? 'active' : 'inactive');

                    $items[] = [
                        'id'       => (int)$row['id'],
                        'title'    => (string)$row['name'],
                        'subtitle' => $subHtml,
                        'url'      => url('modules/companies/view.php?id=' . (int)$row['id']),
                        'icon'     => 'bi-buildings',
                        'type'     => 'Company',
                        'status'   => $row['is_active'] ? 'active' : 'inactive',
                    ];
                }
            }

            $results['groups']['companies'] = [
                'key'   => 'companies',
                'label' => 'Companies',
                'icon'  => 'bi-buildings',
                'total' => $total,
                'items' => $items,
            ];
            $results['total_count'] += $total;
        }
    }

    // =========================================================================
    // 9. USERS (user.view or is_group_admin)
    // =========================================================================
    if ($shouldSearch('users') && (is_group_admin() || user_can('user.view'))) {
        $where = [];
        $params = [];

        if (!is_group_admin()) {
            // Company Administrator: only users in their assigned companies, non-group-admins
            $uCompIds = accessible_company_ids('user.view');
            if (empty($uCompIds)) {
                $where[] = '1 = 0';
            } else {
                $ph = implode(',', array_fill(0, count($uCompIds), '?'));
                $where[] = "u.is_group_admin = 0 AND EXISTS (SELECT 1 FROM company_user_assignments cua WHERE cua.user_id = u.id AND cua.company_id IN ($ph))";
                $params = array_merge($params, $uCompIds);
            }
        }

        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
        $params = array_merge($params, [$like, $like, $like]);

        $whereSql = implode(' AND ', $where);

        $countSt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $countSt->execute($params);
        $total = (int)$countSt->fetchColumn();

        $items = [];
        if ($total > 0) {
            $limitSql = '';
            if ($limitPerGroup !== null) {
                $limitSql = 'LIMIT ' . (int)$limitPerGroup;
            } else {
                $offset = ($page - 1) * $perPage;
                $limitSql = "LIMIT $perPage OFFSET $offset";
            }

            $dataSt = $pdo->prepare("SELECT u.id, u.username, u.email, u.full_name, u.is_group_admin, u.is_active
                                       FROM users u
                                      WHERE $whereSql
                                   ORDER BY u.full_name ASC
                                      $limitSql");
            $dataSt->execute($params);
            foreach ($dataSt->fetchAll() as $row) {
                $adminBadge = $row['is_group_admin']
                    ? '<span class="badge badge-soft-primary me-1">Group Admin</span>'
                    : '<span class="badge badge-soft-secondary me-1">User</span>';
                $statusBadge = status_badge('user', $row['is_active'] ? 'active' : 'inactive');
                $subHtml = '<span class="text-body-secondary me-2">@' . e($row['username']) . '</span>'
                         . '<span class="text-body-secondary me-2">' . e($row['email']) . '</span>'
                         . $adminBadge
                         . $statusBadge;

                $items[] = [
                    'id'       => (int)$row['id'],
                    'title'    => (string)$row['full_name'],
                    'subtitle' => $subHtml,
                    'url'      => url('modules/users/view.php?id=' . (int)$row['id']),
                    'icon'     => 'bi-person-gear',
                    'type'     => 'User',
                    'status'   => $row['is_active'] ? 'active' : 'inactive',
                ];
            }
        }

        $results['groups']['users'] = [
            'key'   => 'users',
            'label' => 'Users',
            'icon'  => 'bi-person-gear',
            'total' => $total,
            'items' => $items,
        ];
        $results['total_count'] += $total;
    }

    return $results;
}

