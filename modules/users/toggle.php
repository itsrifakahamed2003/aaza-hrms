<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('user.manage');
csrf_require();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'User not found.');
}

if ($id === (int)current_user()['id']) {
    flash_set('danger', 'You cannot deactivate your own account.');
    redirect('modules/users/index.php');
}

$pdo = db();
$st = $pdo->prepare('SELECT id, username, full_name, is_active, is_group_admin FROM users WHERE id = ? LIMIT 1');
$st->execute([$id]);
$target = $st->fetch();
if (!$target) {
    abort(404, 'User not found.');
}

// Scoping check for company_admin
if (!is_group_admin()) {
    if ((int)$target['is_group_admin'] === 1) {
        abort(403, 'Cannot modify Group Administrator accounts.');
    }
    $scopedCompanyIds = scoped_company_ids('user.manage');
    $stScope = $pdo->prepare(
        'SELECT COUNT(*) FROM company_user_assignments
          WHERE user_id = ? AND company_id IN (' . implode(',', array_fill(0, count($scopedCompanyIds), '?')) . ')'
    );
    $stScope->execute(array_merge([$id], $scopedCompanyIds));
    if ((int)$stScope->fetchColumn() === 0) {
        abort(403, 'Cannot modify users outside your company scope.');
    }
}

$newState = $target['is_active'] ? 0 : 1;
$pdo->prepare('UPDATE users SET is_active = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
    ->execute([$newState, $id]);

log_activity(
    $newState ? 'user.activate' : 'user.deactivate',
    'user',
    $id,
    ['username' => $target['username'], 'full_name' => $target['full_name']]
);

if ($newState === 0) {
    notify_user(
        $pdo,
        $id,
        'account_status',
        'Account Deactivated',
        'Your user account has been deactivated by an administrator.',
        null,
        null
    );
}

clear_access_cache();

flash_set('success', 'User ' . $target['full_name'] . ($newState ? ' activated.' : ' deactivated.'));

$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if (str_contains($referer, 'modules/users/view.php')) {
    redirect('modules/users/view.php?id=' . $id);
}
redirect('modules/users/index.php');
