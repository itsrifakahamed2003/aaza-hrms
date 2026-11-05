<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/companies.php';

require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Company not found.');
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$st->execute([$id]);
$company = $st->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    abort(404, 'Company not found.');
}

$companyId = (int)$company['id'];

// Multi-tenant RBAC: requires company.settings permission in the target company
// Group admins have global access; company admins only have access to their assigned companies
require_company_access($companyId, 'company.settings');

// Valid tabs
$tabs = [
    'general'       => ['label' => 'General',           'icon' => 'bi-sliders'],
    'attendance'    => ['label' => 'Work & Attendance', 'icon' => 'bi-clock-history'],
    'leave'         => ['label' => 'Leave',             'icon' => 'bi-calendar-check'],
    'recruitment'   => ['label' => 'Recruitment',       'icon' => 'bi-briefcase'],
    'employee'      => ['label' => 'Employee',          'icon' => 'bi-person-badge'],
    'notifications' => ['label' => 'Notifications',     'icon' => 'bi-bell'],
];

$activeTab = (string)($_GET['tab'] ?? $_POST['active_tab'] ?? 'general');
if (!array_key_exists($activeTab, $tabs)) {
    $activeTab = 'general';
}

$definitions = company_settings_definitions($companyId, $company);
$existingSettings = get_all_company_settings($pdo, $companyId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'reset_defaults') {
        $count = reset_company_settings($pdo, $companyId);
        log_activity('company.settings_reset', 'company', $companyId, [
            'company_code' => $company['code'],
            'cleared_rows' => $count,
        ], $companyId);

        flash_set('success', "All settings for {$company['name']} have been reset to group defaults.");
        redirect('modules/companies/settings.php?id=' . $companyId . '&tab=' . urlencode($activeTab));
    }

    if ($action === 'save_settings') {
        $posted = (array)($_POST['settings'] ?? []);
        $updates = [];
        $changes = [];

        // Validate each defined setting
        foreach ($definitions as $key => $def) {
            // Check if key belongs to active tab
            if ($def['tab'] !== $activeTab) {
                continue;
            }

            $currentVal = $existingSettings[$key] ?? $def['default'];
            $newVal = null;

            switch ($def['type']) {
                case 'text':
                    $val = strtoupper(trim((string)($posted[$key] ?? '')));
                    if ($key === 'general.currency_code') {
                        if (!preg_match('/^[A-Z]{3}$/', $val)) {
                            $errors[] = 'Currency code must be a 3-letter uppercase ISO code (e.g. LKR, USD, EUR).';
                        } else {
                            $newVal = $val;
                        }
                    } elseif ($key === 'recruitment.app_prefix') {
                        if (!preg_match('/^[A-Z0-9]{2,10}$/', $val)) {
                            $errors[] = 'Application prefix must be 2-10 letters or numbers (e.g. APP).';
                        } else {
                            $newVal = $val;
                        }
                    } elseif ($key === 'employee.code_prefix') {
                        if (!preg_match('/^[A-Z0-9]{2,10}$/', $val)) {
                            $errors[] = 'Employee code prefix must be 2-10 letters or numbers (e.g. EMP, ' . e($company['code']) . ').';
                        } else {
                            $newVal = $val;
                        }
                    } else {
                        $newVal = $val;
                    }
                    break;

                case 'select':
                    $val = trim((string)($posted[$key] ?? ''));
                    $validOptions = array_keys($def['options'] ?? []);
                    if (!in_array($val, $validOptions, true)) {
                        $errors[] = "Invalid option selected for {$def['label']}.";
                    } else {
                        $newVal = $val;
                    }
                    break;

                case 'time':
                    $val = trim((string)($posted[$key] ?? ''));
                    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $val)) {
                        $errors[] = "Invalid time format for {$def['label']} (expected HH:MM, e.g. 09:00).";
                    } else {
                        $newVal = $val;
                    }
                    break;

                case 'number':
                    $raw = trim((string)($posted[$key] ?? ''));
                    if (!is_numeric($raw)) {
                        $errors[] = "{$def['label']} must be a valid numeric value.";
                    } else {
                        $num = (float)$raw;
                        $min = isset($def['min']) ? (float)$def['min'] : null;
                        $max = isset($def['max']) ? (float)$def['max'] : null;

                        if ($min !== null && $num < $min) {
                            $errors[] = "{$def['label']} cannot be less than {$min}.";
                        } elseif ($max !== null && $num > $max) {
                            $errors[] = "{$def['label']} cannot be greater than {$max}.";
                        } else {
                            // Store integers cleanly, floats with precision
                            $newVal = (string)(isset($def['step']) ? round($num, 1) : (int)$num);
                        }
                    }
                    break;

                case 'weekly_days':
                    $selectedDays = (array)($posted[$key] ?? []);
                    $validDays = [1, 2, 3, 4, 5, 6, 7];
                    $cleanDays = [];
                    foreach ($selectedDays as $d) {
                        $di = (int)$d;
                        if (in_array($di, $validDays, true)) {
                            $cleanDays[] = $di;
                        }
                    }
                    $cleanDays = array_values(array_unique($cleanDays));
                    sort($cleanDays);
                    if (empty($cleanDays)) {
                        $errors[] = 'You must select at least one weekly off day.';
                    } else {
                        $newVal = json_encode($cleanDays);
                    }
                    break;

                case 'notif_types':
                    $selectedTypes = (array)($posted[$key] ?? []);
                    $validTypes = array_keys($def['options'] ?? []);
                    $cleanTypes = [];
                    foreach ($selectedTypes as $t) {
                        $ts = (string)$t;
                        if (in_array($ts, $validTypes, true)) {
                            $cleanTypes[] = $ts;
                        }
                    }
                    $newVal = json_encode(array_values(array_unique($cleanTypes)));
                    break;

                default:
                    $newVal = trim((string)($posted[$key] ?? ''));
                    break;
            }

            if ($newVal !== null) {
                $updates[$key] = $newVal;
                if ((string)$newVal !== (string)$currentVal) {
                    $changes[$key] = [
                        'old' => $currentVal,
                        'new' => $newVal,
                    ];
                }
            }
        }

        if (empty($errors)) {
            foreach ($updates as $k => $v) {
                set_company_setting($pdo, $companyId, $k, $v);
            }

            if (!empty($changes)) {
                log_activity('company.settings_update', 'company', $companyId, [
                    'company_code' => $company['code'],
                    'tab'          => $activeTab,
                    'changes'      => $changes,
                ], $companyId);
            }

            flash_set('success', 'Company settings saved successfully.');
            redirect('modules/companies/settings.php?id=' . $companyId . '&tab=' . urlencode($activeTab));
        }
    }
}

// Refresh settings after potential updates
$existingSettings = get_all_company_settings($pdo, $companyId);

$pageTitle = 'Settings - ' . $company['name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb breadcrumb-clean mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/companies/index.php')) ?>">Companies</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/companies/view.php?id=' . $companyId)) ?>"><?= e($company['name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Settings</li>
        </ol>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <?= company_logo_html($company, $company['name'], 'lg', 'shadow-sm') ?>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="page-header-title mb-0">Company Settings</h1>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($company['code']) ?></span>
                </div>
                <div class="text-muted small mt-1">
                    Configure operational policies, working hours, leave validation, and recruitment preferences for <strong><?= e($company['name']) ?></strong>.
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= e(url('modules/companies/view.php?id=' . $companyId)) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Company Profile
            </a>
            <form method="post" action="<?= e(url('modules/companies/settings.php?id=' . $companyId . '&tab=' . urlencode($activeTab))) ?>" class="d-inline"
                  data-confirm="Reset all customized settings for <?= e($company['name']) ?> back to group defaults? This will erase custom rules for this company."
                  data-confirm-type="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_defaults">
                <input type="hidden" name="active_tab" value="<?= e($activeTab) ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" title="Revert to system defaults">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Defaults
                </button>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger shadow-sm mb-4">
        <div class="d-flex align-items-center gap-2 fw-semibold mb-2">
            <i class="bi bi-exclamation-triangle-fill"></i> Please correct the following errors:
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<ul class="nav nav-tabs border-bottom mb-4" role="tablist">
    <?php foreach ($tabs as $tKey => $tMeta): ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activeTab === $tKey ? 'active fw-bold' : 'text-body' ?>"
               href="<?= e(url('modules/companies/settings.php?id=' . $companyId . '&tab=' . urlencode($tKey))) ?>">
                <i class="bi <?= e($tMeta['icon']) ?> me-1"></i> <?= e($tMeta['label']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-0 fs-6 fw-bold">
                <i class="bi <?= e($tabs[$activeTab]['icon']) ?> me-2 text-primary"></i>
                <?= e($tabs[$activeTab]['label']) ?> Configuration
            </h5>
            <small class="text-muted">Unset preferences fall back automatically to AaZa Group global defaults.</small>
        </div>
    </div>
    <div class="card-body p-4">
        <form method="post" action="<?= e(url('modules/companies/settings.php?id=' . $companyId . '&tab=' . urlencode($activeTab))) ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="active_tab" value="<?= e($activeTab) ?>">

            <div class="row g-4">
                <?php
                foreach ($definitions as $key => $def):
                    if ($def['tab'] !== $activeTab) {
                        continue;
                    }
                    $isCustom = array_key_exists($key, $existingSettings);
                    $val = $existingSettings[$key] ?? $def['default'];
                ?>
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border bg-body-tertiary h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-semibold mb-0" for="setting_<?= e(str_replace('.', '_', $key)) ?>">
                                    <?= e($def['label']) ?>
                                </label>
                                <?php if ($isCustom): ?>
                                    <span class="badge badge-soft-info" title="Custom override for <?= e($company['name']) ?>">
                                        <i class="bi bi-pencil-square me-1"></i>Company override
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-soft-secondary" title="Currently inheriting system default">
                                        <i class="bi bi-diagram-3 me-1"></i>Using group default
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($def['type'] === 'text'): ?>
                                <input type="text"
                                       id="setting_<?= e(str_replace('.', '_', $key)) ?>"
                                       name="settings[<?= e($key) ?>]"
                                       value="<?= e((string)$val) ?>"
                                       class="form-control"
                                       placeholder="<?= e($def['placeholder'] ?? '') ?>">

                            <?php elseif ($def['type'] === 'select'): ?>
                                <select id="setting_<?= e(str_replace('.', '_', $key)) ?>"
                                        name="settings[<?= e($key) ?>]"
                                        class="form-select">
                                    <?php foreach (($def['options'] ?? []) as $optVal => $optLabel): ?>
                                        <option value="<?= e((string)$optVal) ?>" <?= (string)$val === (string)$optVal ? 'selected' : '' ?>>
                                            <?= e($optLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($def['type'] === 'time'): ?>
                                <input type="time"
                                       id="setting_<?= e(str_replace('.', '_', $key)) ?>"
                                       name="settings[<?= e($key) ?>]"
                                       value="<?= e((string)$val) ?>"
                                       class="form-control">

                            <?php elseif ($def['type'] === 'number'): ?>
                                <input type="number"
                                       id="setting_<?= e(str_replace('.', '_', $key)) ?>"
                                       name="settings[<?= e($key) ?>]"
                                       value="<?= e((string)$val) ?>"
                                       <?= isset($def['min']) ? 'min="' . e((string)$def['min']) . '"' : '' ?>
                                       <?= isset($def['max']) ? 'max="' . e((string)$def['max']) . '"' : '' ?>
                                       <?= isset($def['step']) ? 'step="' . e((string)$def['step']) . '"' : '' ?>
                                       class="form-control">

                            <?php elseif ($def['type'] === 'weekly_days'): ?>
                                <?php
                                $selectedArr = is_string($val) ? (json_decode($val, true) ?: [6, 7]) : (array)$val;
                                ?>
                                <div class="d-flex flex-wrap gap-3 mt-1">
                                    <?php foreach (($def['options'] ?? []) as $dayNum => $dayName): ?>
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="settings[<?= e($key) ?>][]"
                                                   id="day_<?= e((string)$dayNum) ?>"
                                                   value="<?= e((string)$dayNum) ?>"
                                                   <?= in_array((int)$dayNum, array_map('intval', $selectedArr), true) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="day_<?= e((string)$dayNum) ?>">
                                                <?= e($dayName) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($def['type'] === 'notif_types'): ?>
                                <?php
                                $selectedTypes = is_string($val) ? (json_decode($val, true) ?: []) : (array)$val;
                                ?>
                                <div class="row g-2 mt-1">
                                    <?php foreach (($def['options'] ?? []) as $tKey => $tLabel): ?>
                                        <div class="col-12 col-sm-6">
                                            <div class="form-check">
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       name="settings[<?= e($key) ?>][]"
                                                       id="notif_<?= e($tKey) ?>"
                                                       value="<?= e($tKey) ?>"
                                                       <?= in_array($tKey, $selectedTypes, true) ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="notif_<?= e($tKey) ?>">
                                                    <strong><?= e(ucfirst($tKey)) ?>:</strong> <?= e($tLabel) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($def['help'])): ?>
                                <div class="form-text mt-2 small text-muted">
                                    <?= e($def['help']) ?>
                                    <?php if (!$isCustom): ?>
                                        <span class="text-body-secondary">&bull; Default: <code><?= e(is_array($def['default']) ? json_encode($def['default']) : (string)$def['default']) ?></code></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <span class="text-muted small">
                    <i class="bi bi-shield-check text-success me-1"></i>Changes are audited and logged with user attribution.
                </span>
                <div class="d-flex gap-2">
                    <a href="<?= e(url('modules/companies/view.php?id=' . $companyId)) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';

