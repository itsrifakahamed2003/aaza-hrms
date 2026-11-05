<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

// 1. Linking logins to employees
$employee = get_linked_employee_for_user($pdo, $currentUserId);
if (!$employee) {
    $pageTitle = 'My Attendance - Account Not Linked';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container-fluid py-5" style="max-width: 750px;">
        <div class="card border-0 shadow-sm text-center p-5">
            <div class="mb-3">
                <i class="bi bi-person-x-fill text-warning" style="font-size: 3.5rem;"></i>
            </div>
            <h3 class="card-title fw-bold">Employee Profile Link Required</h3>
            <p class="text-muted mb-4">
                Your user login is not currently linked to an active employee profile. Ask HR to link your login to your employee record.
            </p>
            <div>
                <a href="<?= e(url('public/index.php')) ?>" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$employeeId = (int)$employee['id'];
$companyId  = (int)$employee['company_id'];
$today      = date('Y-m-d');
$selfServiceEnabled = defined('ATTENDANCE_SELF_SERVICE') && ATTENDANCE_SELF_SERVICE === true;

// Handle Self-Service Check-in / Check-out (today only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selfServiceEnabled) {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));
    $serverTime = date('H:i:s');
    $standardStart = function_exists('get_company_setting')
        ? (string)get_company_setting($pdo, $companyId, 'attendance.work_start_time', defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00')
        : (defined('ATTENDANCE_WORK_START') ? ATTENDANCE_WORK_START : '09:00');
    $lateGrace = function_exists('get_company_setting')
        ? (int)get_company_setting($pdo, $companyId, 'attendance.late_grace_minutes', 0)
        : 0;
    $lateCutoff = $lateGrace > 0 ? date('H:i', strtotime($standardStart . " +{$lateGrace} minutes")) : $standardStart;

    try {
        $pdo->beginTransaction();

        $stToday = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND att_date = ? FOR UPDATE");
        $stToday->execute([$employeeId, $today]);
        $todayAtt = $stToday->fetch();

        if ($action === 'check_in') {
            if ($todayAtt && !empty($todayAtt['check_in'])) {
                $pdo->rollBack();
                flash_set('warning', 'You have already checked in today at ' . substr((string)$todayAtt['check_in'], 0, 5) . '.');
                redirect('modules/attendance/employee_attendance.php');
            }

            $status = (substr($serverTime, 0, 5) > $lateCutoff) ? 'late' : 'present';

            if ($todayAtt) {
                $upd = $pdo->prepare("UPDATE attendance SET check_in = ?, status = ?, recorded_by = ? WHERE id = ?");
                $upd->execute([$serverTime, $status, $currentUserId, (int)$todayAtt['id']]);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO attendance (employee_id, company_id, att_date, check_in, status, recorded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())"
                );
                $ins->execute([$employeeId, $companyId, $today, $serverTime, $status, $currentUserId]);
            }

            $pdo->commit();
            log_activity('attendance.self_check_in', 'attendance', null, ['time' => $serverTime, 'status' => $status], $companyId);
            flash_set('success', "Check-in recorded at " . substr($serverTime, 0, 5) . " (Status: " . ucfirst($status) . ").");
        } elseif ($action === 'check_out') {
            if (!$todayAtt || empty($todayAtt['check_in'])) {
                $pdo->rollBack();
                flash_set('danger', 'You must check in first before checking out.');
                redirect('modules/attendance/employee_attendance.php');
            }

            if (!empty($todayAtt['check_out'])) {
                $pdo->rollBack();
                flash_set('warning', 'You have already checked out today at ' . substr((string)$todayAtt['check_out'], 0, 5) . '.');
                redirect('modules/attendance/employee_attendance.php');
            }

            $upd = $pdo->prepare("UPDATE attendance SET check_out = ?, recorded_by = ? WHERE id = ?");
            $upd->execute([$serverTime, $currentUserId, (int)$todayAtt['id']]);

            $pdo->commit();
            log_activity('attendance.self_check_out', 'attendance', null, ['time' => $serverTime], $companyId);
            flash_set('success', "Check-out recorded at " . substr($serverTime, 0, 5) . ".");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Self attendance error: ' . $e->getMessage());
        flash_set('danger', 'Failed to record attendance time. Please try again.');
    }

    redirect('modules/attendance/employee_attendance.php');
}

// Fetch Today's Attendance State for the Button Widget
$todaySt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND att_date = ?");
$todaySt->execute([$employeeId, $today]);
$todayRecord = $todaySt->fetch();

// Month Filter: default current month (YYYY-MM; strict parse_ym validation)
$rawMonth = trim((string)($_GET['month'] ?? ''));
$parsedMonth = parse_ym($rawMonth);
$selectedMonth = $parsedMonth ?? date('Y-m');

$monthStart = "$selectedMonth-01";
$dStartObj  = new DateTime($monthStart);
$monthEnd   = $dStartObj->format('Y-m-t');

// Fetch all attendance records for this month
$attSql = "SELECT * FROM attendance
            WHERE employee_id = ?
              AND att_date BETWEEN ? AND ?
            ORDER BY att_date DESC";
$attSt = $pdo->prepare($attSql);
$attSt->execute([$employeeId, $monthStart, $monthEnd]);
$monthRecords = $attSt->fetchAll();

// Month public holidays
$empCompanyId = (int)($employee['company_id'] ?? 0);
$monthHolidays = get_holidays_in_range($monthStart, $monthEnd, $empCompanyId);

// Calculate Monthly Summary Stats
$counts = ['present' => 0, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'leave' => 0];
$totalHours = 0.0;
foreach ($monthRecords as $mr) {
    $s = (string)$mr['status'];
    if (isset($counts[$s])) {
        $counts[$s]++;
    }
    if (!empty($mr['check_in']) && !empty($mr['check_out'])) {
        $cin = strtotime((string)$mr['check_in']);
        $cout = strtotime((string)$mr['check_out']);
        if ($cout > $cin) {
            $totalHours += ($cout - $cin) / 3600.0;
        }
    }
}

$pageTitle = 'My Attendance Log';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1100px;">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">My Attendance</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <h1 class="h3 mb-0">My Attendance Log</h1>
                <span class="badge bg-body-secondary text-body border"><?= e($employee['company_code']) ?> &bull; <?= e($employee['employee_code']) ?></span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/leave/my_leaves.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-person-walking me-1"></i> My Leave
            </a>
            <?php if (user_can('leave.request') && !empty($navHasLinkedEmp)): ?>
                <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Apply for Leave
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self-Service Check-in / Check-out Widget (when enabled) -->
    <?php if ($selfServiceEnabled): ?>
        <div class="card border-0 shadow-sm mb-4 bg-body-tertiary">
            <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Today's Attendance (<?= format_date($today, 'l, d M Y') ?>)</h5>
                    <div class="text-muted small">
                        <?php if ($todayRecord): ?>
                            Status: <?= attendance_status_badge((string)$todayRecord['status']) ?> &bull;
                            Check-in: <span class="fw-semibold font-monospace"><?= e(!empty($todayRecord['check_in']) ? substr((string)$todayRecord['check_in'], 0, 5) : '—') ?></span> &bull;
                            Check-out: <span class="fw-semibold font-monospace"><?= e(!empty($todayRecord['check_out']) ? substr((string)$todayRecord['check_out'], 0, 5) : '—') ?></span>
                        <?php else: ?>
                            No attendance recorded yet for today.
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <form method="post" action="<?= e(url('modules/attendance/employee_attendance.php')) ?>" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <?php if (!$todayRecord || empty($todayRecord['check_in'])): ?>
                            <button type="submit" name="action" value="check_in" class="btn btn-success px-4">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Check In Now
                            </button>
                        <?php elseif (empty($todayRecord['check_out'])): ?>
                            <button type="submit" name="action" value="check_out" class="btn btn-warning px-4 text-body">
                                <i class="bi bi-box-arrow-right me-1"></i> Check Out Now
                            </button>
                        <?php else: ?>
                            <span class="badge text-bg-success px-3 py-2 fs-6">
                                <i class="bi bi-check2-circle me-1"></i> Shift Completed
                            </span>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Month Selector & KPI Cards -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <form method="get" action="<?= e(url('modules/attendance/employee_attendance.php')) ?>" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 fw-semibold text-secondary small">Month:</label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()">
                <button type="submit" class="btn btn-sm btn-outline-primary">View</button>
            </form>
            <div class="small text-muted">
                Total Tracked Work Hours: <span class="fw-bold font-monospace text-body"><?= number_format($totalHours, 1) ?> hrs</span>
            </div>
        </div>
    </div>

    <!-- Monthly KPI Counters -->
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-success font-monospace"><?= $counts['present'] ?></div>
                <div class="text-muted small">Present</div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-warning font-monospace"><?= $counts['late'] ?></div>
                <div class="text-muted small">Late</div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-info font-monospace"><?= $counts['half_day'] ?></div>
                <div class="text-muted small">Half Day</div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-primary font-monospace"><?= $counts['leave'] ?></div>
                <div class="text-muted small">On Leave</div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-4 fw-bold text-danger font-monospace"><?= $counts['absent'] ?></div>
                <div class="text-muted small">Absent</div>
            </div>
        </div>
    </div>

    <!-- Records Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0">Daily Logs for <?= format_date($monthStart, 'F Y') ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Day</th>
                            <th>Status</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Duration</th>
                            <th>Remarks / Leave Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthRecords)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    No attendance records found for <?= format_date($monthStart, 'F Y') ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monthRecords as $row): ?>
                                <?php
                                $dTime = strtotime((string)$row['att_date']);
                                $dayName = date('l', $dTime);
                                $rowYmd = (string)$row['att_date'];
                                $rowHoliday = $monthHolidays[$rowYmd] ?? null;
                                $cin = !empty($row['check_in']) ? substr((string)$row['check_in'], 0, 5) : '—';
                                $cout = !empty($row['check_out']) ? substr((string)$row['check_out'], 0, 5) : '—';
                                $hoursWorked = '—';
                                if (!empty($row['check_in']) && !empty($row['check_out'])) {
                                    $diff = strtotime((string)$row['check_out']) - strtotime((string)$row['check_in']);
                                    if ($diff > 0) {
                                        $h = floor($diff / 3600);
                                        $m = floor(($diff % 3600) / 60);
                                        $hoursWorked = sprintf('%02dh %02dm', $h, $m);
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="ps-3 fw-medium font-monospace">
                                        <?= e(format_date($row['att_date'])) ?>
                                        <?php if ($rowHoliday !== null): ?>
                                            <span class="badge text-bg-warning text-body ms-1 small" title="Public Holiday: <?= e($rowHoliday) ?>">
                                                <i class="bi bi-calendar-event me-1"></i><?= e($rowHoliday) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= e($dayName) ?></span>
                                    </td>
                                    <td>
                                        <?= attendance_status_badge((string)$row['status']) ?>
                                    </td>
                                    <td class="font-monospace small">
                                        <?= e($cin) ?>
                                    </td>
                                    <td class="font-monospace small">
                                        <?= e($cout) ?>
                                    </td>
                                    <td class="font-monospace small text-muted">
                                        <?= e($hoursWorked) ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= e($row['remarks'] ?? '—') ?>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
