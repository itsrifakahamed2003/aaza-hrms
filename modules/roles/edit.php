<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('role.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Role not found.');
}

// Fetch role
$stRole = $pdo->prepare('SELECT id, name, label, description, is_system FROM roles WHERE id = ? LIMIT 1');
$stRole->execute([$id]);
$role = $stRole->fetch();
if (!$role) {
    abort(404, 'Role not found.');
}

// Group Admin role has immutable global access
if ($role['name'] === 'group_admin') {
    flash_set('info', 'The Group Administrator role inherently possesses all system permissions and cannot be customized.');
    redirect('modules/roles/index.php');
}

// Fetch all permissions in the system
$allPerms = $pdo->query('SELECT id, code, module, description FROM permissions ORDER BY module ASC, code ASC')->fetchAll();
$validPermMap = [];
$codeToId = [];
$idToCode = [];
foreach ($allPerms as $p) {
    $pid = (int)$p['id'];
    $validPermMap[$pid] = true;
    $codeToId[$p['code']] = $pid;
    $idToCode[$pid] = $p['code'];
}

// Handle Form Submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $rawPosted = $_POST['perms'] ?? [];
    if (!is_array($rawPosted)) {
        $rawPosted = [];
    }

    $postedPermIds = [];
    foreach ($rawPosted as $pid) {
        $pid = (int)$pid;
        if (isset($validPermMap[$pid])) {
            $postedPermIds[] = $pid;
        }
    }
    $postedPermIds = array_unique($postedPermIds);

    // Lockout Guard for company_admin role:
    // Prevent removing the last permission set that would lock out company_admin from user management
    if ($role['name'] === 'company_admin') {
        $userViewId   = $codeToId['user.view'] ?? null;
        $userManageId = $codeToId['user.manage'] ?? null;
        $userEditId   = $codeToId['user.edit'] ?? null;

        $hasView = $userViewId !== null && in_array($userViewId, $postedPermIds, true);
        $hasManage = ($userManageId !== null && in_array($userManageId, $postedPermIds, true))
                  || ($userEditId !== null && in_array($userEditId, $postedPermIds, true));

        if (!$hasView || !$hasManage) {
            $errors[] = 'Administrative lockout protection: Company Administrator must retain user management permissions (user.view and user.manage/user.edit).';
        }
    }

    if (!$errors) {
        // Fetch current role permissions to compute diff
        $stCurr = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
        $stCurr->execute([$id]);
        $currentPermIds = array_map('intval', $stCurr->fetchAll(PDO::FETCH_COLUMN));

        $addedIds   = array_values(array_diff($postedPermIds, $currentPermIds));
        $removedIds = array_values(array_diff($currentPermIds, $postedPermIds));

        $addedCodes   = array_map(fn($pid) => $idToCode[$pid] ?? (string)$pid, $addedIds);
        $removedCodes = array_map(fn($pid) => $idToCode[$pid] ?? (string)$pid, $removedIds);

        // Transactional write
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $del->execute([$id]);

            if (!empty($postedPermIds)) {
                $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($postedPermIds as $pid) {
                    $ins->execute([$id, $pid]);
                }
            }

            $pdo->commit();
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }

        clear_access_cache();

        log_activity('role.permissions_update', 'role', $id, [
            'role'          => $role['name'],
            'added'         => $addedCodes,
            'removed'       => $removedCodes,
            'total_granted' => count($postedPermIds)
        ]);

        flash_set('success', "Permissions for '{$role['label']}' successfully saved.");
        redirect('modules/roles/edit.php?id=' . $id);
    }
}

// Current granted permissions for this role
$stGranted = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
$stGranted->execute([$id]);
$currentGrantedMap = array_fill_keys(array_map('intval', $stGranted->fetchAll(PDO::FETCH_COLUMN)), true);

// Group permissions by module
$permsByModule = [];
foreach ($allPerms as $p) {
    $mod = (string)$p['module'];
    $permsByModule[$mod][] = $p;
}

$totalPermsCount = count($allPerms);
$initialSelectedCount = count($currentGrantedMap);

$pageTitle = 'Edit Permissions: ' . $role['label'];
require __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('modules/roles/index.php')) ?>">Roles &amp; Permissions</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($role['label']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-2">
            <h1 class="page-header-title">Configure Permissions: <?= e($role['label']) ?></h1>
            <?php if ((int)$role['is_system'] === 1): ?>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Built-in Role</span>
            <?php else: ?>
                <span class="badge bg-info-subtle text-info border border-info-subtle">Custom Role</span>
            <?php endif; ?>
        </div>
        <p class="page-header-desc">
            <?= e($role['description'] ?: 'Select the individual granular permissions granted to users holding this role.') ?>
        </p>
    </div>
    <div class="page-header-actions page-actions">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/roles/index.php')) ?>">
            <i class="bi bi-arrow-left me-1"></i> Back to Roles
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mb-4 shadow-sm">
        <div class="d-flex align-items-center gap-2 mb-1 fw-bold">
            <i class="bi bi-shield-exclamation fs-5"></i> Unable to save role permissions:
        </div>
        <ul class="mb-0 ps-3 small">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Summary Toolbar -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">Selection summary:</span>
            <span id="totalCounterBadge" class="badge bg-primary fs-6">
                <?= $initialSelectedCount ?> of <?= $totalPermsCount ?> selected
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectAllGlobal">
                <i class="bi bi-check-all me-1"></i> Select All
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnDeselectAllGlobal">
                <i class="bi bi-x me-1"></i> Deselect All
            </button>
        </div>
    </div>
</div>

<!-- Main Permission Editor Form -->
<form method="post" id="rolePermissionsForm" novalidate>
    <?= csrf_field() ?>

    <div class="accordion mb-5" id="accordionModules">
        <?php foreach ($permsByModule as $moduleName => $modulePerms): ?>
            <?php
            $modKey = preg_replace('/[^a-zA-Z0-9]/', '_', $moduleName);
            $collapseId = 'collapseMod_' . $modKey;
            $modTotal = count($modulePerms);
            $modSelected = 0;
            foreach ($modulePerms as $p) {
                if (isset($currentGrantedMap[(int)$p['id']])) {
                    $modSelected++;
                }
            }
            $allModSelected = ($modSelected === $modTotal && $modTotal > 0);
            ?>
            <div class="accordion-item mb-3 border rounded shadow-none module-card">
                <div class="accordion-header bg-body p-3 d-flex align-items-center justify-content-between gap-3">
                    <button class="accordion-button collapsed p-0 bg-transparent shadow-none w-auto flex-grow-1 fw-bold text-body"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= e($collapseId) ?>"
                            aria-expanded="false"
                            aria-controls="<?= e($collapseId) ?>">
                        <span class="text-capitalize fs-6 flex-grow-1 text-start"><?= e($moduleName) ?> Module</span>
                        <span class="badge bg-secondary-subtle text-secondary module-count-badge me-3" data-module="<?= e($moduleName) ?>" style="font-size: 0.72rem; min-width: 80px; text-align: center; display: inline-flex; align-items: center; justify-content: center;">
                            <?= $modSelected ?> of <?= $modTotal ?>
                        </span>
                    </button>

                    <!-- Module Select All Switch -->
                    <div class="form-check form-switch m-0 flex-shrink-0" onclick="event.stopPropagation();">
                        <input class="form-check-input module-select-all" type="checkbox" role="switch"
                               id="switch_<?= e($modKey) ?>"
                               data-module="<?= e($moduleName) ?>"
                               <?= $allModSelected ? 'checked' : '' ?>
                               title="Toggle all permissions in this module">
                        <label class="form-check-label small text-muted user-select-none" for="switch_<?= e($modKey) ?>">
                            Select All
                        </label>
                    </div>
                </div>

                <div id="<?= e($collapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#accordionModules">
                    <div class="accordion-body p-0 border-top">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <tbody>
                                <?php foreach ($modulePerms as $p): ?>
                                    <?php
                                    $pid = (int)$p['id'];
                                    $isChecked = isset($currentGrantedMap[$pid]);
                                    $isLockoutProtected = ($role['name'] === 'company_admin' && in_array($p['code'], ['user.view', 'user.manage'], true));
                                    ?>
                                    <tr class="permission-row">
                                        <td style="width: 48px;" class="ps-3">
                                            <div class="form-check m-0">
                                                <input class="form-check-input perm-checkbox" type="checkbox"
                                                       name="perms[]" value="<?= $pid ?>"
                                                       id="perm_<?= $pid ?>"
                                                       data-module="<?= e($moduleName) ?>"
                                                       data-code="<?= e($p['code']) ?>"
                                                       <?= $isChecked ? 'checked' : '' ?>>
                                                <label class="visually-hidden" for="perm_<?= $pid ?>"><?= e($p['code']) ?></label>
                                            </div>
                                        </td>
                                        <td>
                                            <label for="perm_<?= $pid ?>" class="d-flex align-items-center gap-2 mb-1 cursor-pointer">
                                                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($p['code']) ?></span>
                                                <?php if ($isLockoutProtected): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border" title="Required to manage company users">
                                                        <i class="bi bi-shield-lock me-1"></i>Protected
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                            <div class="small text-muted"><?= e($p['description'] ?: 'No description provided') ?></div>
                                        </td>
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

    <!-- Sticky Save Bar at Bottom -->
    <div class="sticky-save-bar rounded-top">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold text-body">
                <i class="bi bi-shield-check text-primary me-1"></i> <?= e($role['label']) ?>:
            </span>
            <span id="stickyCounter" class="text-muted small">
                <?= $initialSelectedCount ?> of <?= $totalPermsCount ?> permissions selected
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/roles/index.php')) ?>">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary btn-sm px-4">
                <i class="bi bi-check2 me-1"></i> Save Permissions
            </button>
        </div>
    </div>
</form>

<!-- Vanilla JS for Live Counting and Select All Toggles -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var totalBadge   = document.getElementById('totalCounterBadge');
    var stickyCount  = document.getElementById('stickyCounter');
    var checkboxes   = document.querySelectorAll('.perm-checkbox');
    var moduleSwitches = document.querySelectorAll('.module-select-all');
    var totalCount   = <?= (int)$totalPermsCount ?>;

    function updateCounters() {
        var selectedTotal = 0;
        var moduleCounts = {};

        checkboxes.forEach(function(cb) {
            var mod = cb.getAttribute('data-module');
            if (!moduleCounts[mod]) {
                moduleCounts[mod] = { total: 0, checked: 0 };
            }
            moduleCounts[mod].total++;
            if (cb.checked) {
                selectedTotal++;
                moduleCounts[mod].checked++;
            }
        });

        // Update total badges
        if (totalBadge) {
            totalBadge.textContent = selectedTotal + ' of ' + totalCount + ' selected';
        }
        if (stickyCount) {
            stickyCount.textContent = selectedTotal + ' of ' + totalCount + ' permissions selected';
        }

        // Update per-module counters and switches
        moduleSwitches.forEach(function(sw) {
            var mod = sw.getAttribute('data-module');
            var data = moduleCounts[mod];
            if (data) {
                sw.checked = (data.checked === data.total && data.total > 0);
                var countBadge = document.querySelector('.module-count-badge[data-module="' + CSS.escape(mod) + '"]');
                if (countBadge) {
                    countBadge.textContent = data.checked + ' of ' + data.total;
                    if (data.checked === data.total && data.total > 0) {
                        countBadge.className = 'badge bg-success-subtle text-success border border-success-subtle module-count-badge me-3';
                    } else if (data.checked > 0) {
                        countBadge.className = 'badge bg-primary-subtle text-primary border border-primary-subtle module-count-badge me-3';
                    } else {
                        countBadge.className = 'badge bg-secondary-subtle text-secondary module-count-badge me-3';
                    }
                }
            }
        });
    }

    // Individual checkbox change
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateCounters);
    });

    // Module "Select All" switch change
    moduleSwitches.forEach(function(sw) {
        sw.addEventListener('change', function() {
            var mod = sw.getAttribute('data-module');
            var isChecked = sw.checked;
            document.querySelectorAll('.perm-checkbox[data-module="' + CSS.escape(mod) + '"]').forEach(function(cb) {
                cb.checked = isChecked;
            });
            updateCounters();
        });
    });

    // Global Select All
    var btnSelectAll = document.getElementById('btnSelectAllGlobal');
    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function() {
            checkboxes.forEach(function(cb) {
                cb.checked = true;
            });
            updateCounters();
        });
    }

    // Global Deselect All
    var btnDeselectAll = document.getElementById('btnDeselectAllGlobal');
    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', function() {
            checkboxes.forEach(function(cb) {
                cb.checked = false;
            });
            updateCounters();
        });
    }

    // Initial run
    updateCounters();
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
