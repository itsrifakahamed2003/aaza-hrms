<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Only Group Admin or users with company.delete permission can delete companies
if (!is_group_admin() && !user_can('company.delete')) {
    abort(403, 'Permission denied: only administrators can delete companies.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed. Company deletion requires POST.');
}

csrf_require();

$id  = (int)($_POST['id'] ?? 0);
$pdo = db();

$st = $pdo->prepare('SELECT id, code, name, is_active, logo_path FROM companies WHERE id = ? LIMIT 1');
$st->execute([$id]);
$company = $st->fetch();

if (!$company) {
    flash_set('danger', 'Company not found.');
    redirect('modules/companies/index.php');
}

// 1. Safety Guard: Re-check server-side that is_active = 0
if ((int)$company['is_active'] !== 0) {
    flash_set('danger', 'Cannot delete an active company. Please deactivate the company first.');
    redirect('modules/companies/index.php');
}

// 2. Safety Guard: Confirm code verification
$confirmCode = strtoupper(trim((string)($_POST['confirm_code'] ?? '')));
if ($confirmCode !== strtoupper((string)$company['code'])) {
    flash_set('danger', 'Deletion cancelled: Confirmation code does not match company code "' . $company['code'] . '".');
    redirect('modules/companies/index.php');
}

// 3. Perform safe deletion (checks all dependent data and deletes inside a transaction)
$result = delete_company($id, $pdo);

if ($result['success']) {
    flash_set('success', $result['message']);
} else {
    flash_set('danger', $result['message']);
}

redirect('modules/companies/index.php');
