<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Application Configuration Template.
 * Copy this file to config.php and update values for your environment.
 */

define('APP_NAME', 'AaZa HRMS');
define('APP_ENV', 'local');                 // 'local' or 'production'
define('APP_DEBUG', false);                  // Off by default so PHP warnings never print into the UI
define('APP_TIMEZONE', 'Asia/Colombo');      // System default timezone

// URL path relative to web root, no trailing slash.
// Auto-detects subfolder or root domain if not explicitly defined.
if (!defined('BASE_URL')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '') : '';
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $sub = trim(substr($appRoot, strlen($docRoot)), '/');
        define('BASE_URL', $sub !== '' ? '/' . $sub : '');
    } else {
        define('BASE_URL', '');
    }
}

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Session and authentication security
define('SESSION_NAME', 'AAZASESSID');
define('SESSION_IDLE_TIMEOUT', 1800);       // 30 minutes of inactivity before timeout
define('MAX_FAILED_LOGINS', 5);             // Lock after 5 consecutive failed attempts
define('LOCKOUT_MINUTES', 15);              // Lockout duration in minutes
define('PASSWORD_MIN_LENGTH', 10);          // Minimum required password length

define('PER_PAGE', 20);                     // Default pagination item count

// Attendance & Leave configurations
// 1 = Monday ... 6 = Saturday, 7 = Sunday (ISO-8601)
if (!defined('WEEKLY_OFF_DAYS')) {
    define('WEEKLY_OFF_DAYS', [6, 7]);
}
if (!defined('ATTENDANCE_SELF_SERVICE')) {
    define('ATTENDANCE_SELF_SERVICE', false); // Gate employee self check-in / check-out
}
if (!defined('ATTENDANCE_WORK_START')) {
    define('ATTENDANCE_WORK_START', '09:00'); // Standard workday start time
}
if (!defined('LEAVE_MAX_RANGE_DAYS')) {
    define('LEAVE_MAX_RANGE_DAYS', 90);       // Maximum leave request span in days
}

// Backup configurations
if (!defined('BACKUP_PATH')) {
    define('BACKUP_PATH', ROOT_PATH . '/storage/backups');
}
if (!defined('BACKUP_RETENTION_COUNT')) {
    define('BACKUP_RETENTION_COUNT', 14);     // Number of backup archives to keep
}

// Currency configuration
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', 'LKR');           // Default ISO currency code for financial displays
}

date_default_timezone_set('Asia/Colombo');
