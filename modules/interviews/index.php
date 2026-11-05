<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('interview.view');

$pdo = db();
$userId = current_user_id();

// Scoped company access
$accessibleCompanyIds = accessible_company_ids('interview.view');
$scopedCompanyIds = scoped_company_ids('interview.view');

// Accessible companies for dropdown
$companies = [];
if (!empty($accessibleCompanyIds)) {
    $cph = implode(',', array_fill(0, count($accessibleCompanyIds), '?'));
    $cSt = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($cph) AND is_active = 1 ORDER BY name");
    $cSt->execute($accessibleCompanyIds);
    $companies = $cSt->fetchAll();
}

// Fetch active interviewers/users for filter
$interviewerSql = "SELECT DISTINCT u.id, u.full_name
                     FROM users u
                     WHERE u.is_active = 1
                       AND (u.is_group_admin = 1 OR EXISTS (
                           SELECT 1 FROM company_user_assignments cua
                            WHERE cua.user_id = u.id" .
                            (!empty($accessibleCompanyIds) ? " AND cua.company_id IN (" . implode(',', array_fill(0, count($accessibleCompanyIds), '?')) . ")" : "") .
                       "))
                     ORDER BY u.full_name";
$intSt = $pdo->prepare($interviewerSql);
$intSt->execute($accessibleCompanyIds);
$interviewers = $intSt->fetchAll();

// Filters
$filterCompany = (int)($_GET['company_id'] ?? 0);
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));
$filterInterviewer = (int)($_GET['interviewer_id'] ?? 0);
$filterSearch = trim((string)($_GET['search'] ?? ''));

// Build WHERE clauses
$where = ['1=1'];
$params = [];

// Company scoping
if ($filterCompany > 0) {
    if (!in_array($filterCompany, $accessibleCompanyIds, true)) {
        abort(403, 'Access denied to the specified company.');
    }
    $where[] = 'i.company_id = ?';
    $params[] = $filterCompany;
} else {
    [$cSql, $cParams] = company_in_sql($scopedCompanyIds, 'i.company_id');
    $where[] = $cSql;
    $params = array_merge($params, $cParams);
}

// Status filter
$validStatuses = interview_status_list();
if ($filterStatus !== '' && isset($validStatuses[$filterStatus])) {
    $where[] = 'i.status = ?';
    $params[] = $filterStatus;
}

// Date filters
if ($filterDateFrom !== '') {
    $where[] = 'DATE(i.scheduled_at) >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[] = 'DATE(i.scheduled_at) <= ?';
    $params[] = $filterDateTo;
}

// Interviewer filter
if ($filterInterviewer > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM interview_panel ip_sub WHERE ip_sub.interview_id = i.id AND ip_sub.user_id = ?)';
    $params[] = $filterInterviewer;
}

// Search filter (candidate name, code, vacancy)
if ($filterSearch !== '') {
    $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.candidate_code LIKE ? OR v.title LIKE ? OR v.vacancy_code LIKE ?)';
    $searchWild = '%' . $filterSearch . '%';
    $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild, $searchWild]);
}

$whereClause = implode(' AND ', $where);

// Count total records
$countSql = "SELECT COUNT(DISTINCT i.id)
               FROM interviews i
               JOIN candidate_applications ca ON ca.id = i.application_id
               JOIN candidates c ON c.id = ca.candidate_id
               JOIN job_vacancies v ON v.id = ca.vacancy_id
               JOIN companies comp ON comp.id = i.company_id
              WHERE $whereClause";
$cntSt = $pdo->prepare($countSql);
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$pagination = paginate($total, $page, $perPage);

// Fetch paginated interview rows
$dataSql = "SELECT i.*,
                   ca.candidate_id, ca.vacancy_id, ca.status AS app_status,
                   c.first_name, c.last_name, c.candidate_code,
                   v.title AS vacancy_title, v.vacancy_code,
                   comp.name AS company_name, comp.code AS company_code,
                   GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS panel_names,
                   COUNT(DISTINCT ie.id) AS evaluation_count,
                   EXISTS (SELECT 1 FROM interview_panel ip2 WHERE ip2.interview_id = i.id AND ip2.user_id = ?) AS user_is_panel,
                   EXISTS (SELECT 1 FROM interview_evaluations ie2 WHERE ie2.interview_id = i.id AND ie2.evaluator_id = ?) AS user_evaluated
              FROM interviews i
              JOIN candidate_applications ca ON ca.id = i.application_id
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies v ON v.id = ca.vacancy_id
              JOIN companies comp ON comp.id = i.company_id
              LEFT JOIN interview_panel ip ON ip.interview_id = i.id
              LEFT JOIN users u ON u.id = ip.user_id
              LEFT JOIN interview_evaluations ie ON ie.interview_id = i.id
             WHERE $whereClause
             GROUP BY i.id
             ORDER BY i.scheduled_at DESC
             LIMIT ? OFFSET ?";

$dataParams = array_merge([$userId, $userId], $params, [$pagination['per_page'], $pagination['offset']]);
$dataSt = $pdo->prepare($dataSql);
$dataSt->execute($dataParams);
$interviewsList = $dataSt->fetchAll();

// Quick statistics
$todayCount = count_today_interviews($pdo, $filterCompany > 0 ? $filterCompany : null);

$pageTitle = 'Interviews';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Breadcrumb & Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Interviews</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-0 text-body">Interview Management</h1>
            <p class="text-muted small mb-0">Multi-company recruitment interview schedule, panel tracking, and candidate evaluations.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="View Toggle">
                <a href="<?= e(url('modules/interviews/index.php')) ?>" class="btn btn-primary active" aria-current="page">
                    <i class="bi bi-list-ul me-1"></i>List
                </a>
                <a href="<?= e(url('modules/interviews/calendar.php')) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar3 me-1"></i>Calendar
                </a>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6">
                <i class="bi bi-calendar-check me-1"></i> Today's Interviews: <strong><?= $todayCount ?></strong>
            </span>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="get" action="<?= e(url('modules/interviews/index.php')) ?>" class="row g-2 align-items-end">
                <div class="col-md-3 col-sm-6">
                    <label for="search" class="form-label small fw-semibold text-secondary mb-1">Search Candidate / Vacancy</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-body-tertiary"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="search" name="search"
                               placeholder="Candidate name, code..." value="<?= e($filterSearch) ?>">
                    </div>
                </div>

                <?php if (count($companies) > 1 || is_group_admin()): ?>
                <div class="col-md-2 col-sm-6">
                    <label for="company_id" class="form-label small fw-semibold text-secondary mb-1">Company</label>
                    <select name="company_id" id="company_id" class="form-select form-select-sm">
                        <option value="">All Accessible</option>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= (int)$comp['id'] ?>" <?= $filterCompany === (int)$comp['id'] ? 'selected' : '' ?>>
                                <?= e($comp['code'] . ' - ' . $comp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-2 col-sm-4">
                    <label for="status" class="form-label small fw-semibold text-secondary mb-1">Status</label>
                    <select name="status" id="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($validStatuses as $stKey => $stLbl): ?>
                            <option value="<?= e($stKey) ?>" <?= $filterStatus === $stKey ? 'selected' : '' ?>>
                                <?= e($stLbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-4">
                    <label for="interviewer_id" class="form-label small fw-semibold text-secondary mb-1">Interviewer</label>
                    <select name="interviewer_id" id="interviewer_id" class="form-select form-select-sm">
                        <option value="">All Interviewers</option>
                        <?php foreach ($interviewers as $iv): ?>
                            <option value="<?= (int)$iv['id'] ?>" <?= $filterInterviewer === (int)$iv['id'] ? 'selected' : '' ?>>
                                <?= e($iv['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 col-sm-4">
                    <label class="form-label small fw-semibold text-secondary mb-1">Date Range</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>" placeholder="From">
                        <span class="input-group-text bg-body-tertiary">to</span>
                        <input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>" placeholder="To">
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <a href="<?= e(url('modules/interviews/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Clear
                    </a>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Interviews List Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-semibold">
                Scheduled Interviews <span class="badge bg-body-secondary text-body border ms-1"><?= $total ?></span>
            </h5>
            <small class="text-muted">Showing page <?= $pagination['page'] ?> of <?= max(1, $pagination['pages']) ?></small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($interviewsList)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-calendar-x fs-1 text-secondary opacity-50 d-block mb-3"></i>
                    <h5>No interviews found</h5>
                    <p class="small mb-3">No interview sessions match the current search filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Candidate</th>
                                <th>Vacancy &amp; Company</th>
                                <th>Round / Type</th>
                                <th>Date &amp; Time</th>
                                <th>Panel Members</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($interviewsList as $item): ?>
                                <?php
                                $typeLabels = interview_type_list();
                                $typeText = $typeLabels[$item['interview_type']] ?? ucfirst($item['interview_type']);
                                $isPast = strtotime((string)$item['scheduled_at']) < time();
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div>
                                            <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$item['candidate_id'])) ?>" class="fw-semibold text-body text-decoration-none">
                                                <?= e($item['first_name'] . ' ' . $item['last_name']) ?>
                                            </a>
                                        </div>
                                        <small class="text-muted"><?= e($item['candidate_code']) ?></small>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-body-secondary text-body border me-1"><?= e($item['company_code']) ?></span>
                                            <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$item['vacancy_id'])) ?>" class="text-decoration-none text-body">
                                                <?= e($item['vacancy_title']) ?>
                                            </a>
                                        </div>
                                        <small class="text-muted"><?= e($item['vacancy_code']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-medium">Round <?= (int)$item['round_no'] ?></div>
                                        <small class="text-muted"><?= e($typeText) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-body">
                                            <?= e(format_datetime($item['scheduled_at'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i><?= (int)$item['duration_min'] ?> mins
                                            <?php if (!empty($item['location_or_link'])): ?>
                                                &bull; <span class="text-truncate d-inline-block" style="max-width: 140px;" title="<?= e($item['location_or_link']) ?>">
                                                    <?= e($item['location_or_link']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="small" style="max-width: 220px;">
                                            <?= !empty($item['panel_names']) ? e($item['panel_names']) : '<span class="text-muted">—</span>' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?= interview_status_badge($item['status']) ?></div>
                                        <?php if ((int)$item['evaluation_count'] > 0): ?>
                                            <span class="badge bg-body-secondary text-body border mt-1" style="font-size:0.7rem;">
                                                <i class="bi bi-clipboard-check text-success me-1"></i><?= (int)$item['evaluation_count'] ?> evaluated
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$item['id'])) ?>"
                                               class="btn btn-outline-primary" title="View Interview Details">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <?php if (!empty($item['user_is_panel']) && empty($item['user_evaluated']) && $isPast && $item['status'] !== 'cancelled'): ?>
                                                <a href="<?= e(url('modules/interviews/evaluate.php?id=' . (int)$item['id'])) ?>"
                                                   class="btn btn-outline-success" title="Submit Evaluation">
                                                    <i class="bi bi-pencil-square me-1"></i>Evaluate
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination footer -->
                <?php if ($pagination['pages'] > 1): ?>
                    <div class="card-footer bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted">
                            Showing <?= min($total, $pagination['offset'] + 1) ?> to <?= min($total, $pagination['offset'] + $pagination['per_page']) ?> of <?= $total ?> interviews
                        </small>
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $queryParams = $_GET;
                                $buildPageUrl = function(int $p) use ($queryParams): string {
                                    $queryParams['page'] = $p;
                                    return url('modules/interviews/index.php?' . http_build_query($queryParams));
                                };
                                ?>
                                <li class="page-item <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e($buildPageUrl($pagination['page'] - 1)) ?>">Previous</a>
                                </li>
                                <?php for ($p = 1; $p <= $pagination['pages']; $p++): ?>
                                    <?php if ($p === 1 || $p === $pagination['pages'] || abs($p - $pagination['page']) <= 2): ?>
                                        <li class="page-item <?= $p === $pagination['page'] ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= e($buildPageUrl($p)) ?>"><?= $p ?></a>
                                        </li>
                                    <?php elseif ($p === 2 || $p === $pagination['pages'] - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <li class="page-item <?= $pagination['page'] >= $pagination['pages'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= e($buildPageUrl($pagination['page'] + 1)) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
