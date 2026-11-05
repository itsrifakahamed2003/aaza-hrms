<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Authorization: Requires leave.manage_types
if (!user_can('leave.manage_types')) {
    abort(403, 'You do not have permission to manage leave types.');
}

$pdo = db();
$accessibleCompanies = accessible_company_ids();
$isGroupAdmin = is_group_admin();
$canDeleteType = $isGroupAdmin && user_can('leave_type.delete');

// Fetch accessible companies for dropdown
$switchCompanies = [];
if (!empty($accessibleCompanies)) {
    $ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
    $compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($ph) ORDER BY name");
    $compSt->execute($accessibleCompanies);
    $switchCompanies = $compSt->fetchAll();
}

$editId = (int)($_GET['edit'] ?? 0);
$editType = null;
if ($editId > 0) {
    $stEdit = $pdo->prepare("SELECT * FROM leave_types WHERE id = ?");
    $stEdit->execute([$editId]);
    $editType = $stEdit->fetch();
    if (!$editType) {
        flash_set('danger', 'Leave type not found.');
        redirect('modules/leave/types.php');
    }
    // Company access check
    if ($editType['company_id'] !== null && !in_array((int)$editType['company_id'], $accessibleCompanies, true)) {
        abort(403, 'You do not have access to manage leave types for this company.');
    }
    if ($editType['company_id'] === null && !$isGroupAdmin) {
        abort(403, 'Only Group Administrators can edit group-wide default leave types.');
    }
}

// Handle Form Submissions (Create / Edit / Toggle Active / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? 'save'));

    if ($action === 'delete') {
        if (!is_group_admin() || !user_can('leave_type.delete')) {
            abort(403, 'Permission denied: only administrators can delete leave types.');
        }

        $typeId = (int)($_POST['type_id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM leave_types WHERE id = ?');
        $st->execute([$typeId]);
        $row = $st->fetch();

        if (!$row) {
            flash_set('danger', 'Leave type not found.');
            redirect('modules/leave/types.php');
        }

        $compId = $row['company_id'] !== null ? (int)$row['company_id'] : null;
        if ($compId !== null && !in_array($compId, $accessibleCompanies, true)) {
            abort(403, 'Access denied for this company.');
        }
        if ($compId === null && !is_group_admin()) {
            abort(403, 'Only Group Administrators can delete group-wide leave types.');
        }

        $delRes = delete_leave_type($typeId, $pdo);

        if ($delRes['success']) {
            flash_set('success', $delRes['message']);
        } else {
            flash_set('danger', $delRes['message']);
        }

        redirect('modules/leave/types.php');
    }

    if ($action === 'toggle_active') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM leave_types WHERE id = ?");
        $st->execute([$typeId]);
        $row = $st->fetch();

        if (!$row) {
            flash_set('danger', 'Leave type not found.');
            redirect('modules/leave/types.php');
        }

        $compId = $row['company_id'] !== null ? (int)$row['company_id'] : null;
        if ($compId !== null && !in_array($compId, $accessibleCompanies, true)) {
            abort(403, 'Access denied for this company.');
        }
        if ($compId === null && !$isGroupAdmin) {
            abort(403, 'Only Group Administrators can modify group-wide leave types.');
        }

        $newActive = (int)$row['is_active'] === 1 ? 0 : 1;
        $upSt = $pdo->prepare("UPDATE leave_types SET is_active = ? WHERE id = ?");
        $upSt->execute([$newActive, $typeId]);

        log_activity(
            'leave_type.toggle_active',
            'leave_type',
            $typeId,
            ['old_is_active' => $row['is_active'], 'new_is_active' => $newActive],
            $compId
        );

        $statusLabel = $newActive === 1 ? 'activated' : 'deactivated';
        flash_set('success', "Leave type '{$row['name']}' has been {$statusLabel}.");
        redirect('modules/leave/types.php');
    }

    if ($action === 'save') {
        $typeId          = (int)($_POST['type_id'] ?? 0);
        $name            = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $description     = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
        $rawCompanyId    = trim((string)($_POST['company_id'] ?? ''));
        $daysPerYear     = (float)($_POST['days_per_year'] ?? 0.0);
        $requiresDocument = !empty($_POST['requires_document']) ? 1 : 0;
        $isActive        = !empty($_POST['is_active']) ? 1 : 0;

        $targetCompanyId = ($rawCompanyId === '' || $rawCompanyId === '0') ? null : (int)$rawCompanyId;

        // Validation
        if ($name === '') {
            flash_set('danger', 'Leave type name is required.');
            redirect('modules/leave/types.php' . ($typeId > 0 ? "?edit=$typeId" : ''));
        }
        if ($daysPerYear < 0.0) {
            flash_set('danger', 'Days per year cannot be negative.');
            redirect('modules/leave/types.php' . ($typeId > 0 ? "?edit=$typeId" : ''));
        }

        // Authorization for company scope
        if ($targetCompanyId === null && !$isGroupAdmin) {
            flash_set('danger', 'Only Group Administrators can create or modify group-wide leave types.');
            redirect('modules/leave/types.php' . ($typeId > 0 ? "?edit=$typeId" : ''));
        }
        if ($targetCompanyId !== null && !in_array($targetCompanyId, $accessibleCompanies, true)) {
            abort(403, 'You do not have access to assign leave types to this company.');
        }

        // Unique name per company (or group-wide)
        if ($typeId > 0) {
            $checkSql = "SELECT COUNT(*) FROM leave_types WHERE name = ? AND id != ? AND " .
                ($targetCompanyId === null ? "company_id IS NULL" : "company_id = ?");
            $params = $targetCompanyId === null ? [$name, $typeId] : [$name, $typeId, $targetCompanyId];
        } else {
            $checkSql = "SELECT COUNT(*) FROM leave_types WHERE name = ? AND " .
                ($targetCompanyId === null ? "company_id IS NULL" : "company_id = ?");
            $params = $targetCompanyId === null ? [$name] : [$name, $targetCompanyId];
        }
        $dupSt = $pdo->prepare($checkSql);
        $dupSt->execute($params);
        if ((int)$dupSt->fetchColumn() > 0) {
            flash_set('danger', "A leave type named '{$name}' already exists in this scope.");
            redirect('modules/leave/types.php' . ($typeId > 0 ? "?edit=$typeId" : ''));
        }

        try {
            if ($typeId > 0) {
                // Update
                $upSt = $pdo->prepare(
                    "UPDATE leave_types
                        SET company_id = ?, name = ?, description = ?,
                            days_per_year = ?, requires_document = ?, is_active = ?
                      WHERE id = ?"
                );
                $upSt->execute([
                    $targetCompanyId,
                    $name,
                    $description !== '' ? $description : null,
                    $daysPerYear,
                    $requiresDocument,
                    $isActive,
                    $typeId,
                ]);

                log_activity(
                    'leave_type.edit',
                    'leave_type',
                    $typeId,
                    ['name' => $name, 'company_id' => $targetCompanyId, 'days' => $daysPerYear],
                    $targetCompanyId
                );

                flash_set('success', "Leave type '{$name}' updated successfully.");
            } else {
                // Create
                $insSt = $pdo->prepare(
                    "INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insSt->execute([
                    $targetCompanyId,
                    $name,
                    $description !== '' ? $description : null,
                    $daysPerYear,
                    $requiresDocument,
                    $isActive,
                ]);
                $newId = (int)$pdo->lastInsertId();

                log_activity(
                    'leave_type.create',
                    'leave_type',
                    $newId,
                    ['name' => $name, 'company_id' => $targetCompanyId, 'days' => $daysPerYear],
                    $targetCompanyId
                );

                flash_set('success', "Leave type '{$name}' created successfully.");
            }
        } catch (Throwable $e) {
            error_log('Leave type save error: ' . $e->getMessage());
            flash_set('danger', 'Failed to save leave type due to a database error.');
        }

        redirect('modules/leave/types.php');
    }
}

// Fetch all leave types visible to user
$whereClauses = ['(lt.company_id IS NULL'];
$fetchParams = [];
if (!empty($accessibleCompanies)) {
    $ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
    $whereClauses[] = "lt.company_id IN ($ph))";
    $fetchParams = $accessibleCompanies;
} else {
    $whereClauses[] = "1=0)"; // Only group-wide if no accessible companies
}
$whereSql = implode(' OR ', $whereClauses);

$listSql = "SELECT lt.*, c.name AS company_name, c.code AS company_code,
                   (SELECT COUNT(*) FROM leave_requests lr WHERE lr.leave_type_id = lt.id) AS used_count
              FROM leave_types lt
         LEFT JOIN companies c ON c.id = lt.company_id
             WHERE $whereSql
             ORDER BY (lt.company_id IS NULL) DESC, c.name ASC, lt.name ASC";
$listSt = $pdo->prepare($listSql);
$listSt->execute($fetchParams);
$leaveTypes = $listSt->fetchAll();

$pageTitle = 'Leave Types Management';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Breadcrumb -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/leave/index.php')) ?>">Leave</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Leave Types</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0">Leave Types &amp; Policy Allowances</h1>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/leave/index.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar2-range me-1"></i> Leave Requests
            </a>
            <a href="<?= e(url('modules/leave/reports.php')) ?>" class="btn btn-outline-primary">
                <i class="bi bi-bar-chart me-1"></i> Leave Reports
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- 1. Configured Leave Types Table (Full Width) -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-card-checklist text-primary fs-5"></i>
                        <h5 class="card-title mb-0">Configured Leave Types (<?= count($leaveTypes) ?>)</h5>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <small class="text-body-secondary">Deactivate first to safely delete types with no leave records.</small>
                        <a href="#leaveTypeFormCard" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1"></i> Add Leave Type
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th class="ps-3" style="min-width: 280px;">Scope &amp; Name</th>
                                    <th style="min-width: 140px;">Days / Year</th>
                                    <th style="min-width: 140px;">Requires Doc</th>
                                    <th style="min-width: 120px;">Status</th>
                                    <th class="text-end pe-3" style="min-width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($leaveTypes)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">No leave types found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leaveTypes as $t): ?>
                                        <tr class="<?= ($editType && (int)$editType['id'] === (int)$t['id']) ? 'table-primary' : '' ?>">
                                            <td class="ps-3">
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if ($t['company_id'] === null): ?>
                                                        <span class="badge text-bg-dark" title="Applicable across all AaZa Group companies">Group-Wide</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-body-secondary text-body border" title="<?= e($t['company_name'] ?? '') ?>"><?= e($t['company_code'] ?? 'Company') ?></span>
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="fw-semibold text-body"><?= e($t['name']) ?></span>
                                                        <?php if (!empty($t['description'])): ?>
                                                            <div class="text-muted small"><?= e($t['description']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ((float)$t['days_per_year'] <= 0): ?>
                                                    <span class="badge text-bg-secondary">Unlimited (0)</span>
                                                <?php else: ?>
                                                    <span class="fw-bold font-monospace"><?= e(number_format((float)$t['days_per_year'], 1)) ?></span> days
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$t['requires_document'] === 1): ?>
                                                    <span class="badge text-bg-warning text-body"><i class="bi bi-paperclip me-1"></i>Required</span>
                                                <?php else: ?>
                                                    <span class="text-muted small">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$t['is_active'] === 1): ?>
                                                    <span class="badge text-bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                                <?php if ((int)$t['used_count'] > 0): ?>
                                                    <span class="text-muted small d-block" style="font-size:0.75rem;"><?= (int)$t['used_count'] ?> requests</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= e(url('modules/leave/types.php?edit=' . (int)$t['id'] . '#leaveTypeFormCard')) ?>" class="btn btn-outline-primary" title="Edit Policy">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="post" action="<?= e(url('modules/leave/types.php')) ?>" class="d-inline" data-confirm="Toggle active status for '<?= e($t['name']) ?>'?" data-confirm-type="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_active">
                                                        <input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-<?= (int)$t['is_active'] === 1 ? 'warning' : 'success' ?>" title="<?= (int)$t['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>">
                                                            <i class="bi bi-power"></i>
                                                        </button>
                                                    </form>
                                                    <?php if ($canDeleteType && (int)$t['is_active'] === 0 && (int)$t['used_count'] === 0): ?>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteLeaveTypeModal<?= (int)$t['id'] ?>" title="Delete leave type">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Form Column (Create / Edit) Positioned Under the Table -->
        <div class="col-12" id="leaveTypeFormCard">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= $editType ? 'bi-pencil-square text-warning' : 'bi-plus-circle text-primary' ?> fs-5"></i>
                        <h5 class="card-title mb-0">
                            <?= $editType ? 'Edit Leave Type: ' . e($editType['name']) : 'Create New Leave Type' ?>
                        </h5>
                    </div>
                    <?php if ($editType): ?>
                        <a href="<?= e(url('modules/leave/types.php')) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg me-1"></i> Cancel Edit
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('modules/leave/types.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="type_id" value="<?= $editType ? (int)$editType['id'] : 0 ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Leave Type Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" maxlength="60" required
                                       value="<?= e($editType['name'] ?? '') ?>" placeholder="e.g. Annual Leave, Bereavement Leave">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Scope / Company Assignment</label>
                                <select name="company_id" class="form-select">
                                    <?php if ($isGroupAdmin): ?>
                                        <option value="" <?= ($editType && $editType['company_id'] === null) ? 'selected' : '' ?>>
                                            -- Group-Wide Default (All Companies) --
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($switchCompanies as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= ($editType && (int)$editType['company_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                            <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Group-wide types are available to all companies unless customized.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Annual Allowance (Days / Year) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" step="0.5" min="0" max="365" name="days_per_year" class="form-control" required
                                           value="<?= e((string)($editType['days_per_year'] ?? '14.0')) ?>">
                                    <span class="input-group-text bg-body-tertiary">Days</span>
                                </div>
                                <div class="form-text">Set to 0 for unlimited / unpaid leaves.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Supporting Documents</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="requires_document" id="reqDocCheck" value="1"
                                           <?= !empty($editType['requires_document']) ? ' checked' : '' ?>>
                                    <label class="form-check-label fw-medium" for="reqDocCheck">
                                        Require Supporting Document
                                    </label>
                                </div>
                                <div class="form-text">If enabled, employee must attach a medical certificate or proof.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Policy Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="activeCheck" value="1"
                                           <?= (!isset($editType['is_active']) || (int)$editType['is_active'] === 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-medium" for="activeCheck">
                                        Active Policy
                                    </label>
                                </div>
                                <div class="form-text">Inactive types cannot be selected for new leave requests.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" class="form-control" rows="2" maxlength="255"
                                          placeholder="Brief explanation of eligibility or requirements..."><?= e($editType['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> <?= $editType ? 'Update Leave Type' : 'Create Leave Type' ?>
                            </button>
                            <?php if ($editType): ?>
                                <a href="<?= e(url('modules/leave/types.php')) ?>" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canDeleteType): ?>
    <?php foreach ($leaveTypes as $t): ?>
        <?php if ((int)$t['is_active'] === 0 && (int)$t['used_count'] === 0): ?>
            <?php $delTId = (int)$t['id']; ?>
            <div class="modal fade" id="deleteLeaveTypeModal<?= $delTId ?>" tabindex="-1" aria-labelledby="deleteLeaveTypeModalLabel<?= $delTId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-danger shadow">
                        <form method="post" action="<?= e(url('modules/leave/types.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="type_id" value="<?= $delTId ?>">
                            <div class="modal-header border-danger">
                                <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteLeaveTypeModalLabel<?= $delTId ?>">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Leave Type: <?= e($t['name']) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-start">
                                <p class="mb-2">Are you sure you want to permanently delete leave type <strong><?= e($t['name']) ?></strong>?</p>
                                <div class="alert alert-warning small mb-0">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    <strong>Safety Guard:</strong> If any employee leave applications reference this leave type, deletion will be blocked and you will be instructed to keep it deactivated instead.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="bi bi-trash me-1"></i>Permanently Delete
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
