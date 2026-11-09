<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

// User must be linked to an active employee record
$employee = get_linked_employee_for_user($pdo, $currentUserId);
if (!$employee) {
    $pageTitle = 'My Leave Requests - Account Not Linked';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container-fluid py-5" style="max-width: 750px;">
        <div class="card border-0 shadow-sm text-center p-5">
            <div class="mb-3">
                <i class="bi bi-person-x-fill text-warning" style="font-size: 3.5rem;"></i>
            </div>
            <h3 class="card-title fw-bold">Employee Profile Link Required</h3>
            <p class="text-muted mb-4">
                Your user login is not currently linked to an active employee record. Ask HR to link your login to your employee record.
            </p>
            <div>
                <a href="<?= e(url('public/index.php')) ?>" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$employeeId = (int)$employee['id'];
$companyId  = (int)$employee['company_id'];

// Handle Cancel Action (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'cancel') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            flash_set('danger', 'Invalid leave request specified.');
            redirect('modules/leave/my_leaves.php');
        }

        try {
            $pdo->beginTransaction();

            // Lock employee first to serialise concurrent state modifications
            $stEmpLock = $pdo->prepare("SELECT id FROM employees WHERE id = ? FOR UPDATE");
            $stEmpLock->execute([$employeeId]);

            $stLock = $pdo->prepare(
                "SELECT * FROM leave_requests WHERE id = ? AND employee_id = ? FOR UPDATE"
            );
            $stLock->execute([$requestId, $employeeId]);
            $req = $stLock->fetch();

            if (!$req) {
                $pdo->rollBack();
                flash_set('danger', 'Leave request not found or does not belong to you.');
                redirect('modules/leave/my_leaves.php');
            }

            $currentStatus = (string)$req['status'];
            $startDate     = (string)$req['start_date'];
            $today         = date('Y-m-d');

            // Pending can be cancelled at any time; approved only if start_date is in the future
            if ($currentStatus === 'pending') {
                $canCancel = true;
            } elseif ($currentStatus === 'approved') {
                $canCancel = ($startDate > $today);
                if (!$canCancel) {
                    $pdo->rollBack();
                    flash_set('danger', 'Approved leave requests cannot be cancelled once the start date has arrived or passed.');
                    redirect('modules/leave/my_leaves.php');
                }
            } else {
                $pdo->rollBack();
                flash_set('danger', "This request cannot be cancelled because its status is already '{$currentStatus}'.");
                redirect('modules/leave/my_leaves.php');
            }

            // Update status to cancelled
            $upd = $pdo->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?");
            $upd->execute([$requestId]);

            // If an approved leave is cancelled, remove the matching auto-created attendance rows inside the same transaction
            $removedAttendance = 0;
            if ($currentStatus === 'approved') {
                $removedAttendance = remove_approved_leave_attendance($pdo, $requestId);
            }

            $pdo->commit();

            if (!empty($employee['user_id'])) {
                notify_user(
                    $pdo,
                    (int)$employee['user_id'],
                    'leave_cancelled',
                    'Leave Request Cancelled',
                    "Your leave request from {$req['start_date']} to {$req['end_date']} has been cancelled.",
                    'modules/leave/my_leaves.php',
                    $companyId,
                    'leave:cancelled:' . $requestId
                );
            }
            notify_permission(
                $pdo,
                $companyId,
                'leave.approve',
                'leave_cancelled',
                'Leave Request Cancelled',
                "Leave request #{$requestId} for {$employee['first_name']} {$employee['last_name']} was cancelled.",
                'modules/leave/index.php',
                'leave:cancelled_admin:' . $requestId,
                current_user_id()
            );

            log_activity(
                'leave.cancel',
                'leave_request',
                $requestId,
                [
                    'previous_status'    => $currentStatus,
                    'removed_attendance' => $removedAttendance,
                ],
                $companyId
            );

            flash_set('success', 'Leave request cancelled successfully.' . ($removedAttendance > 0 ? " ({$removedAttendance} scheduled leave attendance record(s) removed)." : ''));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Cancel leave error: ' . $e->getMessage());
            flash_set('danger', 'An error occurred while cancelling your leave request.');
        }

        redirect('modules/leave/my_leaves.php');
    }
}

// Fetch leave balances for current calendar year
$currentYear = (int)date('Y');
$stTypes = $pdo->prepare(
    "SELECT * FROM leave_types
      WHERE is_active = 1
        AND (company_id IS NULL OR company_id = ?)
      ORDER BY (company_id IS NULL) DESC, name ASC"
);
$stTypes->execute([$companyId]);
$leaveTypes = $stTypes->fetchAll();

$balances = [];
foreach ($leaveTypes as $lt) {
    $balances[(int)$lt['id']] = leave_balance($pdo, $employeeId, (int)$lt['id'], $currentYear);
}

// Fetch this employee's leave requests with leave type info and approver details
$stReqs = $pdo->prepare(
    "SELECT lr.*, lt.name AS leave_type_name, lt.requires_document,
            u.full_name AS approver_name
       FROM leave_requests lr
       JOIN leave_types lt ON lt.id = lr.leave_type_id
  LEFT JOIN users u ON u.id = lr.decided_by
      WHERE lr.employee_id = ?
      ORDER BY lr.requested_at DESC, lr.id DESC"
);
$stReqs->execute([$employeeId]);
$myRequests = $stReqs->fetchAll();

$pageTitle = 'My Leave Applications';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Top Bar & Breadcrumb -->
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">My Leave</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h1 class="page-header-title h3 mb-0">My Leave Applications</h1>
                <span class="badge bg-body-secondary text-body border"><?= e($employee['company_code']) ?> &bull; <?= e($employee['employee_code']) ?></span>
            </div>
        </div>
        <div class="page-actions">
            <a href="<?= e(url('modules/attendance/employee_attendance.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar-check me-1"></i> My Attendance
            </a>
            <?php if (user_can('leave.request') && !empty($navHasLinkedEmp)): ?>
                <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Apply for Leave
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Balance Cards Grid -->
    <div class="row g-3 mb-4">
        <?php foreach ($leaveTypes as $lt): ?>
            <?php
            $b = $balances[(int)$lt['id']] ?? null;
            $isUnlim = $b && $b['is_unlimited'];
            $allowanceVal = $b ? $b['allowance'] : (float)$lt['days_per_year'];
            $usedVal      = $b ? $b['taken'] : 0.0;
            $pendingVal   = $b ? $b['pending'] : 0.0;
            $availableVal = $isUnlim ? INF : max(0.0, $allowanceVal - $usedVal - $pendingVal);

            $allowanceText = $isUnlim ? '&infin;' : number_format($allowanceVal, 1);
            $availableText = $isUnlim ? 'Unlimited' : number_format($availableVal, 1);
            ?>
            <div class="col-sm-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small text-truncate fw-semibold mb-1" title="<?= e($lt['name']) ?>">
                            <?= e($lt['name']) ?>
                        </div>
                        <div class="d-flex align-items-baseline justify-content-between mb-1">
                            <span class="fs-4 fw-bold text-body font-monospace"><?= $availableText ?></span>
                            <span class="text-muted small" style="font-size:0.75rem;">avail</span>
                        </div>
                        <div class="text-muted d-flex justify-content-between pt-1 border-top" style="font-size:0.75rem;">
                            <span>Allow: <?= $allowanceText ?></span>
                            <span>Used: <?= number_format($usedVal, 1) ?></span>
                        </div>
                        <div class="d-flex justify-content-between pt-1" style="font-size:0.75rem;">
                            <span class="<?= $pendingVal > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">Pending: <?= number_format($pendingVal, 1) ?></span>
                            <span class="text-success fw-bold">Avail: <?= $availableText ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Leave Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fs-6 fw-bold">Leave History &amp; Applications (<?= count($myRequests) ?>)</h5>
                <small class="text-muted d-block mt-0.5">
                    <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any row to view full leave details.
                </small>
            </div>
            <small class="text-muted">Calendar Year <?= e((string)$currentYear) ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Type</th>
                            <th>Period &amp; Dates</th>
                            <th>Days</th>
                            <th>Reason / Doc</th>
                            <th>Status</th>
                            <th>Decision Details</th>
                            <th class="text-end pe-3 action-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($myRequests)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    You have not submitted any leave applications yet.
                                    <?php if (user_can('leave.request') && !empty($navHasLinkedEmp)): ?>
                                        <div class="mt-2">
                                            <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-sm btn-primary">Apply Now</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($myRequests as $r): ?>
                                <?php
                                $status = (string)$r['status'];
                                $isApprovedFuture = ($status === 'approved' && $r['start_date'] > date('Y-m-d'));
                                $canCancel = ($status === 'pending' || $isApprovedFuture);
                                $myLeaveViewUrl = url('modules/leave/view.php?id=' . (int)$r['id']);
                                ?>
                                <tr class="clickable-row leave-row" data-href="<?= e($myLeaveViewUrl) ?>" title="Double-click to view leave application #<?= (int)$r['id'] ?>">
                                    <td class="ps-3">
                                        <span class="fw-semibold text-body"><?= e($r['leave_type_name']) ?></span>
                                        <?php if ((int)$r['half_day'] === 1): ?>
                                            <span class="badge badge-soft-warning ms-1">Half Day</span>
                                        <?php endif; ?>
                                        <div class="text-muted small">Ref #<?= (int)$r['id'] ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-body">
                                            <?= e(format_date($r['start_date'])) ?>
                                            <?php if ($r['start_date'] !== $r['end_date']): ?>
                                                <i class="bi bi-arrow-right mx-1 text-muted"></i> <?= e(format_date($r['end_date'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small">
                                            Submitted <?= e(format_date($r['requested_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body border font-monospace fw-bold fs-6">
                                            <?= e(number_format((float)$r['total_days'], 1)) ?>
                                        </span>
                                        <span class="text-muted small">day(s)</span>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 220px;" title="<?= e($r['reason'] ?? '') ?>">
                                            <?= e($r['reason'] ?? '—') ?>
                                        </div>
                                        <?php if (!empty($r['document_path'])): ?>
                                            <a href="<?= e(url('modules/leave/download.php?id=' . (int)$r['id'])) ?>" class="small text-decoration-none text-primary d-inline-block mt-1">
                                                <i class="bi bi-paperclip me-1"></i> Attachment
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= leave_status_badge($status) ?>
                                    </td>
                                    <td>
                                        <?php if ($status === 'approved' || $status === 'rejected'): ?>
                                            <div class="small fw-medium text-body">
                                                <?= e($r['approver_name'] ?? 'Authorized Officer') ?>
                                            </div>
                                            <?php if (!empty($r['decided_at'])): ?>
                                                <div class="text-muted small"><?= e(format_date($r['decided_at'])) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['decision_comment'])): ?>
                                                <div class="small text-muted fst-italic">"<?= e($r['decision_comment']) ?>"</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 action-col">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="<?= e(url('modules/leave/view.php?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5" title="View details">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <?= action_menu([
                                                [
                                                    'type'  => 'link',
                                                    'label' => 'View Application',
                                                    'url'   => 'modules/leave/view.php?id=' . (int)$r['id'],
                                                    'icon'  => 'bi-eye',
                                                ],
                                                [
                                                    'type'         => 'post',
                                                    'label'        => 'Cancel Request',
                                                    'url'          => 'modules/leave/my_leaves.php',
                                                    'icon'         => 'bi-x-circle',
                                                    'params'       => ['action' => 'cancel', 'request_id' => (int)$r['id']],
                                                    'confirm'      => "Are you sure you want to cancel this leave application (Ref #" . (int)$r['id'] . ")?",
                                                    'confirm_type' => 'danger',
                                                    'danger'       => true,
                                                    'permission'   => (bool)$canCancel,
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
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
