<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Only Group Admin with employee.delete permission can delete employees
if (!is_group_admin() || !user_can('employee.delete')) {
    abort(403, 'Permission denied: only Administrators can delete employees.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed. Employee deletion requires POST.');
}

csrf_require();

$id = (int)($_POST['id'] ?? 0);
$pdo = db();
$currentUserId = current_user_id();

$result = delete_employee($id, $pdo, $currentUserId);

if ($result['success']) {
    flash_set('success', $result['message']);
    redirect('modules/employees/index.php');
} else {
    flash_set('danger', $result['message']);
    redirect('modules/employees/view.php?id=' . $id);
}

