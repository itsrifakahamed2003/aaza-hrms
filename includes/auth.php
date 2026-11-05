<?php
declare(strict_types=1);

/** Authentication: login, logout, session validation. */

/** Hash used to keep timing similar when the username does not exist. */
function dummy_hash(): string
{
    static $hash = null;
    return $hash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
}

/** The signed-in user (fresh from the database on every request) or null. */
function current_user(bool $reload = false): ?array
{
    static $loaded = false;
    static $user = null;
    if ($reload) {
        $loaded = false;
        $user = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    $st = db()->prepare(
        'SELECT id, username, email, full_name, is_group_admin, is_active, must_change_password FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    // A deactivated user loses access immediately, even with a live session.
    if (!$row || !$row['is_active']) {
        return null;
    }
    return $user = $row;
}

/** Drop all session data and issue a new session id. */
function reset_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function session_is_valid(): bool
{
    if (empty($_SESSION['uid'])) {
        return false;
    }
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
        return false;
    }
    $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!hash_equals((string)($_SESSION['ua'] ?? ''), $ua)) {
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/** A same-site path we may send the user back to after login. */
function safe_internal_path(string $path): bool
{
    return str_starts_with($path, BASE_URL . '/')
        && !str_contains($path, '//' )
        && !str_contains($path, '\\')
        && !preg_match('/[\r\n]/', $path);
}

function safe_return_path(string $raw, string $default): string
{
    return safe_internal_path(strtok($raw, '?') ?: '') ? $raw : $default;
}

function redirect_path(string $fullPath): void
{
    header('Location: ' . $fullPath);
    exit;
}

/** Put this at the top of every protected page. */
function require_login(): void
{
    if (session_is_valid() && ($u = current_user()) !== null) {
        if (!empty($u['must_change_password'])) {
            $self = (string)($_SERVER['SCRIPT_NAME'] ?? '');
            if (!str_contains($self, 'change_password.php') && !str_contains($self, 'logout.php')) {
                redirect('modules/auth/change_password.php');
            }
        }
        return;
    }
    $expired = !empty($_SESSION['uid']);
    reset_session();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (safe_internal_path(strtok($uri, '?') ?: '')) {
            $_SESSION['intended'] = $uri;
        }
    }
    if ($expired) {
        flash_set('warning', 'Your session ended. Please sign in again.');
    }
    redirect('public/login.php');
}

/**
 * Try to sign in. Returns [true, null] or [false, message].
 * The message is deliberately generic so it does not reveal which usernames exist.
 */
function attempt_login(string $login, string $password): array
{
    $generic = 'Invalid username or password, or the account is temporarily locked.';
    $login = trim($login);
    if ($login === '' || $password === '') {
        return [false, $generic];
    }

    $pdo = db();
    $ip = client_ip();

    // Check IP lockout (5 failures in 15 minutes)
    $ipLockSt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits 
          WHERE ip_address = ? AND action = 'login_failed' AND created_at >= NOW() - INTERVAL 15 MINUTE"
    );
    $ipLockSt->execute([$ip]);
    if ((int)$ipLockSt->fetchColumn() >= MAX_FAILED_LOGINS) {
        password_verify($password, dummy_hash());
        log_activity('auth.ip_locked', null, null, ['ip' => $ip]);
        return [false, $generic];
    }

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$login, $login]);
    $u = $st->fetch();

    if (!$u) {
        password_verify($password, dummy_hash());
        $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, 'login_failed', NOW())")->execute([$ip]);
        log_activity('auth.login_failed', null, null, ['login' => mb_substr($login, 0, 100), 'reason' => 'unknown_user']);
        return [false, $generic];
    }

    $uid    = (int)$u['id'];
    $locked = $u['locked_until'] && strtotime((string)$u['locked_until']) > time();

    if ($locked || !$u['is_active']) {
        password_verify($password, dummy_hash());
        $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, 'login_failed', NOW())")->execute([$ip]);
        log_activity('auth.login_failed', 'user', $uid, ['reason' => $locked ? 'locked' : 'inactive'], null, $uid);
        return [false, $generic];
    }

    if (!password_verify($password, (string)$u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, 'login_failed', NOW())")->execute([$ip]);
        if ($fails >= MAX_FAILED_LOGINS) {
            $until = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?')->execute([$until, $uid]);
            log_activity('auth.account_locked', 'user', $uid, ['until' => $until], null, $uid);
        } else {
            $pdo->prepare('UPDATE users SET failed_logins = ? WHERE id = ?')->execute([$fails, $uid]);
        }
        log_activity('auth.login_failed', 'user', $uid, ['reason' => 'bad_password'], null, $uid);
        return [false, $generic];
    }

    // Success: clear failed attempts for this IP
    $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = 'login_failed'")->execute([$ip]);

    // Success: upgrade the hash if PHP's default algorithm/cost changed
    if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
    }
    $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
        ->execute([client_ip(), $uid]);

    // New session id on login prevents session fixation
    $intended = $_SESSION['intended'] ?? null;
    reset_session();
    $_SESSION['uid']           = $uid;
    $_SESSION['ua']            = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['last_activity'] = time();
    if ($intended) {
        $_SESSION['intended'] = $intended;
    }

    log_activity('auth.login', 'user', $uid, null, null, $uid);
    return [true, null];
}

function logout_user(): void
{
    if (!empty($_SESSION['uid'])) {
        log_activity('auth.logout', 'user', (int)$_SESSION['uid']);
    }
    reset_session();
}
