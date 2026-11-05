<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_group_admin();
csrf_require();

$id  = (int)($_POST['id'] ?? 0);
$pdo = db();
$st  = $pdo->prepare('SELECT id, code, name, is_active FROM companies WHERE id = ? LIMIT 1');
$st->execute([$id]);
$company = $st->fetch();
if (!$company) {
    abort(404, 'Company not found.');
}

// Companies are never deleted: their employees, candidates and history must be kept.
$newState = $company['is_active'] ? 0 : 1;
$pdo->prepare('UPDATE companies SET is_active = ? WHERE id = ?')->execute([$newState, $id]);

log_activity($newState ? 'company.activate' : 'company.deactivate', 'company', $id,
    ['code' => $company['code']], $id);
flash_set('success', $company['name'] . ($newState ? ' activated.' : ' deactivated. Its records are kept.'));
redirect('modules/companies/index.php');
