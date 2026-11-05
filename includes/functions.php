<?php
declare(strict_types=1);

/** HTML-escape any value for output. Use on every dynamic value in views. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an internal URL taking auto-detected BASE_URL into account */
function url(string $path = ''): string
{
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $cleanPath = '/' . ltrim($path, '/');
    if ($cleanPath === '/') {
        return $base !== '' ? $base : '/';
    }
    // Prevent double-prefixing if path already starts with base
    if ($base !== '' && (str_starts_with($cleanPath, $base . '/') || $cleanPath === $base)) {
        return $cleanPath;
    }
    return $base . $cleanPath;
}

/**
 * Return an asset URL with ?v=<mtime> cache busting for CSS and JS assets.
 */
function asset_url(string $path): string
{
    $cleanPath = ltrim($path, '/');
    $filePath = defined('ROOT_PATH') ? ROOT_PATH . '/' . $cleanPath : dirname(__DIR__) . '/' . $cleanPath;
    $v = is_file($filePath) ? (string)filemtime($filePath) : '1';
    return url($cleanPath) . '?v=' . $v;
}

/** Redirect to an internal path and stop. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** One-time messages shown after a redirect. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function flash_get(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Only REMOTE_ADDR is trusted; forwarded headers can be spoofed. */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function current_user_id(): int
{
    return (int)(current_user()['id'] ?? 0);
}

/** Stop the request with a simple error page. */
function abort(int $code, string $message = ''): void
{
    http_response_code($code);
    $titles = [403 => 'Access denied', 404 => 'Not found', 419 => 'Page expired', 500 => 'Server error'];
    $title = $titles[$code] ?? 'Error';
    if ($message === '' && $code === 403) {
        $message = 'You do not have permission to view this page.';
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | ' . e(APP_NAME) . '</title>'
        . '<link rel="icon" type="image/png" href="' . e(url('assets/img/favicon.png')) . '">'
        . '<link rel="stylesheet" href="' . e(url('assets/vendor/inter/inter.css')) . '">'
        . '<link rel="stylesheet" href="' . e(url('assets/vendor/bootstrap/bootstrap.min.css')) . '">'
        . '<link rel="stylesheet" href="' . e(url('assets/css/tokens.css')) . '">'
        . '</head><body class="bg-body-tertiary"><div class="container py-5" style="max-width:560px">'
        . '<div class="text-center mb-3"><img src="' . e(url('assets/img/aaza-logo.png')) . '" alt="' . e(APP_NAME) . '" style="max-height:48px;object-fit:contain;"></div>'
        . '<div class="card shadow-sm"><div class="card-body text-center p-4">'
        . '<h1 class="h3 mb-2">' . e((string)$code) . ' &middot; ' . e($title) . '</h1>'
        . '<p class="text-muted">' . e($message) . '</p>'
        . '<a class="btn btn-primary" href="' . e(url('public/index.php')) . '">Back to dashboard</a>'
        . '</div></div></div></body></html>';
    exit;
}

/** Returns an error message if the password is too weak, otherwise null. */
function password_policy_error(string $password, ?string $username = null, ?string $email = null): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if ($username !== null && trim($username) !== '') {
        if (strcasecmp(trim($password), trim($username)) === 0) {
            return 'Password cannot be the same as your username.';
        }
    }
    if ($email !== null && trim($email) !== '') {
        if (strcasecmp(trim($password), trim($email)) === 0) {
            return 'Password cannot be the same as your email address.';
        }
    }
    return null;
}

/** Previously submitted form value, for redisplay after a validation error. */
function old(string $key, mixed $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/** Page window math for LIMIT/OFFSET queries. */
function paginate(int $total, int $page, int $perPage = PER_PAGE): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    return [
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
    ];
}

function render_pagination(array $pg): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $query = $_GET;
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($query): string {
        $query['page'] = $p;
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="?' . e(http_build_query($query)) . '">'
            . $label . '</a></li>';
    };
    $out = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $out .= $link(max(1, $pg['page'] - 1), '&laquo;', false, $pg['page'] === 1);
    for ($i = max(1, $pg['page'] - 2); $i <= min($pg['pages'], $pg['page'] + 2); $i++) {
        $out .= $link($i, (string)$i, $i === $pg['page']);
    }
    $out .= $link(min($pg['pages'], $pg['page'] + 1), '&raquo;', false, $pg['page'] === $pg['pages']);
    return $out . '</ul></nav>';
}

/**
 * Strict Y-m-d date parser. Returns 'YYYY-MM-DD' on strict match without rollover/relative text, or null.
 */
function parse_ymd(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if ($d && $d->format('Y-m-d') === $s) {
        return $s;
    }
    return null;
}

/**
 * Strict Y-m year-month parser. Returns 'YYYY-MM' on strict match or null.
 */
function parse_ym(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m', $s);
    if ($d && $d->format('Y-m') === $s) {
        return $s;
    }
    return null;
}

/**
 * Retrieve a system setting from system_settings table.
 */
function get_system_setting(string $key, ?string $default = null): ?string
{
    try {
        $st = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $val = $st->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : $default;
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Save or update a system setting in system_settings table.
 */
function set_system_setting(string $key, ?string $value): void
{
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $st->execute([$key, $value]);
}

/**
 * Resolves the active login page logo details, respecting the fallback hierarchy:
 * 1. If "branding.login_use_sidebar" is enabled ('1'):
 *    -> Uses sidebar logo (get_system_setting('app_logo')) if file exists
 * 2. Else if custom login logo is set (get_system_setting('branding.login_logo')) and file exists:
 *    -> Uses custom login logo
 * 3. Fallback:
 *    -> If "branding.login_use_sidebar" is '1', sidebar logo if exists, else default icon
 *    -> Else default 'assets/img/aaza-icon.png'
 *
 * Appends cache-busting query parameter ?v=<mtime>.
 *
 * @return array{path: string, url: string, mtime: int, is_custom: bool, is_sidebar: bool, use_sidebar_setting: bool}
 */
function get_login_logo_data(): array
{
    $useSidebar = (get_system_setting('branding.login_use_sidebar', '0') === '1');
    $sidebarLogo = get_system_setting('app_logo');
    $hasSidebarLogo = (!empty($sidebarLogo) && file_exists(ROOT_PATH . '/' . $sidebarLogo));

    $customLoginLogo = get_system_setting('branding.login_logo');
    $hasCustomLoginLogo = (!empty($customLoginLogo) && file_exists(ROOT_PATH . '/' . $customLoginLogo));

    $resolvedPath = null;
    $isCustom = false;
    $isSidebar = false;

    if ($useSidebar) {
        if ($hasSidebarLogo) {
            $resolvedPath = $sidebarLogo;
            $isSidebar = true;
        }
    } else {
        if ($hasCustomLoginLogo) {
            $resolvedPath = $customLoginLogo;
            $isCustom = true;
        }
    }

    if ($resolvedPath === null) {
        if ($useSidebar && $hasSidebarLogo) {
            $resolvedPath = $sidebarLogo;
            $isSidebar = true;
        } else {
            $defaultLoginLogo = file_exists(ROOT_PATH . '/assets/img/aaza-logo-transparent.png')
                ? 'assets/img/aaza-logo-transparent.png'
                : 'assets/img/aaza-icon.png';
            $resolvedPath = $defaultLoginLogo;
        }
    }

    $fullPath = ROOT_PATH . '/' . $resolvedPath;
    $mtime = file_exists($fullPath) ? (int)filemtime($fullPath) : time();
    $url = url($resolvedPath) . '?v=' . $mtime;

    return [
        'path' => $resolvedPath,
        'url' => $url,
        'mtime' => $mtime,
        'is_custom' => $isCustom,
        'is_sidebar' => $isSidebar,
        'use_sidebar_setting' => $useSidebar,
    ];
}

/**
 * Escape LIKE special characters (%, _, \) for safe SQL LIKE queries.
 */
function escape_like(string $s): string
{
    return addcslashes($s, '%_\\');
}

/**
 * Rate limit check using rate_limits table.
 * Returns true if allowed, false if limit exceeded.
 *
 * @param string $action Identifier for the action (e.g. 'search')
 * @param int $maxAttempts Maximum permitted requests within window
 * @param int $windowSeconds Time window in seconds (default 60)
 * @param int|string|null $identifier User ID or IP address (defaults to current user or client_ip)
 */
function check_rate_limit(string $action, int $maxAttempts = 60, int $windowSeconds = 60, int|string|null $identifier = null): bool
{
    $pdo = db();
    $userId = current_user_id();
    $key = (string)($identifier ?? ($userId > 0 ? "user:{$userId}" : client_ip()));
    $key = mb_substr($key, 0, 45);
    $seconds = max(1, $windowSeconds);

    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_limits
              WHERE ip_address = ? AND action = ? AND created_at >= DATE_SUB(NOW(), INTERVAL $seconds SECOND)"
        );
        $st->execute([$key, $action]);
        $count = (int)$st->fetchColumn();
        if ($count >= $maxAttempts) {
            return false;
        }

        $ins = $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, ?, NOW())");
        $ins->execute([$key, $action]);
        return true;
    } catch (Throwable) {
        // Fail open if table error so normal user navigation is not broken
        return true;
    }
}


