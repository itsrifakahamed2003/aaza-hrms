<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Company not found.');
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$st->execute([$id]);
$company = $st->fetch();

if (!$company) {
    abort(404, 'Company not found.');
}

$companyId = (int)$company['id'];
require_company_access($companyId, 'company.view');

// Fetch summary metrics
$empCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE company_id = $companyId AND employment_status = 'active'")->fetchColumn();
$totalEmpCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE company_id = $companyId")->fetchColumn();
$deptCount = (int)$pdo->query("SELECT COUNT(*) FROM departments WHERE company_id = $companyId")->fetchColumn();
$desigCount = (int)$pdo->query("SELECT COUNT(*) FROM designations WHERE company_id = $companyId")->fetchColumn();
$userCount = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM company_user_assignments WHERE company_id = $companyId")->fetchColumn();

// Fetch departments & designations for quick overview
$depts = $pdo->prepare('SELECT id, name, description FROM departments WHERE company_id = ? ORDER BY name');
$depts->execute([$companyId]);
$departmentsList = $depts->fetchAll();

$desigs = $pdo->prepare('SELECT id, title, description FROM designations WHERE company_id = ? ORDER BY title');
$desigs->execute([$companyId]);
$designationsList = $desigs->fetchAll();

$isGroupAdmin = is_group_admin();
$canManageCompany = $isGroupAdmin || user_can('company.manage', $companyId);
$canSettings = $isGroupAdmin || user_can('company.settings', $companyId);
$canDelete = ($isGroupAdmin || user_can('company.delete')) && (int)$company['is_active'] === 0;

$pageTitle = $company['name'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb breadcrumb-clean mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/companies/index.php')) ?>">Companies</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($company['name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <?= company_logo_html($company, $company['name'], 'lg', 'shadow-sm') ?>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="page-header-title mb-0"><?= e($company['name']) ?></h1>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($company['code']) ?></span>
                    <?= $company['is_active']
                        ? '<span class="badge badge-soft-success">Active</span>'
                        : '<span class="badge badge-soft-secondary">Inactive</span>' ?>
                </div>
                <div class="text-muted small mt-1">
                    <?= !empty($company['email']) ? '<i class="bi bi-envelope me-1"></i>' . e($company['email']) : '' ?>
                    <?= (!empty($company['email']) && !empty($company['phone'])) ? '&bull; ' : '' ?>
                    <?= !empty($company['phone']) ? '<i class="bi bi-telephone me-1"></i>' . e($company['phone']) : '' ?>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= e(url('modules/companies/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Companies
            </a>
            <?php if ($canSettings): ?>
                <a href="<?= e(url('modules/companies/settings.php?id=' . $companyId)) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-gear me-1"></i>Settings
                </a>
            <?php endif; ?>
            <?php if ($canManageCompany): ?>
                <a href="<?= e(url('modules/companies/form.php?id=' . $companyId)) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit Company
                </a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteCompanyModal">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- KPI Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <?= ui_stat_card('Active Staff', $empCount, 'bi-people', 'primary', "Total: {$totalEmpCount} historical", 'modules/employees/index.php?company_id=' . $companyId, 'View Employees &rarr;') ?>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?= ui_stat_card('Departments', $deptCount, 'bi-diagram-3', 'info', null, 'modules/organization/departments.php?company_id=' . $companyId, 'Manage Depts &rarr;') ?>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?= ui_stat_card('Designations', $desigCount, 'bi-briefcase', 'warning', null, 'modules/organization/designations.php?company_id=' . $companyId, 'Manage Roles &rarr;') ?>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?= ui_stat_card('Assigned Users', $userCount, 'bi-person-badge', 'success', null, 'modules/users/index.php?company_id=' . $companyId, 'View Users &rarr;') ?>
    </div>
</div>

<div class="row g-4">
    <!-- Company Profile & Contact Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body py-3">
                <h5 class="card-title mb-0 fs-6 fw-bold">Company Profile</h5>
            </div>
            <div class="card-body">
                <dl class="mb-0">
                    <dt class="small text-muted">Legal Entity Name</dt>
                    <dd class="fw-semibold text-body mb-3"><?= e($company['name']) ?></dd>

                    <dt class="small text-muted">Company Identifier Code</dt>
                    <dd class="font-monospace text-body mb-3"><?= e($company['code']) ?></dd>

                    <dt class="small text-muted">Primary Email</dt>
                    <dd class="text-body mb-3"><?= !empty($company['email']) ? e($company['email']) : '<span class="text-muted fst-italic">Not specified</span>' ?></dd>

                    <dt class="small text-muted">Contact Phone</dt>
                    <dd class="text-body mb-3"><?= !empty($company['phone']) ? e($company['phone']) : '<span class="text-muted fst-italic">Not specified</span>' ?></dd>

                    <dt class="small text-muted">Registered Address</dt>
                    <dd class="text-body mb-3"><?= !empty($company['address']) ? nl2br(e($company['address'])) : '<span class="text-muted fst-italic">No address recorded</span>' ?></dd>

                    <dt class="small text-muted">Record Created</dt>
                    <dd class="small text-muted mb-0"><?= e(format_datetime($company['created_at'])) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Departments & Designations -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fs-6 fw-bold">Departments (<?= count($departmentsList) ?>)</h5>
                <?php if ($company['is_active']): ?>
                    <a href="<?= e(url('modules/organization/departments.php?company_id=' . $companyId)) ?>" class="btn btn-sm btn-outline-secondary">
                        Manage
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3" style="width: 40px;">#</th>
                                <th>Department Name</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($departmentsList)): ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No departments configured for this company.</td></tr>
                            <?php else: ?>
                                <?php foreach ($departmentsList as $idx => $d): ?>
                                    <tr>
                                        <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                        <td class="fw-medium text-body"><?= e($d['name']) ?></td>
                                        <td class="text-muted"><?= e($d['description'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fs-6 fw-bold">Designations (<?= count($designationsList) ?>)</h5>
                <?php if ($company['is_active']): ?>
                    <a href="<?= e(url('modules/organization/designations.php?company_id=' . $companyId)) ?>" class="btn btn-sm btn-outline-secondary">
                        Manage
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3" style="width: 40px;">#</th>
                                <th>Designation / Position Title</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($designationsList)): ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No designations configured for this company.</td></tr>
                            <?php else: ?>
                                <?php foreach ($designationsList as $idx => $ds): ?>
                                    <tr>
                                        <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                        <td class="fw-medium text-body"><?= e($ds['title']) ?></td>
                                        <td class="text-muted"><?= e($ds['description'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canDelete): ?>
    <!-- Deletion Confirmation Modal -->
    <div class="modal fade" id="deleteCompanyModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content border-danger shadow">
                <form method="post" action="<?= e(url('modules/companies/delete.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $companyId ?>">
                    <div class="modal-header border-danger">
                        <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteModalLabel">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Confirm Delete Company
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-start">
                        <p class="mb-2">Are you sure you want to permanently delete <strong><?= e($company['name']) ?></strong>?</p>
                        <div class="alert alert-warning small mb-3">
                            <i class="bi bi-shield-lock me-1"></i>
                            <strong>Safety Guard:</strong> If this company has any employees, vacancies, applications, offers, interviews, leave requests, attendance, or user assignments, the system will prevent hard deletion and instruct you to keep it deactivated instead.
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="confirm_code">
                                Type the company code <code class="fw-bold"><?= e($company['code']) ?></code> to confirm:
                            </label>
                            <input type="text" id="confirm_code" name="confirm_code" class="form-control text-uppercase font-monospace" required autocomplete="off" placeholder="<?= e($company['code']) ?>">
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
