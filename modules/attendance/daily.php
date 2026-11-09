<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Authorization: Requires attendance.view or attendance.manage
if (!user_can('attendance.view') && !user_can('attendance.manage')) {
    abort(403, 'You do not have permission to view or manage attendance.');
}

$pdo = db();
$accessibleCompanies = accessible_company_ids();
if (empty($accessibleCompanies)) {
    abort(403, 'You do not have access to any companies.');
}

// Fetch accessible companies
$ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
$compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($ph) ORDER BY name");
$compSt->execute($accessibleCompanies);
$companies = $compSt->fetchAll();

// Determine selected company
$selectedCompanyId = (int)($_GET['company_id'] ?? active_company_id());
if ($selectedCompanyId <= 0 || !in_array($selectedCompanyId, $accessibleCompanies, true)) {
    $selectedCompanyId = (int)$companies[0]['id'];
}

$canManage = user_can('attendance.manage', $selectedCompanyId);

// Selected date (default today; future date not allowed; strict Y-m-d format)
$rawDate = trim((string)($_GET['date'] ?? ''));
$parsedDate = parse_ymd($rawDate);
if ($rawDate !== '' && $parsedDate === null) {
    flash_set('danger', 'Invalid date format specified. Date must be YYYY-MM-DD.');
    $selectedDate = date('Y-m-d');
} else {
    $selectedDate = $parsedDate ?? date('Y-m-d');
}

if ($selectedDate > date('Y-m-d')) {
    flash_set('danger', 'Future dates are not permitted for attendance recording.');
    $selectedDate = date('Y-m-d');
}

// Check 31-day past limit for editing
$limitDate = date('Y-m-d', strtotime('-31 days'));
$isPastLocked = ($selectedDate < $limitDate);

// Handle POST: Bulk Save Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (!$canManage) {
        abort(403, 'You do not have permission to record or update attendance for this company.');
    }

    if ($selectedDate > date('Y-m-d')) {
        flash_set('danger', 'Future dates are not allowed for attendance recording.');
        redirect("modules/attendance/daily.php?company_id=$selectedCompanyId&date=$selectedDate");
    }

    if ($isPastLocked) {
        flash_set('danger', 'Editing attendance records older than 31 days is strictly blocked.');
        redirect("modules/attendance/daily.php?company_id=$selectedCompanyId&date=$selectedDate");
    }

    $records = $_POST['att'] ?? [];
    if (!is_array($records)) {
        flash_set('danger', 'Invalid submission data.');
        redirect("modules/attendance/daily.php?company_id=$selectedCompanyId&date=$selectedDate");
    }

    // Fetch active employees of this company
    $activeEmpSt = $pdo->prepare("SELECT id FROM employees WHERE company_id = ? AND employment_status = 'active'");
    $activeEmpSt->execute([$selectedCompanyId]);
    $validEmpIds = $activeEmpSt->fetchAll(PDO::FETCH_COLUMN);
    $validEmpMap = array_flip($validEmpIds);

    // Fetch approved leaves on this date
    $leaveSt = $pdo->prepare(
        "SELECT employee_id FROM leave_requests
          WHERE company_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?"
    );
    $leaveSt->execute([$selectedCompanyId, $selectedDate, $selectedDate]);
    $onLeaveEmpIds = array_flip($leaveSt->fetchAll(PDO::FETCH_COLUMN));

    $validStatuses = attendance_status_list();
    $currentUserId = current_user_id();
    $savedCount = 0;
    $errors = [];
    $changedAuditRows = [];

    try {
        $pdo->beginTransaction();

        $stExisting = $pdo->prepare("SELECT id, status, check_in, check_out, remarks FROM attendance WHERE employee_id = ? AND att_date = ?");

        $upsertSt = $pdo->prepare(
            "INSERT INTO attendance (
                employee_id, company_id, att_date, check_in, check_out,
                status, remarks, recorded_by, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                check_in    = VALUES(check_in),
                check_out   = VALUES(check_out),
                status      = VALUES(status),
                remarks     = VALUES(remarks),
                recorded_by = VALUES(recorded_by)"
        );

        foreach ($records as $empIdStr => $data) {
            $empId = (int)$empIdStr;

            // Must belong to company and be active
            if (!isset($validEmpMap[$empId])) {
                continue;
            }

            // If employee is on approved leave, lock and skip override unless explicitly needed
            if (isset($onLeaveEmpIds[$empId])) {
                continue;
            }

            $status   = trim((string)($data['status'] ?? 'present'));
            $checkIn  = trim((string)($data['check_in'] ?? ''));
            $checkOut = trim((string)($data['check_out'] ?? ''));
            $remarks  = mb_substr(trim((string)($data['remarks'] ?? '')), 0, 255);

            if (!isset($validStatuses[$status])) {
                $status = 'present';
            }

            // Validate time format (HH:MM or HH:MM:SS)
            $cinVal = null;
            if ($checkIn !== '') {
                if (!preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9](?::[0-5][0-9])?$/', $checkIn)) {
                    $errors[] = "Invalid check-in time format for employee #$empId ($checkIn). Use HH:MM.";
                    continue;
                }
                $cinVal = strlen($checkIn) === 5 ? ($checkIn . ':00') : $checkIn;
            }

            $coutVal = null;
            if ($checkOut !== '') {
                if (!preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9](?::[0-5][0-9])?$/', $checkOut)) {
                    $errors[] = "Invalid check-out time format for employee #$empId ($checkOut). Use HH:MM.";
                    continue;
                }
                $coutVal = strlen($checkOut) === 5 ? ($checkOut . ':00') : $checkOut;
            }

            // Check out must be later than check in
            if ($cinVal !== null && $coutVal !== null && $coutVal <= $cinVal) {
                $errors[] = "Check-out time ($checkOut) must be later than check-in time ($checkIn) for employee #$empId.";
                continue;
            }

            // Auto-suggest late if check-in is after standard start time (+ optional grace) and not absent/leave
            $compStart = function_exists('get_company_setting')
                ? (string)get_company_setting($pdo, $selectedCompanyId, 'attendance.work_start_time', defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00')
                : (defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00');
            $compGrace = function_exists('get_company_setting')
                ? (int)get_company_setting($pdo, $selectedCompanyId, 'attendance.late_grace_minutes', 0)
                : 0;
            $lateCutoff = $compGrace > 0 ? date('H:i', strtotime($compStart . " +{$compGrace} minutes")) : $compStart;
            if ($cinVal !== null && substr($cinVal, 0, 5) > $lateCutoff && $status === 'present') {
                $status = 'late';
            }

            // Before upsert, SELECT existing row to check if row actually changed
            $stExisting->execute([$empId, $selectedDate]);
            $oldRow = $stExisting->fetch();

            $oldData = $oldRow ? [
                'status'    => (string)$oldRow['status'],
                'check_in'  => $oldRow['check_in'] !== null ? substr((string)$oldRow['check_in'], 0, 5) : null,
                'check_out' => $oldRow['check_out'] !== null ? substr((string)$oldRow['check_out'], 0, 5) : null,
                'remarks'   => (string)($oldRow['remarks'] ?? ''),
            ] : null;

            $newData = [
                'status'    => $status,
                'check_in'  => $cinVal !== null ? substr($cinVal, 0, 5) : null,
                'check_out' => $coutVal !== null ? substr($coutVal, 0, 5) : null,
                'remarks'   => $remarks,
            ];

            $hasChanged = false;
            if ($oldData === null) {
                $hasChanged = true;
            } else {
                if ($oldData['status'] !== $newData['status'] ||
                    $oldData['check_in'] !== $newData['check_in'] ||
                    $oldData['check_out'] !== $newData['check_out'] ||
                    $oldData['remarks'] !== $newData['remarks']) {
                    $hasChanged = true;
                }
            }

            if ($hasChanged) {
                $changedAuditRows[] = [
                    'attendance_id' => $oldRow ? (int)$oldRow['id'] : null,
                    'employee_id'   => $empId,
                    'old'           => $oldData,
                    'new'           => $newData,
                ];
            }

            $upsertSt->execute([
                $empId,
                $selectedCompanyId,
                $selectedDate,
                $cinVal,
                $coutVal,
                $status,
                $remarks !== '' ? $remarks : null,
                $currentUserId,
            ]);
            $savedCount++;
        }

        if (!empty($errors)) {
            $pdo->rollBack();
            flash_set('danger', 'Errors prevented saving: ' . implode(' ', $errors));
        } else {
            $pdo->commit();

            // Log detailed audit entries for rows that actually changed
            foreach ($changedAuditRows as $chg) {
                log_activity(
                    'attendance.update',
                    'attendance',
                    $chg['attendance_id'] ?: $chg['employee_id'],
                    [
                        'employee_id' => $chg['employee_id'],
                        'date'        => $selectedDate,
                        'old'         => $chg['old'],
                        'new'         => $chg['new'],
                    ],
                    $selectedCompanyId
                );
            }

            // Summary log entry
            log_activity(
                'attendance.mark_daily',
                'attendance',
                null,
                [
                    'date'          => $selectedDate,
                    'saved_count'   => $savedCount,
                    'changed_count' => count($changedAuditRows),
                ],
                $selectedCompanyId
            );
            flash_set('success', "Attendance saved successfully for $savedCount employee(s) on " . format_date($selectedDate) . '.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Daily attendance save error: ' . $e->getMessage());
        flash_set('danger', 'Failed to save attendance records due to a system error.');
    }

    redirect("modules/attendance/daily.php?company_id=$selectedCompanyId&date=$selectedDate");
}

// Fetch Active Employees for Display
$empSql = "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.department_id,
                  d.name AS department_name, des.title AS designation_title
             FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN designations des ON des.id = e.designation_id
            WHERE e.company_id = ? AND e.employment_status = 'active'
            ORDER BY d.name ASC, e.first_name ASC, e.last_name ASC";
$empSt = $pdo->prepare($empSql);
$empSt->execute([$selectedCompanyId]);
$activeEmployees = $empSt->fetchAll();

// Fetch Existing Attendance for this date
$attSql = "SELECT * FROM attendance WHERE company_id = ? AND att_date = ?";
$attSt = $pdo->prepare($attSql);
$attSt->execute([$selectedCompanyId, $selectedDate]);
$attRecordsRaw = $attSt->fetchAll();
$attByEmp = [];
foreach ($attRecordsRaw as $ar) {
    $attByEmp[(int)$ar['employee_id']] = $ar;
}

// Fetch Approved Leaves for this date
$leaveSql = "SELECT lr.*, lt.name AS leave_type_name
               FROM leave_requests lr
               JOIN leave_types lt ON lt.id = lr.leave_type_id
              WHERE lr.company_id = ?
                AND lr.status = 'approved'
                AND lr.start_date <= ?
                AND lr.end_date >= ?";
$leaveSt = $pdo->prepare($leaveSql);
$leaveSt->execute([$selectedCompanyId, $selectedDate, $selectedDate]);
$leaveRows = $leaveSt->fetchAll();
$leavesByEmp = [];
foreach ($leaveRows as $lr) {
    $leavesByEmp[(int)$lr['employee_id']] = $lr;
}

$statuses = attendance_status_list();
$standardStart = function_exists('get_company_setting')
    ? (string)get_company_setting($pdo, $selectedCompanyId, 'attendance.work_start_time', defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00')
    : (defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00');

$dayHolidays = get_holidays_in_range($selectedDate, $selectedDate, $selectedCompanyId);
$isHolidayDate = !empty($dayHolidays);
$holidayName = $isHolidayDate ? reset($dayHolidays) : '';

$pageTitle = 'Daily Attendance Recording';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1300px;">
    <!-- Top Bar & Breadcrumb -->
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Daily Attendance</li>
                </ol>
            </nav>
            <h1 class="page-header-title mb-0">Daily Attendance Sheet</h1>
        </div>
        <div class="page-actions">
            <a href="<?= e(url('modules/attendance/reports.php')) ?>" class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-bar-graph me-1"></i> Attendance Reports
            </a>
            <?php if (user_can('leave.view', $selectedCompanyId)): ?>
                <a href="<?= e(url('modules/leave/index.php?company_id=' . $selectedCompanyId)) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar2-range me-1"></i> View Leaves
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Company & Date Selector Toolbar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="get" action="<?= e(url('modules/attendance/daily.php')) ?>" class="row g-3 align-items-end">
                <?php if (count($companies) > 1): ?>
                    <div class="col-md-4 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Company</label>
                        <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $selectedCompanyId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="company_id" value="<?= $selectedCompanyId ?>">
                <?php endif; ?>

                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-semibold text-secondary mb-1">Attendance Date</label>
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?= e($selectedDate) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-arrow-repeat me-1"></i> Load Sheet
                    </button>
                    <?php if ($selectedDate !== date('Y-m-d')): ?>
                        <a href="<?= e(url('modules/attendance/daily.php?company_id=' . $selectedCompanyId . '&date=' . date('Y-m-d'))) ?>" class="btn btn-sm btn-outline-secondary">
                            Jump to Today
                        </a>
                    <?php endif; ?>
                </div>

                <div class="col text-end small text-muted align-self-center">
                    Work start standard: <span class="badge bg-body-secondary text-body border font-monospace"><?= e($standardStart) ?></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Past Lock Alert -->
    <?php if ($isPastLocked): ?>
        <div class="alert alert-warning shadow-sm border-0 mb-4" role="alert">
            <i class="bi bi-lock-fill me-2 text-warning"></i>
            <strong>Historical Read-Only Sheet:</strong> Attendance records older than 31 days (before <?= format_date($limitDate) ?>) are locked and cannot be edited.
        </div>
    <?php endif; ?>

    <!-- Public Holiday Banner -->
    <?php if ($isHolidayDate): ?>
        <div class="alert alert-info shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-calendar-event fs-5 text-info"></i>
            <div>
                <strong>Public Holiday:</strong> <?= e($holidayName) ?> is observed on <?= format_date($selectedDate, 'l, d F Y') ?>.
            </div>
        </div>
    <?php endif; ?>

    <!-- Attendance Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">Active Staff (<?= count($activeEmployees) ?>)</h5>
                <small class="text-muted">Date: <?= format_date($selectedDate, 'l, d F Y') ?></small>
            </div>
            <?php if ($canManage && !$isPastLocked): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllPresent()">
                    <i class="bi bi-check-all me-1"></i> Mark All Present
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($activeEmployees)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-people fs-2 d-block mb-2"></i>
                    No active employees found for this company.
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('modules/attendance/daily.php?company_id=' . $selectedCompanyId . '&date=' . $selectedDate)) ?>" id="attendanceSheetForm">
                    <?= csrf_field() ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th class="ps-3" style="width: 250px;">Employee</th>
                                    <th style="width: 170px;">Status <span class="text-danger">*</span></th>
                                    <th style="width: 140px;">Check In (HH:MM)</th>
                                    <th style="width: 140px;">Check Out (HH:MM)</th>
                                    <th>Remarks / Notes</th>
                                    <th class="text-end pe-3" style="width: 100px;">Current</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeEmployees as $emp): ?>
                                    <?php
                                    $empId = (int)$emp['id'];
                                    $att = $attByEmp[$empId] ?? null;
                                    $leave = $leavesByEmp[$empId] ?? null;

                                    $isLockedOnLeave = ($leave !== null);
                                    $currentStatus = $att['status'] ?? ($isLockedOnLeave ? 'leave' : 'present');
                                    $checkInVal = !empty($att['check_in']) ? substr((string)$att['check_in'], 0, 5) : '';
                                    $checkOutVal = !empty($att['check_out']) ? substr((string)$att['check_out'], 0, 5) : '';
                                    $remarksVal = (string)($att['remarks'] ?? '');
                                    ?>
                                    <tr class="<?= $isLockedOnLeave ? 'table-active text-muted' : '' ?>">
                                        <td class="ps-3">
                                            <div class="fw-semibold text-body">
                                                <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                            </div>
                                            <div class="small text-muted font-monospace">
                                                <?= e($emp['employee_code']) ?> &bull; <?= e($emp['department_name'] ?? 'General') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($isLockedOnLeave): ?>
                                                <input type="hidden" name="att[<?= $empId ?>][status]" value="<?= e($currentStatus) ?>">
                                                <span class="badge text-bg-primary px-2 py-1">
                                                    <i class="bi bi-airplane me-1"></i> Approved Leave
                                                </span>
                                            <?php elseif ($isPastLocked || !$canManage): ?>
                                                <?= attendance_status_badge($currentStatus) ?>
                                            <?php else: ?>
                                                <select name="att[<?= $empId ?>][status]" class="form-select form-select-sm status-select" id="status-<?= $empId ?>">
                                                    <?php foreach ($statuses as $sKey => $sVal): ?>
                                                        <option value="<?= e($sKey) ?>" <?= $currentStatus === $sKey ? 'selected' : '' ?>>
                                                            <?= e($sVal) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isLockedOnLeave || $isPastLocked || !$canManage): ?>
                                                <span class="font-monospace small"><?= e($checkInVal !== '' ? $checkInVal : '—') ?></span>
                                            <?php else: ?>
                                                <input type="time" name="att[<?= $empId ?>][check_in]"
                                                       id="cin-<?= $empId ?>" class="form-control form-control-sm font-monospace"
                                                       value="<?= e($checkInVal) ?>" onchange="checkLateAuto(<?= $empId ?>, this.value)">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isLockedOnLeave || $isPastLocked || !$canManage): ?>
                                                <span class="font-monospace small"><?= e($checkOutVal !== '' ? $checkOutVal : '—') ?></span>
                                            <?php else: ?>
                                                <input type="time" name="att[<?= $empId ?>][check_out]"
                                                       id="cout-<?= $empId ?>" class="form-control form-control-sm font-monospace"
                                                       value="<?= e($checkOutVal) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isLockedOnLeave): ?>
                                                <span class="small text-muted fst-italic">
                                                    <?= e($leave['leave_type_name']) ?> (Ref #<?= (int)$leave['id'] ?>)
                                                </span>
                                            <?php elseif ($isPastLocked || !$canManage): ?>
                                                <span class="small text-muted"><?= e($remarksVal !== '' ? $remarksVal : '—') ?></span>
                                            <?php else: ?>
                                                <input type="text" name="att[<?= $empId ?>][remarks]"
                                                       class="form-control form-control-sm" maxlength="255"
                                                       placeholder="Optional note..." value="<?= e($remarksVal) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ($att): ?>
                                                <?= attendance_status_badge((string)$att['status']) ?>
                                            <?php else: ?>
                                                <span class="badge bg-body-secondary text-body border text-muted">Unrecorded</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($canManage && !$isPastLocked): ?>
                        <div class="card-footer bg-body py-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i> Unsaved edits will be recorded in a single atomic transaction.
                            </span>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Save Daily Attendance
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const standardStart = "<?= e($standardStart) ?>";

function checkLateAuto(empId, timeVal) {
    if (!timeVal) return;
    const statusSel = document.getElementById('status-' + empId);
    if (statusSel && timeVal > standardStart && statusSel.value === 'present') {
        statusSel.value = 'late';
    }
}

function markAllPresent() {
    const selects = document.querySelectorAll('.status-select');
    selects.forEach(s => {
        if (!s.disabled) {
            s.value = 'present';
        }
    });
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
