<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If accessed via GET with an ID (e.g. notification link), redirect to the leave review page
    if (isset($_GET['id'])) {
        $reqId = (int)$_GET['id'];
        if ($reqId > 0) {
            redirect('modules/leave/view.php?id=' . $reqId);
        }
    }
    abort(405, 'Method not allowed.');
}

csrf_require();

$requestId = (int)($_POST['request_id'] ?? 0);
$decision  = trim((string)($_POST['decision'] ?? ''));
$comment   = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 255);
$returnTo  = trim((string)($_POST['return_to'] ?? ''));

if ($requestId <= 0) {
    abort(404, 'Leave request not found.');
}

$defaultReturn = url('modules/leave/view.php?id=' . $requestId);
$target = safe_return_path($returnTo, $defaultReturn);

if (!in_array($decision, ['approve', 'reject'], true)) {
    flash_set('danger', 'Invalid decision action specified.');
    redirect_path($target);
}

$pdo = db();

// 1. Pre-fetch row without lock to get company_id and authorize access BEFORE starting transaction
$initSt = $pdo->prepare(
    "SELECT lr.id, lr.company_id, lr.employee_id, e.user_id AS employee_user_id
       FROM leave_requests lr
       JOIN employees e ON e.id = lr.employee_id
      WHERE lr.id = ?
      LIMIT 1"
);
$initSt->execute([$requestId]);
$initReq = $initSt->fetch();

if (!$initReq) {
    abort(404, 'Leave request not found.');
}

$companyId = (int)$initReq['company_id'];
require_company_access($companyId, 'leave.approve');

$currentUserId = current_user_id();

try {
    $pdo->beginTransaction();

    // 1. Lock the leave-owning employee first to serialise concurrent decisions
    $stEmpLock = $pdo->prepare("SELECT id FROM employees WHERE id = ? FOR UPDATE");
    $stEmpLock->execute([(int)$initReq['employee_id']]);

    // 2. Lock leave request row FOR UPDATE
    $stLock = $pdo->prepare(
        "SELECT lr.*, e.user_id AS employee_user_id, e.first_name, e.last_name,
                lt.name AS leave_type_name, lt.days_per_year
           FROM leave_requests lr
           JOIN employees e ON e.id = lr.employee_id
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.id = ?
          FOR UPDATE"
    );
    $stLock->execute([$requestId]);
    $req = $stLock->fetch();

    if (!$req) {
        $pdo->rollBack();
        abort(404, 'Leave request not found.');
    }

    $currentStatus = (string)$req['status'];
    if ($currentStatus !== 'pending') {
        $pdo->rollBack();
        flash_set('danger', "Only pending leave requests can be decided. Current status is already '{$currentStatus}'.");
        redirect_path($target);
    }

    // 3. Self-approval block: A user must NOT approve or reject their own leave request
    if ($req['employee_user_id'] !== null && (int)$req['employee_user_id'] === $currentUserId) {
        $pdo->rollBack();
        log_activity('leave.self_decision_blocked', 'leave_request', $requestId, [
            'attempted_decision' => $decision,
        ], $companyId);

        flash_set('danger', 'You cannot approve or reject your own leave application. Another manager or HR officer must review this request.');
        redirect_path($target);
    }

    if ($decision === 'reject') {
        // Rejection requires a comment
        if ($comment === '') {
            $pdo->rollBack();
            flash_set('danger', 'A comment explaining the rejection reason is mandatory when rejecting a leave application.');
            redirect_path($target);
        }

        // Update leave request
        $upd = $pdo->prepare(
            "UPDATE leave_requests
                SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_comment = ?
              WHERE id = ?"
        );
        $upd->execute([$currentUserId, $comment, $requestId]);

        // Write leave_approvals audit row
        $insAppr = $pdo->prepare(
            "INSERT INTO leave_approvals (leave_request_id, approver_id, decision, comments, decided_at)
             VALUES (?, ?, 'rejected', ?, NOW())"
        );
        $insAppr->execute([$requestId, $currentUserId, $comment]);

        $pdo->commit();

        if (!empty($req['employee_user_id'])) {
            notify_user(
                $pdo,
                (int)$req['employee_user_id'],
                'leave_rejected',
                'Leave Request Rejected',
                "Your {$req['leave_type_name']} request from {$req['start_date']} to {$req['end_date']} was rejected.",
                'modules/leave/my_leaves.php',
                $companyId,
                'leave:rejected:' . $requestId
            );
        }

        log_activity(
            'leave.reject',
            'leave_request',
            $requestId,
            [
                'employee_id' => (int)$req['employee_id'],
                'comment'     => $comment,
            ],
            $companyId
        );

        flash_set('success', "Leave application #{$requestId} for {$req['first_name']} {$req['last_name']} has been rejected.");
        redirect_path($target);
    }

    if ($decision === 'approve') {
        $empId     = (int)$req['employee_id'];
        $typeId    = (int)$req['leave_type_id'];
        $startDate = (string)$req['start_date'];
        $endDate   = (string)$req['end_date'];
        $isHalfDay = (bool)$req['half_day'];
        $allowance = (float)$req['days_per_year'];

        // Re-check overlap inside transaction (exclude self)
        if (has_overlapping_leave($pdo, $empId, $startDate, $endDate, $requestId)) {
            $pdo->rollBack();
            flash_set('danger', 'Cannot approve: An overlapping leave request exists for this employee.');
            redirect_path($target);
        }

        // Re-check balance inside transaction
        if ($allowance > 0.0) {
            $startYear = (int)date('Y', strtotime($startDate));
            $endYear   = (int)date('Y', strtotime($endDate));

            for ($y = $startYear; $y <= min($endYear, $startYear + 2); $y++) {
                $bal = leave_balance($pdo, $empId, $typeId, $y);
                $yearStart = "$y-01-01";
                $yearEnd   = "$y-12-31";
                $overlapS  = max($startDate, $yearStart);
                $overlapE  = min($endDate, $yearEnd);
                $daysInY   = calculate_working_days($overlapS, $overlapE, $isHalfDay, null, (int)$req['company_id']);

                // Note: bal['remaining'] is based on currently approved requests.
                if ($daysInY > $bal['remaining']) {
                    $pdo->rollBack();
                    flash_set('danger', "Cannot approve: Employee has insufficient {$req['leave_type_name']} balance in {$y}. " .
                                        "Required: {$daysInY} day(s), Remaining: {$bal['remaining']} day(s).");
                    redirect_path($target);
                }
            }
        }

        // Update leave request to approved
        $upd = $pdo->prepare(
            "UPDATE leave_requests
                SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_comment = ?
              WHERE id = ?"
        );
        $upd->execute([$currentUserId, $comment !== '' ? $comment : null, $requestId]);

        // Write leave_approvals audit row
        $insAppr = $pdo->prepare(
            "INSERT INTO leave_approvals (leave_request_id, approver_id, decision, comments, decided_at)
             VALUES (?, ?, 'approved', ?, NOW())"
        );
        $insAppr->execute([$requestId, $currentUserId, $comment !== '' ? $comment : null]);

        // Auto-create attendance rows with status 'leave' inside the same transaction
        $syncedAttendance = sync_approved_leave_attendance($pdo, $requestId);

        $pdo->commit();

        if (!empty($req['employee_user_id'])) {
            notify_user(
                $pdo,
                (int)$req['employee_user_id'],
                'leave_approved',
                'Leave Request Approved',
                "Your {$req['leave_type_name']} request from {$req['start_date']} to {$req['end_date']} has been approved.",
                'modules/leave/my_leaves.php',
                $companyId,
                'leave:approved:' . $requestId
            );
        }

        log_activity(
            'leave.approve',
            'leave_request',
            $requestId,
            [
                'employee_id'       => $empId,
                'leave_type'        => $req['leave_type_name'],
                'total_days'        => (float)$req['total_days'],
                'synced_attendance' => $syncedAttendance,
                'comments'          => $comment !== '' ? $comment : null,
            ],
            $companyId
        );

        flash_set('success', "Leave request #{$requestId} approved successfully! ({$syncedAttendance} working day attendance record(s) scheduled).");
        redirect_path($target);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Leave decision error: ' . $e->getMessage());
    flash_set('danger', 'A database error occurred while recording your decision. Please try again.');
    redirect_path($target);
}
