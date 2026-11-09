<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
if ($editing) {
    require_permission('user.edit');
} else {
    require_permission('user.create');
}

$pdo          = db();
$selfId       = (int)current_user()['id'];
$isSelf       = $editing && $id === $selfId;
$isGroupAdmin = is_group_admin();
$scopedCompanyIds = scoped_company_ids('user.manage');

$targetUser = ['full_name' => '', 'username' => '', 'email' => '', 'is_active' => 1, 'is_group_admin' => 0];
$linkedEmployee = null;
if ($editing) {
    $st = $pdo->prepare('SELECT id, full_name, username, email, is_active, is_group_admin FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        abort(404, 'User not found.');
    }
    $targetUser = $row;

    // Scoping check for company_admin
    if (!$isGroupAdmin) {
        if ((int)$targetUser['is_group_admin'] === 1) {
            abort(403, 'Access denied: Cannot edit Group Administrator accounts.');
        }
        $stScope = $pdo->prepare(
            'SELECT COUNT(*) FROM company_user_assignments
              WHERE user_id = ? AND company_id IN (' . implode(',', array_fill(0, count($scopedCompanyIds), '?')) . ')'
        );
        $stScope->execute(array_merge([$id], $scopedCompanyIds));
        if ((int)$stScope->fetchColumn() === 0) {
            abort(403, 'Access denied: This user does not belong to any of your assigned companies.');
        }
    }

    $stEmp = $pdo->prepare('SELECT id, employee_code, first_name, last_name FROM employees WHERE user_id = ? LIMIT 1');
    $stEmp->execute([$id]);
    $linkedEmployee = $stEmp->fetch();
}

if ($isGroupAdmin) {
    $companies = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll();
} else {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $stComp = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
    $stComp->execute($scopedCompanyIds);
    $companies = $stComp->fetchAll();
}

// "group_admin" is a user flag, not a per-company role
$roles = $pdo->query("SELECT id, label FROM roles WHERE name <> 'group_admin' ORDER BY id")->fetchAll();
$validCompanies = array_map('intval', array_column($companies, 'id'));
$validRoles     = array_map('intval', array_column($roles, 'id'));

// Current assignments: company_id => [role_id => true]
$assigned = [];
if ($editing) {
    $st = $pdo->prepare('SELECT company_id, role_id FROM company_user_assignments WHERE user_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $assigned[(int)$r['company_id']][(int)$r['role_id']] = true;
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $isAdmin  = ($isGroupAdmin && isset($_POST['is_group_admin'])) ? 1 : ($editing ? (int)$targetUser['is_group_admin'] : 0);
    if ($isSelf) {
        // Prevent an admin from locking themselves out
        $isActive = 1;
        $isAdmin  = 1;
    }

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        $errors[] = 'Full name is required (max 150 characters).';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3-60 characters: letters, numbers, dot, dash or underscore.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!$editing || $password !== '') {
        if ($msg = password_policy_error($password)) {
            $errors[] = $msg;
        }
    }

    if (!$errors) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $st->execute([$username, $id]);
        if ($st->fetch()) {
            $errors[] = 'That username is already taken.';
        }
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $st->execute([$email, $id]);
        if ($st->fetch()) {
            $errors[] = 'That email is already used by another user.';
        }
    }

    // Keep only company/role ids that really exist
    $newAssigned = [];
    $posted = $_POST['assign'] ?? [];
    if (is_array($posted)) {
        foreach ($posted as $cid => $rids) {
            $cid = (int)$cid;
            if (!in_array($cid, $validCompanies, true) || !is_array($rids)) {
                continue;
            }
            foreach ($rids as $rid) {
                $rid = (int)$rid;
                if (in_array($rid, $validRoles, true)) {
                    $newAssigned[$cid][$rid] = true;
                }
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($editing) {
                if ($password !== '') {
                    $pdo->prepare(
                        'UPDATE users SET full_name=?, username=?, email=?, is_active=?, is_group_admin=?,
                                password_hash=?, failed_logins=0, locked_until=NULL WHERE id=?'
                    )->execute([$fullName, $username, $email, $isActive, $isAdmin,
                                password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $pdo->prepare(
                        'UPDATE users SET full_name=?, username=?, email=?, is_active=?, is_group_admin=? WHERE id=?'
                    )->execute([$fullName, $username, $email, $isActive, $isAdmin, $id]);
                }
                $uid = $id;
            } else {
                $pdo->prepare(
                    'INSERT INTO users (full_name, username, email, password_hash, is_active, is_group_admin, must_change_password)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                )->execute([$fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT), $isActive, $isAdmin]);
                $uid = (int)$pdo->lastInsertId();
            }

            // Replace assignments for active companies (assignments to inactive ones are left alone)
            if ($validCompanies) {
                $ph = implode(',', array_fill(0, count($validCompanies), '?'));
                $pdo->prepare("DELETE FROM company_user_assignments WHERE user_id = ? AND company_id IN ($ph)")
                    ->execute(array_merge([$uid], $validCompanies));
            }
            $ins = $pdo->prepare(
                'INSERT INTO company_user_assignments (user_id, company_id, role_id, assigned_by) VALUES (?, ?, ?, ?)'
            );
            $summary = [];
            foreach ($newAssigned as $cid => $rids) {
                foreach (array_keys($rids) as $rid) {
                    $ins->execute([$uid, $cid, $rid, $selfId]);
                    $summary[] = $cid . ':' . $rid;
                }
            }
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $errors[] = 'Username or email already exists.';
            } else {
                throw $ex;
            }
        }

        if (!$errors) {
            log_activity($editing ? 'user.update' : 'user.create', 'user', $uid, [
                'username'         => $username,
                'is_active'        => $isActive,
                'is_group_admin'   => $isAdmin,
                'password_changed' => $password !== '',
                'assignments'      => $summary,   // "companyId:roleId"
            ]);

            if ($editing && $uid !== current_user_id()) {
                if ($password !== '') {
                    notify_user(
                        $pdo,
                        $uid,
                        'password_reset',
                        'Password Reset by Administrator',
                        'Your password has been updated by an administrator.',
                        null,
                        null
                    );
                }
                if (!empty($targetUser['is_active']) && !$isActive) {
                    notify_user(
                        $pdo,
                        $uid,
                        'account_status',
                        'Account Deactivated',
                        'Your user account has been deactivated by an administrator.',
                        null,
                        null
                    );
                }
            }

            clear_access_cache();
            flash_set('success', 'User ' . ($editing ? 'updated.' : 'created.'));
            redirect('modules/users/view.php?id=' . $uid);
        }
    }

    // Validation failed: redisplay what was typed
    $targetUser = ['full_name' => $fullName, 'username' => $username, 'email' => $email,
                   'is_active' => $isActive, 'is_group_admin' => $isAdmin];
    $assigned = $newAssigned;
}

$pageTitle = $editing ? 'Edit user' : 'Add user';
require __DIR__ . '/../../includes/header.php';
?>
<!-- Page Header Pattern -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('modules/users/index.php')) ?>">Users</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= $editing ? 'Edit' : 'Create' ?></li>
            </ol>
        </nav>
        <h1 class="page-header-title"><?= $editing ? 'Edit User: ' . e($targetUser['full_name']) : 'Create New User Account' ?></h1>
        <p class="page-header-desc">Configure login credentials, administrative privileges, and entity role assignments.</p>
    </div>
    <div class="page-actions page-header-actions">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/users/index.php')) ?>">
            <i class="bi bi-arrow-left me-1"></i> Back to Users
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mb-4 shadow-sm">
        <div class="d-flex align-items-center gap-2 mb-2 fw-semibold">
            <i class="bi bi-exclamation-octagon-fill"></i> Please resolve the following errors:
        </div>
        <ul class="mb-0 ps-4 small">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate autocomplete="off">
    <?= csrf_field() ?>

    <div class="row g-4">
        <!-- Account Details Card -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">Account Credentials</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="full_name">Full Name <span class="text-danger">*</span></label>
                        <input class="form-control" id="full_name" name="full_name" required value="<?= e($targetUser['full_name']) ?>" placeholder="e.g. Sarah Jenkins">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="username">Username <span class="text-danger">*</span></label>
                        <input class="form-control" id="username" name="username" required value="<?= e($targetUser['username']) ?>" placeholder="e.g. sjenkins">
                        <div class="form-text">3-60 alphanumeric characters, dots, dashes, or underscores.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Work Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required value="<?= e($targetUser['email']) ?>" placeholder="e.g. sjenkins@aaza.com">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="password">
                            Password<?= $editing ? ' <span class="text-muted fw-normal">(leave blank to keep current)</span>' : ' <span class="text-danger">*</span>' ?>
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control border-end-0" id="password" name="password"
                                   autocomplete="new-password" placeholder="<?= $editing ? '••••••••' : 'Enter strong password' ?>"<?= $editing ? '' : ' required' ?>>
                            <button type="button" class="btn btn-outline-secondary border-start-0 btn-toggle-password" data-target="password" aria-label="Toggle password visibility">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimum <?= PASSWORD_MIN_LENGTH ?> characters containing at least one letter and one number.</div>
                    </div>

                    <?php if ($editing): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Linked Employee</label>
                            <div>
                                <?php if ($linkedEmployee): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                             <i class="bi bi-person-badge me-1"></i> Linked
                                        </span>
                                        <span class="fw-semibold text-body">
                                            <?= e($linkedEmployee['first_name'] . ' ' . $linkedEmployee['last_name']) ?>
                                        </span>
                                        <code class="text-body-secondary">(<?= e($linkedEmployee['employee_code']) ?>)</code>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="text-muted">Not linked</span>
                                        <span class="text-muted">&mdash;</span>
                                        <a href="<?= e(url('modules/employees/index.php')) ?>" class="small text-decoration-none">
                                            <i class="bi bi-people me-1"></i> Go to Employee List
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">Linked employee record for personal My Leave &amp; My Attendance self-service.</div>
                        </div>
                    <?php endif; ?>

                    <div class="p-3 bg-body-tertiary rounded border mb-3">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                                <?= $targetUser['is_active'] ? 'checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_active">Account Active</label>
                            <div class="form-text mt-0">When inactive, the user cannot sign in to any system module.</div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_group_admin" name="is_group_admin" value="1"
                                <?= $targetUser['is_group_admin'] ? 'checked' : '' ?><?= ($isSelf || !$isGroupAdmin) ? ' disabled' : '' ?>>
                            <label class="form-check-label fw-semibold text-danger" for="is_group_admin">Group Administrator</label>
                            <div class="form-text mt-0">Grants full superuser access across all companies, settings, and logs.</div>
                        </div>

                        <?php if ($isSelf): ?>
                            <div class="small text-muted fst-italic mt-2 pt-2 border-top">
                                Note: You cannot deactivate or revoke administrator privileges from your own active session.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Company Access Roles Card -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fs-6 fw-bold">Company Roles &amp; Scoping</h5>
                    <span class="badge bg-body-secondary text-body border text-secondary">Multi-Tenant Governance</span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Assign roles per company entity. Users only receive privileges within companies where they are assigned.
                        Group Administrators have access to all companies regardless of role checkboxes.
                    </p>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-hover align-middle mb-0 matrix-table">
                            <thead class="table-active">
                            <tr>
                                <th class="ps-3 py-2">Company Entity</th>
                                <?php foreach ($roles as $r): ?>
                                    <th class="text-center py-2"><?= e($r['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($companies as $c): ?>
                                <?php $cid = (int)$c['id']; ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold text-body"><?= e($c['name']) ?></div>
                                        <code class="small"><?= e($c['code']) ?></code>
                                    </td>
                                    <?php foreach ($roles as $r): ?>
                                        <?php $rid = (int)$r['id']; ?>
                                        <td class="text-center">
                                            <input class="form-check-input" type="checkbox"
                                                   name="assign[<?= $cid ?>][]" value="<?= $rid ?>"
                                                   aria-label="<?= e($c['name'] . ' - ' . $r['label']) ?>"
                                                   <?= !empty($assigned[$cid][$rid]) ? 'checked' : '' ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$companies): ?>
                                <tr>
                                    <td colspan="<?= count($roles) + 1 ?>" class="text-center text-muted py-4">No active companies found.</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-body d-flex justify-content-between align-items-center py-3">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/users/index.php')) ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2 me-1"></i> <?= $editing ? 'Update User' : 'Create User' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
