<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Only Group Admin with offer.delete permission can delete job offers
if (!is_group_admin() || !user_can('offer.delete')) {
    abort(403, 'Permission denied: only Administrators can delete job offers.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed. Job offer deletion requires POST.');
}

csrf_require();

$id = (int)($_POST['id'] ?? 0);
$pdo = db();
$currentUserId = current_user_id();

$result = delete_offer($id, $pdo, $currentUserId);

if ($result['success']) {
    flash_set('success', $result['message']);
    redirect('modules/offers/index.php');
} else {
    flash_set('danger', $result['message']);
    redirect('modules/offers/view.php?id=' . $id);
}

