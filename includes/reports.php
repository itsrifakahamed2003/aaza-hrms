<?php
declare(strict_types=1);

/**
 * AaZa Group HRMS - Reporting & Analytics Engine (Phase 9)
 * Centralized, single-query aggregated functions for dashboards, analytics,
 * and tabular reports. Gated by company scoping and permissions.
 */

/**
 * Return scoped company IDs where the user has BOTH the module permission(s)
 * AND the report permission (default 'report.view').
 *
 * @param string|string[] $modulePermissions Single permission or array of permissions (e.g. ['candidate.view', 'vacancy.view'])
 * @param string          $reportPermission   Report permission to intersect (default 'report.view')
 * @return int[]
 */
function report_scope_companies(string|array $modulePermissions, string $reportPermission = 'report.view'): array
{
    $moduleScope = [];
    if (is_array($modulePermissions)) {
        foreach ($modulePermissions as $perm) {
            $moduleScope = array_merge($moduleScope, scoped_company_ids($perm));
        }
        $moduleScope = array_values(array_unique($moduleScope));
    } else {
        $moduleScope = scoped_company_ids($modulePermissions);
    }

    $reportScope = accessible_company_ids($reportPermission);
    return array_values(array_intersect($moduleScope, $reportScope));
}

/**
 * Validate and sanitize report GET filters against accessible company scope.
 * Never trusts raw user input; falls back safely to defaults.
 *
 * @param PDO   $pdo
 * @param int[] $accessibleCompanyIds List of company IDs the user is permitted to query
 * @param array $input                $_GET parameters
 * @param bool  $defaultToActive      Whether to default company filter to active_company_id()
 * @return array{
 *     company_id: int,
 *     scoped_company_ids: int[],
 *     department_id: int,
 *     vacancy_id: int,
 *     date_from: ?string,
 *     date_to: ?string,
 *     date_range_label: string
 * }
 */
function validate_report_filters(
    PDO $pdo,
    array $accessibleCompanyIds,
    array $input,
    bool $defaultToActive = true
): array {
    if (empty($accessibleCompanyIds)) {
        return [
            'company_id'         => 0,
            'scoped_company_ids' => [],
            'department_id'      => 0,
            'vacancy_id'         => 0,
            'date_from'          => null,
            'date_to'            => null,
            'date_range_label'   => 'All time',
        ];
    }

    // 1. Company Filter
    $inputCompanyId = (int)($input['company_id'] ?? 0);
    $activeId = active_company_id();

    $selectedCompanyId = 0;
    if ($inputCompanyId > 0 && in_array($inputCompanyId, $accessibleCompanyIds, true)) {
        $selectedCompanyId = $inputCompanyId;
    } elseif ($inputCompanyId === 0 && isset($input['company_id'])) {
        // Explicitly chose "All Companies"
        $selectedCompanyId = 0;
    } elseif ($defaultToActive && $activeId > 0 && in_array($activeId, $accessibleCompanyIds, true)) {
        // Fall back to active company switcher default
        $selectedCompanyId = $activeId;
    }

    $scopedCompanyIds = ($selectedCompanyId > 0) ? [$selectedCompanyId] : $accessibleCompanyIds;

    // 2. Department Filter (must belong to scoped companies)
    $filterDept = (int)($input['department_id'] ?? 0);
    if ($filterDept > 0) {
        $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $st = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND company_id IN ($ph) LIMIT 1");
        $st->execute(array_merge([$filterDept], $scopedCompanyIds));
        if (!$st->fetch()) {
            $filterDept = 0; // Not allowed / doesn't belong to scoped companies
        }
    }

    // 3. Vacancy Filter (must belong to scoped companies)
    $filterVac = (int)($input['vacancy_id'] ?? 0);
    if ($filterVac > 0) {
        $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $st = $pdo->prepare("SELECT id FROM job_vacancies WHERE id = ? AND company_id IN ($ph) LIMIT 1");
        $st->execute(array_merge([$filterVac], $scopedCompanyIds));
        if (!$st->fetch()) {
            $filterVac = 0;
        }
    }

    // 4. Date Range Filters (Y-m-d parsed with parse_ymd, max span 5 years = 1826 days)
    $rawFrom = trim((string)($input['date_from'] ?? ''));
    $rawTo   = trim((string)($input['date_to'] ?? ''));

    $dateFrom = parse_ymd($rawFrom);
    $dateTo   = parse_ymd($rawTo);

    if ($dateFrom !== null && $dateTo !== null) {
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }

        // Clamp maximum span to 5 years (1826 days)
        $dFrom = new DateTime($dateFrom);
        $dTo   = new DateTime($dateTo);
        $diffDays = (int)$dFrom->diff($dTo)->format('%r%a');
        if ($diffDays > 1826) {
            $dateTo = (clone $dFrom)->modify('+1826 days')->format('Y-m-d');
        }
    }

    $dateLabel = 'All time';
    if ($dateFrom !== null && $dateTo !== null) {
        $dateLabel = format_date($dateFrom) . ' to ' . format_date($dateTo);
    } elseif ($dateFrom !== null) {
        $dateLabel = 'From ' . format_date($dateFrom);
    } elseif ($dateTo !== null) {
        $dateLabel = 'Up to ' . format_date($dateTo);
    }

    return [
        'company_id'         => $selectedCompanyId,
        'scoped_company_ids' => $scopedCompanyIds,
        'department_id'      => $filterDept,
        'vacancy_id'         => $filterVac,
        'date_from'          => $dateFrom,
        'date_to'            => $dateTo,
        'date_range_label'   => $dateLabel,
    ];
}

/**
 * Fetch companies list for filter dropdown.
 */
function report_get_filter_companies(PDO $pdo, array $accessibleCompanyIds): array
{
    if (empty($accessibleCompanyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($accessibleCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name ASC");
    $st->execute($accessibleCompanyIds);
    return $st->fetchAll();
}

/**
 * Fetch departments list for filter dropdown.
 */
function report_get_filter_departments(PDO $pdo, array $scopedCompanyIds): array
{
    if (empty($scopedCompanyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, name FROM departments WHERE company_id IN ($ph) AND is_active = 1 ORDER BY name ASC");
    $st->execute($scopedCompanyIds);
    return $st->fetchAll();
}

/**
 * Fetch open/active vacancies for filter dropdown.
 */
function report_get_filter_vacancies(PDO $pdo, array $scopedCompanyIds): array
{
    if (empty($scopedCompanyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, vacancy_code, title FROM job_vacancies WHERE company_id IN ($ph) ORDER BY title ASC");
    $st->execute($scopedCompanyIds);
    return $st->fetchAll();
}

// ============================================================================
// DASHBOARD METRICS (SINGLE AGGREGATED SQL QUERIES)
// ============================================================================

/**
 * Retrieve the 8 KPI stat card values for the dashboard in aggregated queries.
 *
 * @param PDO   $pdo
 * @param int[] $companyIds
 * @param array $filters
 * @param array $metricCompanyScopes Optional mapping of metric => allowed company IDs
 */
function report_dashboard_cards(
    PDO $pdo,
    array $companyIds,
    array $filters = [],
    array $metricCompanyScopes = []
): array {
    $empScope   = array_key_exists('employee', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['employee']))
        : (user_can('employee.view') ? $companyIds : []);
    $vacScope   = array_key_exists('vacancy', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['vacancy']))
        : (user_can('vacancy.view') ? $companyIds : []);
    $candScope  = array_key_exists('candidate', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['candidate']))
        : (user_can('candidate.view') ? $companyIds : []);
    $intScope   = array_key_exists('interview', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['interview']))
        : (user_can('interview.view') ? $companyIds : []);
    $offScope   = array_key_exists('offer', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['offer']))
        : (user_can('offer.view') ? $companyIds : []);
    $leaveScope = array_key_exists('leave', $metricCompanyScopes)
        ? array_values(array_intersect($companyIds, $metricCompanyScopes['leave']))
        : ((user_can('leave.view') || user_can('leave.approve')) ? $companyIds : []);

    $emptyResult = [
        'total_companies'      => count($companyIds),
        'active_employees'     => !empty($empScope) ? 0 : null,
        'open_vacancies'       => !empty($vacScope) ? 0 : null,
        'total_candidates'     => !empty($candScope) ? 0 : null,
        'shortlisted_selected' => !empty($candScope) ? 0 : null,
        'upcoming_interviews'  => !empty($intScope) ? 0 : null,
        'pending_offers'       => !empty($offScope) ? 0 : null,
        'pending_leaves'       => !empty($leaveScope) ? 0 : null,
    ];

    if (empty($companyIds)) {
        return $emptyResult;
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $deptId = (int)($filters['department_id'] ?? 0);
    $vacId  = (int)($filters['vacancy_id'] ?? 0);
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo   = $filters['date_to'] ?? null;

    // 1. Active Employees (Strictly null if user has no employee.view in scope)
    $activeEmployees = null;
    if (!empty($empScope)) {
        $phEmp = implode(',', array_fill(0, count($empScope), '?'));
        $empWhere = ["company_id IN ($phEmp)", "employment_status = 'active'"];
        $empParams = $empScope;
        if ($deptId > 0) {
            $empWhere[] = "department_id = ?";
            $empParams[] = $deptId;
        }
        $stEmp = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE " . implode(' AND ', $empWhere));
        $stEmp->execute($empParams);
        $activeEmployees = (int)$stEmp->fetchColumn();
    }

    // 2. Open Vacancies
    $openVacancies = null;
    if (!empty($vacScope)) {
        $phVac = implode(',', array_fill(0, count($vacScope), '?'));
        $vacWhere = ["company_id IN ($phVac)", "status = 'open'"];
        $vacParams = $vacScope;
        if ($deptId > 0) {
            $vacWhere[] = "department_id = ?";
            $vacParams[] = $deptId;
        }
        if ($vacId > 0) {
            $vacWhere[] = "id = ?";
            $vacParams[] = $vacId;
        }
        $stVac = $pdo->prepare("SELECT COUNT(*) FROM job_vacancies WHERE " . implode(' AND ', $vacWhere));
        $stVac->execute($vacParams);
        $openVacancies = (int)$stVac->fetchColumn();
    }

    // 3 & 4. Total Candidates & Shortlisted/Selected (Aggregated in candidate_applications)
    $appStats = ['total_candidates' => null, 'shortlisted_selected' => null];
    if (!empty($candScope)) {
        $phCand = implode(',', array_fill(0, count($candScope), '?'));
        $appWhere = ["ca.company_id IN ($phCand)"];
        $appParams = $candScope;
        if ($deptId > 0) {
            $appWhere[] = "jv.department_id = ?";
            $appParams[] = $deptId;
        }
        if ($vacId > 0) {
            $appWhere[] = "ca.vacancy_id = ?";
            $appParams[] = $vacId;
        }
        if ($dateFrom !== null && $dateTo !== null) {
            $appWhere[] = "ca.application_date BETWEEN ? AND ?";
            $appParams[] = $dateFrom;
            $appParams[] = $dateTo;
        }

        $appSql = "SELECT COUNT(DISTINCT ca.candidate_id) AS total_candidates,
                          COUNT(DISTINCT CASE WHEN ca.status IN ('shortlisted', 'interview_scheduled', 'interviewed', 'selected') THEN ca.candidate_id END) AS shortlisted_selected
                     FROM candidate_applications ca
                     JOIN job_vacancies jv ON jv.id = ca.vacancy_id
                    WHERE " . implode(' AND ', $appWhere);
        $stApp = $pdo->prepare($appSql);
        $stApp->execute($appParams);
        $fetched = $stApp->fetch();
        if ($fetched) {
            $appStats['total_candidates'] = (int)$fetched['total_candidates'];
            $appStats['shortlisted_selected'] = (int)$fetched['shortlisted_selected'];
        } else {
            $appStats['total_candidates'] = 0;
            $appStats['shortlisted_selected'] = 0;
        }
    }

    // 5. Upcoming Interviews (Next 7 days, status = scheduled)
    $upcomingInterviews = null;
    if (!empty($intScope)) {
        $phInt = implode(',', array_fill(0, count($intScope), '?'));
        $intWhere = ["i.company_id IN ($phInt)", "i.status = 'scheduled'", "i.scheduled_at >= NOW()", "i.scheduled_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)"];
        $intParams = $intScope;
        if ($vacId > 0) {
            $intWhere[] = "ca.vacancy_id = ?";
            $intParams[] = $vacId;
        }
        $stInt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM interviews i
               JOIN candidate_applications ca ON ca.id = i.application_id
              WHERE " . implode(' AND ', $intWhere)
        );
        $stInt->execute($intParams);
        $upcomingInterviews = (int)$stInt->fetchColumn();
    }

    // 6. Pending Offers (draft + sent)
    $pendingOffers = null;
    if (!empty($offScope)) {
        $phOff = implode(',', array_fill(0, count($offScope), '?'));
        $offWhere = ["jo.company_id IN ($phOff)", "jo.status IN ('draft', 'sent')"];
        $offParams = $offScope;
        if ($deptId > 0) {
            $offWhere[] = "jo.department_id = ?";
            $offParams[] = $deptId;
        }
        $stOff = $pdo->prepare("SELECT COUNT(*) FROM job_offers jo WHERE " . implode(' AND ', $offWhere));
        $stOff->execute($offParams);
        $pendingOffers = (int)$stOff->fetchColumn();
    }

    // 7. Pending Leave Requests
    $pendingLeaves = null;
    if (!empty($leaveScope)) {
        $phLr = implode(',', array_fill(0, count($leaveScope), '?'));
        $lrWhere = ["lr.company_id IN ($phLr)", "lr.status = 'pending'"];
        $lrParams = $leaveScope;
        if ($deptId > 0) {
            $lrWhere[] = "e.department_id = ?";
            $lrParams[] = $deptId;
        }
        $stLr = $pdo->prepare(
            "SELECT COUNT(*)
               FROM leave_requests lr
               JOIN employees e ON e.id = lr.employee_id
              WHERE " . implode(' AND ', $lrWhere)
        );
        $stLr->execute($lrParams);
        $pendingLeaves = (int)$stLr->fetchColumn();
    }

    return [
        'total_companies'      => count($companyIds),
        'active_employees'     => $activeEmployees,
        'open_vacancies'       => $openVacancies,
        'total_candidates'     => $appStats['total_candidates'],
        'shortlisted_selected' => $appStats['shortlisted_selected'],
        'upcoming_interviews'  => $upcomingInterviews,
        'pending_offers'       => $pendingOffers,
        'pending_leaves'       => $pendingLeaves,
    ];
}

/**
 * Company-wise employee count (active and total).
 */
function report_company_employee_counts(PDO $pdo, array $companyIds): array
{
    if (empty($companyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $sql = "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code,
                   COUNT(CASE WHEN e.employment_status = 'active' THEN 1 END) AS active_count,
                   COUNT(e.id) AS total_count
              FROM companies c
         LEFT JOIN employees e ON e.company_id = c.id
             WHERE c.id IN ($ph)
             GROUP BY c.id, c.name, c.code
             ORDER BY c.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($companyIds);
    return $st->fetchAll();
}

/**
 * Company-wise recruitment activity (applications, interviews, offers, hires).
 */
function report_company_recruitment_activity(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $dateFrom = $filters['date_from'] ?? null;
    $dateTo   = $filters['date_to'] ?? null;

    $appDateCond = "";
    $intDateCond = "";
    $offDateCond = "";
    $params = [];

    // Construct subqueries with matching company IN ($ph)
    $appParams = $companyIds;
    if ($dateFrom !== null && $dateTo !== null) {
        $appDateCond = " AND application_date BETWEEN ? AND ?";
        $appParams[] = $dateFrom;
        $appParams[] = $dateTo;
    }

    $intParams = $companyIds;
    if ($dateFrom !== null && $dateTo !== null) {
        $intDateCond = " AND scheduled_at BETWEEN ? AND ?";
        $intParams[] = $dateFrom . ' 00:00:00';
        $intParams[] = $dateTo . ' 23:59:59';
    }

    $offParams = $companyIds;
    if ($dateFrom !== null && $dateTo !== null) {
        $offDateCond = " AND offer_date BETWEEN ? AND ?";
        $offParams[] = $dateFrom;
        $offParams[] = $dateTo;
    }

    $hireParams = $companyIds;
    $hireDateCond = "";
    if ($dateFrom !== null && $dateTo !== null) {
        $hireDateCond = " AND application_date BETWEEN ? AND ?";
        $hireParams[] = $dateFrom;
        $hireParams[] = $dateTo;
    }

    $allParams = array_merge($appParams, $intParams, $offParams, $hireParams, $companyIds);

    $sql = "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code,
                   COALESCE(apps.cnt, 0) AS applications_count,
                   COALESCE(ints.cnt, 0) AS interviews_count,
                   COALESCE(offs.cnt, 0) AS offers_count,
                   COALESCE(hires.cnt, 0) AS hires_count
              FROM companies c
         LEFT JOIN (
             SELECT company_id, COUNT(*) AS cnt
               FROM candidate_applications
              WHERE company_id IN ($ph) $appDateCond
              GROUP BY company_id
         ) apps ON apps.company_id = c.id
         LEFT JOIN (
             SELECT company_id, COUNT(*) AS cnt
               FROM interviews
              WHERE company_id IN ($ph) $intDateCond
              GROUP BY company_id
         ) ints ON ints.company_id = c.id
         LEFT JOIN (
             SELECT company_id, COUNT(*) AS cnt
               FROM job_offers
              WHERE company_id IN ($ph) $offDateCond
              GROUP BY company_id
         ) offs ON offs.company_id = c.id
         LEFT JOIN (
             SELECT company_id, COUNT(*) AS cnt
               FROM candidate_applications
              WHERE company_id IN ($ph) AND status = 'hired' $hireDateCond
              GROUP BY company_id
         ) hires ON hires.company_id = c.id
             WHERE c.id IN ($ph)
             ORDER BY c.name ASC";

    $st = $pdo->prepare($sql);
    $st->execute($allParams);
    return $st->fetchAll();
}

/**
 * Monthly hiring statistics for the last 12 months (applications vs hired).
 */
function report_monthly_hiring_stats(PDO $pdo, array $companyIds): array
{
    if (empty($companyIds)) {
        return [];
    }

    // Build 12 calendar month slots
    $months = [];
    $cur = new DateTime('first day of this month');
    for ($i = 11; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-$i month");
        $ym = $m->format('Y-m');
        $months[$ym] = [
            'ym'           => $ym,
            'label'        => $m->format('M Y'),
            'applications' => 0,
            'hired'        => 0,
        ];
    }

    $startMonth = array_key_first($months) . '-01';
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $params = array_merge($companyIds, [$startMonth]);

    $sql = "SELECT DATE_FORMAT(application_date, '%Y-%m') AS ym,
                   COUNT(*) AS applications,
                   COUNT(CASE WHEN status = 'hired' THEN 1 END) AS hired
              FROM candidate_applications
             WHERE company_id IN ($ph)
               AND application_date >= ?
             GROUP BY ym";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as $r) {
        $ym = (string)$r['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['applications'] = (int)$r['applications'];
            $months[$ym]['hired']        = (int)$r['hired'];
        }
    }

    return array_values($months);
}

/**
 * Monthly employee joining statistics for the last 12 months.
 */
function report_monthly_joining_stats(PDO $pdo, array $companyIds): array
{
    if (empty($companyIds)) {
        return [];
    }

    $months = [];
    $cur = new DateTime('first day of this month');
    for ($i = 11; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-$i month");
        $ym = $m->format('Y-m');
        $months[$ym] = [
            'ym'       => $ym,
            'label'    => $m->format('M Y'),
            'joinings' => 0,
        ];
    }

    $startMonth = array_key_first($months) . '-01';
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $params = array_merge($companyIds, [$startMonth]);

    $sql = "SELECT DATE_FORMAT(joining_date, '%Y-%m') AS ym,
                   COUNT(*) AS joinings
              FROM employees
             WHERE company_id IN ($ph)
               AND joining_date >= ?
             GROUP BY ym";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as $r) {
        $ym = (string)$r['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['joinings'] = (int)$r['joinings'];
        }
    }

    return array_values($months);
}

/**
 * Recruitment funnel statistics (applied -> shortlisted -> interviewed -> selected -> offered -> hired).
 */
function report_recruitment_funnel_stats(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [
            'applied'     => 0,
            'shortlisted' => 0,
            'interviewed' => 0,
            'selected'    => 0,
            'offered'     => 0,
            'hired'       => 0,
        ];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT
              COUNT(ca.id) AS applied,
              COUNT(CASE WHEN ca.status IN ('shortlisted','interview_scheduled','interviewed','selected','offered','offer_accepted','offer_declined','hired') THEN 1 END) AS shortlisted,
              COUNT(CASE WHEN ca.status IN ('interviewed','selected','offered','offer_accepted','offer_declined','hired')
                          OR EXISTS (SELECT 1 FROM interviews i WHERE i.application_id = ca.id) THEN 1 END) AS interviewed,
              COUNT(CASE WHEN ca.status IN ('selected','offered','offer_accepted','offer_declined','hired') THEN 1 END) AS selected,
              COUNT(CASE WHEN ca.status IN ('offered','offer_accepted','offer_declined','hired')
                          OR EXISTS (SELECT 1 FROM job_offers jo WHERE jo.application_id = ca.id) THEN 1 END) AS offered,
              COUNT(CASE WHEN ca.status = 'hired'
                          OR EXISTS (SELECT 1 FROM job_offers jo WHERE jo.application_id = ca.id AND jo.converted_employee_id IS NOT NULL) THEN 1 END) AS hired
            FROM candidate_applications ca
            JOIN job_vacancies jv ON jv.id = ca.vacancy_id
           WHERE $whereSql";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $res = $st->fetch();

    return [
        'applied'     => (int)($res['applied'] ?? 0),
        'shortlisted' => (int)($res['shortlisted'] ?? 0),
        'interviewed' => (int)($res['interviewed'] ?? 0),
        'selected'    => (int)($res['selected'] ?? 0),
        'offered'     => (int)($res['offered'] ?? 0),
        'hired'       => (int)($res['hired'] ?? 0),
    ];
}

// ============================================================================
// RECRUITMENT REPORTS
// ============================================================================

/**
 * Report 1: Applicants by Job Position.
 */
function report_applicants_by_position(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["jv.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "jv.id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }

    $appDateJoin = "";
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $appDateJoin = " AND ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT jv.id AS vacancy_id, jv.vacancy_code, jv.title AS vacancy_title,
                   c.name AS company_name, c.code AS company_code,
                   d.name AS department_name,
                   COUNT(ca.id) AS applications_count,
                   COUNT(CASE WHEN ca.status IN ('shortlisted','interview_scheduled','interviewed','selected','offered','offer_accepted','offer_declined','hired') THEN 1 END) AS shortlisted_count,
                   COUNT(CASE WHEN ca.status IN ('selected','offered','offer_accepted','offer_declined','hired') THEN 1 END) AS selected_count,
                   COUNT(CASE WHEN ca.status = 'rejected' THEN 1 END) AS rejected_count,
                   COUNT(CASE WHEN ca.status = 'hired' THEN 1 END) AS hired_count
              FROM job_vacancies jv
              JOIN companies c ON c.id = jv.company_id
         LEFT JOIN departments d ON d.id = jv.department_id
         LEFT JOIN candidate_applications ca ON ca.vacancy_id = jv.id $appDateJoin
             WHERE $whereSql
             GROUP BY jv.id, jv.vacancy_code, jv.title, c.name, c.code, d.name
             ORDER BY c.name ASC, jv.title ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 2: Candidates Selected for Interview (Count & Paginated rows).
 */
function count_candidates_selected_for_interview(PDO $pdo, array $companyIds, array $filters = []): int
{
    if (empty($companyIds)) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)", "ca.status IN ('shortlisted', 'interview_scheduled', 'interviewed', 'selected')"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT COUNT(DISTINCT ca.id)
              FROM candidate_applications ca
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
             WHERE $whereSql";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function report_candidates_selected_for_interview(
    PDO $pdo,
    array $companyIds,
    array $filters = [],
    int $limit = 0,
    int $offset = 0
): array {
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)", "ca.status IN ('shortlisted', 'interview_scheduled', 'interviewed', 'selected')"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT ca.id AS application_id, ca.status AS application_status, ca.application_date,
                   c.candidate_code, c.first_name, c.last_name,
                   jv.vacancy_code, jv.title AS vacancy_title,
                   comp.name AS company_name, comp.code AS company_code,
                   latest_int.scheduled_at, latest_int.round_no, latest_int.status AS interview_status
              FROM candidate_applications ca
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
              JOIN companies comp ON comp.id = ca.company_id
         LEFT JOIN (
             SELECT i1.application_id, i1.scheduled_at, i1.round_no, i1.status
               FROM interviews i1
              WHERE i1.id = (
                  SELECT i2.id FROM interviews i2 WHERE i2.application_id = i1.application_id ORDER BY i2.scheduled_at DESC, i2.id DESC LIMIT 1
              )
         ) latest_int ON latest_int.application_id = ca.id
             WHERE $whereSql
             ORDER BY ca.application_date DESC, ca.id DESC";

    if ($limit > 0) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 3: Interview Results.
 * Evaluation scores and recommendations are populated ONLY if $canViewEvaluations is true.
 */
function count_interview_results(PDO $pdo, array $companyIds, array $filters = []): int
{
    if (empty($companyIds)) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["i.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "DATE(i.scheduled_at) BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT COUNT(*)
              FROM interviews i
              JOIN candidate_applications ca ON ca.id = i.application_id
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
             WHERE $whereSql";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function report_interview_results(
    PDO $pdo,
    array $companyIds,
    array $filters = [],
    array $evalCompanyIds = [],
    int $limit = 0,
    int $offset = 0
): array {
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["i.company_id IN ($ph)"];
    $whereParams = array_values(array_map('intval', $companyIds));

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $whereParams[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $whereParams[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "DATE(i.scheduled_at) BETWEEN ? AND ?";
        $whereParams[] = $filters['date_from'];
        $whereParams[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);

    // Join / aggregate evaluations ONLY for interviews whose company_id is in $evalCompanyIds
    $allowedEvalCompanyIds = array_values(array_intersect(
        array_values(array_map('intval', $companyIds)),
        array_values(array_map('intval', $evalCompanyIds))
    ));

    $evalParams = [];
    if (!empty($allowedEvalCompanyIds)) {
        $evalPh = implode(',', array_fill(0, count($allowedEvalCompanyIds), '?'));
        $evalJoin = "LEFT JOIN interview_evaluations ie ON ie.interview_id = i.id AND i.company_id IN ($evalPh)";
        $evalParams = $allowedEvalCompanyIds;
        $evalFields = "ROUND(AVG(ie.overall_score), 1) AS avg_score,
                       COUNT(ie.id) AS eval_count,
                       COUNT(CASE WHEN ie.recommendation = 'strong_hire' THEN 1 END) AS rec_strong_hire,
                       COUNT(CASE WHEN ie.recommendation = 'hire' THEN 1 END) AS rec_hire,
                       COUNT(CASE WHEN ie.recommendation = 'hold' THEN 1 END) AS rec_hold,
                       COUNT(CASE WHEN ie.recommendation = 'reject' THEN 1 END) AS rec_reject,
                       COUNT(CASE WHEN ie.recommendation IS NOT NULL AND ie.recommendation NOT IN ('strong_hire', 'hire', 'hold', 'reject') THEN 1 END) AS rec_other";
    } else {
        $evalJoin = "";
        $evalFields = "NULL AS avg_score,
                       0 AS eval_count,
                       0 AS rec_strong_hire,
                       0 AS rec_hire,
                       0 AS rec_hold,
                       0 AS rec_reject,
                       0 AS rec_other";
    }

    $sql = "SELECT i.id AS interview_id, i.round_no, i.interview_type, i.scheduled_at, i.status AS interview_status,
                   c.candidate_code, c.first_name, c.last_name,
                   jv.vacancy_code, jv.title AS vacancy_title,
                   comp.id AS company_id, comp.name AS company_name, comp.code AS company_code,
                   $evalFields
              FROM interviews i
              JOIN candidate_applications ca ON ca.id = i.application_id
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
              JOIN companies comp ON comp.id = i.company_id
              $evalJoin
             WHERE $whereSql
             GROUP BY i.id, i.round_no, i.interview_type, i.scheduled_at, i.status,
                      c.candidate_code, c.first_name, c.last_name,
                      jv.vacancy_code, jv.title, comp.id, comp.name, comp.code
             ORDER BY i.scheduled_at DESC, i.id DESC";

    if ($limit > 0) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $st = $pdo->prepare($sql);
    $st->execute(array_merge($evalParams, $whereParams));
    return $st->fetchAll();
}

/**
 * Report 4: Candidate Selection Rate (Per Vacancy & Company, division-by-zero guarded).
 */
function report_candidate_selection_rate(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["jv.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "jv.id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }

    $dateJoin = "";
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $dateJoin = " AND ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT jv.id AS vacancy_id, jv.vacancy_code, jv.title AS vacancy_title,
                   c.name AS company_name, c.code AS company_code,
                   d.name AS department_name,
                   COUNT(ca.id) AS total_applicants,
                   COUNT(CASE WHEN ca.status IN ('selected', 'hired', 'offered', 'offer_accepted') THEN 1 END) AS selected_or_hired
              FROM job_vacancies jv
              JOIN companies c ON c.id = jv.company_id
         LEFT JOIN departments d ON d.id = jv.department_id
         LEFT JOIN candidate_applications ca ON ca.vacancy_id = jv.id $dateJoin
             WHERE $whereSql
             GROUP BY jv.id, jv.vacancy_code, jv.title, c.name, c.code, d.name
             ORDER BY c.name ASC, jv.title ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $total = (int)$r['total_applicants'];
        $sel   = (int)$r['selected_or_hired'];
        $r['selection_rate'] = ($total > 0) ? round(($sel / $total) * 100, 1) : 0.0;
    }
    unset($r);

    return $rows;
}

/**
 * Report 5: Hired Candidates (Count & Paginated rows).
 */
function count_hired_candidates(PDO $pdo, array $companyIds, array $filters = []): int
{
    if (empty($companyIds)) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)", "ca.status = 'hired'"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT COUNT(*)
              FROM candidate_applications ca
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
             WHERE $whereSql";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function report_hired_candidates(
    PDO $pdo,
    array $companyIds,
    array $filters = [],
    int $limit = 0,
    int $offset = 0
): array {
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)", "ca.status = 'hired'"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    // Confidential columns (NIC, DOB, address, salary) are omitted.
    $sql = "SELECT c.candidate_code, c.first_name, c.last_name,
                   jv.vacancy_code, jv.title AS vacancy_title,
                   comp.name AS company_name, comp.code AS company_code,
                   d.name AS department_name,
                   COALESCE(e.employee_code, 'Pending') AS employee_code,
                   COALESCE(e.joining_date, jo.proposed_join_date, ca.application_date) AS hire_date
              FROM candidate_applications ca
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
              JOIN companies comp ON comp.id = ca.company_id
         LEFT JOIN departments d ON d.id = jv.department_id
         LEFT JOIN job_offers jo ON jo.application_id = ca.id AND jo.converted_employee_id IS NOT NULL
         LEFT JOIN employees e ON e.id = jo.converted_employee_id OR e.candidate_id = c.id
             WHERE $whereSql
             ORDER BY hire_date DESC, c.last_name ASC";

    if ($limit > 0) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 6: Recruitment Status Summary.
 */
function report_recruitment_status_summary(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT ca.status, COUNT(*) AS status_count
              FROM candidate_applications ca
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
             WHERE $whereSql
             GROUP BY ca.status
             ORDER BY status_count DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $total = array_sum(array_column($rows, 'status_count'));
    foreach ($rows as &$r) {
        $cnt = (int)$r['status_count'];
        $r['percentage'] = ($total > 0) ? round(($cnt / $total) * 100, 1) : 0.0;
        $r['status_label'] = application_status_list()[$r['status']] ?? ucfirst((string)$r['status']);
    }
    unset($r);

    return $rows;
}

/**
 * Report 7: Recruitment Source Analysis (division-by-zero guarded).
 */
function report_recruitment_source_analysis(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["ca.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "jv.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['vacancy_id'])) {
        $where[] = "ca.vacancy_id = ?";
        $params[] = (int)$filters['vacancy_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "ca.application_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT COALESCE(NULLIF(TRIM(c.source), ''), 'Direct / Unspecified') AS source_name,
                   COUNT(DISTINCT c.id) AS candidates_count,
                   COUNT(ca.id) AS applications_count,
                   COUNT(CASE WHEN ca.status = 'hired' THEN 1 END) AS hired_count
              FROM candidate_applications ca
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies jv ON jv.id = ca.vacancy_id
             WHERE $whereSql
             GROUP BY source_name
             ORDER BY applications_count DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $apps  = (int)$r['applications_count'];
        $hires = (int)$r['hired_count'];
        $r['conversion_rate'] = ($apps > 0) ? round(($hires / $apps) * 100, 1) : 0.0;
    }
    unset($r);

    return $rows;
}

// ============================================================================
// EMPLOYEE & WORKFORCE REPORTS
// ============================================================================

/**
 * Report 8: Total Employees with Company-Wise Breakdown.
 */
function report_employees_by_company(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $deptFilter = "";
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $deptFilter = " AND e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }

    $sql = "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code,
                   COUNT(CASE WHEN e.employment_status = 'active' THEN 1 END) AS active_count,
                   COUNT(CASE WHEN e.employment_status = 'on_leave' THEN 1 END) AS on_leave_count,
                   COUNT(CASE WHEN e.employment_status = 'transferred' THEN 1 END) AS transferred_count,
                   COUNT(CASE WHEN e.employment_status = 'resigned' THEN 1 END) AS resigned_count,
                   COUNT(CASE WHEN e.employment_status = 'terminated' THEN 1 END) AS terminated_count,
                   COUNT(CASE WHEN e.employment_status = 'retired' THEN 1 END) AS retired_count,
                   COUNT(e.id) AS total_count
              FROM companies c
         LEFT JOIN employees e ON e.company_id = c.id $deptFilter
             WHERE c.id IN ($ph)
             GROUP BY c.id, c.name, c.code
             ORDER BY c.name ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 9: Department-Wise Employee Count.
 */
function report_department_employee_counts(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["c.id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "d.id = ?";
        $params[] = (int)$filters['department_id'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT c.name AS company_name, c.code AS company_code,
                   d.id AS department_id, d.name AS department_name,
                   COUNT(CASE WHEN e.employment_status = 'active' THEN 1 END) AS active_count,
                   COUNT(e.id) AS total_count
              FROM departments d
              JOIN companies c ON c.id = d.company_id
         LEFT JOIN employees e ON e.department_id = d.id
             WHERE $whereSql
             GROUP BY c.name, c.code, d.id, d.name
             ORDER BY c.name ASC, d.name ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 10: Employment-Type Summary.
 */
function report_employment_type_summary(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $deptFilter = "";
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $deptFilter = " AND e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }

    $sql = "SELECT c.id AS company_id, c.name AS company_name, c.code AS company_code,
                   COUNT(CASE WHEN e.employment_type = 'full_time' THEN 1 END) AS full_time_count,
                   COUNT(CASE WHEN e.employment_type = 'part_time' THEN 1 END) AS part_time_count,
                   COUNT(CASE WHEN e.employment_type = 'contract' THEN 1 END) AS contract_count,
                   COUNT(CASE WHEN e.employment_type = 'intern' THEN 1 END) AS intern_count,
                   COUNT(CASE WHEN e.employment_type = 'probation' THEN 1 END) AS probation_count,
                   COUNT(e.id) AS total_count
              FROM companies c
         LEFT JOIN employees e ON e.company_id = c.id $deptFilter
             WHERE c.id IN ($ph)
             GROUP BY c.id, c.name, c.code
             ORDER BY c.name ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Report 11: Active and Inactive Employees Status Summary.
 */
function report_employee_status_summary(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["e.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT e.employment_status, COUNT(*) AS status_count
              FROM employees e
             WHERE $whereSql
             GROUP BY e.employment_status
             ORDER BY status_count DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $total = array_sum(array_column($rows, 'status_count'));
    foreach ($rows as &$r) {
        $cnt = (int)$r['status_count'];
        $r['percentage'] = ($total > 0) ? round(($cnt / $total) * 100, 1) : 0.0;
        $r['status_label'] = employee_status_list()[$r['employment_status']] ?? ucfirst((string)$r['employment_status']);
    }
    unset($r);

    return $rows;
}

/**
 * Report 12: Employee Joining History (Monthly summary + Paginated rows).
 */
function report_employee_joining_history_monthly(PDO $pdo, array $companyIds, array $filters = []): array
{
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["e.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "e.joining_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT DATE_FORMAT(e.joining_date, '%Y-%m') AS ym,
                   COUNT(*) AS joinings_count
              FROM employees e
             WHERE $whereSql
             GROUP BY ym
             ORDER BY ym DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function count_employee_joining_history(PDO $pdo, array $companyIds, array $filters = []): int
{
    if (empty($companyIds)) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["e.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "e.joining_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE $whereSql");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function report_employee_joining_history_rows(
    PDO $pdo,
    array $companyIds,
    array $filters = [],
    int $limit = 0,
    int $offset = 0
): array {
    if (empty($companyIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $where = ["e.company_id IN ($ph)"];
    $params = $companyIds;

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $where[] = "e.joining_date BETWEEN ? AND ?";
        $params[] = $filters['date_from'];
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    // Confidential columns (NIC, DOB, address, salary) are omitted.
    $sql = "SELECT e.id AS employee_id, e.employee_code, e.first_name, e.last_name,
                   e.joining_date, e.employment_type, e.employment_status,
                   c.name AS company_name, c.code AS company_code,
                   d.name AS department_name,
                   des.title AS designation_title
              FROM employees e
              JOIN companies c ON c.id = e.company_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
             WHERE $whereSql
             ORDER BY e.joining_date DESC, e.id DESC";

    if ($limit > 0) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ============================================================================
// CSV EXPORT STREAMING HELPER
// ============================================================================

/**
 * Stream a sanitized CSV export response directly to php://output.
 * Enforces report.export permission, formula injection protection, UTF-8 BOM,
 * fixed timestamped filenames, 50,000 row cap, and audit logging.
 *
 * @param string   $slug          Report slug (e.g. 'applicants-by-position')
 * @param string[] $headers       Column headers
 * @param iterable $rows          Data rows (arrays of scalars)
 * @param array    $filtersLogged Filters to record in audit log
 * @param int      $companyLogged Primary company ID for audit log (0 if multi-company)
 * @return never
 */
function report_stream_csv(
    string $slug,
    array $headers,
    iterable $rows,
    array $filtersLogged = [],
    array $scopedCompanyIds = [],
    int $companyLogged = 0
): void {
    if (empty($scopedCompanyIds)) {
        if (!empty($filtersLogged['scoped_company_ids'])) {
            $scopedCompanyIds = (array)$filtersLogged['scoped_company_ids'];
        } elseif (!empty($filtersLogged['company_id'])) {
            $scopedCompanyIds = [(int)$filtersLogged['company_id']];
        }
    }

    if (empty($scopedCompanyIds)) {
        abort(403, 'You do not have permission to export reports.');
    }

    foreach ($scopedCompanyIds as $cid) {
        if (!user_can('report.export', (int)$cid)) {
            abort(403, 'You do not have permission to export reports for all companies in the export scope.');
        }
    }

    $cleanSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
    $timestamp = date('Ymd-His');
    $filename = "report-{$cleanSlug}-{$timestamp}.csv";

    // Clean output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }

    // Emit UTF-8 BOM for Microsoft Excel compatibility
    fputs($out, "\xEF\xBB\xBF");

    // Output headers (escaped)
    $escapedHeaders = array_map(fn($h) => escape_csv_cell((string)$h), $headers);
    fputcsv($out, $escapedHeaders);

    $count = 0;
    $maxCap = 50000;

    foreach ($rows as $row) {
        if ($count >= $maxCap) {
            break;
        }
        $line = [];
        foreach ($row as $val) {
            $line[] = escape_csv_cell($val);
        }
        fputcsv($out, $line);
        $count++;
    }

    fclose($out);

    // Audit log
    log_activity(
        'report.export',
        'report',
        0,
        [
            'report_slug' => $cleanSlug,
            'filters'     => $filtersLogged,
            'row_count'   => $count,
        ],
        $companyLogged > 0 ? $companyLogged : null
    );

    exit;
}
