<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
}

http_response_code(500);
$pageTitle = '500 · Server Error';
$refId = 'ERR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/tokens.css')) ?>">
</head>
<body class="bg-body-tertiary">
    <div class="container py-5" style="max-width:540px">
        <div class="text-center mb-4">
            <img src="<?= e(url('assets/img/aaza-logo.png')) ?>" alt="<?= e(APP_NAME) ?>" style="max-height:48px;object-fit:contain;">
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center p-4 p-md-5">
                <div class="rounded-circle bg-danger-subtle text-danger mx-auto d-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                    <i class="bi bi-exclamation-triangle fs-1"></i>
                </div>
                <h1 class="h4 fw-bold mb-2 text-body">500 · Server Error</h1>
                <p class="text-secondary small mb-3">An unexpected server error occurred while processing your request.</p>
                <p class="text-muted small mb-4">Please quote reference ID <code><?= e($refId) ?></code> if contacting technical support.</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="<?= e(url('public/index.php')) ?>" class="btn btn-primary btn-sm px-3">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

