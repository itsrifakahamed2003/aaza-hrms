<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('role.manage');

$pdo = db();

// Handle POST actions: Create Custom Role & Delete Custom Role
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));

    // Create New Custom Role
    if ($action === 'create_role') {
        $rawName = trim((string)($_POST['name'] ?? ''));
        $label   = trim((string)($_POST['label'] ?? ''));
        $desc    = trim((string)($_POST['description'] ?? ''));

        // Normalize slug: lowercase letters, digits, and underscores
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $rawName));
        $slug = preg_replace('/_+/', '_', trim($slug, '_'));

        $errors = [];
        if (!preg_match('/^[a-z0-9_]{3,50}$/', $slug)) {
            $errors[] = 'Role identifier must be 3-50 alphanumeric characters or underscores.';
        }
        if ($label === '' || mb_strlen($label) > 100) {
            $errors[] = 'Role label is required (max 100 characters).';
        }

        if (!$errors) {
            $stDup = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
            $stDup->execute([$slug]);
            if ($stDup->fetch()) {
                $errors[] = "A role with the identifier '{$slug}' already exists.";
            }
        }

        if ($errors) {
            flash_set('danger', implode(' ', $errors));
            redirect('modules/roles/index.php');
        }

        $stIns = $pdo->prepare('INSERT INTO roles (name, label, description, is_system) VALUES (?, ?, ?, 0)');
        $stIns->execute([$slug, $label, $desc !== '' ? $desc : null]);
        $newRoleId = (int)$pdo->lastInsertId();

        log_activity('role.create', 'role', $newRoleId, [
            'name'  => $slug,
            'label' => $label
        ]);

        flash_set('success', "Custom role '{$label}' created. Configure its permissions below.");
        redirect('modules/roles/edit.php?id=' . $newRoleId);
    }

    // Delete Custom Role
    if ($action === 'delete_role') {
        if (!is_group_admin() || !user_can('role.delete')) {
            abort(403, 'Permission denied: only administrators can delete custom roles.');
        }

        $delId = (int)($_POST['role_id'] ?? 0);
        $delRes = delete_role($delId, $pdo);

        if ($delRes['success']) {
            flash_set('success', $delRes['message']);
        } else {
            flash_set('danger', $delRes['message']);
        }
        redirect('modules/roles/index.php');
    }
}

// Fetch all permissions count
$totalPermsCount = (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

// Fetch roles with user assignments count and granted permissions count
$sql = "
    SELECT r.id, r.name, r.label, r.description, r.is_system,
           COUNT(DISTINCT cua.user_id) AS user_count,
           COUNT(DISTINCT rp.permission_id) AS perm_count
      FROM roles r
      LEFT JOIN company_user_assignments cua ON cua.role_id = r.id
      LEFT JOIN role_permissions rp ON rp.role_id = r.id
     GROUP BY r.id, r.name, r.label, r.description, r.is_system
     ORDER BY r.is_system DESC, r.id ASC
";
$roles = $pdo->query($sql)->fetchAll();
$canDeleteRole = is_group_admin() && user_can('role.delete');

$pageTitle = 'Roles & Permissions';
require __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Roles &amp; Permissions</li>
            </ol>
        </nav>
        <h1 class="page-header-title">Roles &amp; Security Profiles</h1>
        <p class="page-header-desc">Manage standard authorization profiles and configure fine-grained module access controls.</p>
    </div>
    <div class="page-header-actions page-actions">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRoleModal">
            <i class="bi bi-shield-plus me-1"></i> New Role
        </button>
    </div>
</div>

<!-- Role Cards Grid -->
<div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4 mb-4">
    <?php foreach ($roles as $r): ?>
        <?php
        $rid = (int)$r['id'];
        $isGroupAdmin = ($r['name'] === 'group_admin');
        $isSystem = ((int)$r['is_system'] === 1);
        $userCount = (int)$r['user_count'];
        $permCount = $isGroupAdmin ? $totalPermsCount : (int)$r['perm_count'];
        $permPercent = $totalPermsCount > 0 ? round(($permCount / $totalPermsCount) * 100) : 0;
        ?>
        <div class="col">
            <div class="card h-100 border-0 shadow-sm role-card">
                <div class="card-body p-4 d-flex flex-column">
                    <!-- Top Badge & Label -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 class="fs-6 fw-bold mb-1 text-body"><?= e($r['label']) ?></h2>
                            <span class="badge bg-body-secondary text-body border font-monospace" style="font-size: 0.72rem;"><?= e($r['name']) ?></span>
                        </div>
                        <div>
                            <?php if ($isSystem): ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    <i class="bi bi-lock me-1"></i>Built-in
                                </span>
                            <?php else: ?>
                                <span class="badge bg-info-subtle text-info border border-info-subtle">
                                    <i class="bi bi-sliders me-1"></i>Custom
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Role Description -->
                    <p class="text-muted small mb-4 flex-grow-1" style="min-height: 40px;">
                        <?= e($r['description'] ?: 'No description specified for this role profile.') ?>
                    </p>

                    <!-- Metrics Box -->
                    <div class="p-3 bg-body-tertiary rounded border mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted d-flex align-items-center gap-1">
                                <i class="bi bi-people"></i> Assigned Users:
                            </span>
                            <span class="fw-semibold text-body small">
                                <?= $userCount ?> <?= $userCount === 1 ? 'user' : 'users' ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small text-muted d-flex align-items-center gap-1">
                                <i class="bi bi-key"></i> Permissions:
                            </span>
                            <span class="fw-semibold text-body small">
                                <?php if ($isGroupAdmin): ?>
                                    <span class="text-primary fw-bold">Full Access (All)</span>
                                <?php else: ?>
                                    <?= $permCount ?> of <?= $totalPermsCount ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div class="progress" style="height: 6px;" role="progressbar" aria-label="Permissions granted"
                             aria-valuenow="<?= (int)$permPercent ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $isGroupAdmin ? 'bg-dark' : 'bg-primary' ?>" style="width: <?= (int)$permPercent ?>%;"></div>
                        </div>
                    </div>

                    <!-- Card Actions -->
                    <div class="d-flex justify-content-between align-items-center gap-2 pt-2 border-top">
                        <div>
                            <span class="small text-muted font-monospace"><?= $userCount ?> user<?= $userCount === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="d-inline-flex align-items-center gap-1">
                            <?php if ($isGroupAdmin): ?>
                                <span class="badge text-bg-secondary"><i class="bi bi-shield-check me-1"></i>Full Access</span>
                            <?php else: ?>
                                <a class="btn btn-outline-primary btn-sm py-1 px-2.5" href="<?= e(url('modules/roles/edit.php?id=' . $rid)) ?>">
                                    <i class="bi bi-pencil-square me-1"></i> Edit
                                </a>
                                <?= action_menu([
                                    [
                                        'type'  => 'link',
                                        'label' => 'View Users (' . $userCount . ')',
                                        'url'   => 'modules/users/index.php?role_id=' . $rid,
                                        'icon'  => 'bi-people',
                                    ],
                                    [
                                        'type'       => 'button',
                                        'label'      => 'Delete Custom Role',
                                        'icon'       => 'bi-trash3',
                                        'modal'      => '#deleteRoleModal' . $rid,
                                        'danger'     => true,
                                        'permission' => (!$isSystem && $canDeleteRole && $userCount === 0),
                                    ],
                                ]) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($canDeleteRole): ?>
    <?php foreach ($roles as $r): ?>
        <?php if (!(int)$r['is_system'] && (int)$r['user_count'] === 0): ?>
            <?php $delRoleId = (int)$r['id']; ?>
            <div class="modal fade" id="deleteRoleModal<?= $delRoleId ?>" tabindex="-1" aria-labelledby="deleteRoleModalLabel<?= $delRoleId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-danger shadow">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_role">
                            <input type="hidden" name="role_id" value="<?= $delRoleId ?>">
                            <div class="modal-header border-danger">
                                <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteRoleModalLabel<?= $delRoleId ?>">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Role: <?= e($r['label']) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-start">
                                <p class="mb-2">Are you sure you want to permanently delete custom role <strong><?= e($r['label']) ?></strong> (<code><?= e($r['name']) ?></code>)?</p>
                                <div class="alert alert-warning small mb-0">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    <strong>Safety Guard:</strong> System roles cannot be deleted. If this custom role has any assigned users, deletion will be blocked and you must reassign them first.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="bi bi-trash3 me-1"></i>Permanently Delete
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal: New Custom Role -->
<div class="modal fade" id="newRoleModal" tabindex="-1" aria-labelledby="newRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_role">
                <div class="modal-header">
                    <h5 class="modal-title fs-6 fw-bold" id="newRoleModalLabel">
                        <i class="bi bi-shield-plus me-1 text-primary"></i> Create Custom Role
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="role_label">Role Display Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="role_label" name="label" required placeholder="e.g. Payroll Specialist" autocomplete="off">
                        <div class="form-text">Human-friendly label shown in user assignment dropdowns.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="role_name">Role Slug Identifier <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="role_name" name="name" required placeholder="e.g. payroll_specialist" autocomplete="off">
                        <div class="form-text">Unique system slug: 3-50 letters, numbers, or underscores.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="role_desc">Description</label>
                        <textarea class="form-control" id="role_desc" name="description" rows="3" placeholder="Describe the responsibilities and scope of this role..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-body-tertiary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Create &amp; Configure Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var labelInput = document.getElementById('role_label');
    var slugInput  = document.getElementById('role_name');
    if (labelInput && slugInput) {
        labelInput.addEventListener('input', function() {
            // Only auto-fill if user hasn't typed manually in slugInput
            if (!slugInput.dataset.touched) {
                var slug = labelInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                slugInput.value = slug;
            }
        });
        slugInput.addEventListener('input', function() {
            slugInput.dataset.touched = 'true';
        });
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
