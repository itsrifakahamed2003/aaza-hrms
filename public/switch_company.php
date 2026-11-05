<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_require();

$id = (int)($_POST['company_id'] ?? 0);
if ($id === 0) {
    unset($_SESSION['active_company']);
} elseif (in_array($id, accessible_company_ids(), true)) {
    $_SESSION['active_company'] = $id;
} else {
    // Trying to switch into a company they are not assigned to
    log_activity('access.denied', 'company', $id, ['action' => 'switch_company'], $id);
    abort(403);
}

// Return to the page they were on (same site only), else the dashboard
$back = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
$host = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
$ownHost = (string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($host !== '' && $host === $ownHost && safe_internal_path($back)) {
    header('Location: ' . $back);
    exit;
}
redirect('public/index.php');
