<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$authUserRow = current_user();
$uid = (int)($authUserRow['id'] ?? 0);
if ($uid <= 0) {
    redirect('public/login.php');
}

$pdo = db();
$mustChange = !empty($authUserRow['must_change_password']);
$errors = [];

// Rate limiting on password change: max 5 failed attempts per 15 minutes
$ip = client_ip();
$rateKey = 'pwd_change_failed';
$stFailures = $pdo->prepare(
    "SELECT COUNT(*) FROM rate_limits
      WHERE ip_address = ? AND action = ? AND created_at >= NOW() - INTERVAL 15 MINUTE"
);
$stFailures->execute([$ip, $rateKey]);
$failedAttempts = (int)$stFailures->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($failedAttempts >= MAX_FAILED_LOGINS) {
        $errors[] = 'Too many failed attempts. Password change is temporarily locked for 15 minutes.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        // Fetch user's current password hash
        $st = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $row = $st->fetch();

        if (!$row || !password_verify($currentPassword, (string)$row['password_hash'])) {
            // Record failure in rate limits
            $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, ?, NOW())")
                ->execute([$ip, $rateKey]);
            $failedAttempts++;
            $errors[] = 'The current password you entered is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'The new password and confirmation password do not match.';
        } elseif ($currentPassword === $newPassword) {
            $errors[] = 'Your new password cannot be the same as your current password.';
        } else {
            $policyErr = password_policy_error($newPassword, (string)$row['username'], (string)$row['email']);
            if ($policyErr !== null) {
                $errors[] = $policyErr;
            } else {
                // Update password and clear must_change_password
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $upSt = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?');
                $upSt->execute([$newHash, $uid]);

                // Reset failed attempts for this IP
                $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = ?")
                    ->execute([$ip, $rateKey]);

                // Regenerate session id on privilege/credential change
                session_regenerate_id(true);

                // Audit log (never log plain password or hashes)
                log_activity('auth.password_change', 'user', $uid, [
                    'must_change_was' => $mustChange ? 1 : 0,
                ], null, $uid);

                flash_set('success', 'Your password has been updated successfully.');
                redirect('public/index.php');
            }
        }
    }
}

$pageTitle = 'Change Password';
require __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center py-4">
    <div class="col-md-7 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-body border-bottom py-3">
                <h4 class="card-title mb-0 fw-bold text-body font-sans d-flex align-items-center">
                    <i class="bi bi-key text-primary me-2"></i>Change Password
                </h4>
                <p class="text-muted small mb-0 font-sans mt-1">
                    Manage your account security credentials.
                </p>
            </div>
            <div class="card-body p-4">
                <?php if ($mustChange): ?>
                    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-shield-exclamation fs-4 me-2 text-warning"></i>
                        <div>
                            <strong>Password change required:</strong> Your account was created by an administrator with a temporary password. You must set a new secure password before proceeding.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('modules/auth/change_password.php')) ?>" class="font-sans">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="current_password" class="form-label fw-semibold small text-muted">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-body-tertiary"><i class="bi bi-lock"></i></span>
                            <input type="password" name="current_password" id="current_password" class="form-control"
                                   required autocomplete="current-password" autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold small text-muted">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-body-tertiary"><i class="bi bi-shield-lock"></i></span>
                            <input type="password" name="new_password" id="new_password" class="form-control"
                                   required minlength="10" autocomplete="new-password">
                        </div>
                        <div class="form-text small text-muted">
                            Minimum 10 characters, including letters and numbers. Cannot match your username or email.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold small text-muted">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-body-tertiary"><i class="bi bi-shield-check"></i></span>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                                   required minlength="10" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary py-2 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Update Password
                        </button>
                        <?php if (!$mustChange): ?>
                            <a href="<?= e(url('public/index.php')) ?>" class="btn btn-outline-secondary py-2">
                                Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-2 px-3 small text-muted font-sans text-center">
                <i class="bi bi-info-circle me-1"></i>All credential changes are securely audited.
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
