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
<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('modules/companies/index.php')) ?>">Companies</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($company['name']) ?></li>
    </ol>
</nav>

<!-- Detail Header -->
<div class="detail-header">
    <div class="detail-header-main d-flex align-items-center gap-3">
        <?= company_logo_html($company, $company['name'], 'lg', 'shadow-sm flex-shrink-0') ?>
        <div>
            <div class="detail-header-title-row">
                <h1 class="detail-header-title"><?= e($company['name']) ?></h1>
                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($company['code']) ?></span>
                <?= $company['is_active']
                    ? '<span class="badge text-bg-success">Active</span>'
                    : '<span class="badge text-bg-secondary">Inactive</span>' ?>
            </div>
            <p class="detail-header-subtitle">
                <?= !empty($company['email']) ? '<i class="bi bi-envelope me-1"></i>' . e($company['email']) : '' ?>
                <?= (!empty($company['email']) && !empty($company['phone'])) ? '&bull; ' : '' ?>
                <?= !empty($company['phone']) ? '<i class="bi bi-telephone me-1"></i>' . e($company['phone']) : '' ?>
            </p>
        </div>
    </div>

    <div class="detail-header-actions">
        <!-- ONE Primary Button -->
        <?php if ($canManageCompany): ?>
            <a href="<?= e(url('modules/companies/form.php?id=' . $companyId)) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil me-1"></i> Edit Company
            </a>
        <?php endif; ?>

        <!-- ONE "More" 3-dot menu -->
        <?php
        $cmpMore = [];
        if ($canSettings) {
            $cmpMore[] = [
                'type' => 'link',
                'icon' => 'bi-gear',
                'label' => 'Settings',
                'url' => 'modules/companies/settings.php?id=' . $companyId,
            ];
        }
        $cmpMore[] = [
            'type' => 'link',
            'icon' => 'bi-arrow-left',
            'label' => 'Back to Companies',
            'url' => 'modules/companies/index.php',
        ];
        if ($canDelete) {
            $cmpMore[] = [
                'type' => 'button',
                'icon' => 'bi-trash',
                'label' => 'Delete Company',
                'modal' => '#deleteCompanyModal',
                'danger' => true,
            ];
        }
        echo action_menu($cmpMore, [
            'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
            'aria_label' => 'More company actions',
        ]);
        ?>
    </div>
</div>

<!-- KPI Stat Cards -->
<div class="row g-3 mb-3">
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

<div class="row g-3 align-items-start">
    <!-- Company Profile & Contact Card -->
    <div class="col-lg-4">
        <div class="detail-card">
            <div class="card-header">
                <span>Company Profile</span>
            </div>
            <div class="card-body">
                <div class="kv-list kv-list-single">
                    <div class="kv-item">
                        <span class="kv-label">Legal Entity Name</span>
                        <span class="kv-value fw-semibold"><?= e($company['name']) ?></span>
                    </div>

                    <div class="kv-item">
                        <span class="kv-label">Company Identifier Code</span>
                        <span class="kv-value font-monospace"><?= e($company['code']) ?></span>
                    </div>

                    <div class="kv-item">
                        <span class="kv-label">Primary Email</span>
                        <span class="kv-value"><?= !empty($company['email']) ? e($company['email']) : '—' ?></span>
                    </div>

                    <div class="kv-item">
                        <span class="kv-label">Contact Phone</span>
                        <span class="kv-value"><?= !empty($company['phone']) ? e($company['phone']) : '—' ?></span>
                    </div>

                    <div class="kv-item">
                        <span class="kv-label">Registered Address</span>
                        <span class="kv-value small"><?= !empty($company['address']) ? nl2br(e($company['address'])) : '—' ?></span>
                    </div>

                    <div class="kv-item pt-2 border-top">
                        <span class="kv-label">Record Created</span>
                        <span class="kv-value small text-muted"><?= e(format_datetime($company['created_at'])) ?></span>
                    </div>
                </div>
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
