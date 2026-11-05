<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Employee not found.');
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT e.*, c.name AS company_name, c.code AS company_code,
            d.name AS department_name, des.title AS designation_title,
            m.first_name AS manager_first, m.last_name AS manager_last, m.employee_code AS manager_code,
            u.username AS login_username, creator.full_name AS created_by_name
       FROM employees e
       JOIN companies c ON c.id = e.company_id
       LEFT JOIN departments d ON d.id = e.department_id
       LEFT JOIN designations des ON des.id = e.designation_id
       LEFT JOIN employees m ON m.id = e.reporting_manager_id
       LEFT JOIN users u ON u.id = e.user_id
       LEFT JOIN users creator ON creator.id = e.created_by
      WHERE e.id = ?
      LIMIT 1'
);
$st->execute([$id]);
$emp = $st->fetch();

if (!$emp) {
    abort(404, 'Employee not found.');
}

$companyId = (int)$emp['company_id'];
require_company_access($companyId, 'employee.view');

// Permissions
$canEdit          = user_can('employee.edit', $companyId);
$canTransfer      = user_can('employee.transfer', $companyId);
$canSalary        = user_can('salary.view', $companyId);
$canViewDocs      = user_can('document.view', $companyId);
$canUploadDocs    = user_can('document.upload', $companyId);
$canConfidential  = user_can('document.confidential', $companyId);

// 1. Documents query (gated by document.view and document.confidential)
$documents = [];
if ($canViewDocs) {
    $docSql = 'SELECT ed.*, u.full_name AS uploaded_by_name
                 FROM employee_documents ed
                 LEFT JOIN users u ON u.id = ed.uploaded_by
                WHERE ed.employee_id = ? '
            . ($canConfidential ? '' : 'AND ed.is_confidential = 0 ')
            . 'ORDER BY ed.uploaded_at DESC';
    $stDocs = $pdo->prepare($docSql);
    $stDocs->execute([$id]);
    $documents = $stDocs->fetchAll();
}

// 2. Employment History query
$stHist = $pdo->prepare(
    'SELECT eh.*, c.name AS company_name, c.code AS company_code,
            d.name AS department_name, des.title AS designation_title,
            u.full_name AS recorded_by_name
       FROM employment_history eh
       JOIN companies c ON c.id = eh.company_id
       LEFT JOIN departments d ON d.id = eh.department_id
       LEFT JOIN designations des ON des.id = eh.designation_id
       LEFT JOIN users u ON u.id = eh.recorded_by
      WHERE eh.employee_id = ?
      ORDER BY eh.start_date DESC, eh.id DESC'
);
$stHist->execute([$id]);
$employmentHistory = $stHist->fetchAll();

// 3. Transfer History query
$stTrans = $pdo->prepare(
    'SELECT et.*,
            fc.name AS from_company_name, fc.code AS from_company_code,
            tc.name AS to_company_name, tc.code AS to_company_code,
            fd.name AS from_dept_name, td.name AS to_dept_name,
            fdes.title AS from_desig_title, tdes.title AS to_desig_title,
            req.full_name AS requested_by_name, app.full_name AS approved_by_name
       FROM employee_transfers et
       JOIN companies fc ON fc.id = et.from_company_id
       JOIN companies tc ON tc.id = et.to_company_id
       LEFT JOIN departments fd ON fd.id = et.from_department_id
       LEFT JOIN departments td ON td.id = et.to_department_id
       LEFT JOIN designations fdes ON fdes.id = et.from_designation_id
       LEFT JOIN designations tdes ON tdes.id = et.to_designation_id
       LEFT JOIN users req ON req.id = et.requested_by
       LEFT JOIN users app ON app.id = et.approved_by
      WHERE et.employee_id = ?
      ORDER BY et.transfer_date DESC, et.id DESC'
);
$stTrans->execute([$id]);
$transferHistory = $stTrans->fetchAll();

$docTypeLabels = employee_doc_type_list();
$typeLabels    = employment_type_list();
$statusLabels  = employee_status_list();

// Performance query & tab authorization
$canViewPerformance = is_group_admin()
    || user_can('performance.view', $companyId)
    || user_can('performance.manage', $companyId)
    || can_user_manage_employee_performance($pdo, current_user_id(), $id, $companyId)
    || (!empty($emp['user_id']) && (int)$emp['user_id'] === current_user_id());

$canManagePerfForEmp = can_user_manage_employee_performance($pdo, current_user_id(), $id, $companyId);

$employeeReviews = [];
$employeeGoals = [];
if ($canViewPerformance) {
    $isSelfOnly = (!is_group_admin()
        && !user_can('performance.view', $companyId)
        && !user_can('performance.manage', $companyId)
        && !can_user_manage_employee_performance($pdo, current_user_id(), $id, $companyId)
        && (!empty($emp['user_id']) && (int)$emp['user_id'] === current_user_id()));

    $revSql = 'SELECT pr.*, u.full_name AS reviewer_name,
                      (SELECT COUNT(*) FROM performance_goals pg WHERE pg.review_id = pr.id) AS goals_count,
                      (SELECT COUNT(*) FROM performance_goals pg WHERE pg.review_id = pr.id AND pg.status = "achieved") AS goals_achieved
                 FROM performance_reviews pr
            LEFT JOIN users u ON u.id = pr.reviewer_user_id
                WHERE pr.employee_id = ?';
    if ($isSelfOnly) {
        $revSql .= ' AND pr.status IN ("submitted", "acknowledged")';
    }
    $revSql .= ' ORDER BY pr.period_end DESC, pr.review_date DESC';
    $stPerf = $pdo->prepare($revSql);
    $stPerf->execute([$id]);
    $employeeReviews = $stPerf->fetchAll(PDO::FETCH_ASSOC);

    $stGoals = $pdo->prepare('SELECT * FROM performance_goals WHERE employee_id = ? ORDER BY target_date DESC, id DESC');
    $stGoals->execute([$id]);
    $employeeGoals = $stGoals->fetchAll(PDO::FETCH_ASSOC);
}

// Active tab handling
$allowedTabs = ['details', 'documents', 'history', 'transfers'];
if ($canViewPerformance) {
    $allowedTabs[] = 'performance';
}
$currentTab = (string)($_GET['tab'] ?? 'details');
if (!in_array($currentTab, $allowedTabs, true)) {
    $currentTab = 'details';
}

$pageTitle = $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_code'] . ')';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('modules/employees/index.php')) ?>">Employees</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($emp['employee_code']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/employees/form.php?id=' . $id)) ?>">
                Edit Details
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#statusModal">
                Change Status
            </button>
        <?php endif; ?>
        <?php if ($canTransfer && $emp['employment_status'] === 'active'): ?>
            <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/employees/transfer.php?id=' . $id)) ?>">
                Transfer Company
            </a>
        <?php endif; ?>
        <?php if ($canUploadDocs): ?>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                Upload Document
            </button>
        <?php endif; ?>
        <?php if (is_group_admin() && user_can('employee.delete')): ?>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDeleteEmployee(<?= $id ?>, '<?= e(addslashes($emp['first_name'] . ' ' . $emp['last_name'])) ?>')">
                <i class="bi bi-trash me-1"></i> Delete
            </button>
            <form method="post" action="<?= e(url('modules/employees/delete.php')) ?>" id="deleteEmployeeForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
            </form>
            <script>
            function confirmDeleteEmployee(id, name) {
                if (typeof showConfirmModal === 'function') {
                    showConfirmModal({
                        title: 'Delete Employee Record',
                        message: 'Are you sure you want to permanently delete employee "' + name + '"? Only terminated, resigned, or retired employees who are not active supervisors or managers can be deleted. This action cannot be undone.',
                        confirmText: 'Delete Employee',
                        variant: 'danger',
                        onConfirm: function() {
                            document.getElementById('deleteEmployeeForm').submit();
                        }
                    });
                } else if (confirm('Are you sure you want to permanently delete employee "' + name + '"?')) {
                    document.getElementById('deleteEmployeeForm').submit();
                }
            }
            </script>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Header Card -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary-subtle text-primary fw-bold rounded-circle d-flex align-items-center justify-content-center"
                     style="width: 58px; height: 58px; font-size: 1.4rem;">
                    <?= e(strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1))) ?>
                </div>
                <div>
                    <h1 class="h4 mb-1"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></h1>
                    <div class="d-flex flex-wrap align-items-center gap-2 text-muted small">
                        <span class="badge text-bg-dark font-monospace"><?= e($emp['employee_code']) ?></span>
                        <span class="badge bg-body-secondary text-body border"><?= e($emp['company_code']) ?> - <?= e($emp['company_name']) ?></span>
                        <?php if (!empty($emp['department_name'])): ?>
                            <span>&bull; <?= e($emp['department_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($emp['designation_title'])): ?>
                            <span>&bull; <?= e($emp['designation_title']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="text-end">
                <div class="mb-1"><?= employee_status_badge((string)$emp['employment_status']) ?></div>
                <div class="small text-muted">
                    Joined <?= e(date('M d, Y', strtotime((string)$emp['joining_date']))) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" id="employeeTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link<?= $currentTab === 'details' ? ' active' : '' ?>"
           href="<?= e(url('modules/employees/view.php?id=' . $id . '&tab=details')) ?>">
            Employee Details
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link<?= $currentTab === 'documents' ? ' active' : '' ?>"
           href="<?= e(url('modules/employees/view.php?id=' . $id . '&tab=documents')) ?>">
            Documents (<?= count($documents) ?>)
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link<?= $currentTab === 'history' ? ' active' : '' ?>"
           href="<?= e(url('modules/employees/view.php?id=' . $id . '&tab=history')) ?>">
            Employment History (<?= count($employmentHistory) ?>)
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link<?= $currentTab === 'transfers' ? ' active' : '' ?>"
           href="<?= e(url('modules/employees/view.php?id=' . $id . '&tab=transfers')) ?>">
            Transfer History (<?= count($transferHistory) ?>)
        </a>
    </li>
    <?php if ($canViewPerformance): ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link<?= $currentTab === 'performance' ? ' active' : '' ?>"
               href="<?= e(url('modules/employees/view.php?id=' . $id . '&tab=performance')) ?>">
                Performance (<?= count($employeeReviews) ?>)
            </a>
        </li>
    <?php endif; ?>
</ul>

<!-- Tab 1: Profile & Job Details -->
<?php if ($currentTab === 'details'): ?>
    <div class="row g-4">
        <div class="col-12 col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body fw-semibold">Personal Information</div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted ps-0" style="width:40%">Full Name:</th>
                            <td class="fw-semibold"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Email:</th>
                            <td>
                                <?php if (!empty($emp['email'])): ?>
                                    <a href="mailto:<?= e($emp['email']) ?>"><?= e($emp['email']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">Not specified</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Phone:</th>
                            <td><?= e($emp['phone'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">NIC / Passport:</th>
                            <td><?= e($emp['nic_passport'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Date of Birth:</th>
                            <td>
                                <?= !empty($emp['dob']) ? e(date('M d, Y', strtotime((string)$emp['dob']))) : '—' ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Gender:</th>
                            <td><?= e(ucfirst($emp['gender'] ?? '—')) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Residential Address:</th>
                            <td><?= nl2br(e($emp['address'] ?? '—')) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-body fw-semibold">Employment &amp; Organization</div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted ps-0" style="width:40%">Company:</th>
                            <td><span class="badge bg-body-secondary text-body border"><?= e($emp['company_code']) ?></span> <?= e($emp['company_name']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Department:</th>
                            <td><?= e($emp['department_name'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Designation:</th>
                            <td><?= e($emp['designation_title'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Reporting Manager:</th>
                            <td>
                                <?php if (!empty($emp['reporting_manager_id'])): ?>
                                    <a href="<?= e(url('modules/employees/view.php?id=' . (int)$emp['reporting_manager_id'])) ?>">
                                        <?= e($emp['manager_first'] . ' ' . $emp['manager_last']) ?>
                                    </a>
                                    <span class="text-muted small">(<?= e($emp['manager_code']) ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Employment Type:</th>
                            <td><?= e($typeLabels[$emp['employment_type']] ?? $emp['employment_type']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Joining Date:</th>
                            <td><?= e(date('M d, Y', strtotime((string)$emp['joining_date']))) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Employment Status:</th>
                            <td><?= employee_status_badge((string)$emp['employment_status']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted ps-0">Basic Salary:</th>
                            <td>
                                <?php if ($canSalary): ?>
                                    <?php if ($emp['basic_salary'] !== null): ?>
                                        <span class="font-monospace fw-bold fs-6">$<?= e(number_format((float)$emp['basic_salary'], 2)) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not configured</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary text-opacity-75" title="Requires salary.view permission">
                                        Restricted (salary.view required)
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Tab 2: Documents -->
<?php if ($currentTab === 'documents'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-body d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Employee Documents</span>
            <?php if ($canUploadDocs): ?>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                    &plus; Upload Document
                </button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-active">
                <tr>
                    <th>Type</th>
                    <th>Document Title</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Expiry Date</th>
                    <th>Security</th>
                    <th>Uploaded By</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc): ?>
                    <?php
                    $isExpired = !empty($doc['expiry_date']) && strtotime((string)$doc['expiry_date']) < time();
                    ?>
                    <tr>
                        <td>
                            <span class="badge bg-body-secondary text-body border">
                                <?= e($docTypeLabels[$doc['doc_type']] ?? $doc['doc_type']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e($doc['title'] ?? $doc['original_name']) ?></div>
                        </td>
                        <td>
                            <span class="small font-monospace text-muted"><?= e($doc['original_name']) ?></span>
                        </td>
                        <td>
                            <span class="small text-muted">
                                <?= number_format($doc['file_size'] / 1024, 1) ?> KB
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($doc['expiry_date'])): ?>
                                <span class="<?= $isExpired ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    <?= e(date('M d, Y', strtotime((string)$doc['expiry_date']))) ?>
                                </span>
                                <?php if ($isExpired): ?>
                                    <span class="badge text-bg-danger ms-1">Expired</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($doc['is_confidential'])): ?>
                                <span class="badge text-bg-warning text-body">Confidential</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary text-opacity-50">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small"><?= e($doc['uploaded_by_name'] ?? 'System') ?></div>
                            <div class="text-muted" style="font-size:0.75rem">
                                <?= e(date('M d, Y H:i', strtotime((string)$doc['uploaded_at']))) ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('modules/employees/download.php?id=' . (int)$doc['id'])) ?>"
                               title="Download securely">
                                Download
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$documents): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <div class="mb-2">No documents stored for this employee yet.</div>
                            <?php if ($canUploadDocs): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                                    Upload First Document
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Tab 3: Employment History -->
<?php if ($currentTab === 'history'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-body d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Employment History &amp; Career Progression</span>
            <span class="badge bg-body-secondary text-body border text-muted">Immutable Audit Record</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-active">
                <tr>
                    <th>Period</th>
                    <th>Change Event</th>
                    <th>Company</th>
                    <th>Department &amp; Designation</th>
                    <th>Type</th>
                    <th>Remarks</th>
                    <th>Recorded By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($employmentHistory as $h): ?>
                    <?php
                    $changeBadges = [
                        'joined'            => 'badge text-bg-success',
                        'promotion'         => 'badge text-bg-primary',
                        'transfer_in'       => 'badge text-bg-info text-body',
                        'transfer_out'      => 'badge text-bg-secondary',
                        'department_change' => 'badge text-bg-secondary',
                        'status_change'     => 'badge text-bg-warning text-body',
                        'termination'       => 'badge text-bg-danger',
                        'resignation'       => 'badge text-bg-dark',
                    ];
                    $badgeCls = $changeBadges[$h['change_type']] ?? 'badge bg-body-secondary text-body';
                    ?>
                    <tr>
                        <td class="text-nowrap">
                            <div class="fw-medium"><?= e(date('M d, Y', strtotime((string)$h['start_date']))) ?></div>
                            <div class="small text-muted">
                                <?= !empty($h['end_date']) ? 'to ' . e(date('M d, Y', strtotime((string)$h['end_date']))) : 'to Present' ?>
                            </div>
                        </td>
                        <td>
                            <span class="<?= e($badgeCls) ?>"><?= e(ucwords(str_replace('_', ' ', $h['change_type']))) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-body-secondary text-body border"><?= e($h['company_code']) ?></span>
                            <span class="small d-block text-muted"><?= e($h['company_name']) ?></span>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e($h['department_name'] ?? '—') ?></div>
                            <div class="small text-muted"><?= e($h['designation_title'] ?? '—') ?></div>
                        </td>
                        <td>
                            <span class="small"><?= e($typeLabels[$h['employment_type']] ?? ($h['employment_type'] ?? '—')) ?></span>
                        </td>
                        <td>
                            <span class="small"><?= e($h['remarks'] ?? '—') ?></span>
                        </td>
                        <td>
                            <div class="small"><?= e($h['recorded_by_name'] ?? 'System') ?></div>
                            <div class="text-muted" style="font-size:0.75rem">
                                <?= e(date('M d, Y H:i', strtotime((string)$h['created_at']))) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$employmentHistory): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            No employment history recorded.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Tab 4: Transfer History -->
<?php if ($currentTab === 'transfers'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-body d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Inter-Company Transfer Records</span>
            <?php if ($canTransfer && $emp['employment_status'] === 'active'): ?>
                <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/employees/transfer.php?id=' . $id)) ?>">
                    New Transfer
                </a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-active">
                <tr>
                    <th>Transfer Date</th>
                    <th>From Company</th>
                    <th>To Company</th>
                    <th>Department / Designation</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Authorized By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($transferHistory as $t): ?>
                    <tr>
                        <td class="text-nowrap fw-medium">
                            <?= e(date('M d, Y', strtotime((string)$t['transfer_date']))) ?>
                        </td>
                        <td>
                            <span class="badge bg-body-secondary text-body border"><?= e($t['from_company_code']) ?></span>
                            <div class="small text-muted"><?= e($t['from_company_name']) ?></div>
                            <?php if (!empty($t['from_dept_name']) || !empty($t['from_desig_title'])): ?>
                                <div class="text-muted" style="font-size:0.75rem">
                                    <?= e($t['from_dept_name'] ?? '') ?> &bull; <?= e($t['from_desig_title'] ?? '') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-primary"><?= e($t['to_company_code']) ?></span>
                            <div class="small fw-semibold"><?= e($t['to_company_name']) ?></div>
                            <?php if (!empty($t['to_dept_name']) || !empty($t['to_desig_title'])): ?>
                                <div class="text-muted" style="font-size:0.75rem">
                                    <?= e($t['to_dept_name'] ?? '') ?> &bull; <?= e($t['to_desig_title'] ?? '') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small">
                                <span class="text-muted">Target Dept:</span> <?= e($t['to_dept_name'] ?? 'Unassigned') ?><br>
                                <span class="text-muted">Target Desig:</span> <?= e($t['to_desig_title'] ?? 'Unassigned') ?>
                            </div>
                        </td>
                        <td>
                            <span class="small"><?= e($t['reason'] ?? '—') ?></span>
                        </td>
                        <td>
                            <span class="badge text-bg-success"><?= e(ucfirst($t['status'])) ?></span>
                        </td>
                        <td>
                            <div class="small"><?= e($t['approved_by_name'] ?? ($t['requested_by_name'] ?? 'System')) ?></div>
                            <div class="text-muted" style="font-size:0.75rem">
                                <?= e(date('M d, Y H:i', strtotime((string)$t['created_at']))) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transferHistory): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            No company transfers have been recorded for this employee.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Tab 5: Performance & Appraisals -->
<?php if ($currentTab === 'performance' && $canViewPerformance): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold">
                <i class="bi bi-award text-primary me-2"></i>Performance Appraisals &amp; Review History
            </span>
            <?php if ($canManagePerfForEmp && $emp['employment_status'] === 'active'): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(url('modules/performance/form.php?employee_id=' . $id)) ?>">
                    <i class="bi bi-plus-lg me-1"></i>New Review
                </a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-active">
                    <tr>
                        <th>Period</th>
                        <th>Overall Rating</th>
                        <th>Goals Achieved</th>
                        <th>Review Date</th>
                        <th>Reviewer</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employeeReviews)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-award fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <div class="fw-semibold">No performance reviews recorded for this employee.</div>
                                <?php if ($canManagePerfForEmp && $emp['employment_status'] === 'active'): ?>
                                    <div class="small mt-1">
                                        <a href="<?= e(url('modules/performance/form.php?employee_id=' . $id)) ?>" class="btn btn-sm btn-outline-primary mt-2">
                                            Create First Appraisal &rarr;
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employeeReviews as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-body"><?= e($r['period_label']) ?></div>
                                    <div class="small text-muted">
                                        <?= date('d M Y', strtotime($r['period_start'])) ?> &ndash; <?= date('d M Y', strtotime($r['period_end'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= performance_rating_stars_html((int)$r['overall_rating']) ?>
                                </td>
                                <td>
                                    <?php if ((int)$r['goals_count'] > 0): ?>
                                        <span class="badge bg-body-secondary text-body border">
                                            <?= (int)$r['goals_achieved'] ?> / <?= (int)$r['goals_count'] ?> Achieved
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small"><?= date('d M Y', strtotime($r['review_date'])) ?></span>
                                </td>
                                <td>
                                    <span class="small fw-medium"><?= e($r['reviewer_name'] ?? 'System') ?></span>
                                </td>
                                <td>
                                    <?= performance_status_badge((string)$r['status']) ?>
                                </td>
                                <td class="text-end text-nowrap pe-3">
                                    <a href="<?= e(url('modules/performance/view.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php if (can_user_edit_performance_review($pdo, current_user_id(), $r)): ?>
                                        <a href="<?= e(url('modules/performance/form.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Goals Card -->
    <?php if (!empty($employeeGoals)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-body">
                <span class="fw-semibold">
                    <i class="bi bi-flag-fill text-info me-2"></i>Performance Goals &amp; Development Objectives
                </span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <?php foreach ($employeeGoals as $g): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="p-3 rounded border bg-body-tertiary h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <h6 class="fw-bold mb-0 text-body fs-6"><?= e($g['title']) ?></h6>
                                        <div><?= performance_goal_status_badge((string)$g['status']) ?></div>
                                    </div>
                                    <?php if (!empty($g['description'])): ?>
                                        <p class="small text-muted mb-3"><?= e($g['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <div class="flex-grow-1">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar <?= (int)$g['progress'] === 100 ? 'bg-success' : 'bg-primary' ?>"
                                                     style="width: <?= (int)$g['progress'] ?>%;"></div>
                                            </div>
                                        </div>
                                        <span class="small font-monospace fw-bold"><?= (int)$g['progress'] ?>%</span>
                                    </div>
                                    <?php if (!empty($g['target_date'])): ?>
                                        <div class="small text-muted" style="font-size: 0.75rem;">
                                            <i class="bi bi-calendar-event me-1"></i>Target: <?= date('d M Y', strtotime($g['target_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Modal: Status Change -->
<?php if ($canEdit): ?>
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/employees/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="modal-header">
                    <h5 class="modal-title h6" id="statusModalLabel">Change Employment Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="newStatus">New Employment Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="newStatus" name="new_status" required>
                            <?php foreach ($statusLabels as $sKey => $sLabel): ?>
                                <?php if ($sKey !== 'transferred'): // Transferred must be performed via Transfer workflow ?>
                                    <option value="<?= e($sKey) ?>"<?= ($emp['employment_status'] === $sKey) ? ' selected' : '' ?>>
                                        <?= e($sLabel) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Company transfers must be performed via the "Transfer Company" workflow.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="statusEffectiveDate">Effective Date <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="statusEffectiveDate" name="effective_date" required
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="statusRemarks">Remarks / Reason</label>
                        <textarea class="form-control" id="statusRemarks" name="remarks" rows="2" maxlength="255"
                                  placeholder="e.g. Resigned with 1-month notice, retirement, medical leave..."></textarea>
                        <div class="form-text">This will be permanently added to the employee's history. No hard deletes.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm updating employee status?');">
                        Save Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Upload Document -->
<?php if ($canUploadDocs): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1" aria-labelledby="uploadDocModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/employees/document_upload.php')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="employee_id" value="<?= $id ?>">
                <div class="modal-header">
                    <h5 class="modal-title h6" id="uploadDocModalLabel">Upload Employee Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="docType">Document Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="docType" name="doc_type" required>
                            <?php foreach ($docTypeLabels as $dtKey => $dtLabel): ?>
                                <option value="<?= e($dtKey) ?>"><?= e($dtLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="docTitle">Document Title</label>
                        <input class="form-control" type="text" id="docTitle" name="title" maxlength="150"
                               placeholder="e.g. Appointment Letter 2026, Degree Certificate">
                        <div class="form-text">Leave blank to use the document type name.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="docFile">File (PDF, JPG, PNG, DOCX) <span class="text-danger">*</span></label>
                        <input class="form-control" type="file" id="docFile" name="document" required
                               accept=".pdf,.jpg,.jpeg,.png,.docx,application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        <div class="form-text">Maximum file size: 5 MB. Files are stored securely outside public web root.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="docExpiry">Expiry Date (optional)</label>
                        <input class="form-control" type="date" id="docExpiry" name="expiry_date">
                        <div class="form-text">Useful for agreements, visas, or expiring certifications.</div>
                    </div>

                    <?php if ($canConfidential): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="docConfidential" name="is_confidential" value="1">
                            <label class="form-check-label" for="docConfidential">
                                Mark as Confidential <span class="badge text-bg-warning ms-1">document.confidential</span>
                            </label>
                            <div class="form-text">Only accessible to users holding the <code>document.confidential</code> permission.</div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Document</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
