<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Authorization: Requires leave.view OR leave.approve
if (!user_can('leave.view') && !user_can('leave.approve')) {
    abort(403, 'You do not have permission to view leave requests.');
}

$pdo = db();
$allAccessible = accessible_company_ids();
$authorizedCompanies = [];
foreach ($allAccessible as $cid) {
    if (user_can('leave.view', $cid) || user_can('leave.approve', $cid)) {
        $authorizedCompanies[] = $cid;
    }
}

if (empty($authorizedCompanies)) {
    abort(403, 'You do not have access to leave management for any company.');
}

// Fetch company list for filters
$phComp = implode(',', array_fill(0, count($authorizedCompanies), '?'));
$compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($phComp) ORDER BY name");
$compSt->execute($authorizedCompanies);
$companies = $compSt->fetchAll();

// Filter inputs
$filterCompany = (int)($_GET['company_id'] ?? 0);
if ($filterCompany > 0 && !in_array($filterCompany, $authorizedCompanies, true)) {
    $filterCompany = 0;
}

$filterStatus  = trim((string)($_GET['status'] ?? ''));
$filterType    = (int)($_GET['type_id'] ?? 0);
$rawDateFrom    = trim((string)($_GET['date_from'] ?? ''));
$rawDateTo      = trim((string)($_GET['date_to'] ?? ''));
$filterDateFrom = parse_ymd($rawDateFrom) ?? '';
$filterDateTo   = parse_ymd($rawDateTo) ?? '';
$searchQuery    = trim((string)($_GET['q'] ?? ''));

// Fetch leave types for filter
$ltSql = "SELECT id, name FROM leave_types WHERE is_active = 1";
if ($filterCompany > 0) {
    $ltSql .= " AND (company_id IS NULL OR company_id = ?)";
    $ltSt = $pdo->prepare($ltSql);
    $ltSt->execute([$filterCompany]);
} else {
    $ltSt = $pdo->query($ltSql);
}
$availableTypes = $ltSt->fetchAll();

// Build Query
$where = [];
$params = [];

// Company scope
if ($filterCompany > 0) {
    $where[] = "lr.company_id = ?";
    $params[] = $filterCompany;
} else {
    $ph = implode(',', array_fill(0, count($authorizedCompanies), '?'));
    $where[] = "lr.company_id IN ($ph)";
    $params = array_merge($params, $authorizedCompanies);
}

// Status filter
$validStatuses = leave_status_list();
if ($filterStatus !== '' && isset($validStatuses[$filterStatus])) {
    $where[] = "lr.status = ?";
    $params[] = $filterStatus;
}

// Type filter
if ($filterType > 0) {
    $where[] = "lr.leave_type_id = ?";
    $params[] = $filterType;
}

// Date filters (Strict Y-m-d)
if ($filterDateFrom !== '') {
    $where[] = "lr.start_date >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[] = "lr.end_date <= ?";
    $params[] = $filterDateTo;
}

// Keyword Search
if ($searchQuery !== '') {
    $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR lr.reason LIKE ?)";
    $like = "%$searchQuery%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

// Count for pagination
$countSql = "SELECT COUNT(*)
               FROM leave_requests lr
               JOIN employees e ON e.id = lr.employee_id
              WHERE $whereSql";
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRecords = (int)$countSt->fetchColumn();

// Pagination
$perPage = PER_PAGE;
$page = max(1, (int)($_GET['page'] ?? 1));
$pagination = paginate($totalRecords, $page, $perPage);
$offset = (int)$pagination['offset'];

// Fetch Records
$dataSql = "SELECT lr.*, lt.name AS leave_type_name,
                   e.first_name, e.last_name, e.employee_code, e.department_id,
                   c.name AS company_name, c.code AS company_code,
                   d.name AS department_name,
                   u.full_name AS approver_name
              FROM leave_requests lr
              JOIN employees e ON e.id = lr.employee_id
              JOIN companies c ON c.id = lr.company_id
              JOIN leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN users u ON u.id = lr.decided_by
             WHERE $whereSql
             ORDER BY (lr.status = 'pending') DESC, lr.requested_at DESC, lr.id DESC
             LIMIT $perPage OFFSET $offset";
$dataSt = $pdo->prepare($dataSql);
$dataSt->execute($params);
$requests = $dataSt->fetchAll();

// KPI stats for quick cards
$pendingCount = count_pending_leaves($pdo, $filterCompany > 0 ? $filterCompany : null);

$pageTitle = 'Leave Management';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Top Bar -->
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Leave Management</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h1 class="page-header-title h3 mb-0">Leave Applications</h1>
                <?php if ($pendingCount > 0): ?>
                    <span class="badge text-bg-warning px-3 py-2">
                        <i class="bi bi-clock-history me-1"></i> <?= $pendingCount ?> Pending Review
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="page-actions">
            <?php if (user_can('leave.manage_types')): ?>
                <a href="<?= e(url('modules/leave/types.php')) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-tags me-1"></i> Policy &amp; Types
                </a>
            <?php endif; ?>
            <a href="<?= e(url('modules/leave/reports.php')) ?>" class="btn btn-outline-primary">
                <i class="bi bi-bar-chart me-1"></i> Leave Reports
            </a>
            <?php if (user_can('leave.request') && !empty($navHasLinkedEmp)): ?>
                <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Apply for Leave
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="get" action="<?= e(url('modules/leave/index.php')) ?>" class="row g-2 align-items-end">
                <?php if (count($companies) > 1): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Company</label>
                        <select name="company_id" class="form-select form-select-sm">
                            <option value="">All My Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $filterCompany === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($validStatuses as $sVal => $sLbl): ?>
                            <option value="<?= e($sVal) ?>" <?= $filterStatus === $sVal ? 'selected' : '' ?>>
                                <?= e($sLbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Leave Type</label>
                    <select name="leave_type_id" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <?php foreach ($availableTypes as $at): ?>
                            <option value="<?= (int)$at['id'] ?>" <?= $filterType === (int)$at['id'] ? 'selected' : '' ?>>
                                <?= e($at['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filterDateFrom) ?>">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filterDateTo) ?>">
                </div>

                <div class="col-md-2 col-sm-12">
                    <label class="form-label small fw-semibold text-secondary mb-1">Search Staff / Reason</label>
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, code, reason..." value="<?= e($searchQuery) ?>">
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <a href="<?= e(url('modules/leave/index.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fs-6 fw-bold">Leave Applications (<?= number_format($totalRecords) ?>)</h5>
                <small class="text-muted d-block mt-0.5">
                    <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any row to view full leave details.
                </small>
            </div>
            <small class="text-muted">Showing page <?= $page ?> of <?= max(1, (int)$pagination['pages']) ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Employee</th>
                            <th>Company / Dept</th>
                            <th>Type</th>
                            <th>Dates &amp; Period</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="text-end pe-3 action-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    No leave requests found matching the current filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $r): ?>
                                <?php
                                $canApproveThis = user_can('leave.approve', (int)$r['company_id']);
                                $status = (string)$r['status'];
                                $leaveViewUrl = url('modules/leave/view.php?id=' . (int)$r['id']);
                                ?>
                                <tr class="clickable-row leave-row" data-href="<?= e($leaveViewUrl) ?>" title="Double-click to view leave application #<?= (int)$r['id'] ?>">
                                    <td class="ps-3">
                                        <a href="<?= e($leaveViewUrl) ?>" class="fw-semibold text-decoration-none text-body">
                                            <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                                        </a>
                                        <div class="text-muted small font-monospace"><?= e($r['employee_code']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body border"><?= e($r['company_code']) ?></span>
                                        <div class="text-muted small"><?= e($r['department_name'] ?? 'General') ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-medium text-body"><?= e($r['leave_type_name']) ?></span>
                                        <?php if ((int)$r['half_day'] === 1): ?>
                                            <span class="badge badge-soft-warning ms-1">Half Day</span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['document_path'])): ?>
                                            <a href="<?= e(url('modules/leave/download.php?id=' . (int)$r['id'])) ?>" class="text-primary d-block small" title="Supporting Document">
                                                <i class="bi bi-paperclip"></i> Attachment
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-body">
                                            <?= e(format_date($r['start_date'])) ?>
                                            <?php if ($r['start_date'] !== $r['end_date']): ?>
                                                <i class="bi bi-arrow-right mx-1 text-muted"></i> <?= e(format_date($r['end_date'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($r['reason'])): ?>
                                            <div class="text-muted small text-truncate" style="max-width: 200px;" title="<?= e($r['reason']) ?>">
                                                <?= e($r['reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body border font-monospace fw-bold fs-6">
                                            <?= e(number_format((float)$r['total_days'], 1)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= leave_status_badge($status) ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted"><?= e(format_date($r['requested_at'])) ?></div>
                                    </td>
                                    <td class="text-end pe-3 action-col">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="<?= e($leaveViewUrl) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5" title="View leave application details">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <?php if ($status === 'pending' && $canApproveThis): ?>
                                                <a href="<?= e($leaveViewUrl) ?>" class="btn btn-sm btn-primary py-1 px-2 text-nowrap" title="Review and decide on request">
                                                    <i class="bi bi-check2-circle me-1"></i>Review
                                                </a>
                                            <?php endif; ?>
                                            <?= action_menu([
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Employee Profile',
                                                    'url'        => 'modules/employees/view.php?id=' . (int)$r['employee_id'],
                                                    'icon'       => 'bi-person',
                                                    'permission' => user_can('employee.view'),
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

            <!-- Pagination Bar -->
            <?php if ((int)$pagination['pages'] > 1): ?>
                <div class="card-footer bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-muted small">
                        Showing <?= $offset + 1 ?> to <?= min($totalRecords, $offset + $perPage) ?> of <?= number_format($totalRecords) ?> applications
                    </span>
                    <nav aria-label="Leave pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $queryParams = $_GET;
                            $curP = (int)$pagination['page'];
                            $totalP = (int)$pagination['pages'];
                            ?>
                            <li class="page-item <?= $curP <= 1 ? 'disabled' : '' ?>">
                                <?php $queryParams['page'] = $curP - 1; ?>
                                <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Previous</a>
                            </li>
                            <?php for ($p = max(1, $curP - 2); $p <= min($totalP, $curP + 2); $p++): ?>
                                <?php $queryParams['page'] = $p; ?>
                                <li class="page-item <?= $p === $curP ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $curP >= $totalP ? 'disabled' : '' ?>">
                                <?php $queryParams['page'] = $curP + 1; ?>
                                <a class="page-link" href="?<?= http_build_query($queryParams) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
