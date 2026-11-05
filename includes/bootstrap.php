<?php
declare(strict_types=1);

/**
 * Loaded at the top of every page. Sets up config, error handling,
 * security headers, the secure session and all shared helpers.
 */

// Ensure configuration files exist before loading
if (!is_file(__DIR__ . '/../config/config.php') || !is_file(__DIR__ . '/../config/database.php')) {
    if (is_file(__DIR__ . '/../public/setup.php')) {
        header('Location: ../public/setup.php');
        exit;
    }
    http_response_code(500);
    die('System configuration files are missing. Please complete setup at public/setup.php.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Set configured timezone
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Colombo');

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Error reporting & production display suppression
$isProduction = (defined('APP_ENV') && APP_ENV === 'production') || !APP_DEBUG;
error_reporting(E_ALL);
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');

// Rotate log file if exceeding 5MB (keep up to 5 archives: app.log.1 ... app.log.5)
$logPath = STORAGE_PATH . '/logs/app.log';
if (is_file($logPath) && @filesize($logPath) >= 5242880) {
    for ($i = 4; $i >= 1; $i--) {
        $source = "{$logPath}.{$i}";
        $dest = "{$logPath}." . ($i + 1);
        if (is_file($source)) {
            @rename($source, $dest);
        }
    }
    @rename($logPath, "{$logPath}.1");
}
ini_set('error_log', $logPath);

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log("PHP Error [$severity]: $message in $file on line $line");
    if (APP_DEBUG && (!defined('APP_ENV') || APP_ENV !== 'production')) {
        return false;
    }
    return true; // Suppress display in production
});

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/recruitment.php';
require_once __DIR__ . '/offers.php';
require_once __DIR__ . '/hr_ops.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/companies.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/organization.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/performance.php';

set_exception_handler(function (Throwable $e): void {
    $refId = 'ERR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log("[$refId] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (APP_DEBUG && (!defined('APP_ENV') || APP_ENV !== 'production')) {
        http_response_code(500);
        echo '<div style="font-family:sans-serif;padding:24px;max-width:800px;margin:40px auto;border:1px solid #dc3545;border-radius:8px;background:#fff8f8;">';
        echo '<h3 style="color:#dc3545;margin-top:0;">Development Error Trace [' . e($refId) . ']</h3>';
        echo '<pre style="white-space:pre-wrap;background:#f8f9fa;padding:12px;border:1px solid #dee2e6;border-radius:4px;font-size:13px;">'
            . e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
        echo '</div>';
        exit;
    }
    abort(500, 'Something went wrong. Please quote reference ID ' . $refId . ' when contacting system support.');
});

// Security Headers
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self';");

start_secure_session();
