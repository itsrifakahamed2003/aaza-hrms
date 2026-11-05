<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Company Domain Logic & Helpers
 * Handles company logo validation/storage, deletion safety checks, and presentation.
 */

/**
 * Validates a company logo upload.
 * Validates: upload errors, file size (max 2 MB), finfo MIME type, and getimagesize.
 *
 * @param array $file $_FILES item
 * @return array{0: bool, 1: string|array{tmp_name: string, ext: string, mime: string, size: int}}
 */
function validate_company_logo(array $file): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $msg = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Company logo exceeds the maximum file size limit (2 MB).',
            UPLOAD_ERR_PARTIAL   => 'The logo upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'No logo file was uploaded.',
            default              => 'Failed to upload logo file (error code: ' . $err . ').',
        };
        return [false, $msg];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && php_sapi_name() !== 'cli')) {
        return [false, 'Invalid file upload source.'];
    }

    $fileSize = (int)($file['size'] ?? 0);
    $maxBytes = 2 * 1024 * 1024; // 2 MB
    if ($fileSize <= 0 || $fileSize > $maxBytes) {
        return [false, 'Company logo must not exceed 2 MB.'];
    }

    // 1. Validate real MIME type via Fileinfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpPath);

    $allowedMimes = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mime])) {
        return [false, 'Invalid file format. Only PNG, JPG/JPEG, and WebP images are allowed.'];
    }

    // 2. Validate image structure via getimagesize
    $imgInfo = @getimagesize($tmpPath);
    if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
        return [false, 'The uploaded file is not a valid image.'];
    }

    $validTypes = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
    if (!in_array($imgInfo[2], $validTypes, true)) {
        return [false, 'Unsupported image type. Please provide a valid PNG, JPG, or WebP image.'];
    }

    $ext = match ($imgInfo[2]) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default        => $allowedMimes[$mime],
    };

    return [
        true,
        [
            'tmp_name' => $tmpPath,
            'ext'      => $ext,
            'mime'     => $mime,
            'size'     => $fileSize,
        ],
    ];
}

/**
 * Saves a validated company logo and cleans up any old logo file.
 *
 * @param array $validFile Result data from validate_company_logo
 * @param string|null $oldPath Previous logo_path from database
 * @return string Relative path stored in database (e.g. assets/uploads/company_logos/logo_xxx.png)
 */
function save_company_logo(array $validFile, ?string $oldPath = null): string
{
    $dir = ROOT_PATH . '/assets/uploads/company_logos';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $randName = 'logo_' . bin2hex(random_bytes(16)) . '.' . $validFile['ext'];
    $destPath = $dir . '/' . $randName;

    $success = false;
    if (is_uploaded_file($validFile['tmp_name'])) {
        $success = move_uploaded_file($validFile['tmp_name'], $destPath);
    } elseif (php_sapi_name() === 'cli' && file_exists($validFile['tmp_name'])) {
        $success = copy($validFile['tmp_name'], $destPath) || rename($validFile['tmp_name'], $destPath);
    }

    if (!$success) {
        throw new RuntimeException('Failed to move uploaded logo file to destination.');
    }

    // Remove old logo if it exists and is located in the company_logos directory
    remove_company_logo($oldPath);

    return 'assets/uploads/company_logos/' . $randName;
}

/**
 * Safely removes a logo file from disk.
 *
 * @param string|null $path Relative path from DB
 */
function remove_company_logo(?string $path): void
{
    if (empty($path)) {
        return;
    }
    $cleanPath = str_replace(['\\', '..'], ['/', ''], $path);
    if (!str_starts_with($cleanPath, 'assets/uploads/company_logos/')) {
        return;
    }
    $fullPath = ROOT_PATH . '/' . $cleanPath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/**
 * Renders HTML for a company logo or an initials avatar circle fallback.
 *
 * @param array{logo_path?: ?string, name?: ?string, code?: ?string}|string|null $company Company data or logo path
 * @param string $fallbackName Company name or code for initials
 * @param string $size 'sm' (28px), 'md' (40px), or 'lg' (64px)
 * @param string $extraClass Optional additional CSS classes
 */
function company_logo_html(
    array|string|null $company,
    string $fallbackName = '',
    string $size = 'md',
    string $extraClass = ''
): string {
    $logoPath = null;
    $name = $fallbackName;
    $code = '';

    if (is_array($company)) {
        $logoPath = $company['logo_path'] ?? null;
        $name = (string)($company['name'] ?? $fallbackName);
        $code = (string)($company['code'] ?? '');
    } elseif (is_string($company)) {
        $logoPath = $company;
    }

    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    if (!empty($logoPath)) {
        $fullPath = ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
        if (is_file($fullPath)) {
            return '<img src="' . e(url($logoPath)) . '" alt="' . e($name) . '" class="company-logo-' . e($size) . ' ' . e($extraClass) . '" />';
        }
    }

    // Fallback: Initials circle
    $initials = '';
    if ($code !== '') {
        $initials = mb_substr($code, 0, 2);
    } else {
        $words = preg_split('/\s+/', trim($name));
        if (!empty($words[0])) {
            $initials .= mb_substr($words[0], 0, 1);
        }
        if (!empty($words[1])) {
            $initials .= mb_substr($words[1], 0, 1);
        }
        if ($initials === '') {
            $initials = mb_substr($name, 0, 2);
        }
    }
    $initials = strtoupper($initials ?: 'CO');

    return '<div class="company-logo-initials-' . e($size) . ' ' . e($extraClass) . '">' . e($initials) . '</div>';
}

/**
 * Safety check: Determines if a company can be permanently deleted.
 * Checks whether the company is inactive and whether any related domain data exists.
 *
 * @param int $companyId
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, company: ?array, counts: array<string, int>}
 */
function check_company_deletable(int $companyId, PDO $pdo): array
{
    $st = $pdo->prepare('SELECT id, code, name, is_active, logo_path FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        return [
            'can_delete' => false,
            'reason'     => 'Company not found.',
            'company'    => null,
            'counts'     => [],
        ];
    }

    // 1. Must be deactivated first
    if ((int)$company['is_active'] !== 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Active companies cannot be deleted. You must deactivate the company first.',
            'company'    => $company,
            'counts'     => [],
        ];
    }

    // 2. Count all dependent domain data
    $queries = [
        'employees'             => 'SELECT COUNT(*) FROM employees WHERE company_id = ?',
        'vacancies'             => 'SELECT COUNT(*) FROM job_vacancies WHERE company_id = ?',
        'applications'          => 'SELECT COUNT(*) FROM candidate_applications WHERE company_id = ?',
        'candidates'            => 'SELECT COUNT(DISTINCT candidate_id) FROM candidate_applications WHERE company_id = ?',
        'interviews'            => 'SELECT COUNT(*) FROM interviews WHERE company_id = ?',
        'offers'                => 'SELECT COUNT(*) FROM job_offers WHERE company_id = ?',
        'leave requests'        => 'SELECT COUNT(*) FROM leave_requests WHERE company_id = ?',
        'attendance records'    => 'SELECT COUNT(*) FROM attendance WHERE company_id = ?',
        'performance reviews'   => 'SELECT COUNT(*) FROM performance_reviews WHERE company_id = ?',
        'notifications'         => 'SELECT COUNT(*) FROM notifications WHERE company_id = ?',
        'user assignments'      => 'SELECT COUNT(*) FROM company_user_assignments WHERE company_id = ?',
        'departments'           => 'SELECT COUNT(*) FROM departments WHERE company_id = ?',
        'designations'          => 'SELECT COUNT(*) FROM designations WHERE company_id = ?',
        'employee documents'    => 'SELECT COUNT(*) FROM employee_documents WHERE company_id = ?',
        'employment history'    => 'SELECT COUNT(*) FROM employment_history WHERE company_id = ?',
    ];

    $counts = [];
    $blocking = [];

    foreach ($queries as $label => $sql) {
        try {
            $chk = $pdo->prepare($sql);
            $chk->execute([$companyId]);
            $num = (int)$chk->fetchColumn();
            $counts[$label] = $num;
            if ($num > 0) {
                $blocking[] = $num . ' ' . ($num === 1 ? rtrim($label, 's') : $label);
            }
        } catch (Throwable $e) {
            error_log("Failed checking company deletion dependencies ({$label}): " . $e->getMessage());
            return [
                'can_delete' => false,
                'reason'     => 'Could not verify dependent data.',
                'company'    => $company,
                'counts'     => $counts,
            ];
        }
    }

    // Check transfers
    try {
        $chkTrans = $pdo->prepare('SELECT COUNT(*) FROM employee_transfers WHERE from_company_id = ? OR to_company_id = ?');
        $chkTrans->execute([$companyId, $companyId]);
        $numTrans = (int)$chkTrans->fetchColumn();
        if ($numTrans > 0) {
            $counts['transfers'] = $numTrans;
            $blocking[] = $numTrans . ' transfer record' . ($numTrans === 1 ? '' : 's');
        }
    } catch (Throwable $e) {
        error_log("Failed checking company deletion dependencies (transfers): " . $e->getMessage());
        return [
            'can_delete' => false,
            'reason'     => 'Could not verify dependent data.',
            'company'    => $company,
            'counts'     => $counts,
        ];
    }

    if (!empty($blocking)) {
        $reason = 'This company has ' . implode(', ', $blocking) . '. Cannot delete. Keep it deactivated instead.';
        return [
            'can_delete' => false,
            'reason'     => $reason,
            'company'    => $company,
            'counts'     => $counts,
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'company'    => $company,
        'counts'     => $counts,
    ];
}

/**
 * Permanently deletes a deactivated company with no dependent data inside a transaction.
 * Removes its logo file and logs the deletion to activity_logs.
 *
 * @param int $companyId
 * @param PDO $pdo
 * @return array{success: bool, message: string}
 */
function delete_company(int $companyId, PDO $pdo): array
{
    $check = check_company_deletable($companyId, $pdo);
    if (!$check['can_delete']) {
        return ['success' => false, 'message' => $check['reason']];
    }

    $company = $check['company'];
    $code = (string)$company['code'];
    $name = (string)$company['name'];
    $logoPath = $company['logo_path'] ?? null;

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Clean up empty leaf settings or configuration rows
        $pdo->prepare('DELETE FROM company_settings WHERE company_id = ?')->execute([$companyId]);
        $pdo->prepare('DELETE FROM company_holidays WHERE company_id = ?')->execute([$companyId]);

        try {
            $pdo->prepare('DELETE FROM user_permission_overrides WHERE company_id = ?')->execute([$companyId]);
        } catch (Throwable) {}

        try {
            $pdo->prepare('DELETE FROM leave_types WHERE company_id = ?')->execute([$companyId]);
        } catch (Throwable) {}

        // Nullify company_id in activity_logs so historical audit records remain intact
        try {
            $pdo->prepare('UPDATE activity_logs SET company_id = NULL WHERE company_id = ?')->execute([$companyId]);
        } catch (Throwable) {}

        // Delete the company record
        $del = $pdo->prepare('DELETE FROM companies WHERE id = ?');
        $del->execute([$companyId]);

        // Remove the logo file from disk if present
        if (!empty($logoPath)) {
            remove_company_logo((string)$logoPath);
        }

        // Log activity
        log_activity('company.delete', 'company', $companyId, [
            'code' => $code,
            'name' => $name,
        ], null);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => 'Company "' . $name . '" (' . $code . ') was permanently deleted.',
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete company {$companyId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Cannot delete company due to existing database relationships. Please keep it deactivated instead.',
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting company {$companyId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the company: ' . $e->getMessage(),
        ];
    }
}

// ============================================================================
// COMPANY SETTINGS HELPERS & DICTIONARY
// ============================================================================

/**
 * Internal reference to in-memory company settings cache.
 */
function &company_settings_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Invalidate or clear company settings cache.
 */
function clear_company_settings_cache(?int $companyId = null): void
{
    $cache = &company_settings_cache();
    if ($companyId === null) {
        $cache = [];
    } else {
        unset($cache[$companyId]);
    }
}

/**
 * Retrieve a company setting with per-request in-memory cache and default fallback.
 *
 * @param PDO    $pdo
 * @param int    $companyId
 * @param string $key
 * @param mixed  $default Fallback value if setting is not present.
 * @return mixed The setting value (string) or $default.
 */
function get_company_setting(PDO $pdo, int $companyId, string $key, mixed $default = null): mixed
{
    if ($companyId <= 0) {
        return $default;
    }

    $cache = &company_settings_cache();
    if (isset($cache[$companyId]) && array_key_exists($key, $cache[$companyId])) {
        return $cache[$companyId][$key] ?? $default;
    }

    try {
        $st = $pdo->prepare('SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1');
        $st->execute([$companyId, $key]);
        $val = $st->fetchColumn();

        if (!isset($cache[$companyId])) {
            $cache[$companyId] = [];
        }

        if ($val !== false && $val !== null) {
            $cache[$companyId][$key] = (string)$val;
            return $cache[$companyId][$key];
        }

        $cache[$companyId][$key] = null;
        return $default;
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Save or update a company setting and update in-memory cache.
 */
function set_company_setting(PDO $pdo, int $companyId, string $key, ?string $value): void
{
    if ($companyId <= 0) {
        return;
    }

    $st = $pdo->prepare(
        'INSERT INTO company_settings (company_id, setting_key, setting_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $st->execute([$companyId, $key, $value]);

    $cache = &company_settings_cache();
    if (!isset($cache[$companyId])) {
        $cache[$companyId] = [];
    }
    $cache[$companyId][$key] = $value;
}

/**
 * Retrieve all custom settings saved for a company.
 */
function get_all_company_settings(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $st = $pdo->prepare('SELECT setting_key, setting_value FROM company_settings WHERE company_id = ?');
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    $cache = &company_settings_cache();
    if (!isset($cache[$companyId])) {
        $cache[$companyId] = [];
    }
    foreach ($rows as $k => $v) {
        $cache[$companyId][$k] = $v;
    }

    return $rows ?: [];
}

/**
 * Reset all settings for a company back to system defaults.
 */
function reset_company_settings(PDO $pdo, int $companyId): int
{
    if ($companyId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('DELETE FROM company_settings WHERE company_id = ?');
    $st->execute([$companyId]);
    clear_company_settings_cache($companyId);
    return $st->rowCount();
}

/**
 * Return definitions of all company settings grouped by tabs.
 */
function company_settings_definitions(int $companyId = 0, ?array $company = null): array
{
    $defaultCurrency = defined('CURRENCY_CODE') ? (string)CURRENCY_CODE : 'LKR';
    $defaultWorkStart = defined('ATTENDANCE_WORK_START') ? (string)ATTENDANCE_WORK_START : '09:00';
    $defaultOffDays = defined('WEEKLY_OFF_DAYS') ? json_encode(WEEKLY_OFF_DAYS) : json_encode([6, 7]);
    $defaultMaxRange = defined('LEAVE_MAX_RANGE_DAYS') ? (string)LEAVE_MAX_RANGE_DAYS : '90';
    $defaultEmpPrefix = !empty($company['code']) ? (string)$company['code'] : 'EMP';

    return [
        // Tab 1: General
        'general.currency_code' => [
            'tab'         => 'general',
            'label'       => 'Currency Code',
            'type'        => 'text',
            'default'     => $defaultCurrency,
            'help'        => 'ISO 3-letter currency code for financial displays (e.g. LKR, USD, EUR, GBP, AED).',
            'placeholder' => 'LKR',
        ],
        'general.date_format' => [
            'tab'         => 'general',
            'label'       => 'Display Date Format',
            'type'        => 'select',
            'default'     => 'd M Y',
            'help'        => 'Standard date format displayed across modules and printed reports.',
            'options'     => [
                'd M Y' => '29 Oct 2026 (d M Y)',
                'Y-m-d' => '2026-10-29 (Y-m-d)',
                'd/m/Y' => '29/10/2026 (d/m/Y)',
                'm/d/Y' => '10/29/2026 (m/d/Y)',
            ],
        ],
        'general.timezone' => [
            'tab'         => 'general',
            'label'       => 'Time Zone',
            'type'        => 'select',
            'default'     => 'Asia/Colombo',
            'help'        => 'Company local operational timezone.',
            'options'     => [
                'Asia/Colombo'   => 'Asia/Colombo (UTC+5:30)',
                'Asia/Kolkata'   => 'Asia/Kolkata (UTC+5:30)',
                'Asia/Dubai'     => 'Asia/Dubai (UTC+4:00)',
                'Asia/Singapore' => 'Asia/Singapore (UTC+8:00)',
                'Asia/Bangkok'   => 'Asia/Bangkok (UTC+7:00)',
                'Europe/London'  => 'Europe/London (GMT/BST)',
                'America/New_York' => 'America/New_York (EST/EDT)',
                'UTC'            => 'UTC (Coordinated Universal Time)',
            ],
        ],
        'general.fiscal_year_start' => [
            'tab'         => 'general',
            'label'       => 'Financial Year Start',
            'type'        => 'select',
            'default'     => '01-01',
            'help'        => 'Month and day marking the start of the corporate financial year.',
            'options'     => [
                '01-01' => 'January 1st (Calendar Year)',
                '04-01' => 'April 1st (Q2 Start)',
                '07-01' => 'July 1st (Mid Year)',
                '10-01' => 'October 1st (Q4 Start)',
            ],
        ],

        // Tab 2: Work & Attendance
        'attendance.weekly_off_days' => [
            'tab'         => 'attendance',
            'label'       => 'Weekly Off Days',
            'type'        => 'weekly_days',
            'default'     => $defaultOffDays,
            'help'        => 'Non-working days excluded from working day and leave calculations.',
            'options'     => [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ],
        ],
        'attendance.work_start_time' => [
            'tab'         => 'attendance',
            'label'       => 'Standard Work Start Time',
            'type'        => 'time',
            'default'     => $defaultWorkStart,
            'help'        => 'Standard morning shift start time (HH:MM) used for late arrival evaluation.',
        ],
        'attendance.late_grace_minutes' => [
            'tab'         => 'attendance',
            'label'       => 'Late Grace Minutes',
            'type'        => 'number',
            'default'     => '15',
            'min'         => 0,
            'max'         => 120,
            'help'        => 'Grace period in minutes after work start time before an attendance status is marked Late.',
        ],
        'attendance.half_day_hours' => [
            'tab'         => 'attendance',
            'label'       => 'Half-Day Minimum Hours',
            'type'        => 'number',
            'step'        => '0.5',
            'default'     => '4.0',
            'min'         => 1,
            'max'         => 8,
            'help'        => 'Minimum hours worked required to qualify for half-day attendance credit.',
        ],

        // Tab 3: Leave
        'leave.max_request_range_days' => [
            'tab'         => 'leave',
            'label'       => 'Maximum Request Span (Days)',
            'type'        => 'number',
            'default'     => $defaultMaxRange,
            'min'         => 1,
            'max'         => 365,
            'help'        => 'Maximum allowable calendar day range in a single leave submission.',
        ],
        'leave.allow_backdated_days' => [
            'tab'         => 'leave',
            'label'       => 'Allow Back-Dated Leave (Days)',
            'type'        => 'number',
            'default'     => '0',
            'min'         => 0,
            'max'         => 180,
            'help'        => 'Number of days in the past employees may request leave for (0 disallows past dates).',
        ],
        'leave.carry_forward_enabled' => [
            'tab'         => 'leave',
            'label'       => 'Annual Leave Carry Forward',
            'type'        => 'select',
            'default'     => '0',
            'help'        => 'Whether remaining unused annual leave balances can be rolled into the subsequent year.',
            'options'     => [
                '0' => 'Disabled (Use It or Lose It)',
                '1' => 'Enabled (Carry forward unspent allowance)',
            ],
        ],

        // Tab 4: Recruitment
        'recruitment.app_prefix' => [
            'tab'         => 'recruitment',
            'label'       => 'Application Number Prefix',
            'type'        => 'text',
            'default'     => 'APP',
            'help'        => 'Prefix for candidate application tracking numbers (e.g. APP, AAPP).',
        ],
        'recruitment.interview_score_scale' => [
            'tab'         => 'recruitment',
            'label'       => 'Interview Score Scale',
            'type'        => 'select',
            'default'     => '5',
            'help'        => 'Maximum rating scale for technical, communication, and attitude scorecards.',
            'options'     => [
                '5'  => '1 to 5 (Standard)',
                '10' => '1 to 10 (Granular)',
            ],
        ],
        'recruitment.offer_validity_days' => [
            'tab'         => 'recruitment',
            'label'       => 'Default Offer Validity (Days)',
            'type'        => 'number',
            'default'     => '14',
            'min'         => 1,
            'max'         => 90,
            'help'        => 'Default calendar days before sent job offers automatically expire.',
        ],

        // Tab 5: Employee
        'employee.code_prefix' => [
            'tab'         => 'employee',
            'label'       => 'Employee Code Prefix',
            'type'        => 'text',
            'default'     => $defaultEmpPrefix,
            'help'        => 'Prefix used when auto-generating new employee numbers (e.g. AZT, EMP).',
        ],
        'employee.code_padding' => [
            'tab'         => 'employee',
            'label'       => 'Code Numeric Padding',
            'type'        => 'number',
            'default'     => '4',
            'min'         => 3,
            'max'         => 8,
            'help'        => 'Number of zero-padded digits (e.g. 4 produces EMP-0001, 5 produces EMP-00001).',
        ],

        // Tab 6: Notifications
        'notifications.enabled_types' => [
            'tab'         => 'notifications',
            'label'       => 'Active Notification Channels',
            'type'        => 'notif_types',
            'default'     => json_encode(['leave', 'recruitment', 'interview', 'offer', 'security', 'document']),
            'help'        => 'Toggle domain notification events dispatched for this company.',
            'options'     => [
                'leave'       => 'Leave requests, approvals & cancellations',
                'recruitment' => 'New applications & candidate pipeline changes',
                'interview'   => 'Interview scheduling & evaluation submissions',
                'offer'       => 'Job offers sent, accepted, declined & converted',
                'document'    => 'Employee document expiry alerts',
                'security'    => 'Password resets & account status changes',
            ],
        ],
        'notifications.reminder_lead_days' => [
            'tab'         => 'notifications',
            'label'       => 'Reminder Lead Time (Days)',
            'type'        => 'number',
            'default'     => '2',
            'min'         => 1,
            'max'         => 7,
            'help'        => 'Lead days ahead of upcoming interviews and vacancy closing deadlines.',
        ],
        'notifications.doc_expiry_lead_days' => [
            'tab'         => 'notifications',
            'label'       => 'Document Expiry Warning Lead (Days)',
            'type'        => 'number',
            'default'     => '30',
            'min'         => 7,
            'max'         => 90,
            'help'        => 'Lead days ahead of employee document expiry dates to send warning reminders.',
        ],
    ];
}
