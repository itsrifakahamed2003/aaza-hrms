<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Authorization: Requires attendance.view or attendance.manage
if (!user_can('attendance.view') && !user_can('attendance.manage')) {
    abort(403, 'You do not have permission to view attendance reports.');
}

$pdo = db();
$allAccessible = accessible_company_ids();
$authorizedCompanies = [];
foreach ($allAccessible as $cid) {
    if (user_can('attendance.view', $cid) || user_can('attendance.manage', $cid)) {
        $authorizedCompanies[] = $cid;
    }
}

if (empty($authorizedCompanies)) {
    abort(403, 'You do not have access to attendance records for any company.');
}

// Fetch companies
$phComp = implode(',', array_fill(0, count($authorizedCompanies), '?'));
$compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($phComp) ORDER BY name");
$compSt->execute($authorizedCompanies);
$companies = $compSt->fetchAll();

// Report Type: 'daily' | 'monthly' | 'employee'
$reportType = trim((string)($_GET['type'] ?? 'daily'));
if (!in_array($reportType, ['daily', 'monthly', 'employee'], true)) {
    $reportType = 'daily';
}

$selectedCompanyId = (int)($_GET['company_id'] ?? active_company_id());
if ($selectedCompanyId <= 0 || !in_array($selectedCompanyId, $authorizedCompanies, true)) {
    $selectedCompanyId = (int)$companies[0]['id'];
}

// Fetch departments for filter
$deptSt = $pdo->prepare("SELECT id, name FROM departments WHERE company_id = ? ORDER BY name");
$deptSt->execute([$selectedCompanyId]);
$departments = $deptSt->fetchAll();

// Fetch employees for employee filter
$empSt = $pdo->prepare("SELECT id, employee_code, first_name, last_name FROM employees WHERE company_id = ? ORDER BY first_name, last_name");
$empSt->execute([$selectedCompanyId]);
$employeesList = $empSt->fetchAll();

$filterDept = (int)($_GET['department_id'] ?? 0);
$filterEmp  = (int)($_GET['employee_id'] ?? 0);

$rawSelectedDate = trim((string)($_GET['date'] ?? ''));
$selectedDate = parse_ymd($rawSelectedDate) ?? date('Y-m-d');

$rawSelectedMonth = trim((string)($_GET['month'] ?? ''));
$selectedMonth = parse_ym($rawSelectedMonth) ?? date('Y-m');

$rawDateFrom = trim((string)($_GET['date_from'] ?? ''));
$rawDateTo   = trim((string)($_GET['date_to'] ?? ''));

$dateFrom = parse_ymd($rawDateFrom) ?? date('Y-m-01');
$dateTo   = parse_ymd($rawDateTo) ?? date('Y-m-d');

if ($dateFrom > $dateTo) {
    $dateTo = $dateFrom;
}

// In reports, a date range must not exceed 366 days
$dFrom = new DateTime($dateFrom);
$dTo   = new DateTime($dateTo);
$rangeDiff = (int)$dFrom->diff($dTo)->format('%r%a');
if ($rangeDiff > 366) {
    $dateTo = (clone $dFrom)->modify('+366 days')->format('Y-m-d');
}

$isExport = (($_GET['export'] ?? '') === 'csv');

// -------------------------------------------------------------
// Report 1: Daily Report
// -------------------------------------------------------------
if ($reportType === 'daily') {
    $where = ["a.company_id = ?", "a.att_date = ?"];
    $params = [$selectedCompanyId, $selectedDate];

    if ($filterDept > 0) {
        $where[] = "e.department_id = ?";
        $params[] = $filterDept;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT a.*, e.employee_code, e.first_name, e.last_name,
                   d.name AS department_name, des.title AS designation_title
              FROM attendance a
              JOIN employees e ON e.id = a.employee_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
             WHERE $whereSql
             ORDER BY d.name ASC, e.first_name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    if ($isExport) {
        log_activity('attendance.report_export', 'attendance', null, ['type' => 'daily', 'date' => $selectedDate], $selectedCompanyId);
        $filename = 'attendance_daily_' . $selectedDate . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Emp Code', 'Employee Name', 'Department', 'Designation', 'Date', 'Status', 'Check In', 'Check Out', 'Remarks']);

        foreach ($rows as $r) {
            fputcsv($out, [
                escape_csv_cell($r['employee_code']),
                escape_csv_cell($r['first_name'] . ' ' . $r['last_name']),
                escape_csv_cell($r['department_name'] ?? 'General'),
                escape_csv_cell($r['designation_title'] ?? 'Staff'),
                escape_csv_cell($r['att_date']),
                escape_csv_cell(ucfirst((string)$r['status'])),
                escape_csv_cell(!empty($r['check_in']) ? substr((string)$r['check_in'], 0, 5) : ''),
                escape_csv_cell(!empty($r['check_out']) ? substr((string)$r['check_out'], 0, 5) : ''),
                escape_csv_cell($r['remarks'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// Report 2: Monthly Summary Per Employee
// -------------------------------------------------------------
if ($reportType === 'monthly') {
    $mStart = "$selectedMonth-01";
    $mEnd   = (new DateTime($mStart))->format('Y-m-t');

    $where = ["e.company_id = ?", "e.employment_status = 'active'"];
    $params = [$selectedCompanyId];

    if ($filterDept > 0) {
        $where[] = "e.department_id = ?";
        $params[] = $filterDept;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT e.id, e.employee_code, e.first_name, e.last_name,
                   d.name AS department_name, des.title AS designation_title,
                   COUNT(CASE WHEN a.status = 'present' THEN 1 END) AS present_count,
                   COUNT(CASE WHEN a.status = 'late' THEN 1 END) AS late_count,
                   COUNT(CASE WHEN a.status = 'half_day' THEN 1 END) AS half_day_count,
                   COUNT(CASE WHEN a.status = 'leave' THEN 1 END) AS leave_count,
                   COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS absent_count,
                   COUNT(a.id) AS total_logged
              FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
         LEFT JOIN attendance a ON a.employee_id = e.id AND a.att_date BETWEEN '$mStart' AND '$mEnd'
             WHERE $whereSql
             GROUP BY e.id, e.employee_code, e.first_name, e.last_name, d.name, des.title
             ORDER BY d.name ASC, e.first_name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $monthlyRows = $st->fetchAll();

    if ($isExport) {
        log_activity('attendance.report_export', 'attendance', null, ['type' => 'monthly', 'month' => $selectedMonth], $selectedCompanyId);
        $filename = 'attendance_monthly_summary_' . $selectedMonth . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Emp Code', 'Employee Name', 'Department', 'Designation', 'Month', 'Present', 'Late', 'Half Day', 'On Leave', 'Absent', 'Total Recorded']);

        foreach ($monthlyRows as $r) {
            fputcsv($out, [
                escape_csv_cell($r['employee_code']),
                escape_csv_cell($r['first_name'] . ' ' . $r['last_name']),
                escape_csv_cell($r['department_name'] ?? 'General'),
                escape_csv_cell($r['designation_title'] ?? 'Staff'),
                escape_csv_cell($selectedMonth),
                (int)$r['present_count'],
                (int)$r['late_count'],
                (int)$r['half_day_count'],
                (int)$r['leave_count'],
                (int)$r['absent_count'],
                (int)$r['total_logged'],
            ]);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// Report 3: Employee-Wise History
// -------------------------------------------------------------
if ($reportType === 'employee') {
    $where = ["a.company_id = ?", "a.att_date BETWEEN ? AND ?"];
    $params = [$selectedCompanyId, $dateFrom, $dateTo];

    if ($filterEmp > 0) {
        $where[] = "a.employee_id = ?";
        $params[] = $filterEmp;
    }
    if ($filterDept > 0) {
        $where[] = "e.department_id = ?";
        $params[] = $filterDept;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT a.*, e.employee_code, e.first_name, e.last_name,
                   d.name AS department_name
              FROM attendance a
              JOIN employees e ON e.id = a.employee_id
         LEFT JOIN departments d ON d.id = e.department_id
             WHERE $whereSql
             ORDER BY a.att_date DESC, e.first_name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $empHistoryRows = $st->fetchAll();

    if ($isExport) {
        log_activity('attendance.report_export', 'attendance', null, ['type' => 'employee', 'from' => $dateFrom, 'to' => $dateTo], $selectedCompanyId);
        $filename = 'attendance_history_' . $dateFrom . '_to_' . $dateTo . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Emp Code', 'Employee Name', 'Department', 'Status', 'Check In', 'Check Out', 'Remarks']);

        foreach ($empHistoryRows as $r) {
            fputcsv($out, [
                escape_csv_cell($r['att_date']),
                escape_csv_cell($r['employee_code']),
                escape_csv_cell($r['first_name'] . ' ' . $r['last_name']),
                escape_csv_cell($r['department_name'] ?? 'General'),
                escape_csv_cell(ucfirst((string)$r['status'])),
                escape_csv_cell(!empty($r['check_in']) ? substr((string)$r['check_in'], 0, 5) : ''),
                escape_csv_cell(!empty($r['check_out']) ? substr((string)$r['check_out'], 0, 5) : ''),
                escape_csv_cell($r['remarks'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }
}

$pageTitle = 'Attendance Reports & Summaries';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/attendance/daily.php')) ?>">Attendance</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reports</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0">Attendance Analytics &amp; Reports</h1>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/attendance/daily.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar-check me-1"></i> Daily Sheet
            </a>
            <?php
            $exportParams = $_GET;
            $exportParams['export'] = 'csv';
            ?>
            <a href="?<?= http_build_query($exportParams) ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-1"></i> Export to CSV
            </a>
        </div>
    </div>

    <!-- Report Type Tabs -->
    <ul class="nav nav-pills mb-4 bg-body p-2 rounded shadow-sm border">
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'daily' ? 'active' : '' ?>" href="?type=daily&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-calendar-day me-1"></i> Daily Log Report
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'monthly' ? 'active' : '' ?>" href="?type=monthly&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-calendar-month me-1"></i> Monthly Summary per Employee
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'employee' ? 'active' : '' ?>" href="?type=employee&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-clock-history me-1"></i> Employee-wise History
            </a>
        </li>
    </ul>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="get" action="<?= e(url('modules/attendance/reports.php')) ?>" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="<?= e($reportType) ?>">

                <?php if (count($companies) > 1): ?>
                    <div class="col-md-3 col-sm-6">
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

                <?php if (!empty($departments)): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Department</label>
                        <select name="department_id" class="form-select form-select-sm">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($reportType === 'daily'): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($selectedDate) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                <?php elseif ($reportType === 'monthly'): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Month</label>
                        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($selectedMonth) ?>">
                    </div>
                <?php elseif ($reportType === 'employee'): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Employee</label>
                        <select name="employee_id" class="form-select form-select-sm">
                            <option value="">All Employees</option>
                            <?php foreach ($employeesList as $em): ?>
                                <option value="<?= (int)$em['id'] ?>" <?= $filterEmp === (int)$em['id'] ? 'selected' : '' ?>>
                                    <?= e($em['first_name'] . ' ' . $em['last_name']) ?> (<?= e($em['employee_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
                    </div>
                <?php endif; ?>

                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                    <a href="?type=<?= e($reportType) ?>&company_id=<?= $selectedCompanyId ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Table Render -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0">
                <?php if ($reportType === 'daily'): ?>
                    Daily Attendance Logs: <?= format_date($selectedDate, 'l, d F Y') ?> (<?= count($rows) ?> records)
                <?php elseif ($reportType === 'monthly'): ?>
                    Monthly Staff Summary: <?= format_date("$selectedMonth-01", 'F Y') ?> (<?= count($monthlyRows) ?> employees)
                <?php elseif ($reportType === 'employee'): ?>
                    Attendance Log History: <?= format_date($dateFrom) ?> to <?= format_date($dateTo) ?> (<?= count($empHistoryRows) ?> entries)
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if ($reportType === 'daily'): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Employee</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No attendance logs found for this date.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold text-body">
                                            <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                                            <span class="text-muted small font-monospace ms-1">(<?= e($r['employee_code']) ?>)</span>
                                        </td>
                                        <td><?= e($r['department_name'] ?? 'General') ?></td>
                                        <td><?= attendance_status_badge((string)$r['status']) ?></td>
                                        <td class="font-monospace small"><?= e(!empty($r['check_in']) ? substr((string)$r['check_in'], 0, 5) : '—') ?></td>
                                        <td class="font-monospace small"><?= e(!empty($r['check_out']) ? substr((string)$r['check_out'], 0, 5) : '—') ?></td>
                                        <td class="small text-muted"><?= e($r['remarks'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($reportType === 'monthly'): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Employee</th>
                                <th>Department</th>
                                <th class="text-center">Present</th>
                                <th class="text-center">Late</th>
                                <th class="text-center">Half Day</th>
                                <th class="text-center">On Leave</th>
                                <th class="text-center">Absent</th>
                                <th class="text-end pe-3">Total Logs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthlyRows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No records found for this month.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthlyRows as $mr): ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold text-body">
                                            <?= e($mr['first_name'] . ' ' . $mr['last_name']) ?>
                                            <span class="text-muted small font-monospace ms-1">(<?= e($mr['employee_code']) ?>)</span>
                                        </td>
                                        <td><?= e($mr['department_name'] ?? 'General') ?></td>
                                        <td class="text-center font-monospace fw-bold text-success"><?= (int)$mr['present_count'] ?></td>
                                        <td class="text-center font-monospace fw-bold text-warning"><?= (int)$mr['late_count'] ?></td>
                                        <td class="text-center font-monospace fw-bold text-info"><?= (int)$mr['half_day_count'] ?></td>
                                        <td class="text-center font-monospace fw-bold text-primary"><?= (int)$mr['leave_count'] ?></td>
                                        <td class="text-center font-monospace fw-bold text-danger"><?= (int)$mr['absent_count'] ?></td>
                                        <td class="text-end pe-3 font-monospace fw-bold"><?= (int)$mr['total_logged'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($reportType === 'employee'): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Date</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($empHistoryRows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No attendance entries found for the selected period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($empHistoryRows as $er): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace fw-medium"><?= e(format_date($er['att_date'])) ?></td>
                                        <td class="fw-semibold text-body">
                                            <?= e($er['first_name'] . ' ' . $er['last_name']) ?>
                                            <span class="text-muted small font-monospace ms-1">(<?= e($er['employee_code']) ?>)</span>
                                        </td>
                                        <td><?= e($er['department_name'] ?? 'General') ?></td>
                                        <td><?= attendance_status_badge((string)$er['status']) ?></td>
                                        <td class="font-monospace small"><?= e(!empty($er['check_in']) ? substr((string)$er['check_in'], 0, 5) : '—') ?></td>
                                        <td class="font-monospace small"><?= e(!empty($er['check_out']) ? substr((string)$er['check_out'], 0, 5) : '—') ?></td>
                                        <td class="small text-muted"><?= e($er['remarks'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
