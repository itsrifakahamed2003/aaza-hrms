<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

// Initial check: must have performance.view, performance.manage, performance.view_own, or be a reporting manager
$canViewAll   = is_group_admin() || !empty(accessible_company_ids('performance.view'));
$canManageAll = is_group_admin() || !empty(accessible_company_ids('performance.manage'));
$isManager    = is_reporting_manager_of_any($pdo, $currentUserId);
$myEmp        = get_linked_employee_for_user($pdo, $currentUserId);
$hasViewOwn   = ($myEmp !== null) && (is_group_admin() || !empty(accessible_company_ids('performance.view_own')) || true);

if (!$canViewAll && !$canManageAll && !$isManager && !$hasViewOwn) {
    abort(403, 'You do not have permission to access the Performance module.');
}

// Accessible companies
$accessibleCompanies = is_group_admin()
    ? $pdo->query('SELECT id, name, code FROM companies ORDER BY name')->fetchAll()
    : $pdo->query('SELECT id, name, code FROM companies WHERE id IN (' . implode(',', accessible_company_ids() ?: [0]) . ') ORDER BY name')->fetchAll();

// Filter inputs
$filterCompanyId = (int)($_GET['company_id'] ?? 0);
$filterDeptId    = (int)($_GET['department_id'] ?? 0);
$filterPeriod    = trim((string)($_GET['period'] ?? ''));
$filterStatus    = trim((string)($_GET['status'] ?? ''));
$filterRating    = (int)($_GET['rating'] ?? 0);
$searchQ         = trim((string)($_GET['q'] ?? ''));

// Build scoping filter
$scopeFilter = build_performance_scope_filter($pdo, $currentUserId, $filterCompanyId > 0 ? $filterCompanyId : null);

// Base query parts
$where = [$scopeFilter['where_sql']];
$params = $scopeFilter['params'];

if ($filterDeptId > 0) {
    $where[] = 'e.department_id = ?';
    $params[] = $filterDeptId;
}
if ($filterPeriod !== '') {
    $where[] = 'pr.period_label = ?';
    $params[] = $filterPeriod;
}
if (in_array($filterStatus, ['draft', 'submitted', 'acknowledged'], true)) {
    $where[] = 'pr.status = ?';
    $params[] = $filterStatus;
}
if ($filterRating >= 1 && $filterRating <= 5) {
    $where[] = 'pr.overall_rating = ?';
    $params[] = $filterRating;
}
if ($searchQ !== '') {
    $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)';
    $escQ = '%' . $searchQ . '%';
    $params[] = $escQ;
    $params[] = $escQ;
    $params[] = $escQ;
}

$whereClause = implode(' AND ', $where);

// Count total
$countSql = "SELECT COUNT(*)
               FROM performance_reviews pr
               JOIN employees e ON e.id = pr.employee_id
               JOIN companies c ON c.id = pr.company_id
              WHERE {$whereClause}";
$cntSt = $pdo->prepare($countSql);
$cntSt->execute($params);
$totalRecords = (int)$cntSt->fetchColumn();

// Pagination
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Fetch rows
$fetchSql = "SELECT pr.*,
                    e.first_name, e.last_name, e.employee_code, e.photo_path,
                    d.name AS department_name, des.title AS designation_title,
                    c.name AS company_name, c.code AS company_code,
                    u.full_name AS reviewer_name,
                    (SELECT COUNT(*) FROM performance_goals pg WHERE pg.review_id = pr.id) AS goals_count,
                    (SELECT COUNT(*) FROM performance_goals pg WHERE pg.review_id = pr.id AND pg.status = 'achieved') AS goals_achieved
               FROM performance_reviews pr
               JOIN employees e ON e.id = pr.employee_id
               JOIN companies c ON c.id = pr.company_id
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN designations des ON des.id = e.designation_id
          LEFT JOIN users u ON u.id = pr.reviewer_user_id
              WHERE {$whereClause}
           ORDER BY pr.period_end DESC, pr.review_date DESC, pr.id DESC
              LIMIT {$perPage} OFFSET {$offset}";

$st = $pdo->prepare($fetchSql);
$st->execute($params);
$reviews = $st->fetchAll(PDO::FETCH_ASSOC);

// Fetch filter options: departments & periods
$scopedCids = $filterCompanyId > 0 ? [$filterCompanyId] : array_column($accessibleCompanies, 'id');
$deptSql = 'SELECT id, name FROM departments';
if (!empty($scopedCids)) {
    $deptSql .= ' WHERE company_id IN (' . implode(',', $scopedCids) . ')';
}
$deptSql .= ' ORDER BY name';
$departments = $pdo->query($deptSql)->fetchAll();

$periodSql = 'SELECT DISTINCT period_label FROM performance_reviews';
if (!empty($scopedCids)) {
    $periodSql .= ' WHERE company_id IN (' . implode(',', $scopedCids) . ')';
}
$periodSql .= ' ORDER BY period_end DESC LIMIT 20';
$periodLabels = $pdo->query($periodSql)->fetchAll(PDO::FETCH_COLUMN);

// Stat metrics
$statParams = $scopeFilter['params'];
$statSql = "SELECT
                COUNT(*) AS total_reviews,
                AVG(pr.overall_rating) AS avg_rating,
                SUM(CASE WHEN pr.status = 'draft' THEN 1 ELSE 0 END) AS count_draft,
                SUM(CASE WHEN pr.status = 'submitted' THEN 1 ELSE 0 END) AS count_submitted,
                SUM(CASE WHEN pr.status = 'acknowledged' THEN 1 ELSE 0 END) AS count_acknowledged
              FROM performance_reviews pr
              JOIN employees e ON e.id = pr.employee_id
             WHERE {$scopeFilter['where_sql']}";
$statSt = $pdo->prepare($statSql);
$statSt->execute($statParams);
$metrics = $statSt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_reviews'      => 0,
    'avg_rating'         => 0.0,
    'count_draft'        => 0,
    'count_submitted'    => 0,
    'count_acknowledged' => 0,
];

// Can user create a review?
$canCreateReview = is_group_admin() || !empty(accessible_company_ids('performance.manage')) || $isManager;

$pageTitle = 'Performance Management';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb breadcrumb-clean mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
            <li class="breadcrumb-item active" aria-current="page">Performance</li>
        </ol>
    </nav>
    <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="page-header-title-block">
            <h1 class="page-header-title mb-0">Employee Performance Reviews</h1>
            <p class="text-muted small mb-0 mt-1">Confidential performance appraisals, structured competency scorecards, and developmental goals.</p>
        </div>
        <div class="page-actions d-flex gap-2 align-items-center flex-wrap">
            <?php if (user_can('report.view') && ($canViewAll || $canManageAll)): ?>
                <a href="<?= e(url('modules/reports/performance_ratings.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-bar-chart me-1"></i>Performance Reports
                </a>
            <?php endif; ?>
            <?php if ($canCreateReview): ?>
                <a href="<?= e(url('modules/performance/form.php')) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>New Review
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stat Summary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-primary-subtle text-primary">
                    <i class="bi bi-award fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Total Reviews</div>
                    <div class="fs-4 fw-bold text-body"><?= (int)$metrics['total_reviews'] ?></div>
                    <div class="small text-muted">Evaluations logged</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-warning-subtle text-warning">
                    <i class="bi bi-star fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Average Rating</div>
                    <div class="fs-4 fw-bold text-body">
                        <?= !empty($metrics['avg_rating']) ? number_format((float)$metrics['avg_rating'], 2) . ' / 5.0' : 'N/A' ?>
                    </div>
                    <div class="small text-muted">
                        <?= !empty($metrics['avg_rating']) ? performance_rating_label((int)round((float)$metrics['avg_rating'])) : 'Across active scope' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-info-subtle text-info">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Awaiting Acknowledgment</div>
                    <div class="fs-4 fw-bold text-body"><?= (int)$metrics['count_submitted'] ?></div>
                    <div class="small text-muted">Submitted to employees</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle p-3 bg-success-subtle text-success">
                    <i class="bi bi-check2-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Acknowledged</div>
                    <div class="fs-4 fw-bold text-body"><?= (int)$metrics['count_acknowledged'] ?></div>
                    <div class="small text-muted"><?= (int)$metrics['count_draft'] ?> drafts in progress</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="get" action="<?= e(url('modules/performance/index.php')) ?>" class="row g-2 align-items-end">
            <?php if (count($accessibleCompanies) > 1): ?>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">Company</label>
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All Companies</option>
                        <?php foreach ($accessibleCompanies as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $filterCompanyId === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Department</label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $filterDeptId === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Period</label>
                <select name="period" class="form-select form-select-sm">
                    <option value="">All Periods</option>
                    <?php foreach ($periodLabels as $p): ?>
                        <option value="<?= e($p) ?>" <?= $filterPeriod === $p ? 'selected' : '' ?>>
                            <?= e($p) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="acknowledged" <?= $filterStatus === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-1">
                <label class="form-label small fw-semibold text-secondary mb-1">Rating</label>
                <select name="rating" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?= $r ?>" <?= $filterRating === $r ? 'selected' : '' ?>>
                            <?= $r ?> Stars
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Search Employee</label>
                <input type="text" name="q" value="<?= e($searchQ) ?>" class="form-control form-control-sm" placeholder="Name or code...">
            </div>

            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="<?= e(url('modules/performance/index.php')) ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Reviews Table Card -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
                <tr>
                    <th>Employee</th>
                    <th>Period</th>
                    <th>Overall Rating</th>
                    <th>Goals</th>
                    <th>Reviewer &amp; Date</th>
                    <th>Status</th>
                    <th class="text-end pe-3 action-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviews)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            <div class="fw-semibold">No performance reviews found.</div>
                            <div class="small">Try adjusting your filter criteria or submit a new performance review.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reviews as $rev): ?>
                        <?php
                        $canEditThis = can_user_edit_performance_review($pdo, $currentUserId, $rev);
                        $canAckThis  = can_user_acknowledge_performance_review($pdo, $currentUserId, $rev);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-circle-sm bg-primary-subtle text-primary fw-bold text-center" style="width:36px; height:36px; line-height:36px; border-radius:50%;">
                                        <?= e(strtoupper(substr($rev['first_name'], 0, 1) . substr($rev['last_name'], 0, 1))) ?>
                                    </div>
                                    <div>
                                        <a href="<?= e(url('modules/performance/view.php?id=' . (int)$rev['id'])) ?>" class="fw-semibold text-body text-decoration-none">
                                            <?= e($rev['first_name'] . ' ' . $rev['last_name']) ?>
                                        </a>
                                        <div class="small text-muted font-monospace">
                                            <?= e($rev['employee_code']) ?> &bull; <?= e($rev['department_name'] ?? 'General') ?>
                                            <span class="badge bg-body-secondary text-body border ms-1"><?= e($rev['company_code']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-medium text-body"><?= e($rev['period_label']) ?></div>
                                <div class="small text-muted">
                                    <?= date('d M Y', strtotime($rev['period_start'])) ?> &ndash; <?= date('d M Y', strtotime($rev['period_end'])) ?>
                                </div>
                            </td>
                            <td>
                                <?= performance_rating_stars_html((int)$rev['overall_rating']) ?>
                            </td>
                            <td>
                                <?php if ((int)$rev['goals_count'] > 0): ?>
                                    <span class="badge bg-body-secondary text-body border">
                                        <?= (int)$rev['goals_achieved'] ?> / <?= (int)$rev['goals_count'] ?> Achieved
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small text-body fw-medium"><?= e($rev['reviewer_name'] ?? 'System') ?></div>
                                <div class="small text-muted"><?= date('d M Y', strtotime($rev['review_date'])) ?></div>
                            </td>
                            <td>
                                <?= performance_status_badge((string)$rev['status']) ?>
                            </td>
                            <td class="text-end text-nowrap pe-3 action-col">
                                <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                    <a href="<?= e(url('modules/performance/view.php?id=' . (int)$rev['id'])) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5" title="View review details">
                                        <i class="bi bi-eye me-1"></i>View
                                    </a>
                                    <?= action_menu([
                                        [
                                            'type'       => 'link',
                                            'label'      => 'Employee Profile',
                                            'url'        => 'modules/employees/view.php?id=' . (int)$rev['employee_id'],
                                            'icon'       => 'bi-person',
                                            'permission' => user_can('employee.view'),
                                        ],
                                        [
                                            'type'       => 'link',
                                            'label'      => 'Edit Review',
                                            'url'        => 'modules/performance/form.php?id=' . (int)$rev['id'],
                                            'icon'       => 'bi-pencil',
                                            'permission' => (bool)$canEditThis,
                                        ],
                                        [
                                            'type'       => 'link',
                                            'label'      => 'Acknowledge Review',
                                            'url'        => 'modules/performance/view.php?id=' . (int)$rev['id'],
                                            'icon'       => 'bi-check-circle',
                                            'permission' => (bool)$canAckThis,
                                        ],
                                    ]) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted">
                Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> reviews
            </div>
            <nav aria-label="Performance pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(url('modules/performance/index.php?' . http_build_query(array_merge($_GET, ['page' => $page - 1])))) ?>">
                            Previous
                        </a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('modules/performance/index.php?' . http_build_query(array_merge($_GET, ['page' => $p])))) ?>">
                                <?= $p ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(url('modules/performance/index.php?' . http_build_query(array_merge($_GET, ['page' => $page + 1])))) ?>">
                            Next
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';

