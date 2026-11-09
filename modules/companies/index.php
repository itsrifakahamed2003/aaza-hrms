<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_group_admin();

$pdo = db();

// Handle Application-Wide Branding update by Group Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_app_branding'])) {
    csrf_require();
    if (!is_group_admin() || !user_can('company.logo')) {
        abort(403, 'Permission denied: only Group Administrators can update application branding.');
    }

    $messages = [];

    // --- 1. Sidebar & Navigation Logo (Existing) ---
    if (!empty($_POST['reset_app_logo'])) {
        $oldLogo = get_system_setting('app_logo');
        if (!empty($oldLogo)) {
            remove_company_logo($oldLogo);
            set_system_setting('app_logo', null);
            log_activity('app.branding_update', 'system', 0, ['action' => 'reset_default']);
            $messages[] = 'Sidebar & navigation logo reset to default.';
        }
    } elseif (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        [$ok, $res] = validate_company_logo($_FILES['app_logo']);
        if (!$ok) {
            flash_set('danger', (string)$res);
            redirect('modules/companies/index.php');
        } else {
            $oldLogo = get_system_setting('app_logo');
            $newPath = save_company_logo($res, $oldLogo);
            set_system_setting('app_logo', $newPath);
            log_activity('app.branding_update', 'system', 0, ['action' => 'upload', 'logo_path' => $newPath]);
            $messages[] = 'Sidebar & navigation logo updated successfully.';
        }
    }

    // --- 2. Login Page Logo (New Card) ---
    if (!empty($_POST['reset_login_logo'])) {
        $oldLoginLogo = get_system_setting('branding.login_logo');
        if (!empty($oldLoginLogo)) {
            remove_company_logo($oldLoginLogo);
            set_system_setting('branding.login_logo', null);
        }
        set_system_setting('branding.login_use_sidebar', '0');
        log_activity('branding.login_logo_update', 'system', 0, ['action' => 'reset_default']);
        $messages[] = 'Login page logo reset to system default.';
    } else {
        $useSidebar = !empty($_POST['branding_login_use_sidebar']) ? '1' : '0';
        $prevUseSidebar = get_system_setting('branding.login_use_sidebar', '0');
        $settingChanged = ($useSidebar !== $prevUseSidebar);

        if ($settingChanged) {
            set_system_setting('branding.login_use_sidebar', $useSidebar);
        }

        if (isset($_FILES['branding_login_logo']) && $_FILES['branding_login_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            [$ok, $res] = validate_company_logo($_FILES['branding_login_logo']);
            if (!$ok) {
                flash_set('danger', (string)$res);
                redirect('modules/companies/index.php');
            }
            $oldLoginLogo = get_system_setting('branding.login_logo');
            $newLoginPath = save_company_logo($res, $oldLoginLogo);
            set_system_setting('branding.login_logo', $newLoginPath);
            log_activity('branding.login_logo_update', 'system', 0, [
                'action' => 'upload',
                'logo_path' => $newLoginPath,
                'use_sidebar' => $useSidebar,
            ]);
            $messages[] = 'Login page logo uploaded and branding updated.';
        } elseif ($settingChanged) {
            log_activity('branding.login_logo_update', 'system', 0, [
                'action' => 'toggle_use_sidebar',
                'use_sidebar' => $useSidebar,
            ]);
            $messages[] = 'Login page branding settings updated.';
        }
    }

    if (!empty($messages)) {
        flash_set('success', implode(' ', $messages));
    }
    redirect('modules/companies/index.php');
}

$companies = $pdo->query(
    "SELECT c.id, c.code, c.name, c.email, c.phone, c.is_active, c.logo_path,
            (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.id AND e.employment_status = 'active') AS employees,
            (SELECT COUNT(*) FROM departments d WHERE d.company_id = c.id) AS departments,
            (SELECT COUNT(DISTINCT cua.user_id) FROM company_user_assignments cua WHERE cua.company_id = c.id) AS users
       FROM companies c
      ORDER BY c.is_active DESC, c.name"
)->fetchAll();

$pageTitle = 'Companies';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header mb-3">
    <div class="page-header-title-block">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
                <li class="breadcrumb-item active" aria-current="page">Companies</li>
            </ol>
        </nav>
        <h1 class="page-header-title mb-0">Companies</h1>
        <p class="text-muted mb-0 small">Every company in AaZa Group. Deactivating a company removes access while keeping its history. Deletion is permitted only for deactivated companies with no records.</p>
    </div>
    <div class="page-actions">
        <?php if (is_group_admin() && user_can('company.logo')): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#appBrandingModal">
                <i class="bi bi-palette me-1"></i>App Branding
            </button>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('modules/companies/form.php')) ?>">
            <i class="bi bi-plus-lg me-1"></i>Add company
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-0 fs-6 fw-bold">Company Entities Directory</h5>
            <small class="text-muted d-block mt-0.5">
                <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any company row to view company overview.
            </small>
        </div>
        <span class="badge bg-body-secondary text-body border"><?= count($companies) ?> entit<?= count($companies) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
            <tr>
                <th style="width: 100px;">Code</th>
                <th>Company Entity</th>
                <th class="text-center">Employees</th>
                <th class="text-center">Departments</th>
                <th class="text-center">Users</th>
                <th>Status</th>
                <th class="text-end pe-3 action-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($companies as $c): ?>
                <?php
                $cid = (int)$c['id'];
                $compViewUrl = url('modules/companies/view.php?id=' . $cid);
                ?>
                <tr class="clickable-row company-row" data-href="<?= e($compViewUrl) ?>" title="Double-click to view company <?= e($c['code']) ?>">
                    <td><code><?= e($c['code']) ?></code></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= company_logo_html($c, $c['name'], 'md') ?>
                            <div>
                                <a href="<?= e(url('modules/companies/view.php?id=' . $cid)) ?>" class="fw-semibold text-body text-decoration-none">
                                    <?= e($c['name']) ?>
                                </a>
                                <div class="small text-muted"><?= e(trim(($c['email'] ?? '') . ' ' . ($c['phone'] ?? ''))) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="text-center font-monospace fw-semibold"><?= (int)$c['employees'] ?></td>
                    <td class="text-center font-monospace"><?= (int)$c['departments'] ?></td>
                    <td class="text-center font-monospace"><?= (int)$c['users'] ?></td>
                    <td>
                        <?= $c['is_active']
                            ? '<span class="badge badge-soft-success">Active</span>'
                            : '<span class="badge badge-soft-secondary">Inactive</span>' ?>
                    </td>
                    <td class="text-end text-nowrap pe-3 action-col">
                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-primary py-1 px-2.5" href="<?= e($compViewUrl) ?>" title="View company profile">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            <a class="btn btn-sm btn-outline-secondary py-1 px-2" href="<?= e(url('modules/companies/form.php?id=' . $cid)) ?>" title="Edit company details">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <?= action_menu([
                                [
                                    'type'       => 'link',
                                    'label'      => 'View Company Overview',
                                    'url'        => 'modules/companies/view.php?id=' . $cid,
                                    'icon'       => 'bi-eye',
                                ],
                                [
                                    'type'       => 'link',
                                    'label'      => 'Settings',
                                    'url'        => 'modules/companies/settings.php?id=' . $cid,
                                    'icon'       => 'bi-gear',
                                    'permission' => (is_group_admin() || user_can('company.settings', $cid)),
                                ],
                                [
                                    'type'       => 'link',
                                    'label'      => 'Departments',
                                    'url'        => 'modules/organization/departments.php?company_id=' . $cid,
                                    'icon'       => 'bi-diagram-3',
                                    'permission' => (bool)$c['is_active'],
                                ],
                                [
                                    'type'       => 'link',
                                    'label'      => 'Designations',
                                    'url'        => 'modules/organization/designations.php?company_id=' . $cid,
                                    'icon'       => 'bi-award',
                                    'permission' => (bool)$c['is_active'],
                                ],
                                [
                                    'type'         => 'post',
                                    'label'        => 'Deactivate',
                                    'url'          => 'modules/companies/toggle.php',
                                    'icon'         => 'bi-pause-circle',
                                    'params'       => ['id' => $cid],
                                    'confirm'      => "Deactivate " . $c['name'] . "? Its history and records will be kept.",
                                    'confirm_type' => 'warning',
                                    'danger'       => true,
                                    'permission'   => (bool)$c['is_active'],
                                ],
                                [
                                    'type'         => 'post',
                                    'label'        => 'Activate',
                                    'url'          => 'modules/companies/toggle.php',
                                    'icon'         => 'bi-play-circle',
                                    'params'       => ['id' => $cid],
                                    'confirm'      => "Activate " . $c['name'] . "?",
                                    'confirm_type' => 'success',
                                    'permission'   => !(bool)$c['is_active'],
                                ],
                                [
                                    'type'       => 'button',
                                    'label'      => 'Delete Company',
                                    'icon'       => 'bi-trash',
                                    'modal'      => '#deleteCompanyModal' . $cid,
                                    'danger'     => true,
                                    'permission' => !(bool)$c['is_active'],
                                ],
                            ]) ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$companies): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No companies yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Deletion Confirmation Modals for Inactive Companies -->
<?php foreach ($companies as $c): ?>
    <?php if (!$c['is_active']): ?>
        <?php $cid = (int)$c['id']; ?>
        <div class="modal fade" id="deleteCompanyModal<?= $cid ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?= $cid ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content border-danger shadow">
                    <form method="post" action="<?= e(url('modules/companies/delete.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <div class="modal-header border-danger">
                            <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteModalLabel<?= $cid ?>">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Company: <?= e($c['name']) ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p class="mb-2">Are you sure you want to permanently delete <strong><?= e($c['name']) ?></strong>?</p>
                            <div class="alert alert-warning small mb-3">
                                <i class="bi bi-shield-lock me-1"></i>
                                <strong>Safety Guard:</strong> If this company has any employees, vacancies, applications, offers, interviews, leave requests, attendance, or user assignments, the system will prevent hard deletion and instruct you to keep it deactivated instead.
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold" for="confirm_code_<?= $cid ?>">
                                    Type the company code <code class="fw-bold"><?= e($c['code']) ?></code> to confirm:
                                </label>
                                <input type="text" id="confirm_code_<?= $cid ?>" name="confirm_code" class="form-control text-uppercase font-monospace" required autocomplete="off" placeholder="<?= e($c['code']) ?>">
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

<!-- Modal for Application-Wide Branding -->
<?php if (is_group_admin() && user_can('company.logo')): ?>
    <div class="modal fade" id="appBrandingModal" tabindex="-1" aria-labelledby="appBrandingLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow border-0">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="update_app_branding" value="1">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title fs-6 fw-bold" id="appBrandingLabel">
                            <i class="bi bi-palette me-1 text-primary"></i> Application Branding
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3 p-md-4">
                        <?php
                        $curAppLogo = get_system_setting('app_logo');
                        $hasCustomLogo = !empty($curAppLogo) && file_exists(ROOT_PATH . '/' . $curAppLogo);
                        $loginLogoData = get_login_logo_data();
                        ?>

                        <!-- Card 1: Sidebar & Navigation Logo (Existing) -->
                        <div class="card border mb-4 shadow-sm">
                            <div class="card-header bg-body py-2 px-3 border-bottom">
                                <h6 class="mb-0 fs-6 fw-semibold text-body">
                                    <i class="bi bi-layout-sidebar me-1 text-primary"></i> Sidebar &amp; Navigation Logo
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-body-tertiary rounded border">
                                    <img src="<?= e(url($hasCustomLogo ? $curAppLogo : 'assets/img/aaza-icon.png')) ?>" alt="<?= e(APP_NAME) ?>" style="height: 48px; max-width: 120px; object-fit: contain;">
                                    <div>
                                        <div class="fw-semibold text-body">Sidebar &amp; Navigation Logo</div>
                                        <div class="small text-muted"><?= $hasCustomLogo ? 'Custom logo active' : 'Using default AaZa HRMS logo' ?></div>
                                    </div>
                                </div>
                                <?php if ($hasCustomLogo): ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="reset_app_logo" id="reset_app_logo" value="1">
                                        <label class="form-check-label text-danger small fw-semibold" for="reset_app_logo">
                                            Reset to default application logo
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <label class="form-label small fw-semibold" for="app_logo">Upload New App Logo</label>
                                    <input type="file" class="form-control form-control-sm" id="app_logo" name="app_logo" accept="image/png,image/jpeg,image/webp">
                                    <div class="form-text small">PNG, JPG, or WebP. Max 2 MB. Square or compact aspect ratio recommended.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Card 2: Login Page Logo (New Card) -->
                        <div class="card border shadow-sm">
                            <div class="card-header bg-body py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fs-6 fw-semibold text-body">
                                    <i class="bi bi-box-arrow-in-right me-1 text-primary"></i> Login Page Logo
                                </h6>
                                <?php if ($loginLogoData['is_custom'] || $loginLogoData['use_sidebar_setting']): ?>
                                    <button type="submit" name="reset_login_logo" value="1" class="btn btn-outline-danger btn-sm py-1 px-2" style="font-size: 0.775rem;" data-confirm="Reset login page logo to default?" data-confirm-type="warning">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to default
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3">
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold text-body mb-2">Current Logo Preview</label>
                                    <div class="row g-2">
                                        <!-- Light Box -->
                                        <div class="col-12 col-sm-6">
                                            <div class="p-3 rounded border text-center" style="background-color: #ffffff; min-height: 110px; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                                                <span class="small fw-semibold text-secondary mb-2" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px;">Light Mode</span>
                                                <div style="display: inline-flex; align-items: center; justify-content: center; padding: 4px 12px;">
                                                    <img src="<?= e($loginLogoData['url']) ?>" alt="Login Logo Preview (Light)" style="max-height: 52px; max-width: 100%; object-fit: contain;">
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Dark Box -->
                                        <div class="col-12 col-sm-6">
                                            <div class="p-3 rounded border text-center" style="background-color: #0f172a; border-color: #334155 !important; min-height: 110px; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                                                <span class="small fw-semibold mb-2" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8;">Dark Mode</span>
                                                <div style="display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border-radius: 10px; background: #ffffff; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);">
                                                    <img src="<?= e($loginLogoData['url']) ?>" alt="Login Logo Preview (Dark)" style="max-height: 48px; max-width: 100%; object-fit: contain;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-2 small text-muted">
                                        Source: <span class="fw-semibold text-body"><?= $loginLogoData['is_sidebar'] ? 'Sidebar & Navigation Logo' : ($loginLogoData['is_custom'] ? 'Custom Login Logo' : 'Default AaZa Logo') ?></span>
                                    </div>
                                </div>

                                <div class="form-check mb-3 p-2 bg-body-tertiary rounded border ps-5">
                                    <input class="form-check-input ms-n4" type="checkbox" name="branding_login_use_sidebar" id="branding_login_use_sidebar" value="1" <?= $loginLogoData['use_sidebar_setting'] ? 'checked' : '' ?>>
                                    <label class="form-check-label small fw-semibold text-body" for="branding_login_use_sidebar">
                                        Use the same logo as sidebar
                                    </label>
                                    <div class="form-text small text-muted mb-0">When enabled, the login screen automatically uses your Sidebar &amp; Navigation logo.</div>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label small fw-semibold text-body" for="branding_login_logo">Upload New Login Logo</label>
                                    <input type="file" class="form-control form-control-sm" id="branding_login_logo" name="branding_login_logo" accept="image/png,image/jpeg,image/webp">
                                    <div class="form-text small">PNG, JPG, or WebP. Max 2 MB. Max displayed height 72px.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-check-lg me-1"></i>Save Branding
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
