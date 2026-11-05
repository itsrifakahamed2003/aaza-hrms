<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// 1. Permission check: Only Administrator (users.is_group_admin = 1) with user.delete permission can delete users
if (!is_group_admin() || !user_can('user.delete')) {
    abort(403, 'Permission denied: only Administrators can delete users.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed. User deletion requires POST.');
}

csrf_require();

$id = (int)($_POST['id'] ?? 0);
$pdo = db();
$currentUserId = (int)current_user()['id'];

$st = $pdo->prepare('SELECT id, username, email, full_name, is_group_admin, is_active FROM users WHERE id = ? LIMIT 1');
$st->execute([$id]);
$targetUser = $st->fetch();

if (!$targetUser) {
    flash_set('danger', 'User not found.');
    redirect('modules/users/index.php');
}

// 2. Self deletion check
if ($id === $currentUserId) {
    flash_set('danger', 'You cannot delete your own account.');
    redirect('modules/users/view.php?id=' . $id);
}

// 3. Safety Guard: Re-check server-side that is_active = 0
if ((int)$targetUser['is_active'] !== 0) {
    flash_set('danger', 'Cannot delete an active user. Please deactivate the user first.');
    redirect('modules/users/view.php?id=' . $id);
}

// 4. Safety Guard: Confirm username verification
$confirmUsername = trim((string)($_POST['confirm_username'] ?? ''));
if ($confirmUsername !== (string)$targetUser['username']) {
    flash_set('danger', 'Deletion cancelled: Confirmation username does not match "@' . $targetUser['username'] . '".');
    redirect('modules/users/view.php?id=' . $id);
}

// 5. Perform safe deletion (checks all dependent records and deletes in one transaction)
$result = delete_user($id, $pdo, $currentUserId);

if ($result['success']) {
    flash_set('success', $result['message']);
    redirect('modules/users/index.php');
} else {
    flash_set('danger', $result['message']);
    redirect('modules/users/view.php?id=' . $id);
}
