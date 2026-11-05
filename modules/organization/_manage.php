<?php
declare(strict_types=1);

/**
 * Shared page for managing a per-company list (departments or designations).
 * Included by departments.php / designations.php, which define $org.
 * Not meant to be opened directly.
 */
if (!isset($org) || !is_array($org)) {
    http_response_code(404);
    exit;
}

$perm  = 'department.manage';
$table = $org['table'];   // fixed values from the calling page, never from the browser
$field = $org['field'];
$fk    = $org['fk'];

require_permission($perm);

$pdo     = db();
$allowed = accessible_company_ids($perm);
if (!$allowed) {
    abort(403, 'No company is available for you to manage.');
}
$ph = implode(',', array_fill(0, count($allowed), '?'));
$st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) ORDER BY name");
$st->execute($allowed);
$companies = $st->fetchAll();

$back = function (int $cid) use ($org): void {
    redirect($org['self'] . '?company_id=' . $cid);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');
    $name   = trim((string)($_POST['name'] ?? ''));
    $desc   = trim((string)($_POST['description'] ?? ''));

    if ($action === 'add' || $action === 'seed') {
        $cid = (int)($_POST['company_id'] ?? 0);
        require_company_access($cid, $perm);   // the company id comes from the browser, so check it

        if ($action === 'add') {
            if ($name === '' || mb_strlen($name) > 100) {
                flash_set('danger', $org['singular'] . ' name is required (max 100 characters).');
            } elseif (mb_strlen($desc) > 255) {
                flash_set('danger', 'Description is too long (max 255 characters).');
            } else {
                try {
                    $pdo->prepare("INSERT INTO $table (company_id, $field, description) VALUES (?, ?, ?)")
                        ->execute([$cid, $name, $desc ?: null]);
                    log_activity($org['key'] . '.create', $org['key'], (int)$pdo->lastInsertId(), ['name' => $name], $cid);
                    flash_set('success', $org['singular'] . ' added.');
                } catch (PDOException $ex) {
                    if ($ex->getCode() !== '23000') {
                        throw $ex;
                    }
                    flash_set('danger', 'That ' . strtolower($org['singular']) . ' already exists in this company.');
                }
            }
        } else {
            $added = 0;
            $ins = $pdo->prepare("INSERT IGNORE INTO $table (company_id, $field) VALUES (?, ?)");
            foreach ($org['defaults'] as $default) {
                $ins->execute([$cid, $default]);
                $added += $ins->rowCount();
            }
            log_activity($org['key'] . '.seed_defaults', $org['key'], null, ['added' => $added], $cid);
            flash_set('success', $added . ' default ' . strtolower($org['plural']) . ' added (existing ones were skipped).');
        }
        $back($cid);
    }

    if ($action === 'update' || $action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT id, company_id, $field AS label, is_active FROM $table WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            abort(404, $org['singular'] . ' not found.');
        }
        $cid = (int)$row['company_id'];
        require_company_access($cid, $perm);   // check against the row's real company, not a posted one

        if ($action === 'toggle') {
            $new = $row['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE $table SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            log_activity($org['key'] . ($new ? '.activate' : '.deactivate'), $org['key'], $id, ['name' => $row['label']], $cid);
            flash_set('success', $row['label'] . ($new ? ' activated.' : ' deactivated. Existing employees keep it.'));
        } else {
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($desc) > 255) {
                flash_set('danger', 'Name is required (max 100 characters); description max 255.');
            } else {
                try {
                    $pdo->prepare("UPDATE $table SET $field = ?, description = ? WHERE id = ?")
                        ->execute([$name, $desc ?: null, $id]);
                    log_activity($org['key'] . '.update', $org['key'], $id, ['from' => $row['label'], 'to' => $name], $cid);
                    flash_set('success', $org['singular'] . ' updated.');
                } catch (PDOException $ex) {
                    if ($ex->getCode() !== '23000') {
                        throw $ex;
                    }
                    flash_set('danger', 'Another ' . strtolower($org['singular']) . ' with that name already exists.');
                }
            }
        }
        $back($cid);
    }

    if ($action === 'delete') {
        $delPerm = $org['key'] . '.delete';
        if (!is_group_admin() || !user_can($delPerm)) {
            abort(403, 'Permission denied: only administrators can delete ' . strtolower($org['plural']) . '.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT id, company_id, $field AS label, is_active FROM $table WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            flash_set('danger', $org['singular'] . ' not found.');
            $back((int)($_POST['company_id'] ?? active_company_id() ?? 0));
        }
        $cid = (int)$row['company_id'];
        require_company_access($cid, $perm);

        if ($org['key'] === 'department') {
            $delRes = delete_department($id, $pdo);
        } else {
            $delRes = delete_designation($id, $pdo);
        }

        if ($delRes['success']) {
            flash_set('success', $delRes['message']);
        } else {
            flash_set('danger', $delRes['message']);
        }

        $back($cid);
    }

    abort(400, 'Unknown action.');
}

// Which company are we looking at?
$companyId = (int)($_GET['company_id'] ?? 0);
if ($companyId === 0) {
    $companyId = active_company_id() ?? 0;
    if (!in_array($companyId, $allowed, true)) {
        $companyId = (int)$companies[0]['id'];
    }
}
if (!in_array($companyId, $allowed, true)) {
    log_activity('access.denied', 'company', $companyId, ['page' => $org['self']], $companyId);
    abort(403);
}

$refSql = ($org['key'] === 'department')
    ? "(SELECT COUNT(*) FROM employees WHERE department_id = x.id) +
       (SELECT COUNT(*) FROM job_vacancies WHERE department_id = x.id) +
       (SELECT COUNT(*) FROM job_offers WHERE department_id = x.id) +
       (SELECT COUNT(*) FROM employment_history WHERE department_id = x.id) +
       (SELECT COUNT(*) FROM employee_transfers WHERE from_department_id = x.id OR to_department_id = x.id)"
    : "(SELECT COUNT(*) FROM employees WHERE designation_id = x.id) +
       (SELECT COUNT(*) FROM job_vacancies WHERE designation_id = x.id) +
       (SELECT COUNT(*) FROM job_offers WHERE designation_id = x.id) +
       (SELECT COUNT(*) FROM employment_history WHERE designation_id = x.id) +
       (SELECT COUNT(*) FROM employee_transfers WHERE from_designation_id = x.id OR to_designation_id = x.id)";

$st = $pdo->prepare(
    "SELECT x.id, x.$field AS label, x.description, x.is_active,
            (SELECT COUNT(*) FROM employees e WHERE e.$fk = x.id) AS used,
            ($refSql) AS total_refs
       FROM $table x
      WHERE x.company_id = ?
      ORDER BY x.is_active DESC, x.$field"
);
$st->execute([$companyId]);
$items = $st->fetchAll();

$deletePerm = $org['key'] . '.delete';
$canDelete  = is_group_admin() && user_can($deletePerm);

$pageTitle = $org['plural'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e($org['plural']) ?></h1>
        <p class="text-muted mb-0 small">
            Kept per company. Deactivate first to safely delete records with no dependent data.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm <?= $org['key'] === 'department' ? 'btn-dark' : 'btn-outline-dark' ?>"
           href="<?= e(url('modules/organization/departments.php?company_id=' . $companyId)) ?>">Departments</a>
        <a class="btn btn-sm <?= $org['key'] === 'designation' ? 'btn-dark' : 'btn-outline-dark' ?>"
           href="<?= e(url('modules/organization/designations.php?company_id=' . $companyId)) ?>">Designations</a>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-sm-6 col-md-4">
        <label class="visually-hidden" for="company_id">Company</label>
        <select class="form-select" id="company_id" name="company_id" onchange="this.form.submit()">
            <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $companyId ? ' selected' : '' ?>>
                    <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-body fw-semibold">Add <?= e(strtolower($org['singular'])) ?></div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="company_id" value="<?= $companyId ?>">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="new_name"><?= e($org['singular']) ?> name</label>
                <input class="form-control" id="new_name" name="name" maxlength="100" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1" for="new_desc">Description (optional)</label>
                <input class="form-control" id="new_desc" name="description" maxlength="255">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary" type="submit">Add</button>
            </div>
        </form>
        <?php if (!$items): ?>
            <form method="post" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="seed">
                <input type="hidden" name="company_id" value="<?= $companyId ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">
                    Add the standard AaZa <?= e(strtolower($org['plural'])) ?> (<?= count($org['defaults']) ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-active">
            <tr><th><?= e($org['singular']) ?></th><th>Description</th><th class="text-center">Employees</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <?php $iid = (int)$it['id']; ?>
                <tr class="<?= $it['is_active'] ? '' : 'text-muted' ?>">
                    <td style="min-width:200px">
                        <input class="form-control form-control-sm" name="name" maxlength="100" required
                               form="u<?= $iid ?>" value="<?= e($it['label']) ?>"
                               aria-label="<?= e($org['singular']) ?> name">
                    </td>
                    <td style="min-width:220px">
                        <input class="form-control form-control-sm" name="description" maxlength="255"
                               form="u<?= $iid ?>" value="<?= e($it['description']) ?>" aria-label="Description">
                    </td>
                    <td class="text-center"><?= (int)$it['used'] ?></td>
                    <td>
                        <?= $it['is_active']
                            ? '<span class="badge text-bg-success">Active</span>'
                            : '<span class="badge text-bg-secondary">Inactive</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <form id="u<?= $iid ?>" method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $iid ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $iid ?>">
                            <button class="btn btn-sm btn-outline-<?= $it['is_active'] ? 'danger' : 'success' ?>" type="submit">
                                <?= $it['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <?php if ($canDelete && !(int)$it['is_active'] && (int)$it['total_refs'] === 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteOrgModal<?= $iid ?>" title="Delete <?= strtolower($org['singular']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">
                    No <?= e(strtolower($org['plural'])) ?> yet for this company.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canDelete): ?>
    <?php foreach ($items as $it): ?>
        <?php if (!(int)$it['is_active'] && (int)$it['total_refs'] === 0): ?>
            <?php $delModalId = (int)$it['id']; ?>
            <div class="modal fade" id="deleteOrgModal<?= $delModalId ?>" tabindex="-1" aria-labelledby="deleteOrgModalLabel<?= $delModalId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-danger shadow">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $delModalId ?>">
                            <input type="hidden" name="company_id" value="<?= $companyId ?>">
                            <div class="modal-header border-danger">
                                <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteOrgModalLabel<?= $delModalId ?>">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete <?= e($org['singular']) ?>: <?= e($it['label']) ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-start">
                                <p class="mb-2">Are you sure you want to permanently delete <?= strtolower($org['singular']) ?> <strong><?= e($it['label']) ?></strong>?</p>
                                <div class="alert alert-warning small mb-0">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    <strong>Safety Guard:</strong> If this <?= strtolower($org['singular']) ?> is referenced by any employees, vacancies, offers, or historical transfer records, deletion will be blocked and you must keep it deactivated instead.
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
