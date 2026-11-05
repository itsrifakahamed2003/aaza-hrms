<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('warning', 'Leave application ID is missing or invalid.');
    redirect('modules/leave/index.php');
}

$pdo = db();
$st = $pdo->prepare(
    "SELECT lr.*, lt.name AS leave_type_name, lt.days_per_year, lt.requires_document, lt.description AS leave_type_desc,
            e.first_name, e.last_name, e.employee_code, e.user_id AS employee_user_id,
            e.employment_status, e.joining_date,
            c.id AS company_id, c.name AS company_name, c.code AS company_code,
            d.name AS department_name, des.title AS designation_title,
            u.full_name AS decided_by_name
       FROM leave_requests lr
       JOIN employees e ON e.id = lr.employee_id
       JOIN companies c ON c.id = lr.company_id
       JOIN leave_types lt ON lt.id = lr.leave_type_id
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN designations des ON des.id = e.designation_id
  LEFT JOIN users u ON u.id = lr.decided_by
      WHERE lr.id = ?
      LIMIT 1"
);
$st->execute([$id]);
$req = $st->fetch();

if (!$req) {
    flash_set('warning', 'The requested leave application (#' . $id . ') was not found. It may have been deleted, cancelled, or already removed.');
    redirect('modules/leave/index.php');
}

$companyId = (int)$req['company_id'];
$isOwner   = ($req['employee_user_id'] !== null && (int)$req['employee_user_id'] === current_user_id());
$canView   = $isOwner || user_can('leave.view', $companyId) || user_can('leave.approve', $companyId);

if (!$canView) {
    abort(403, 'You do not have access to view this leave application.');
}

$canApprove = user_can('leave.approve', $companyId);
$isSelfRequest = ($req['employee_user_id'] !== null && (int)$req['employee_user_id'] === current_user_id());

// Leave balance for employee for this leave type
$requestYear = (int)substr((string)$req['start_date'], 0, 4);
$balance = leave_balance($pdo, (int)$req['employee_id'], (int)$req['leave_type_id'], $requestYear);

// Fetch decision history from leave_approvals
$stAppr = $pdo->prepare(
    "SELECT la.*, u.full_name AS approver_name, u.email AS approver_email
       FROM leave_approvals la
       JOIN users u ON u.id = la.approver_id
      WHERE la.leave_request_id = ?
      ORDER BY la.decided_at DESC, la.id DESC"
);
$stAppr->execute([$id]);
$approvalHistory = $stAppr->fetchAll();

$status = (string)$req['status'];

$pageTitle = 'Leave Request #' . $id . ' - ' . $req['first_name'] . ' ' . $req['last_name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1050px;">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/leave/index.php')) ?>">Leave Applications</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Request #<?= (int)$req['id'] ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <h1 class="h3 mb-0">Leave Application #<?= (int)$req['id'] ?></h1>
                <?= leave_status_badge($status) ?>
                <?php if ((int)$req['half_day'] === 1): ?>
                    <span class="badge badge-soft-warning">Half Day</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/leave/index.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Requests
            </a>
            <?php if (user_can('employee.view', $companyId)): ?>
                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$req['employee_id'])) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-person me-1"></i> Employee Profile
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self-Approval Warning Banner -->
    <?php if ($status === 'pending' && $canApprove && $isSelfRequest): ?>
        <div class="alert alert-warning border-warning shadow-sm mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
            <strong>Self-Approval Blocked:</strong> Company policy strictly prohibits approving or rejecting your own leave request. Another authorized manager or HR administrator must review this application.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Main Application Details -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Application Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="text-muted small">Employee</div>
                            <div class="fw-semibold text-body">
                                <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                <span class="font-monospace text-muted small ms-1">(<?= e($req['employee_code']) ?>)</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Company &amp; Department</div>
                            <div class="fw-semibold text-body">
                                <span class="badge bg-body-secondary text-body border me-1"><?= e($req['company_code']) ?></span>
                                <?= e($req['department_name'] ?? 'General') ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Designation / Title</div>
                            <div class="fw-semibold text-body"><?= e($req['designation_title'] ?? 'Staff') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Leave Policy / Type</div>
                            <div class="fw-semibold text-body">
                                <?= e($req['leave_type_name']) ?>
                                <?php if ((int)$req['requires_document'] === 1): ?>
                                    <span class="badge text-bg-warning text-body ms-1" style="font-size:0.75rem;">Doc Required</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Leave Period</div>
                            <div class="fw-semibold text-body fs-6">
                                <?= e(format_date($req['start_date'])) ?>
                                <?php if ($req['start_date'] !== $req['end_date']): ?>
                                    <i class="bi bi-arrow-right mx-1 text-muted"></i> <?= e(format_date($req['end_date'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Total Working Days</div>
                            <div class="fw-bold font-monospace fs-5 text-primary">
                                <?= e(number_format((float)$req['total_days'], 1)) ?> <span class="fs-6 fw-normal text-muted">day(s)</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Submission Date</div>
                            <div class="fw-semibold text-body"><?= e(format_date($req['requested_at'])) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Supporting Document</div>
                            <?php if (!empty($req['document_path'])): ?>
                                <a href="<?= e(url('modules/leave/download.php?id=' . (int)$req['id'])) ?>" class="btn btn-sm btn-outline-primary mt-1">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Attachment
                                </a>
                            <?php else: ?>
                                <span class="text-muted">None attached</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-2">
                        <div class="text-muted small fw-semibold">Reason / Handover Notes</div>
                        <div class="bg-body-tertiary p-3 rounded mt-1 border text-body">
                            <?= !empty($req['reason']) ? nl2br(e($req['reason'])) : '<span class="text-muted fst-italic">No reason stated.</span>' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Decision History Table -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Decision History &amp; Audit Log</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($approvalHistory)): ?>
                        <div class="text-center py-4 text-muted small">
                            No approval decisions recorded yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-active">
                                    <tr>
                                        <th class="ps-3">Approver</th>
                                        <th>Decision</th>
                                        <th>Comments</th>
                                        <th class="text-end pe-3">Decided At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($approvalHistory as $ah): ?>
                                        <tr>
                                            <td class="ps-3 fw-medium text-body">
                                                <?= e($ah['approver_name']) ?>
                                            </td>
                                            <td>
                                                <?= $ah['decision'] === 'approved'
                                                    ? '<span class="badge text-bg-success">Approved</span>'
                                                    : '<span class="badge text-bg-danger">Rejected</span>' ?>
                                            </td>
                                            <td>
                                                <?= !empty($ah['comments']) ? e($ah['comments']) : '<span class="text-muted">—</span>' ?>
                                            </td>
                                            <td class="text-end pe-3 text-muted small">
                                                <?= e(format_date($ah['decided_at'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar Actions & Balance Column -->
        <div class="col-lg-4">
            <!-- Review & Decision Action Card (for Managers / HR) -->
            <?php if ($status === 'pending' && $canApprove && !$isSelfRequest): ?>
                <div class="card border-0 shadow-sm mb-4 border-top border-4 border-primary">
                    <div class="card-header bg-body py-3">
                        <h6 class="card-title mb-0 fw-bold">Review Application</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            You have authority to decide on this leave request. Approving will update the employee's balance and schedule 'leave' attendance records.
                        </p>
                        <form method="post" action="<?= e(url('modules/leave/decide.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Decision Comments</label>
                                <textarea name="comment" class="form-control form-control-sm" rows="3" maxlength="255"
                                          placeholder="Optional for approval; mandatory for rejection..."></textarea>
                                <div class="form-text small">Mandatory when rejecting.</div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" name="decision" value="approve" class="btn btn-success"
                                        data-confirm="Approve leave request for <?= e($req['first_name'] . ' ' . $req['last_name']) ?> (<?= e((string)$req['total_days']) ?> days)?" data-confirm-type="success">
                                    <i class="bi bi-check-circle me-1"></i> Approve Leave Request
                                </button>
                                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger"
                                        data-confirm="Reject this leave request? A comment is required." data-confirm-type="danger">
                                    <i class="bi bi-x-circle me-1"></i> Reject Leave Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Leave Balance Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h6 class="card-title mb-0 fw-bold"><?= e((string)$requestYear) ?> Balance for <?= e($req['leave_type_name']) ?></h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Annual Allowance</span>
                        <span class="fw-bold font-monospace"><?= $balance['is_unlimited'] ? 'Unlimited' : number_format($balance['allowance'], 1) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Approved Taken</span>
                        <span class="fw-bold font-monospace text-success"><?= number_format($balance['taken'], 1) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Pending Requests</span>
                        <span class="fw-bold font-monospace text-warning"><?= number_format($balance['pending'], 1) ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-body">Remaining Balance</span>
                        <span class="fw-bold font-monospace fs-5 text-primary">
                            <?= $balance['is_unlimited'] ? 'Unlimited' : number_format($balance['remaining'], 1) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
