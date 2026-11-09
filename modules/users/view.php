<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('user.view');

$pdo = db();
$currentUserId = (int)current_user()['id'];
$isCurrentGroupAdmin = is_group_admin();
$scopedCompanyIds = scoped_company_ids('user.view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'User not found.');
}

// Fetch target user
$st = $pdo->prepare('SELECT id, username, email, full_name, is_group_admin, is_active, last_login_at, last_login_ip, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
$st->execute([$id]);
$targetUser = $st->fetch();
if (!$targetUser) {
    abort(404, 'User not found.');
}

$isSelf = ($id === $currentUserId);
$canManageUsers = user_can('user.manage') || user_can('user.edit');
$canManageRoles = user_can('role.manage');
$canDeleteUser  = $isCurrentGroupAdmin && user_can('user.delete');
$isTargetActive = ((int)$targetUser['is_active'] === 1);

// Scope verification:
// A company_admin can only view/manage users assigned to companies they have access to.
// A company_admin cannot view/manage group admins.
if (!$isCurrentGroupAdmin) {
    if ((int)$targetUser['is_group_admin'] === 1) {
        abort(403, 'Access denied: You do not have permission to view or manage Group Administrators.');
    }
    // Check if target user has at least one assignment in scoped companies
    $stScope = $pdo->prepare(
        'SELECT COUNT(*) FROM company_user_assignments
          WHERE user_id = ? AND company_id IN (' . implode(',', array_fill(0, count($scopedCompanyIds), '?')) . ')'
    );
    $stScope->execute(array_merge([$id], $scopedCompanyIds));
    if ((int)$stScope->fetchColumn() === 0) {
        abort(403, 'Access denied: This user does not belong to any of your assigned companies.');
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));

    // 1. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        if (!$canManageUsers) {
            abort(403, 'Permission denied.');
        }
        if ($isSelf) {
            flash_set('danger', 'You cannot deactivate your own account.');
            redirect('modules/users/view.php?id=' . $id);
        }
        $newState = (int)$targetUser['is_active'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_active = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([$newState, $id]);
        log_activity(
            $newState ? 'user.activate' : 'user.deactivate',
            'user',
            $id,
            ['username' => $targetUser['username'], 'full_name' => $targetUser['full_name']]
        );
        clear_access_cache();
        flash_set('success', 'User account ' . ($newState ? 'activated.' : 'deactivated.'));
        redirect('modules/users/view.php?id=' . $id);
    }

    // 2. Toggle Group Admin
    if ($action === 'toggle_group_admin') {
        if (!$isCurrentGroupAdmin) {
            abort(403, 'Only Group Administrators can modify Group Administrator privileges.');
        }
        if ($isSelf) {
            flash_set('danger', 'You cannot revoke Group Administrator privileges from your own session.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }
        $newAdminState = (int)$targetUser['is_group_admin'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_group_admin = ? WHERE id = ?')->execute([$newAdminState, $id]);
        log_activity(
            'user.group_admin_change',
            'user',
            $id,
            ['username' => $targetUser['username'], 'is_group_admin' => $newAdminState]
        );
        clear_access_cache();
        flash_set('success', 'Group Administrator privilege ' . ($newAdminState ? 'granted.' : 'revoked.'));
        redirect('modules/users/view.php?id=' . $id . '&tab=roles');
    }

    // 3. Reset Password
    if ($action === 'reset_password') {
        if (!$canManageUsers && !$isSelf) {
            abort(403, 'Permission denied.');
        }
        $newPassword = (string)($_POST['new_password'] ?? '');
        $policyErr = password_policy_error($newPassword, $targetUser['username'], $targetUser['email']);
        if ($policyErr !== null) {
            flash_set('danger', $policyErr);
            redirect('modules/users/view.php?id=' . $id);
        }
        $pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
        log_activity('user.password_reset', 'user', $id, ['username' => $targetUser['username']]);

        notify_user(
            $pdo,
            $id,
            'password_reset',
            'Password Reset by Administrator',
            'Your password has been reset by an administrator. You will be required to change your password on next sign-in.',
            null,
            null
        );

        flash_set('success', 'Password reset successfully for ' . $targetUser['full_name'] . '. User will be required to change password on next sign-in.');
        redirect('modules/users/view.php?id=' . $id);
    }

    // 4. Add or Update Role Assignment
    if ($action === 'assign_role') {
        if (!$canManageRoles && !$canManageUsers) {
            abort(403, 'Permission denied.');
        }
        if ($isSelf) {
            flash_set('danger', 'You cannot alter your own role assignments.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }

        $assignCompanyId = (int)($_POST['company_id'] ?? 0);
        $assignRoleId    = (int)($_POST['role_id'] ?? 0);

        if (!$isCurrentGroupAdmin && !in_array($assignCompanyId, $scopedCompanyIds, true)) {
            abort(403, 'Cannot assign roles for a company outside your administrative scope.');
        }

        // Validate role exists and is not group_admin
        $stRoleCheck = $pdo->prepare("SELECT id, name, label FROM roles WHERE id = ? AND name <> 'group_admin'");
        $stRoleCheck->execute([$assignRoleId]);
        $roleRow = $stRoleCheck->fetch();
        if (!$roleRow) {
            flash_set('danger', 'Invalid role selected.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }

        // Validate company exists
        $stCompCheck = $pdo->prepare('SELECT id, code, name FROM companies WHERE id = ? AND is_active = 1');
        $stCompCheck->execute([$assignCompanyId]);
        $compRow = $stCompCheck->fetch();
        if (!$compRow) {
            flash_set('danger', 'Invalid company selected.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }

        // Check if assignment exists for this (user_id, company_id)
        $stExisting = $pdo->prepare('SELECT id, role_id FROM company_user_assignments WHERE user_id = ? AND company_id = ?');
        $stExisting->execute([$id, $assignCompanyId]);
        $existing = $stExisting->fetch();

        if ($existing) {
            $pdo->prepare('UPDATE company_user_assignments SET role_id = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?')
                ->execute([$assignRoleId, $currentUserId, $existing['id']]);
            log_activity('user.role_change', 'user', $id, [
                'company'  => $compRow['code'],
                'new_role' => $roleRow['label']
            ], $assignCompanyId);
            flash_set('success', 'Updated role in ' . $compRow['name'] . ' to ' . $roleRow['label'] . '.');
        } else {
            $pdo->prepare('INSERT INTO company_user_assignments (user_id, company_id, role_id, assigned_by) VALUES (?, ?, ?, ?)')
                ->execute([$id, $assignCompanyId, $assignRoleId, $currentUserId]);
            log_activity('user.role_assign', 'user', $id, [
                'company' => $compRow['code'],
                'role'    => $roleRow['label']
            ], $assignCompanyId);
            flash_set('success', 'Assigned role ' . $roleRow['label'] . ' in ' . $compRow['name'] . '.');
        }

        clear_access_cache();
        redirect('modules/users/view.php?id=' . $id . '&tab=roles');
    }

    // 5. Remove Role Assignment
    if ($action === 'remove_role') {
        if (!$canManageRoles && !$canManageUsers) {
            abort(403, 'Permission denied.');
        }
        if ($isSelf) {
            flash_set('danger', 'You cannot remove your own role assignments.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }

        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $stAsgn = $pdo->prepare('SELECT cua.id, cua.company_id, c.code, c.name, r.label AS role_label
                                   FROM company_user_assignments cua
                                   JOIN companies c ON c.id = cua.company_id
                                   JOIN roles r ON r.id = cua.role_id
                                  WHERE cua.id = ? AND cua.user_id = ?');
        $stAsgn->execute([$assignmentId, $id]);
        $asgnRow = $stAsgn->fetch();
        if (!$asgnRow) {
            flash_set('danger', 'Assignment not found.');
            redirect('modules/users/view.php?id=' . $id . '&tab=roles');
        }

        if (!$isCurrentGroupAdmin && !in_array((int)$asgnRow['company_id'], $scopedCompanyIds, true)) {
            abort(403, 'Cannot remove assignment for a company outside your administrative scope.');
        }

        $pdo->prepare('DELETE FROM company_user_assignments WHERE id = ?')->execute([$assignmentId]);
        log_activity('user.role_remove', 'user', $id, [
            'company' => $asgnRow['code'],
            'role'    => $asgnRow['role_label']
        ], (int)$asgnRow['company_id']);

        clear_access_cache();
        flash_set('success', 'Removed assignment for ' . $asgnRow['name'] . '.');
        redirect('modules/users/view.php?id=' . $id . '&tab=roles');
    }

    // 6. User Permission Override (Option 5)
    if ($action === 'set_permission_override') {
        if (!$canManageRoles && !$canManageUsers) {
            abort(403, 'Permission denied.');
        }
        if ($isSelf) {
            flash_set('danger', 'You cannot alter your own permission overrides.');
            redirect('modules/users/view.php?id=' . $id . '&tab=permissions');
        }

        $overrideCompanyId = (int)($_POST['company_id'] ?? 0);
        $overridePermId    = (int)($_POST['permission_id'] ?? 0);
        $overrideEffect    = trim((string)($_POST['effect'] ?? 'inherit'));

        if (!$isCurrentGroupAdmin && !in_array($overrideCompanyId, $scopedCompanyIds, true)) {
            abort(403, 'Scope denied.');
        }

        $stPerm = $pdo->prepare('SELECT code FROM permissions WHERE id = ?');
        $stPerm->execute([$overridePermId]);
        $permCode = $stPerm->fetchColumn();
        if (!$permCode) {
            flash_set('danger', 'Permission not found.');
            redirect('modules/users/view.php?id=' . $id . '&tab=permissions');
        }

        if ($overrideEffect === 'allow' || $overrideEffect === 'deny') {
            $pdo->prepare(
                'INSERT INTO user_permission_overrides (user_id, company_id, permission_id, effect)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect)'
            )->execute([$id, $overrideCompanyId, $overridePermId, $overrideEffect]);

            log_activity('user.permission_override', 'user', $id, [
                'permission' => $permCode,
                'effect'     => $overrideEffect,
                'company_id' => $overrideCompanyId
            ], $overrideCompanyId);
            flash_set('success', 'Permission override saved: ' . $permCode . ' -> ' . strtoupper($overrideEffect));
        } else {
            // 'inherit' -> delete override row
            $pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id = ? AND company_id = ? AND permission_id = ?')
                ->execute([$id, $overrideCompanyId, $overridePermId]);
            log_activity('user.permission_override_cleared', 'user', $id, [
                'permission' => $permCode,
                'company_id' => $overrideCompanyId
            ], $overrideCompanyId);
            flash_set('success', 'Permission ' . $permCode . ' reset to role inheritance.');
        }

        clear_access_cache();
        redirect('modules/users/view.php?id=' . $id . '&tab=permissions&company_id=' . $overrideCompanyId);
    }
}

// Linked Employee query
$stEmp = $pdo->prepare(
    'SELECT e.id, e.employee_code, e.first_name, e.last_name, e.employment_status, c.name AS company_name
       FROM employees e
       JOIN companies c ON c.id = e.company_id
      WHERE e.user_id = ?
      LIMIT 1'
);
$stEmp->execute([$id]);
$linkedEmployee = $stEmp->fetch();

// Current Assignments query
$stAssign = $pdo->prepare(
    'SELECT cua.id AS assignment_id, cua.company_id, cua.role_id, cua.assigned_at,
            c.code AS company_code, c.name AS company_name,
            r.label AS role_label, r.description AS role_desc,
            u_by.full_name AS assigned_by_name
       FROM company_user_assignments cua
       JOIN companies c ON c.id = cua.company_id
       JOIN roles r ON r.id = cua.role_id
       LEFT JOIN users u_by ON u_by.id = cua.assigned_by
      WHERE cua.user_id = ?
      ORDER BY c.name ASC'
);
$stAssign->execute([$id]);
$userAssignments = $stAssign->fetchAll();

// Available companies for assignment dropdown
if ($isCurrentGroupAdmin) {
    $assignableCompanies = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll();
} else {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $stComp = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
    $stComp->execute($scopedCompanyIds);
    $assignableCompanies = $stComp->fetchAll();
}

// Available roles for assignment dropdown (excluding group_admin)
$assignableRoles = $pdo->query("SELECT id, name, label FROM roles WHERE name <> 'group_admin' ORDER BY label ASC")->fetchAll();

// Active tab selection
$activeTab = trim((string)($_GET['tab'] ?? 'roles'));
if (!in_array($activeTab, ['roles', 'permissions', 'activity'], true)) {
    $activeTab = 'roles';
}

// Active company selection for Permissions tab
$assignedCompanyIds = array_map('intval', array_column($userAssignments, 'company_id'));
$firstCompId = !empty($assignedCompanyIds) ? $assignedCompanyIds[0] : (!empty($assignableCompanies) ? (int)$assignableCompanies[0]['id'] : 0);
$selectedPermCompanyId = (int)($_GET['company_id'] ?? $firstCompId);
if (!in_array($selectedPermCompanyId, $assignedCompanyIds, true) && !$isCurrentGroupAdmin && !in_array($selectedPermCompanyId, $scopedCompanyIds, true)) {
    $selectedPermCompanyId = $firstCompId;
}

// Calculate effective permissions for selected company
$effectivePerms = [];
$permsByModule = [];
if ($selectedPermCompanyId > 0 || (int)$targetUser['is_group_admin'] === 1) {
    $effectivePerms = get_user_effective_permissions($id, $selectedPermCompanyId, $pdo);
    foreach ($effectivePerms as $ep) {
        $mod = (string)$ep['module'];
        $permsByModule[$mod][] = $ep;
    }
}

// Fetch last 20 activity logs for Activity tab
$stLogs = $pdo->prepare(
    "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.ip_address, al.created_at,
            c.code AS company_code, c.name AS company_name
       FROM activity_logs al
       LEFT JOIN companies c ON c.id = al.company_id
      WHERE al.user_id = ? OR (al.entity_type = 'user' AND al.entity_id = ?)
      ORDER BY al.created_at DESC
      LIMIT 20"
);
$stLogs->execute([$id, $id]);
$activityLogs = $stLogs->fetchAll();

// Avatar initials
$uParts = explode(' ', trim((string)$targetUser['full_name']));
$uInitials = strtoupper(mb_substr($uParts[0] ?? '', 0, 1) . (count($uParts) > 1 ? mb_substr(end($uParts), 0, 1) : ''));

$pageTitle = 'User Details: ' . $targetUser['full_name'];
require __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('modules/users/index.php')) ?>">Users</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($targetUser['username']) ?></li>
    </ol>
</nav>

<!-- Detail Header -->
<div class="detail-header">
    <div class="detail-header-main">
        <div class="detail-header-title-row">
            <h1 class="detail-header-title"><?= e($targetUser['full_name']) ?></h1>
            <span class="badge text-bg-dark font-monospace">@<?= e($targetUser['username']) ?></span>
            <?php if ((int)$targetUser['is_group_admin'] === 1): ?>
                <span class="badge text-bg-primary"><i class="bi bi-shield-check me-1"></i>Group Admin</span>
            <?php endif; ?>
            <?php if ((int)$targetUser['is_active'] === 1): ?>
                <span class="badge text-bg-success">Active</span>
            <?php else: ?>
                <span class="badge text-bg-danger">Inactive</span>
            <?php endif; ?>
        </div>
        <p class="detail-header-subtitle">
            <i class="bi bi-envelope me-1"></i><?= e($targetUser['email']) ?>
            &bull; Created: <?= e(date('M j, Y', strtotime((string)$targetUser['created_at']))) ?>
            <?php if ($targetUser['last_login_at']): ?>
                &bull; Last sign-in: <?= e(date('M j, Y H:i', strtotime((string)$targetUser['last_login_at']))) ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="detail-header-actions">
        <!-- ONE Primary Button -->
        <?php if ($canManageUsers): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('modules/users/form.php?id=' . $id)) ?>">
                <i class="bi bi-pencil me-1"></i> Edit User Info
            </a>
        <?php endif; ?>

        <!-- ONE "More" 3-dot menu -->
        <?php
        $usrMore = [];
        if ($canManageUsers || $isSelf) {
            $usrMore[] = [
                'type' => 'button',
                'icon' => 'bi-key',
                'label' => 'Reset Password',
                'modal' => '#resetPasswordModal',
            ];
        }
        if ($canManageUsers && !$isSelf) {
            $usrMore[] = [
                'type' => 'post',
                'icon' => (int)$targetUser['is_active'] === 1 ? 'bi-slash-circle' : 'bi-check-circle',
                'label' => (int)$targetUser['is_active'] === 1 ? 'Deactivate Account' : 'Activate Account',
                'url' => 'modules/users/view.php?id=' . $id,
                'params' => ['action' => 'toggle_status'],
                'confirm' => 'Are you sure you want to ' . ((int)$targetUser['is_active'] === 1 ? 'deactivate' : 'activate') . ' user "' . e($targetUser['full_name']) . '"?',
                'confirm_type' => (int)$targetUser['is_active'] === 1 ? 'danger' : 'success',
                'danger' => ((int)$targetUser['is_active'] === 1),
            ];
        }
        $usrMore[] = [
            'type' => 'link',
            'icon' => 'bi-arrow-left',
            'label' => 'Back to Users',
            'url' => 'modules/users/index.php',
        ];
        echo action_menu($usrMore, [
            'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
            'aria_label' => 'More user actions',
        ]);
        ?>
    </div>
</div>

<div class="row g-3 align-items-start">
    <!-- LEFT COLUMN: Profile Card (4 cols) -->
    <div class="col-lg-4 col-xl-3">
        <div class="detail-card">
            <div class="card-header">
                <span>Account Profile</span>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="avatar-initials-lg mx-auto mb-2">
                        <?= e($uInitials) ?>
                    </div>
                    <div class="fw-bold text-body"><?= e($targetUser['full_name']) ?></div>
                    <div class="small text-muted font-monospace">@<?= e($targetUser['username']) ?></div>
                </div>

                <div class="kv-list kv-list-single">
                    <div class="kv-item">
                        <span class="kv-label">Email Address</span>
                        <span class="kv-value small"><?= e($targetUser['email']) ?></span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Last Sign-In</span>
                        <span class="kv-value small">
                            <?= $targetUser['last_login_at'] ? e(date('M j, Y H:i', strtotime((string)$targetUser['last_login_at']))) : 'Never' ?>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Last IP Address</span>
                        <span class="kv-value font-monospace small"><?= $targetUser['last_login_ip'] ? e($targetUser['last_login_ip']) : 'None' ?></span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Account Created</span>
                        <span class="kv-value small"><?= e(date('M j, Y', strtotime((string)$targetUser['created_at']))) ?></span>
                    </div>
                    <div class="kv-item pt-2 border-top">
                        <span class="kv-label">Linked Employee</span>
                        <div class="kv-value">
                            <?php if ($linkedEmployee): ?>
                                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$linkedEmployee['id'])) ?>" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none">
                                    <i class="bi bi-person-badge me-1"></i> <?= e($linkedEmployee['employee_code']) ?>
                                </a>
                            <?php else: ?>
                                <span class="badge bg-body-secondary text-muted border">Not linked</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Tabs (Roles & Companies, Permissions, Activity) (8-9 cols) -->
    <div class="col-lg-8 col-xl-9">
        <div class="detail-card">
            <div class="card-header bg-body p-0 border-bottom">
                <ul class="nav nav-tabs nav-tabs-scroll card-header-tabs px-3 pt-2 mb-0" id="userTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $activeTab === 'roles' ? 'active' : '' ?>"
                           href="<?= e(url('modules/users/view.php?id=' . $id . '&tab=roles')) ?>">
                            <i class="bi bi-buildings me-1"></i> Roles &amp; Companies
                            <span class="badge bg-body-secondary text-body border ms-1"><?= count($userAssignments) ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $activeTab === 'permissions' ? 'active' : '' ?>"
                           href="<?= e(url('modules/users/view.php?id=' . $id . '&tab=permissions')) ?>">
                            <i class="bi bi-shield-lock me-1"></i> Permissions
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>"
                           href="<?= e(url('modules/users/view.php?id=' . $id . '&tab=activity')) ?>">
                            <i class="bi bi-journal-text me-1"></i> Activity
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card-body p-4">
                <!-- TAB 1: Roles & Companies -->
                <?php if ($activeTab === 'roles'): ?>
                    <!-- Group Administrator Switch Card -->
                    <div class="p-3 mb-4 rounded border bg-body-tertiary">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="fw-semibold text-body d-flex align-items-center gap-2">
                                    <i class="bi bi-shield-shaded text-danger"></i> Group Administrator Privileges
                                </div>
                                <div class="small text-muted">
                                    Group Administrators have global superuser access to all companies, settings, and features.
                                </div>
                            </div>
                            <div>
                                <?php if ($isCurrentGroupAdmin): ?>
                                    <form method="post" class="d-inline" data-confirm="Confirm updating Group Administrator privilege for '<?= e($targetUser['full_name']) ?>'?" data-confirm-type="warning">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_group_admin">
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="groupAdminSwitch"
                                                   onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit();"
                                                   <?= (int)$targetUser['is_group_admin'] === 1 ? 'checked' : '' ?>
                                                   <?= $isSelf ? 'disabled' : '' ?>>
                                            <label class="form-check-label fw-bold <?= (int)$targetUser['is_group_admin'] === 1 ? 'text-danger' : 'text-muted' ?>" for="groupAdminSwitch">
                                                <?= (int)$targetUser['is_group_admin'] === 1 ? 'Enabled' : 'Disabled' ?>
                                            </label>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <span class="badge text-bg-<?= (int)$targetUser['is_group_admin'] === 1 ? 'dark' : 'secondary' ?>">
                                        <?= (int)$targetUser['is_group_admin'] === 1 ? 'Enabled' : 'Disabled' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isSelf && $isCurrentGroupAdmin): ?>
                            <div class="small text-muted fst-italic mt-2 pt-2 border-top">
                                Note: You cannot revoke Group Administrator privileges from your own active session.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Assignments Table -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="fs-6 fw-bold mb-0">Assigned Company Roles</h2>
                        <span class="small text-muted"><?= count($userAssignments) ?> company <?= count($userAssignments) === 1 ? 'assignment' : 'assignments' ?></span>
                    </div>

                    <div class="table-responsive border rounded mb-4">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th class="ps-3">Company</th>
                                    <th>Assigned Role</th>
                                    <th>Assigned On</th>
                                    <th>Assigned By</th>
                                    <?php if ($canManageRoles && !$isSelf): ?>
                                         <th class="text-end pe-3">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($userAssignments as $asgn): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold text-body"><?= e($asgn['company_name']) ?></div>
                                        <span class="badge bg-body-secondary text-body border font-monospace"><?= e($asgn['company_code']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-primary px-2 py-1"><?= e($asgn['role_label']) ?></span>
                                        <?php if (!empty($asgn['role_desc'])): ?>
                                            <div class="small text-muted mt-1"><?= e($asgn['role_desc']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= e(date('M j, Y H:i', strtotime((string)$asgn['assigned_at']))) ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $asgn['assigned_by_name'] ? e($asgn['assigned_by_name']) : '<span class="text-muted">System</span>' ?>
                                    </td>
                                    <?php if ($canManageRoles && !$isSelf): ?>
                                        <td class="text-end pe-3 text-nowrap">
                                            <?php
                                            $canModifyThisComp = $isCurrentGroupAdmin || in_array((int)$asgn['company_id'], $scopedCompanyIds, true);
                                            ?>
                                            <?php if ($canModifyThisComp): ?>
                                                <form method="post" class="d-inline" data-confirm="Are you sure you want to remove role in '<?= e($asgn['company_name']) ?>'?" data-confirm-type="danger">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="remove_role">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$asgn['assignment_id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Remove Role">
                                                        <i class="bi bi-trash3 me-1"></i> Remove
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-body-secondary text-muted" title="Out of administrative scope">Locked</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$userAssignments): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="bi bi-buildings fs-3 d-block mb-2 text-muted"></i>
                                        No companies assigned to this user yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Inline Assign / Update Role Form -->
                    <?php if ($canManageRoles && !$isSelf && !empty($assignableCompanies) && !empty($assignableRoles)): ?>
                        <div class="card border bg-body-tertiary">
                            <div class="card-header bg-transparent py-2">
                                <h3 class="card-title fs-6 fw-bold mb-0">
                                    <i class="bi bi-plus-circle me-1"></i> Assign or Update Company Role
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="post" class="row g-3 align-items-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_role">
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold small" for="assign_company_id">Company Entity <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm" id="assign_company_id" name="company_id" required>
                                            <option value="">Select Company...</option>
                                            <?php foreach ($assignableCompanies as $c): ?>
                                                <option value="<?= (int)$c['id'] ?>">
                                                    <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold small" for="assign_role_id">Role <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm" id="assign_role_id" name="role_id" required>
                                            <option value="">Select Role...</option>
                                            <?php foreach ($assignableRoles as $r): ?>
                                                <option value="<?= (int)$r['id'] ?>">
                                                    <?= e($r['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">
                                            <i class="bi bi-check2 me-1"></i> Save
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- TAB 2: Permissions -->
                <?php elseif ($activeTab === 'permissions'): ?>
                    <?php if ((int)$targetUser['is_group_admin'] === 1): ?>
                        <div class="alert alert-dark d-flex align-items-center gap-3 shadow-sm mb-4">
                            <i class="bi bi-shield-fill-check fs-2 text-primary"></i>
                            <div>
                                <h2 class="alert-heading fs-6 fw-bold mb-1">Full Group Administrator Access</h2>
                                <p class="mb-0 small">
                                    As a designated Group Administrator, this user inherently holds unrestricted permissions across all companies, modules, and administrative interfaces.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Company Selector for Effective Permissions -->
                    <div class="card border bg-body-tertiary mb-4">
                        <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-2">
                                <label for="perm_company_select" class="fw-semibold small mb-0 text-nowrap">
                                    <i class="bi bi-building me-1"></i> View Effective Permissions For:
                                </label>
                                <form method="get" class="d-inline">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="tab" value="permissions">
                                    <select class="form-select form-select-sm" id="perm_company_select" name="company_id" onchange="this.form.submit();">
                                        <?php if (empty($userAssignments)): ?>
                                            <option value="0">No companies assigned</option>
                                        <?php else: ?>
                                            <?php foreach ($userAssignments as $asgn): ?>
                                                <option value="<?= (int)$asgn['company_id'] ?>" <?= $selectedPermCompanyId === (int)$asgn['company_id'] ? 'selected' : '' ?>>
                                                    <?= e($asgn['company_name']) ?> (<?= e($asgn['company_code']) ?>) &ndash; <?= e($asgn['role_label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </form>
                            </div>

                            <div class="d-flex align-items-center gap-2 small">
                                <span class="d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-check-circle-fill text-success"></i> Granted
                                </span>
                                <span class="d-inline-flex align-items-center gap-1 text-muted ms-2">
                                    <i class="bi bi-dash-circle text-muted"></i> Denied / Not Granted
                                </span>
                                <span class="d-inline-flex align-items-center gap-1 ms-2">
                                    <span class="badge text-bg-warning">Override</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Collapsible Module Accordion -->
                    <?php if (!empty($permsByModule)): ?>
                        <div class="accordion" id="accordionPermissions">
                            <?php $modIndex = 0; ?>
                            <?php foreach ($permsByModule as $moduleName => $modulePerms): ?>
                                <?php
                                $modIndex++;
                                $collapseId = 'collapsePerm_' . preg_replace('/[^a-zA-Z0-9]/', '_', $moduleName);
                                $totalModPerms = count($modulePerms);
                                $grantedModPerms = count(array_filter($modulePerms, fn($p) => !empty($p['granted'])));
                                $badgeClass = $grantedModPerms === $totalModPerms ? 'badge-soft-success' : ($grantedModPerms > 0 ? 'badge-soft-info' : 'badge-soft-secondary');
                                ?>
                                <div class="accordion-item mb-2 border rounded shadow-none">
                                    <h2 class="accordion-header" id="heading_<?= e($collapseId) ?>">
                                        <button class="accordion-button collapsed py-2 px-3 fw-semibold text-body perm-accordion-button" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= e($collapseId) ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= e($collapseId) ?>">
                                            <span class="perm-module-title"><?= e($moduleName) ?></span>
                                            <span class="badge <?= $badgeClass ?> perm-module-badge">
                                                <?= $grantedModPerms ?> of <?= $totalModPerms ?> granted
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="<?= e($collapseId) ?>" class="accordion-collapse collapse" aria-labelledby="heading_<?= e($collapseId) ?>">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle mb-0">
                                                    <tbody>
                                                    <?php foreach ($modulePerms as $perm): ?>
                                                        <tr class="permission-row">
                                                            <td style="width: 36px;" class="ps-3">
                                                                <?php if ($perm['granted']): ?>
                                                                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                                                <?php else: ?>
                                                                    <i class="bi bi-dash-circle text-muted fs-5 opacity-50"></i>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($perm['code']) ?></span>
                                                                    <?php if ($perm['override'] === 'allow'): ?>
                                                                        <span class="badge text-bg-warning" title="Explicit Allow Override active">
                                                                            <i class="bi bi-shield-exclamation me-1"></i>Override: Allow
                                                                        </span>
                                                                    <?php elseif ($perm['override'] === 'deny'): ?>
                                                                        <span class="badge text-bg-danger" title="Explicit Deny Override active">
                                                                            <i class="bi bi-slash-circle me-1"></i>Override: Deny
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="small text-muted mt-1"><?= e($perm['description'] ?? 'No description available') ?></div>
                                                            </td>
                                                            <td class="text-nowrap small text-muted">
                                                                <?php if ($perm['source'] === 'group_admin'): ?>
                                                                    <span class="badge badge-soft-secondary">via Group Admin</span>
                                                                <?php elseif ($perm['source'] === 'override_allow'): ?>
                                                                    <span class="badge badge-soft-warning">User Override</span>
                                                                <?php elseif ($perm['source'] === 'override_deny'): ?>
                                                                    <span class="badge badge-soft-danger">Denied by Override</span>
                                                                <?php elseif ($perm['source'] === 'role'): ?>
                                                                    <span class="badge badge-soft-secondary">via <?= e($perm['role_name'] ?? 'Role') ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-muted fst-italic">Not assigned</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <?php if ($canManageRoles && !$isSelf && (int)$targetUser['is_group_admin'] !== 1 && $selectedPermCompanyId > 0): ?>
                                                                <td class="text-end pe-3 text-nowrap" style="width: 140px;">
                                                                    <!-- Three-state Override Control (Inherit / Allow / Deny) -->
                                                                    <form method="post" class="d-inline">
                                                                        <?= csrf_field() ?>
                                                                        <input type="hidden" name="action" value="set_permission_override">
                                                                        <input type="hidden" name="company_id" value="<?= $selectedPermCompanyId ?>">
                                                                        <input type="hidden" name="permission_id" value="<?= (int)$perm['permission_id'] ?>">
                                                                        <div class="btn-group btn-group-sm" role="group" aria-label="Permission Override">
                                                                            <button type="submit" name="effect" value="inherit"
                                                                                    class="btn btn-outline-secondary <?= $perm['override'] === null ? 'active' : '' ?>"
                                                                                    title="Inherit from role">
                                                                                Auto
                                                                            </button>
                                                                            <button type="submit" name="effect" value="allow"
                                                                                    class="btn btn-outline-success <?= $perm['override'] === 'allow' ? 'active' : '' ?>"
                                                                                    title="Force Allow override">
                                                                                Allow
                                                                            </button>
                                                                            <button type="submit" name="effect" value="deny"
                                                                                    class="btn btn-outline-danger <?= $perm['override'] === 'deny' ? 'active' : '' ?>"
                                                                                    title="Force Deny override">
                                                                                Deny
                                                                            </button>
                                                                        </div>
                                                                    </form>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state p-4 text-center">
                            <i class="bi bi-shield-slash fs-2 text-muted mb-2"></i>
                            <h3 class="fs-6 fw-bold">No permissions to display</h3>
                            <p class="text-muted small">Please assign a company entity to this user to view their effective permissions.</p>
                        </div>
                    <?php endif; ?>

                <!-- TAB 3: Activity Logs -->
                <?php elseif ($activeTab === 'activity'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="fs-6 fw-bold mb-0">Recent User Activity Trail</h2>
                        <span class="small text-muted">Last 20 recorded events</span>
                    </div>

                    <div class="table-responsive border rounded">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th class="ps-3">Timestamp</th>
                                    <th>Action</th>
                                    <th>Company</th>
                                    <th>Entity</th>
                                    <th>IP Address</th>
                                    <th class="pe-3">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activityLogs as $log): ?>
                                <?php
                                $detailArr = null;
                                if (!empty($log['details'])) {
                                    $detailArr = json_decode((string)$log['details'], true);
                                }
                                ?>
                                <tr>
                                    <td class="ps-3 small text-muted text-nowrap">
                                        <?= e(date('M j, Y H:i:s', strtotime((string)$log['created_at']))) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body border font-monospace"><?= e($log['action']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['company_code'])): ?>
                                            <span class="badge bg-body-secondary text-body border"><?= e($log['company_code']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted text-nowrap">
                                        <?= $log['entity_type'] ? e($log['entity_type'] . ' #' . $log['entity_id']) : '&mdash;' ?>
                                    </td>
                                    <td class="small text-muted font-monospace">
                                        <?= $log['ip_address'] ? e($log['ip_address']) : '&mdash;' ?>
                                    </td>
                                    <td class="pe-3 small text-muted">
                                        <?php if (is_array($detailArr)): ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($detailArr as $k => $v): ?>
                                                    <?php if (is_scalar($v)): ?>
                                                        <span class="badge bg-body-secondary text-body border"><?= e((string)$k) ?>: <?= e((string)$v) ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <?= e((string)$log['details']) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$activityLogs): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="bi bi-clock-history fs-3 d-block mb-2 text-muted"></i>
                                        No recent activity recorded for this user.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- DANGER ZONE CARD -->
<?php if ($canManageUsers && !$isSelf): ?>
<div class="card border-danger shadow-sm mt-4">
    <div class="card-header bg-danger-subtle border-danger text-danger py-3">
        <h5 class="card-title mb-0 fs-6 fw-bold d-flex align-items-center">
            <i class="bi bi-exclamation-octagon-fill me-2"></i> Danger Zone
        </h5>
    </div>
    <div class="card-body p-4">
        <!-- Row 1: Deactivate / Activate Account -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center pb-3 border-bottom gap-3">
            <div>
                <div class="fw-semibold text-body">
                    <?= $isTargetActive ? 'Deactivate this user account' : 'Activate this user account' ?>
                </div>
                <div class="small text-muted">
                    <?= $isTargetActive
                        ? 'Deactivating suspends user access immediately. The user will be unable to log in, but historical records remain intact.'
                        : 'Reactivating restores the user\'s ability to log in with their existing credentials and roles.' ?>
                </div>
            </div>
            <div>
                <form method="post" class="m-0" data-confirm="Are you sure you want to <?= $isTargetActive ? 'deactivate' : 'activate' ?> user '<?= e($targetUser['full_name']) ?>'?" data-confirm-type="<?= $isTargetActive ? 'danger' : 'success' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <button type="submit" class="btn btn-outline-<?= $isTargetActive ? 'danger' : 'success' ?> btn-sm text-nowrap">
                        <i class="bi bi-<?= $isTargetActive ? 'slash-circle' : 'check-circle' ?> me-1"></i>
                        <?= $isTargetActive ? 'Deactivate User' : 'Activate User' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Row 2: Delete User -->
        <?php if ($canDeleteUser): ?>
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center pt-3 gap-3">
                <div>
                    <div class="fw-semibold text-danger">Permanently delete this user</div>
                    <div class="small text-muted">
                        Permanently remove this user account from the system. If the user is linked to an employee or has created operational records, deletion will be blocked to preserve historical data.
                    </div>
                    <?php if ($isTargetActive): ?>
                        <div class="small text-warning-emphasis fw-medium mt-1">
                            <i class="bi bi-info-circle me-1"></i> Deactivate this user first to enable delete.
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if (!$isTargetActive): ?>
                        <button type="button" class="btn btn-danger btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                            <i class="bi bi-trash me-1"></i> Delete User
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline-danger btn-sm text-nowrap" disabled title="Deactivate this user first to enable delete">
                            <i class="bi bi-trash me-1"></i> Delete User
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Reset Password -->
<?php if ($canManageUsers || $isSelf): ?>
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <div class="modal-header">
                    <h5 class="modal-title fs-6 fw-bold" id="resetPasswordModalLabel">
                        <i class="bi bi-key me-1 text-primary"></i> Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Set a new temporary or permanent password for <strong><?= e($targetUser['full_name']) ?></strong>.
                        The user will be prompted to update their password upon next login.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="new_password">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" id="btnGenPassword" title="Generate Secure Password">
                                <i class="bi bi-magic"></i> Generate
                            </button>
                        </div>
                        <div class="form-text">Minimum <?= PASSWORD_MIN_LENGTH ?> characters, including at least one letter and one number.</div>
                    </div>
                </div>
                <div class="modal-footer bg-body-tertiary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var genBtn = document.getElementById('btnGenPassword');
    if (genBtn) {
        genBtn.addEventListener('click', function() {
            var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
            var pass = '';
            for (var i = 0; i < 14; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            var pwdField = document.getElementById('new_password');
            if (pwdField) {
                pwdField.type = 'text';
                pwdField.value = pass;
            }
        });
    }
});
</script>
<?php endif; ?>

<?php if ($canDeleteUser && !$isTargetActive && !$isSelf): ?>
<!-- Modal: Delete User Permanently -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger shadow">
            <form method="post" action="<?= e(url('modules/users/delete.php')) ?>" id="deleteUserForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="modal-header border-danger">
                    <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteUserModalLabel">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete User Permanently
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="alert alert-danger d-flex align-items-start gap-2 mb-3">
                        <i class="bi bi-exclamation-octagon fs-5 flex-shrink-0"></i>
                        <div class="small">
                            <strong>Warning: This cannot be undone.</strong>
                            Permanently deleting this account removes all company role assignments, permissions, and session access.
                        </div>
                    </div>

                    <div class="p-3 bg-body-tertiary rounded border mb-3">
                        <div class="small text-muted mb-1">User account details:</div>
                        <div class="fw-bold fs-6 text-body"><?= e($targetUser['full_name']) ?></div>
                        <div class="small text-muted font-monospace">@<?= e($targetUser['username']) ?></div>
                        <div class="small text-muted"><?= e($targetUser['email']) ?></div>
                    </div>

                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-shield-lock me-1"></i>
                        <strong>Safety Guard:</strong> If this user is linked to an employee profile or is referenced by important operational records (created vacancies, candidates, applications, interviews, offers, attendance, or leave approvals), deletion will be blocked and you will be instructed to keep them deactivated.
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="confirm_user_username">
                            Type the username <code class="fw-bold text-danger"><?= e($targetUser['username']) ?></code> to confirm:
                        </label>
                        <input type="text"
                               id="confirm_user_username"
                               name="confirm_username"
                               class="form-control font-monospace"
                               required
                               autocomplete="off"
                               placeholder="<?= e($targetUser['username']) ?>"
                               data-target-username="<?= e($targetUser['username']) ?>">
                    </div>
                </div>
                <div class="modal-footer bg-body-tertiary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm" id="btnDeleteUserConfirm" disabled>
                        <i class="bi bi-trash me-1"></i> Delete permanently
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var confirmInput = document.getElementById('confirm_user_username');
    var deleteBtn = document.getElementById('btnDeleteUserConfirm');
    if (confirmInput && deleteBtn) {
        var expectedUsername = confirmInput.getAttribute('data-target-username') || '';
        confirmInput.addEventListener('input', function() {
            var typed = confirmInput.value.trim();
            deleteBtn.disabled = (typed !== expectedUsername);
        });
    }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
