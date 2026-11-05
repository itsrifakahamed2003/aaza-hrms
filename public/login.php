<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in? Go to the dashboard.
if (session_is_valid() && current_user() !== null) {
    redirect('public/index.php');
}

$error = null;
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $login = (string)($_POST['login'] ?? '');
    [$ok, $error] = attempt_login($login, (string)($_POST['password'] ?? ''));
    if ($ok) {
        $u = current_user();
        if (!empty($u['is_group_admin']) && APP_ENV === 'production' && file_exists(__DIR__ . '/setup.php')) {
            flash_set('danger', 'SECURITY CRITICAL: public/setup.php still exists on disk in a production environment. Delete it immediately!');
        }
        $intended = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        if (is_string($intended) && safe_internal_path(strtok($intended, '?') ?: '')) {
            header('Location: ' . $intended);
            exit;
        }
        redirect('public/index.php');
    }
}

if (isset($_GET['installed'])) {
    if ($_GET['installed'] === 'warning') {
        flash_set('warning', 'Installation completed successfully! Warning: public/setup.php could not be automatically deleted. Please delete public/setup.php manually from your server.');
    } else {
        flash_set('success', 'Installation completed successfully! You can now sign in with your Group Administrator credentials.');
    }
}

$flashes = flash_get();
$logoPath = file_exists(__DIR__ . '/../assets/img/aaza-logo-transparent.png')
    ? 'assets/img/aaza-logo-transparent.png'
    : 'assets/img/aaza-logo.png';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | <?= e(APP_NAME) ?></title>
    <!-- Prevent theme flash -->
    <script>
    (function() {
        try {
            var stored = localStorage.getItem('aaza-theme');
            var theme = stored;
            if (!theme || theme === 'auto') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/img/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= e(url('assets/img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/login.css')) ?>">
</head>
<body class="login-body">
    <!-- Subtle Ambient Decorative Glows (accent at 8% light / 14% dark) -->
    <div class="login-bg-glow-layer" aria-hidden="true">
        <div class="login-bg-glow glow-1"></div>
        <div class="login-bg-glow glow-2"></div>
    </div>

    <main class="login-container">
        <div class="login-card">
            <!-- Brand Logo -->
            <div class="login-logo-wrap">
                <div class="login-logo-badge">
                    <img src="<?= e(url($logoPath)) ?>" alt="<?= e(APP_NAME) ?>" class="login-logo" height="48">
                </div>
            </div>

            <!-- Header Titles -->
            <div class="login-card-header">
                <h1 class="login-title">Welcome back</h1>
                <p class="login-subtitle">Sign in to your <?= e(APP_NAME) ?> account</p>
            </div>

            <!-- Session Flash Alerts -->
            <?php foreach ($flashes as [$fType, $fMsg]): ?>
                <div class="alert alert-<?= e($fType) ?> alert-dismissible fade show" role="alert">
                    <?= e($fMsg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>

            <!-- Error Alert -->
            <?php if ($error !== null): ?>
                <div class="login-error-alert" role="alert" aria-live="assertive">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Authentication Form -->
            <form method="post" action="<?= e(url('public/login.php')) ?>" id="loginForm" novalidate>
                <?= csrf_field() ?>

                <!-- Username or Email Field -->
                <div class="form-group">
                    <label for="login" class="form-label">Username or Email</label>
                    <div class="input-wrapper">
                        <i class="bi bi-person input-icon" aria-hidden="true"></i>
                        <input
                            type="text"
                            class="form-input"
                            id="login"
                            name="login"
                            required
                            <?= $error === null ? 'autofocus' : '' ?>
                            autocomplete="username"
                            placeholder="Enter username or email"
                            value="<?= e($login) ?>"
                            spellcheck="false"
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper has-toggle">
                        <i class="bi bi-lock input-icon" aria-hidden="true"></i>
                        <input
                            type="password"
                            class="form-input"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >
                        <button
                            type="button"
                            class="btn-password-toggle"
                            id="passwordToggleBtn"
                            aria-label="Show password"
                            title="Show password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="caps-lock-hint" id="capsLockHint" role="status" aria-live="polite">
                        <i class="bi bi-capslock-fill" aria-hidden="true"></i>
                        <span>Caps Lock is on</span>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>Sign in</span>
                    <i class="bi bi-arrow-right btn-arrow" aria-hidden="true"></i>
                </button>
            </form>
        </div>

        <!-- Footer Line below/outside the card -->
        <footer class="login-footer">
            <span>&copy; <?= date('Y') ?> AaZa Group</span>
        </footer>
    </main>

    <!-- Floating Theme Toggle in Fixed Top-Right Corner (tab order is LAST) -->
    <button
        type="button"
        class="theme-toggle-fixed"
        id="themeToggleBtn"
        aria-label="Switch to dark mode"
        title="Switch to dark mode"
        aria-pressed="false"
        tabindex="0"
    >
        <i class="bi bi-moon-stars" aria-hidden="true"></i>
    </button>

    <script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(url('assets/js/login.js')) ?>"></script>
</body>
</html>
