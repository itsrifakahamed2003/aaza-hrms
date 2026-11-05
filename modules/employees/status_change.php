<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
csrf_require();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Employee not found.');
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
$st->execute([$id]);
$emp = $st->fetch();

if (!$emp) {
    abort(404, 'Employee not found.');
}

$companyId = (int)$emp['company_id'];
require_company_access($companyId, 'employee.edit');

$redirectUrl = 'modules/employees/view.php?id=' . $id;

$newStatus = trim((string)($_POST['new_status'] ?? ''));
$validStatuses = employee_status_list();
if (!array_key_exists($newStatus, $validStatuses)) {
    flash_set('danger', 'Invalid employment status selected.');
    redirect($redirectUrl);
}

if ($newStatus === 'transferred') {
    flash_set('warning', 'To transfer an employee between companies, please use the Transfer workflow.');
    redirect('modules/employees/transfer.php?id=' . $id);
}

if ($newStatus === $emp['employment_status']) {
    flash_set('info', 'The employee already has status: ' . $validStatuses[$newStatus] . '.');
    redirect($redirectUrl);
}

$effectiveDate = trim((string)($_POST['effective_date'] ?? date('Y-m-d')));
$d = DateTime::createFromFormat('Y-m-d', $effectiveDate);
if (!$d || $d->format('Y-m-d') !== $effectiveDate) {
    flash_set('danger', 'Effective date must be a valid date (YYYY-MM-DD).');
    redirect($redirectUrl);
}

$remarks = trim((string)($_POST['remarks'] ?? ''));
if (mb_strlen($remarks) > 255) {
    flash_set('danger', 'Remarks must not exceed 255 characters.');
    redirect($redirectUrl);
}

// Map change type for employment_history
$changeType = match ($newStatus) {
    'resigned'   => 'resignation',
    'terminated' => 'termination',
    default      => 'status_change',
};

$oldLabel = $validStatuses[$emp['employment_status']] ?? $emp['employment_status'];
$newLabel = $validStatuses[$newStatus] ?? $newStatus;
$historyRemarks = "Status changed from '{$oldLabel}' to '{$newLabel}'";
if ($remarks !== '') {
    $historyRemarks .= ": $remarks";
}
if (mb_strlen($historyRemarks) > 255) {
    $historyRemarks = mb_substr($historyRemarks, 0, 255);
}

$currentUserId = (int)current_user()['id'];

$pdo->beginTransaction();
try {
    // 1. Close the currently open employment_history row
    $stClose = $pdo->prepare(
        'UPDATE employment_history SET end_date = ? WHERE employee_id = ? AND end_date IS NULL'
    );
    $stClose->execute([$effectiveDate, $id]);

    // 2. Write new employment_history row
    $stHist = $pdo->prepare('INSERT INTO employment_history (
        employee_id, company_id, department_id, designation_id,
        employment_type, start_date, end_date, change_type, remarks, recorded_by
    ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)');

    $stHist->execute([
        $id,
        $companyId,
        $emp['department_id'],
        $emp['designation_id'],
        $emp['employment_type'],
        $effectiveDate,
        $changeType,
        $historyRemarks,
        $currentUserId,
    ]);

    // 2. Update employees table status (no hard delete)
    $stUp = $pdo->prepare('UPDATE employees SET employment_status = ?, updated_at = NOW() WHERE id = ?');
    $stUp->execute([$newStatus, $id]);

    $pdo->commit();

    log_activity('employee.status_change', 'employee', $id, [
        'employee_code'  => $emp['employee_code'],
        'name'           => $emp['first_name'] . ' ' . $emp['last_name'],
        'old_status'     => $emp['employment_status'],
        'new_status'     => $newStatus,
        'effective_date' => $effectiveDate,
        'remarks'        => $remarks,
    ], $companyId);

    flash_set('success', "Employee status updated to {$validStatuses[$newStatus]}.");
    redirect($redirectUrl);
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
