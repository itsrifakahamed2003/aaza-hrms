<?php
declare(strict_types=1);

/**
 * Server-side access control. Every page and every action must call these.
 * Hiding a menu link is only cosmetic; these checks are the real protection.
 *
 * Model:
 *  - users.is_group_admin = 1  -> full access to every company.
 *  - everyone else             -> only the companies in company_user_assignments,
 *                                 and only the permissions of the role they hold there.
 *    (A user can hold different roles in different companies.)
 */

/** Load the signed-in user's access once per request. */
function load_access(bool $forceReload = false): array
{
    static $access = null;
    if ($forceReload) {
        $access = null;
    }
    if ($access !== null) {
        return $access;
    }
    $access = ['admin' => false, 'perms' => [], 'company_ids' => []];
    $user = current_user();
    if (!$user) {
        return $access;
    }

    $pdo = db();
    if ($user['is_group_admin']) {
        $access['admin'] = true;
        $ids = $pdo->query('SELECT id FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $access['company_ids'] = array_map('intval', $ids);
        return $access;
    }

    // 1. All companies assigned to user
    $stComp = $pdo->prepare(
        'SELECT DISTINCT cua.company_id
           FROM company_user_assignments cua
           JOIN companies c ON c.id = cua.company_id AND c.is_active = 1
          WHERE cua.user_id = ?'
    );
    $stComp->execute([$user['id']]);
    foreach ($stComp->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int)$cid;
        $access['perms'][$cid] ??= [];
        if (!in_array($cid, $access['company_ids'], true)) {
            $access['company_ids'][] = $cid;
        }
    }

    // 2. Role permissions
    $st = $pdo->prepare(
        'SELECT cua.company_id, p.code
           FROM company_user_assignments cua
           JOIN companies c ON c.id = cua.company_id AND c.is_active = 1
           JOIN role_permissions rp ON rp.role_id = cua.role_id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE cua.user_id = ?'
    );
    $st->execute([$user['id']]);
    foreach ($st->fetchAll() as $row) {
        $cid = (int)$row['company_id'];
        $access['perms'][$cid] ??= [];
        if (!empty($row['code'])) {
            $access['perms'][$cid][$row['code']] = true;
        }
    }

    // 3. Apply per-user overrides: deny override > allow override > role permission
    try {
        $stOv = $pdo->prepare(
            'SELECT upo.company_id, upo.effect, p.code
               FROM user_permission_overrides upo
               JOIN permissions p ON p.id = upo.permission_id
               JOIN companies c ON c.id = upo.company_id AND c.is_active = 1
              WHERE upo.user_id = ?'
        );
        $stOv->execute([$user['id']]);
        foreach ($stOv->fetchAll() as $ov) {
            $cid = (int)$ov['company_id'];
            $access['perms'][$cid] ??= [];
            if (!in_array($cid, $access['company_ids'], true)) {
                $access['company_ids'][] = $cid;
            }
            if ($ov['effect'] === 'deny') {
                $access['perms'][$cid][$ov['code']] = false;
            } elseif ($ov['effect'] === 'allow') {
                $access['perms'][$cid][$ov['code']] = true;
            }
        }
    } catch (PDOException) {
        // Table may not exist during early migration
    }

    return $access;
}

/** Invalidate loaded permission cache for current process. */
function clear_access_cache(): void
{
    load_access(true);
}

function is_group_admin(): bool
{
    return load_access()['admin'];
}

/**
 * Does the user hold $perm?
 *  - with $companyId: in that company
 *  - without:         in at least one company
 *
 * Precedence:
 *  1. Group Admin (full access)
 *  2. Deny override (false)
 *  3. Allow override (true)
 *  4. Role permission (true if in role)
 */
function user_can(string $perm, ?int $companyId = null): bool
{
    $a = load_access();
    if ($a['admin']) {
        return true;
    }
    $equivs = [$perm];
    if ($perm === 'employee.update') {
        $equivs[] = 'employee.edit';
    } elseif ($perm === 'employee.edit') {
        $equivs[] = 'employee.update';
    }
    if ($perm === 'user.create' || $perm === 'user.edit') {
        $equivs[] = 'user.manage';
    }

    if ($companyId === null) {
        foreach ($a['perms'] as $cid => $set) {
            if (isset($set[$perm]) && $set[$perm] === false) {
                continue; // Denied in this company
            }
            foreach ($equivs as $p) {
                if (!empty($set[$p])) {
                    return true;
                }
            }
        }
        return false;
    }

    // Specific company check: explicit deny override check
    if (isset($a['perms'][$companyId][$perm]) && $a['perms'][$companyId][$perm] === false) {
        return false;
    }

    foreach ($equivs as $p) {
        if (!empty($a['perms'][$companyId][$p])) {
            return true;
        }
    }
    return false;
}

/** Stop with 403 unless the user holds $perm (in $companyId, if given). */
function require_permission(string $perm, ?int $companyId = null): void
{
    if (!user_can($perm, $companyId)) {
        log_activity('access.denied', null, null, ['permission' => $perm, 'company_id' => $companyId], $companyId);
        abort(403);
    }
}

function require_group_admin(): void
{
    if (!is_group_admin()) {
        log_activity('access.denied', null, null, ['required' => 'group_admin']);
        abort(403);
    }
}

/** Alias that reads better on pages that act on one company's record. */
function require_company_access(int $companyId, string $perm): void
{
    require_permission($perm, $companyId);
}

/** Company ids the user may use, optionally only those where they hold $perm. */
function accessible_company_ids(?string $perm = null): array
{
    $a = load_access();
    if ($a['admin'] || $perm === null) {
        return $a['company_ids'];
    }
    $equivs = [$perm];
    if ($perm === 'employee.update') {
        $equivs[] = 'employee.edit';
    } elseif ($perm === 'employee.edit') {
        $equivs[] = 'employee.update';
    }
    if ($perm === 'user.create' || $perm === 'user.edit') {
        $equivs[] = 'user.manage';
    }
    $ids = [];
    foreach ($a['perms'] as $cid => $set) {
        if (isset($set[$perm]) && $set[$perm] === false) {
            continue; // Denied by override in this company
        }
        foreach ($equivs as $p) {
            if (!empty($set[$p])) {
                $ids[] = (int)$cid;
                break;
            }
        }
    }
    return $ids;
}

/**
 * Calculate effective permissions for a given user and company.
 * Precedence:
 *  1. Group Admin -> all permissions granted ('group_admin')
 *  2. Deny override -> denied ('override_deny')
 *  3. Allow override -> granted ('override_allow')
 *  4. Role permission -> granted ('role')
 *  5. None -> not granted ('none')
 */
function get_user_effective_permissions(int $userId, int $companyId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    $stUser = $pdo->prepare('SELECT is_group_admin FROM users WHERE id = ? LIMIT 1');
    $stUser->execute([$userId]);
    $isGroupAdmin = (bool)$stUser->fetchColumn();

    $allPerms = $pdo->query('SELECT id, code, module, description FROM permissions ORDER BY module, code')->fetchAll();

    if ($isGroupAdmin) {
        $result = [];
        foreach ($allPerms as $p) {
            $result[] = [
                'permission_id' => (int)$p['id'],
                'code'          => (string)$p['code'],
                'module'        => (string)$p['module'],
                'description'   => (string)$p['description'],
                'granted'       => true,
                'source'        => 'group_admin',
                'role_name'     => 'Group Administrator',
                'override'      => null,
            ];
        }
        return $result;
    }

    // Role permissions for this user in this company
    $stRole = $pdo->prepare(
        'SELECT r.id AS role_id, r.label AS role_label, rp.permission_id
           FROM company_user_assignments cua
           JOIN roles r ON r.id = cua.role_id
           JOIN role_permissions rp ON rp.role_id = r.id
          WHERE cua.user_id = ? AND cua.company_id = ?'
    );
    $stRole->execute([$userId, $companyId]);
    $roleGrants = [];
    $roleLabels = [];
    foreach ($stRole->fetchAll() as $r) {
        $pid = (int)$r['permission_id'];
        $roleGrants[$pid] = true;
        $roleLabels[$pid] = $r['role_label'];
    }

    $stRoleName = $pdo->prepare(
        'SELECT r.label
           FROM company_user_assignments cua
           JOIN roles r ON r.id = cua.role_id
          WHERE cua.user_id = ? AND cua.company_id = ?
          LIMIT 1'
    );
    $stRoleName->execute([$userId, $companyId]);
    $assignedRoleLabel = $stRoleName->fetchColumn() ?: null;

    // Load overrides
    $overrides = [];
    try {
        $stOv = $pdo->prepare(
            'SELECT permission_id, effect
               FROM user_permission_overrides
              WHERE user_id = ? AND company_id = ?'
        );
        $stOv->execute([$userId, $companyId]);
        foreach ($stOv->fetchAll() as $row) {
            $overrides[(int)$row['permission_id']] = $row['effect'];
        }
    } catch (PDOException) {
        // user_permission_overrides table may not exist
    }

    $result = [];
    foreach ($allPerms as $p) {
        $pid = (int)$p['id'];
        $ov = $overrides[$pid] ?? null;

        if ($ov === 'deny') {
            $granted = false;
            $source = 'override_deny';
        } elseif ($ov === 'allow') {
            $granted = true;
            $source = 'override_allow';
        } elseif (!empty($roleGrants[$pid])) {
            $granted = true;
            $source = 'role';
        } else {
            $granted = false;
            $source = 'none';
        }

        $result[] = [
            'permission_id' => $pid,
            'code'          => (string)$p['code'],
            'module'        => (string)$p['module'],
            'description'   => (string)$p['description'],
            'granted'       => $granted,
            'source'        => $source,
            'role_name'     => $roleLabels[$pid] ?? $assignedRoleLabel,
            'override'      => $ov,
        ];
    }

    return $result;
}

/** The company chosen in the top-bar switcher, or null for "all my companies". */
function active_company_id(): ?int
{
    $id = $_SESSION['active_company'] ?? null;
    if ($id === null) {
        return null;
    }
    if (!in_array((int)$id, accessible_company_ids(), true)) {
        unset($_SESSION['active_company']);
        return null;
    }
    return (int)$id;
}

/**
 * The company ids a list/report query must be limited to:
 * the switcher's company if one is chosen, otherwise everything the user may see.
 * Always run queries through this; never trust a company_id from the browser.
 */
function scoped_company_ids(?string $perm = null): array
{
    $ids = accessible_company_ids($perm);
    $active = active_company_id();
    if ($active !== null && in_array($active, $ids, true)) {
        return [$active];
    }
    return $ids;
}

/**
 * SQL fragment for "row belongs to one of these companies".
 * Returns [sql, params]; an empty list yields a clause that matches nothing.
 *   [$sql, $params] = company_in_sql(scoped_company_ids('employee.view'), 'e.company_id');
 */
function company_in_sql(array $ids, string $column = 'company_id'): array
{
    if (!$ids) {
        return ['1 = 0', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ["$column IN ($placeholders)", array_values($ids)];
}

/**
 * Checks if a role can be safely deleted.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, role: ?array, counts: array<string, int>}
 */
function check_role_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Invalid role ID.',
            'role'       => null,
            'counts'     => [],
        ];
    }

    $st = $pdo->prepare('SELECT id, name, label, description, is_system FROM roles WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $role = $st->fetch();

    if (!$role) {
        return [
            'can_delete' => false,
            'reason'     => 'Role not found.',
            'role'       => null,
            'counts'     => [],
        ];
    }

    // 1. Built-in system roles cannot be deleted
    if ((int)$role['is_system'] === 1) {
        return [
            'can_delete' => false,
            'reason'     => 'System built-in roles cannot be deleted.',
            'role'       => $role,
            'counts'     => [],
        ];
    }

    // 2. Count assigned users in company_user_assignments
    $stCount = $pdo->prepare('SELECT COUNT(*) FROM company_user_assignments WHERE role_id = ?');
    $stCount->execute([$id]);
    $assignedCount = (int)$stCount->fetchColumn();

    if ($assignedCount > 0) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete role '{$role['label']}': Used by {$assignedCount} user" . ($assignedCount === 1 ? '' : 's') . '. Reassign them first.',
            'role'       => $role,
            'counts'     => ['assigned_users' => $assignedCount],
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'role'       => $role,
        'counts'     => ['assigned_users' => 0],
    ];
}

/**
 * Permanently deletes a custom non-system role with no assigned users inside a transaction.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{success: bool, message: string}
 */
function delete_role(int $id, PDO $pdo): array
{
    $chk = check_role_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $role  = $chk['role'];
    $name  = (string)$role['name'];
    $label = (string)$role['label'];

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);

        log_activity('role.delete', 'role', $id, [
            'name'  => $name,
            'label' => $label,
        ]);

        clear_access_cache();

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => "Custom role '{$label}' was permanently deleted.",
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete role {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Cannot delete role '{$label}' due to database integrity constraints. Keep it deactivated instead.",
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting role {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the role: ' . $e->getMessage(),
        ];
    }
}

