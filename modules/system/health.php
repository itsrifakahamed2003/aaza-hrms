<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_group_admin();

$pageTitle = 'System Health & Diagnostics';
$pdo = db();

// Handle deletion of public/setup.php if requested by admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_setup') {
    csrf_require();
    $setupPath = ROOT_PATH . '/public/setup.php';
    if (is_file($setupPath)) {
        if (@unlink($setupPath)) {
            flash_set('success', 'public/setup.php was successfully deleted.');
        } else {
            flash_set('danger', 'Could not automatically delete public/setup.php due to filesystem permissions. Please remove it manually.');
        }
    } else {
        flash_set('info', 'public/setup.php has already been deleted.');
    }
    redirect('modules/system/health.php');
}

// 1. Environment & PHP Details
$phpVersion = PHP_VERSION;
$phpPass = version_compare($phpVersion, '8.0.0', '>=');
$exts = [
    'pdo_mysql' => ['name' => 'PDO MySQL', 'req' => true],
    'mbstring'  => ['name' => 'Multibyte String (mbstring)', 'req' => true],
    'fileinfo'  => ['name' => 'File Information (fileinfo)', 'req' => true],
    'zip'       => ['name' => 'Zip Archive (zip)', 'req' => true],
    'gd'        => ['name' => 'GD Graphics Library', 'req' => false],
    'openssl'   => ['name' => 'OpenSSL', 'req' => true],
    'json'      => ['name' => 'JSON', 'req' => true],
    'curl'      => ['name' => 'cURL', 'req' => false],
];

// 2. Database Connection Diagnostics
$dbConnected = false;
$dbVersion = 'Unknown';
$dbTime = 'Unknown';
$dbTimezone = 'Unknown';
try {
    $dbConnected = true;
    $dbVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $dbInfo = $pdo->query("SELECT @@time_zone as tz, NOW() as current_time_val")->fetch();
    $dbTimezone = (string)($dbInfo['tz'] ?? 'SYSTEM');
    $dbTime = (string)($dbInfo['current_time_val'] ?? date('Y-m-d H:i:s'));
} catch (Throwable $e) {
    $dbConnected = false;
}

// 3. Storage Directory Permissions
$dirs = [
    'storage'         => ROOT_PATH . '/storage',
    'storage/logs'    => ROOT_PATH . '/storage/logs',
    'storage/backups' => ROOT_PATH . '/storage/backups',
    'storage/uploads' => ROOT_PATH . '/storage/uploads',
    'assets/uploads'  => ROOT_PATH . '/assets/uploads',
];
$dirStatus = [];
foreach ($dirs as $label => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $dirStatus[$label] = [
        'path'     => $path,
        'exists'   => $exists,
        'writable' => $writable,
    ];
}

// 4. Backups Inspection
$backupFiles = glob(STORAGE_PATH . '/backups/*.{gz,sql,zip}', GLOB_BRACE) ?: [];
rsort($backupFiles);
$latestBackup = !empty($backupFiles) ? $backupFiles[0] : null;
$latestBackupTime = $latestBackup ? date('Y-m-d H:i:s', (int)filemtime($latestBackup)) : null;
$latestBackupSize = $latestBackup ? format_filesize((int)filesize($latestBackup)) : null;

// 5. Reminders Run Inspection
$latestReminder = null;
try {
    $stRem = $pdo->query("SELECT created_at FROM activity_logs WHERE action IN ('cron.reminders', 'reminders.run') ORDER BY id DESC LIMIT 1");
    $latestReminder = $stRem->fetchColumn() ?: null;
} catch (Throwable) {}

// 6. Installed Lock & Public Setup Check
$lockFile = STORAGE_PATH . '/installed.lock';
$lockExists = is_file($lockFile);
$setupPath = ROOT_PATH . '/public/setup.php';
$setupExists = is_file($setupPath);

// Post-Install Health & Security Review Checks
$postInstallChecks = [];

// 1. Setup script check
$setupPath = ROOT_PATH . '/public/setup.php';
$setupExists = is_file($setupPath);
$postInstallChecks['setup_exists'] = [
    'title'    => 'Public Setup Script (public/setup.php)',
    'pass'     => !$setupExists,
    'status'   => $setupExists ? 'Vulnerable (Exists)' : 'Removed',
    'badge'    => $setupExists ? 'danger' : 'success',
    'message'  => $setupExists
        ? 'public/setup.php is still present on disk. An existing installer poses an administrative exposure risk.'
        : 'public/setup.php has been safely removed from disk.',
];

// 2. APP_ENV check
$isProduction = (defined('APP_ENV') && APP_ENV === 'production');
$postInstallChecks['app_env'] = [
    'title'    => 'Environment Mode (APP_ENV)',
    'pass'     => $isProduction,
    'status'   => $isProduction ? 'Production' : (defined('APP_ENV') ? APP_ENV : 'development'),
    'badge'    => $isProduction ? 'success' : 'warning',
    'message'  => $isProduction
        ? 'APP_ENV is configured as production. Error display is suppressed.'
        : 'APP_ENV is not set to production. Set APP_ENV=production in config/config.php for live hosting.',
];

// 3. HTTPS check
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$postInstallChecks['https'] = [
    'title'    => 'HTTPS / Transport Security',
    'pass'     => $isHttps,
    'status'   => $isHttps ? 'Enabled (Encrypted)' : 'Off (Plain HTTP)',
    'badge'    => $isHttps ? 'success' : 'warning',
    'message'  => $isHttps
        ? 'Traffic is encrypted with HTTPS. Secure cookie attributes are enforced.'
        : 'HTTPS is off. Sensitive login credentials and sessions are transmitted unencrypted.',
];

// 4. Default admin username check
$hasDefaultAdmin = false;
if ($dbConnected) {
    try {
        $stAdmin = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin' AND is_active = 1");
        $stAdmin->execute();
        $hasDefaultAdmin = ((int)$stAdmin->fetchColumn()) > 0;
    } catch (Throwable) {}
}
$postInstallChecks['default_admin'] = [
    'title'    => 'Default Administrator Account ("admin")',
    'pass'     => !$hasDefaultAdmin,
    'status'   => $hasDefaultAdmin ? 'Default Username "admin" Active' : 'Custom / Hardened',
    'badge'    => $hasDefaultAdmin ? 'warning' : 'success',
    'message'  => $hasDefaultAdmin
        ? 'Default admin username "admin" is active. Rename this account to protect against credential stuffing.'
        : 'Default username "admin" is not in use.',
];

// 5. Storage backups web accessibility self-request test
$backupsAccessible = false;
$backupsCheckNote = '';
$testUrl = url('storage/backups/');
if (!str_starts_with($testUrl, 'http://') && !str_starts_with($testUrl, 'https://')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $testUrl = $scheme . $host . $testUrl;
}

$ctx = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'timeout'       => 2.0,
        'ignore_errors' => true,
        'header'        => "User-Agent: AaZa-HealthCheck/1.0\r\nConnection: close\r\n",
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$headers = @get_headers($testUrl, true, $ctx);
if ($headers && isset($headers[0])) {
    $statusLine = is_array($headers[0]) ? end($headers[0]) : $headers[0];
    if (str_contains($statusLine, '200 OK')) {
        $backupsAccessible = true;
        $backupsCheckNote = 'Exposed! Direct HTTP GET returned 200 OK (' . htmlspecialchars($testUrl) . ')';
    } else {
        $backupsCheckNote = 'Protected (HTTP response: ' . htmlspecialchars(substr($statusLine, 9, 30)) . ')';
    }
} else {
    $backupsCheckNote = 'Protected (.htaccess blocks web access)';
}

$postInstallChecks['backups_access'] = [
    'title'    => 'Backup Storage Web Shielding',
    'pass'     => !$backupsAccessible,
    'status'   => $backupsAccessible ? 'Exposed to Web' : 'Blocked (403/Forbidden)',
    'badge'    => $backupsAccessible ? 'danger' : 'success',
    'message'  => $backupsAccessible
        ? 'CRITICAL: storage/backups is directly accessible via web request! Ensure Apache AllowOverride All and .htaccess rules are active.'
        : 'storage/backups is secured against unauthorized web downloads. ' . $backupsCheckNote,
];

// 6. Last backup age check
$backupOutdated = false;
$backupAgeDesc = 'No backups found';
if (!$latestBackup) {
    $backupOutdated = true;
} else {
    $backupMtime = (int)filemtime($latestBackup);
    $backupAgeDays = (time() - $backupMtime) / 86400;
    if ($backupAgeDays > 3.0) {
        $backupOutdated = true;
        $backupAgeDesc = number_format($backupAgeDays, 1) . ' days old';
    } else {
        $backupAgeDesc = number_format($backupAgeDays, 1) . ' days old';
    }
}

$postInstallChecks['backup_freshness'] = [
    'title'    => 'Backup Freshness (< 3 Days)',
    'pass'     => !$backupOutdated,
    'status'   => $backupOutdated ? 'Outdated or Missing' : 'Recent (' . $backupAgeDesc . ')',
    'badge'    => $backupOutdated ? 'warning' : 'success',
    'message'  => $backupOutdated
        ? 'Last database backup is older than 3 days or missing (' . $backupAgeDesc . '). Ensure daily cron runs php bin/backup.php.'
        : 'Recent database backup archive available (' . $backupAgeDesc . ').',
];

// 7. Schema Integrity & Tables Check
$expectedTables = [
    'activity_logs', 'attendance', 'candidates', 'candidate_applications',
    'candidate_documents', 'candidate_status_history', 'companies', 'company_holidays',
    'company_settings', 'company_user_assignments', 'departments', 'designations',
    'employees', 'employee_documents', 'employee_transfers', 'employment_history',
    'interviews', 'interview_evaluations', 'interview_panel', 'job_offers',
    'job_vacancies', 'leave_approvals', 'leave_requests', 'leave_types',
    'notifications', 'performance_goals', 'performance_reviews', 'permissions',
    'rate_limits', 'roles', 'role_permissions', 'system_settings', 'users',
    'user_permission_overrides'
];

$existingTables = [];
try {
    $rows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    $existingTables = array_map('strtolower', $rows);
} catch (Throwable) {}

$missingTables = array_diff($expectedTables, $existingTables);

function format_filesize(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid px-3 px-md-4 py-4">
    <!-- Breadcrumb & Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">System Health</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div>
            <h1 class="h3 fw-bold mb-1 text-body">
                <i class="bi bi-heart-pulse me-2 text-danger"></i>System Health &amp; Diagnostics
            </h1>
            <p class="text-muted small mb-0">Production readiness, environment inspection, and integrity checks.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/system/demo_data.php')) ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-database-gear me-1"></i> Demo Data Manager
            </a>
            <a href="<?= e(url('modules/system/health.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh Diagnostics
            </a>
        </div>
    </div>

    <?php if ($setupExists): ?>
        <div class="alert alert-danger d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4 shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-shield-exclamation fs-3"></i>
                <div>
                    <strong>CRITICAL SECURITY WARNING:</strong>
                    <div><code>public/setup.php</code> is present on disk. An existing installer poses an administrative exposure risk.</div>
                </div>
            </div>
            <form method="post" class="mb-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_setup">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete public/setup.php?');">
                    <i class="bi bi-trash3 me-1"></i> Delete setup.php Now
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (APP_DEBUG || (defined('APP_ENV') && APP_ENV !== 'production')): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle fs-4"></i>
            <div>
                <strong>Non-Production Configuration:</strong>
                <div>
                    <code>APP_ENV</code> is set to <strong><?= e(APP_ENV) ?></strong> and <code>APP_DEBUG</code> is <strong><?= APP_DEBUG ? 'true' : 'false' ?></strong>.
                    For live production deployments, ensure <code>APP_ENV=production</code> and <code>APP_DEBUG=false</code> in <code>config/config.php</code>.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Post-Install Warnings Summary -->
    <?php if (!$isHttps): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 shadow-sm" role="alert">
            <i class="bi bi-shield-slash fs-4 text-warning"></i>
            <div>
                <strong>HTTPS is Off:</strong>
                <div>Web traffic is currently unencrypted. For live hosting, install an SSL certificate and redirect all traffic to HTTPS.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hasDefaultAdmin): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 shadow-sm" role="alert">
            <i class="bi bi-person-exclamation fs-4 text-warning"></i>
            <div>
                <strong>Default Administrator Username Detected:</strong>
                <div>An active account with username <code>admin</code> exists. It is strongly recommended to rename this account to avoid brute force targeting.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($backupsAccessible): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3 shadow-sm" role="alert">
            <i class="bi bi-exclamation-octagon-fill fs-3 text-danger"></i>
            <div>
                <strong>CRITICAL SECURITY VULNERABILITY:</strong>
                <div><code>storage/backups</code> is web-accessible via direct HTTP requests. Confirm Apache <code>AllowOverride All</code> is enabled and .htaccess is honored.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($backupOutdated): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 shadow-sm" role="alert">
            <i class="bi bi-clock-history fs-4 text-warning"></i>
            <div>
                <strong>Backup Outdated or Missing:</strong>
                <div>The latest database backup is older than 3 days (or missing). Run <code>php bin/backup.php</code> or verify your automated cron schedule.</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Post-Install Hardening Checklist Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-0 pt-3 pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold mb-0 text-body">
                <i class="bi bi-shield-check me-2 text-primary"></i>Post-Install Security &amp; Operations Review
            </h5>
            <span class="badge bg-light text-secondary border">6 Automated Checks</span>
        </div>
        <div class="card-body">
            <div class="list-group list-group-flush border rounded-3">
                <?php foreach ($postInstallChecks as $chk): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                        <div class="me-3">
                            <span class="fw-semibold small text-body"><?= e($chk['title']) ?></span>
                            <div class="text-muted" style="font-size: 12px;"><?= e($chk['message']) ?></div>
                        </div>
                        <span class="badge bg-<?= e($chk['badge']) ?>-subtle text-<?= e($chk['badge']) ?> border border-<?= e($chk['badge']) ?>-subtle px-2 py-1 small text-nowrap">
                            <?= e($chk['status']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Diagnostic Cards Grid -->
    <div class="row g-4">
        <!-- 1. Database & Connection -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-body">
                        <i class="bi bi-database me-2 text-primary"></i>Database Connection
                    </h5>
                    <?php if ($dbConnected): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Connected</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Disconnected</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless small mb-0">
                        <tr>
                            <td class="text-secondary" style="width: 35%;">Engine / Host</td>
                            <td class="fw-medium font-monospace"><?= e(DB_HOST) ?>:<?= e(defined('DB_PORT') ? (string)DB_PORT : '3306') ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Database Name</td>
                            <td class="fw-medium font-monospace"><?= e(DB_NAME) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Server Version</td>
                            <td class="fw-medium"><?= e($dbVersion) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Session Timezone</td>
                            <td class="fw-medium font-monospace"><?= e($dbTimezone) ?> (Current: <?= e($dbTime) ?>)</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Schema Parity</td>
                            <td>
                                <?php if (empty($missingTables)): ?>
                                    <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>All 34 core tables present</span>
                                <?php else: ?>
                                    <span class="text-danger fw-semibold"><i class="bi bi-x-circle-fill me-1"></i>Missing tables: <?= e(implode(', ', $missingTables)) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2. PHP Environment & Resources -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-body">
                        <i class="bi bi-code-slash me-2 text-primary"></i>PHP Environment
                    </h5>
                    <span class="badge bg-<?= $phpPass ? 'success' : 'danger' ?>-subtle text-<?= $phpPass ? 'success' : 'danger' ?> border">
                        PHP <?= e($phpVersion) ?>
                    </span>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless small mb-0">
                        <tr>
                            <td class="text-secondary" style="width: 35%;">PHP SAPI</td>
                            <td class="fw-medium"><?= e(PHP_SAPI) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Configured Timezone</td>
                            <td class="fw-medium"><?= e(date_default_timezone_get()) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">upload_max_filesize</td>
                            <td class="fw-medium font-monospace"><?= e(ini_get('upload_max_filesize')) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">post_max_size</td>
                            <td class="fw-medium font-monospace"><?= e(ini_get('post_max_size')) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">memory_limit</td>
                            <td class="fw-medium font-monospace"><?= e(ini_get('memory_limit')) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">max_execution_time</td>
                            <td class="fw-medium font-monospace"><?= e(ini_get('max_execution_time')) ?>s</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. PHP Extensions Checklist -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0 text-body">
                        <i class="bi bi-puzzle me-2 text-primary"></i>Required Extensions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php foreach ($exts as $extName => $meta): ?>
                            <?php $loaded = extension_loaded($extName); ?>
                            <div class="col-sm-6">
                                <div class="p-2 border rounded-2 d-flex justify-content-between align-items-center">
                                    <span class="small fw-medium"><?= e($meta['name']) ?></span>
                                    <?php if ($loaded): ?>
                                        <i class="bi bi-check-circle-fill text-success fs-6"></i>
                                    <?php elseif (!$meta['req']): ?>
                                        <span class="badge bg-secondary-subtle text-secondary small">Optional</span>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger fs-6"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Storage & Directory Permissions -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0 text-body">
                        <i class="bi bi-folder-check me-2 text-primary"></i>Filesystem Permissions
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($dirStatus as $dName => $info): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                <div>
                                    <span class="fw-semibold">/<?= e($dName) ?></span>
                                </div>
                                <?php if ($info['writable']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Writable</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Not Writable</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 5. Operations: Backups & Scheduled Jobs -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0 text-body">
                        <i class="bi bi-clock-history me-2 text-primary"></i>Automated Tasks &amp; Operations
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3 border">
                                <div class="text-secondary small fw-medium mb-1">Latest Database Backup</div>
                                <div class="fw-bold text-dark fs-6">
                                    <?= $latestBackupTime ? e($latestBackupTime) : '<span class="text-muted">No backups found</span>' ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <?= $latestBackupSize ? 'Archive size: ' . e($latestBackupSize) : 'Run php bin/backup.php' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3 border">
                                <div class="text-secondary small fw-medium mb-1">Scheduled Reminders Cron</div>
                                <div class="fw-bold text-dark fs-6">
                                    <?= $latestReminder ? e($latestReminder) : '<span class="text-muted">Not yet logged</span>' ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    Target schedule: Daily at 07:00
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded-3 border">
                                <div class="text-secondary small fw-medium mb-1">Installation Lock</div>
                                <div class="fw-bold text-dark fs-6">
                                    <?php if ($lockExists): ?>
                                        <span class="text-success"><i class="bi bi-lock-fill me-1"></i>Installed &amp; Protected</span>
                                    <?php else: ?>
                                        <span class="text-warning"><i class="bi bi-unlock-fill me-1"></i>storage/installed.lock missing</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <?= $lockExists ? 'Created: ' . date('Y-m-d H:i:s', (int)filemtime($lockFile)) : 'System in setup mode' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
