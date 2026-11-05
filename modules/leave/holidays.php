<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Permission check: group admin or users with leave.manage / leave.manage_types
if (!is_group_admin() && !user_can('leave.manage') && !user_can('leave.manage_types')) {
    abort(403, 'Permission denied: You do not have permission to manage company public holidays.');
}

$pdo = db();
$currentUserId = current_user_id();
$isGroupAdmin  = is_group_admin();
$accessibleCompanyIds = accessible_company_ids();

// Fetch companies for dropdowns
$companies = [];
if (!empty($accessibleCompanyIds)) {
    $ph = implode(',', array_fill(0, count($accessibleCompanyIds), '?'));
    $stComp = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name ASC");
    $stComp->execute($accessibleCompanyIds);
    $companies = $stComp->fetchAll();
} elseif ($isGroupAdmin) {
    $companies = $pdo->query("SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}
$validCompanyIds = array_map('intval', array_column($companies, 'id'));

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $date = trim((string)($_POST['holiday_date'] ?? ''));
        $rawCompId = trim((string)($_POST['company_id'] ?? ''));
        $companyId = ($rawCompId === '' || $rawCompId === '0') ? null : (int)$rawCompId;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Holiday name is required and must not exceed 150 characters.';
        }
        $parsedDate = parse_ymd($date);
        if ($parsedDate === null) {
            $errors[] = 'A valid holiday date (YYYY-MM-DD) is required.';
        }
        if ($companyId !== null && !in_array($companyId, $validCompanyIds, true)) {
            $errors[] = 'Invalid company selected.';
        }
        if (!$isGroupAdmin && $companyId === null) {
            $errors[] = 'Only Group Administrators can configure group-wide public holidays.';
        }

        if (empty($errors)) {
            // Check uniqueness
            if ($companyId === null) {
                $chk = $pdo->prepare("SELECT id FROM company_holidays WHERE company_id IS NULL AND holiday_date = ? LIMIT 1");
                $chk->execute([$parsedDate]);
            } else {
                $chk = $pdo->prepare("SELECT id FROM company_holidays WHERE company_id = ? AND holiday_date = ? LIMIT 1");
                $chk->execute([$companyId, $parsedDate]);
            }
            if ($chk->fetch()) {
                $errors[] = 'A holiday already exists for this company/scope on ' . format_date($parsedDate) . '.';
            }
        }

        if (empty($errors)) {
            $ins = $pdo->prepare(
                "INSERT INTO company_holidays (company_id, holiday_date, name, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $ins->execute([$companyId, $parsedDate, $name, $isActive, $currentUserId]);
            $newId = (int)$pdo->lastInsertId();

            log_activity('holiday.create', 'holiday', $newId, [
                'name'         => $name,
                'holiday_date' => $parsedDate,
                'company_id'   => $companyId,
                'is_active'    => $isActive,
            ], $companyId);

            flash_set('success', "Public holiday '{$name}' created successfully.");
            redirect('modules/leave/holidays.php?year=' . date('Y', strtotime($parsedDate)));
        } else {
            flash_set('danger', implode(' ', $errors));
            redirect('modules/leave/holidays.php');
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $date = trim((string)($_POST['holiday_date'] ?? ''));
        $rawCompId = trim((string)($_POST['company_id'] ?? ''));
        $companyId = ($rawCompId === '' || $rawCompId === '0') ? null : (int)$rawCompId;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $st = $pdo->prepare("SELECT * FROM company_holidays WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $existing = $st->fetch();

        if (!$existing) {
            flash_set('danger', 'Holiday not found.');
            redirect('modules/leave/holidays.php');
        }

        $errors = [];
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Holiday name is required and must not exceed 150 characters.';
        }
        $parsedDate = parse_ymd($date);
        if ($parsedDate === null) {
            $errors[] = 'A valid holiday date (YYYY-MM-DD) is required.';
        }
        if ($companyId !== null && !in_array($companyId, $validCompanyIds, true)) {
            $errors[] = 'Invalid company selected.';
        }
        if (!$isGroupAdmin && $companyId === null) {
            $errors[] = 'Only Group Administrators can configure group-wide public holidays.';
        }

        if (empty($errors)) {
            if ($companyId === null) {
                $chk = $pdo->prepare("SELECT id FROM company_holidays WHERE company_id IS NULL AND holiday_date = ? AND id != ? LIMIT 1");
                $chk->execute([$parsedDate, $id]);
            } else {
                $chk = $pdo->prepare("SELECT id FROM company_holidays WHERE company_id = ? AND holiday_date = ? AND id != ? LIMIT 1");
                $chk->execute([$companyId, $parsedDate, $id]);
            }
            if ($chk->fetch()) {
                $errors[] = 'A holiday already exists for this company/scope on ' . format_date($parsedDate) . '.';
            }
        }

        if (empty($errors)) {
            $upd = $pdo->prepare(
                "UPDATE company_holidays
                    SET company_id = ?, holiday_date = ?, name = ?, is_active = ?, updated_at = NOW()
                  WHERE id = ?"
            );
            $upd->execute([$companyId, $parsedDate, $name, $isActive, $id]);

            log_activity('holiday.update', 'holiday', $id, [
                'name'         => $name,
                'holiday_date' => $parsedDate,
                'company_id'   => $companyId,
                'is_active'    => $isActive,
            ], $companyId);

            flash_set('success', "Holiday '{$name}' updated successfully.");
            redirect('modules/leave/holidays.php?year=' . date('Y', strtotime($parsedDate)));
        } else {
            flash_set('danger', implode(' ', $errors));
            redirect('modules/leave/holidays.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM company_holidays WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $existing = $st->fetch();

        if (!$existing) {
            flash_set('danger', 'Holiday not found.');
            redirect('modules/leave/holidays.php');
        }

        $newActive = ((int)$existing['is_active'] === 1) ? 0 : 1;
        $pdo->prepare("UPDATE company_holidays SET is_active = ?, updated_at = NOW() WHERE id = ?")->execute([$newActive, $id]);

        log_activity('holiday.toggle', 'holiday', $id, [
            'name'      => $existing['name'],
            'is_active' => $newActive,
        ], $existing['company_id'] ? (int)$existing['company_id'] : null);

        flash_set('success', "Holiday '" . $existing['name'] . "' status updated to " . ($newActive ? 'Active' : 'Inactive') . ".");
        redirect('modules/leave/holidays.php?year=' . date('Y', strtotime((string)$existing['holiday_date'])));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM company_holidays WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $existing = $st->fetch();

        if (!$existing) {
            flash_set('danger', 'Holiday not found.');
            redirect('modules/leave/holidays.php');
        }

        // Rule: delete only inactive holidays
        if ((int)$existing['is_active'] !== 0) {
            flash_set('danger', "Active holidays cannot be deleted. Please deactivate '" . $existing['name'] . "' first.");
            redirect('modules/leave/holidays.php?year=' . date('Y', strtotime((string)$existing['holiday_date'])));
        }

        $pdo->prepare("DELETE FROM company_holidays WHERE id = ?")->execute([$id]);

        log_activity('holiday.delete', 'holiday', $id, [
            'name'         => $existing['name'],
            'holiday_date' => $existing['holiday_date'],
            'company_id'   => $existing['company_id'],
        ], $existing['company_id'] ? (int)$existing['company_id'] : null);

        flash_set('success', "Holiday '" . $existing['name'] . "' deleted successfully.");
        redirect('modules/leave/holidays.php?year=' . date('Y', strtotime((string)$existing['holiday_date'])));
    }
}

// Filters
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) && (int)$_GET['year'] >= 2000 && (int)$_GET['year'] <= 2100
    ? (int)$_GET['year']
    : $currentYear;

$selectedCompany = isset($_GET['company_id']) && $_GET['company_id'] !== ''
    ? (int)$_GET['company_id']
    : null;

$selectedStatus = trim((string)($_GET['status'] ?? ''));

// Build listing query
$where = ["YEAR(h.holiday_date) = ?"];
$params = [$selectedYear];

if ($selectedCompany !== null) {
    if ($selectedCompany === 0) {
        $where[] = "h.company_id IS NULL";
    } else {
        $where[] = "(h.company_id = ? OR h.company_id IS NULL)";
        $params[] = $selectedCompany;
    }
} elseif (!$isGroupAdmin && !empty($accessibleCompanyIds)) {
    $ph = implode(',', array_fill(0, count($accessibleCompanyIds), '?'));
    $where[] = "(h.company_id IS NULL OR h.company_id IN ($ph))";
    $params = array_merge($params, $accessibleCompanyIds);
}

if ($selectedStatus === 'active') {
    $where[] = "h.is_active = 1";
} elseif ($selectedStatus === 'inactive') {
    $where[] = "h.is_active = 0";
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT h.*, c.name AS company_name, c.code AS company_code, u.full_name AS creator_name
          FROM company_holidays h
     LEFT JOIN companies c ON c.id = h.company_id
     LEFT JOIN users u ON u.id = h.created_by
         WHERE $whereSql
      ORDER BY h.holiday_date ASC, h.name ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$holidaysList = $st->fetchAll();

// KPI counts for selected year
$kpiTotal = count($holidaysList);
$kpiActive = 0;
$kpiGroup = 0;
foreach ($holidaysList as $hRow) {
    if ((int)$hRow['is_active'] === 1) $kpiActive++;
    if ($hRow['company_id'] === null) $kpiGroup++;
}

$pageTitle = 'Public Holidays';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header Pattern -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('modules/leave/index.php')) ?>">Leave</a></li>
                <li class="breadcrumb-item active" aria-current="page">Public Holidays</li>
            </ol>
        </nav>
        <h1 class="page-header-title h3 mb-0">Public Holidays Management</h1>
        <p class="page-header-desc text-muted small mb-0">
            Define company and group-wide non-working holidays. Active holidays are automatically excluded from leave deduction and working day counts.
        </p>
    </div>
    <div class="page-header-actions d-flex gap-2">
        <a href="<?= e(url('modules/leave/types.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-tags me-1"></i> Leave Types
        </a>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addHolidayModal">
            <i class="bi bi-plus-lg me-1"></i> Add Holiday
        </button>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="rounded-3 bg-primary-subtle text-primary p-3 fs-4 d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                    <i class="bi bi-calendar-event"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold text-uppercase">Total Holidays (<?= $selectedYear ?>)</div>
                    <div class="fs-4 fw-bold text-body"><?= $kpiTotal ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="rounded-3 bg-success-subtle text-success p-3 fs-4 d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold text-uppercase">Active Excluded Days</div>
                    <div class="fs-4 fw-bold text-success"><?= $kpiActive ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="rounded-3 bg-info-subtle text-info p-3 fs-4 d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                    <i class="bi bi-globe"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold text-uppercase">Group-Wide Observances</div>
                    <div class="fs-4 fw-bold text-body"><?= $kpiGroup ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="get" action="<?= e(url('modules/leave/holidays.php')) ?>" class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1">Calendar Year</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for ($y = $currentYear - 2; $y <= $currentYear + 3; $y++): ?>
                        <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php if (count($companies) > 1 || $isGroupAdmin): ?>
                <div class="col-sm-4 col-md-4">
                    <label class="form-label small fw-semibold text-secondary mb-1">Company Entity</label>
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Companies &amp; Group</option>
                        <option value="0" <?= $selectedCompany === 0 ? 'selected' : '' ?>>Group-Wide Only</option>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= (int)$comp['id'] ?>" <?= $selectedCompany === (int)$comp['id'] ? 'selected' : '' ?>>
                                <?= e($comp['name']) ?> (<?= e($comp['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $selectedStatus === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="<?= e(url('modules/leave/holidays.php')) ?>" class="btn btn-sm btn-link text-decoration-none">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Holidays Listing Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Scheduled Public Holidays (<?= $selectedYear ?>)</h5>
        <span class="badge text-bg-light border text-secondary"><?= count($holidaysList) ?> entries</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
                <tr>
                    <th class="ps-3 py-2" style="width: 140px;">Date</th>
                    <th class="py-2" style="width: 110px;">Day</th>
                    <th class="py-2">Holiday Name</th>
                    <th class="py-2">Scope / Entity</th>
                    <th class="py-2 text-center" style="width: 100px;">Status</th>
                    <th class="pe-3 py-2 text-end" style="width: 160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($holidaysList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i>
                            <div>No public holidays found for <?= $selectedYear ?> matching the selected criteria.</div>
                            <div class="small mt-1">Click "Add Holiday" above to schedule a non-working observance.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($holidaysList as $h): ?>
                        <?php
                        $hDate = (string)$h['holiday_date'];
                        $dayName = date('l', strtotime($hDate));
                        $isAct = ((int)$h['is_active'] === 1);
                        ?>
                        <tr>
                            <td class="ps-3 fw-semibold font-monospace">
                                <?= format_date($hDate) ?>
                            </td>
                            <td>
                                <span class="badge text-bg-light border text-secondary fw-normal"><?= $dayName ?></span>
                            </td>
                            <td class="fw-semibold text-body">
                                <?= e($h['name']) ?>
                            </td>
                            <td>
                                <?php if ($h['company_id'] === null): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="bi bi-globe me-1"></i> Group-Wide (All)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">
                                        <?= e($h['company_name'] ?? 'Company #' . $h['company_id']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isAct): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Edit Trigger -->
                                    <button type="button" class="btn btn-outline-secondary"
                                            title="Edit Holiday"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                'id'           => (int)$h['id'],
                                                'name'         => $h['name'],
                                                'holiday_date' => $h['holiday_date'],
                                                'company_id'   => $h['company_id'] !== null ? (int)$h['company_id'] : '',
                                                'is_active'    => (int)$h['is_active'],
                                            ]), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <!-- Toggle Active/Inactive Form -->
                                    <form method="post" action="<?= e(url('modules/leave/holidays.php')) ?>" class="d-inline m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary"
                                                title="<?= $isAct ? 'Deactivate (stop excluding from leave)' : 'Activate (exclude from leave)' ?>">
                                            <i class="bi <?= $isAct ? 'bi-pause-circle' : 'bi-play-circle text-success' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Delete Button (Only inactive can be deleted) -->
                                    <?php if (!$isAct): ?>
                                        <button type="button" class="btn btn-outline-danger"
                                                title="Delete Inactive Holiday"
                                                onclick="confirmDeleteHoliday(<?= (int)$h['id'] ?>, '<?= e(addslashes($h['name'])) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary disabled opacity-50"
                                                title="Active holidays cannot be deleted. Deactivate first." disabled>
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

<!-- Add Holiday Modal -->
<div class="modal fade" id="addHolidayModal" tabindex="-1" aria-labelledby="addHolidayTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('modules/leave/holidays.php')) ?>" class="modal-content border-0 shadow">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold" id="addHolidayTitle">
                    <i class="bi bi-calendar-plus text-primary me-2"></i>Add Public Holiday
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="addHolidayName">Holiday Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="addHolidayName" name="name" required maxlength="150" placeholder="e.g. Sinhala &amp; Tamil New Year">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="addHolidayDate">Holiday Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="addHolidayDate" name="holiday_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="addHolidayCompany">Scope / Entity</label>
                    <select class="form-select" id="addHolidayCompany" name="company_id">
                        <?php if ($isGroupAdmin): ?>
                            <option value="">Group-Wide (All Companies)</option>
                        <?php endif; ?>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= (int)$comp['id'] ?>">
                                <?= e($comp['name']) ?> (<?= e($comp['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Group-wide holidays apply across all group companies automatically.</div>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="addHolidayActive" name="is_active" value="1" checked>
                    <label class="form-check-label fw-semibold" for="addHolidayActive">Active Holiday (Exclude from Working Days)</label>
                </div>
            </div>
            <div class="modal-footer border-top bg-body-tertiary">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2 me-1"></i> Save Holiday
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Holiday Modal -->
<div class="modal fade" id="editHolidayModal" tabindex="-1" aria-labelledby="editHolidayTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('modules/leave/holidays.php')) ?>" class="modal-content border-0 shadow" id="editHolidayForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editHolidayId" value="0">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold" id="editHolidayTitle">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit Public Holiday
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="editHolidayName">Holiday Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editHolidayName" name="name" required maxlength="150">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="editHolidayDate">Holiday Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="editHolidayDate" name="holiday_date" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="editHolidayCompany">Scope / Entity</label>
                    <select class="form-select" id="editHolidayCompany" name="company_id">
                        <?php if ($isGroupAdmin): ?>
                            <option value="">Group-Wide (All Companies)</option>
                        <?php endif; ?>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= (int)$comp['id'] ?>">
                                <?= e($comp['name']) ?> (<?= e($comp['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="editHolidayActive" name="is_active" value="1">
                    <label class="form-check-label fw-semibold" for="editHolidayActive">Active Holiday (Exclude from Working Days)</label>
                </div>
            </div>
            <div class="modal-footer border-top bg-body-tertiary">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2 me-1"></i> Update Holiday
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Delete Form -->
<form method="post" action="<?= e(url('modules/leave/holidays.php')) ?>" id="deleteHolidayForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteHolidayId" value="0">
</form>

<script>
function openEditModal(data) {
    document.getElementById('editHolidayId').value = data.id;
    document.getElementById('editHolidayName').value = data.name;
    document.getElementById('editHolidayDate').value = data.holiday_date;
    document.getElementById('editHolidayCompany').value = data.company_id || '';
    document.getElementById('editHolidayActive').checked = (parseInt(data.is_active, 10) === 1);

    const modal = new bootstrap.Modal(document.getElementById('editHolidayModal'));
    modal.show();
}

function confirmDeleteHoliday(id, name) {
    if (typeof showConfirmModal === 'function') {
        showConfirmModal({
            title: 'Delete Inactive Holiday',
            message: 'Are you sure you want to permanently delete the inactive holiday "' + name + '"? This action cannot be undone.',
            confirmText: 'Delete Holiday',
            variant: 'danger',
            onConfirm: function() {
                document.getElementById('deleteHolidayId').value = id;
                document.getElementById('deleteHolidayForm').submit();
            }
        });
    } else {
        if (confirm('Are you sure you want to permanently delete the inactive holiday "' + name + '"?')) {
            document.getElementById('deleteHolidayId').value = id;
            document.getElementById('deleteHolidayForm').submit();
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

