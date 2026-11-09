<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Authorization: Requires leave.view or leave.approve
if (!user_can('leave.view') && !user_can('leave.approve')) {
    abort(403, 'You do not have permission to view leave reports.');
}

$pdo = db();
$allAccessible = accessible_company_ids();
$authorizedCompanies = [];
foreach ($allAccessible as $cid) {
    if (user_can('leave.view', $cid) || user_can('leave.approve', $cid)) {
        $authorizedCompanies[] = $cid;
    }
}

if (empty($authorizedCompanies)) {
    abort(403, 'You do not have access to leave records for any company.');
}

// Fetch companies
$phComp = implode(',', array_fill(0, count($authorizedCompanies), '?'));
$compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($phComp) ORDER BY name");
$compSt->execute($authorizedCompanies);
$companies = $compSt->fetchAll();

// Report Type: 'balances' | 'history' | 'status'
$reportType = trim((string)($_GET['type'] ?? 'balances'));
if (!in_array($reportType, ['balances', 'history', 'status'], true)) {
    $reportType = 'balances';
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

// Fetch leave types for filter
$ltSt = $pdo->prepare("SELECT id, name, days_per_year FROM leave_types WHERE is_active = 1 AND (company_id IS NULL OR company_id = ?) ORDER BY name");
$ltSt->execute([$selectedCompanyId]);
$leaveTypes = $ltSt->fetchAll();

$filterDept   = (int)($_GET['department_id'] ?? 0);
$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterType   = (int)($_GET['leave_type_id'] ?? 0);
$filterStatus = trim((string)($_GET['status'] ?? ''));

$selectedYear = (int)($_GET['year'] ?? date('Y'));
if ($selectedYear < 2000 || $selectedYear > 2100) $selectedYear = (int)date('Y');

$rawDateFrom = trim((string)($_GET['date_from'] ?? ''));
$rawDateTo   = trim((string)($_GET['date_to'] ?? ''));

$dateFrom = parse_ymd($rawDateFrom) ?? "$selectedYear-01-01";
$dateTo   = parse_ymd($rawDateTo) ?? "$selectedYear-12-31";

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
// Report 1: Leave Balance Report Per Company
// -------------------------------------------------------------
if ($reportType === 'balances') {
    // Fetch active employees (capped at 200 per page if not exporting)
    $empWhere = ["e.company_id = ?", "e.employment_status = 'active'"];
    $empParams = [$selectedCompanyId];

    if ($filterDept > 0) {
        $empWhere[] = "e.department_id = ?";
        $empParams[] = $filterDept;
    }
    if ($filterEmp > 0) {
        $empWhere[] = "e.id = ?";
        $empParams[] = $filterEmp;
    }

    $empSql = "SELECT e.id, e.employee_code, e.first_name, e.last_name,
                      d.name AS department_name
                 FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
                WHERE " . implode(' AND ', $empWhere) . "
                ORDER BY d.name ASC, e.first_name ASC";
    if (!$isExport) {
        $empSql .= " LIMIT 200";
    }
    $stE = $pdo->prepare($empSql);
    $stE->execute($empParams);
    $activeStaff = $stE->fetchAll();

    // Compute balances for all staff with ONE aggregated query
    $yearStart = "$selectedYear-01-01";
    $yearEnd   = "$selectedYear-12-31";

    $stAggr = $pdo->prepare(
        "SELECT lr.employee_id, lr.leave_type_id, lr.status,
                lr.start_date, lr.end_date, lr.half_day
           FROM leave_requests lr
          WHERE lr.company_id = ?
            AND lr.status IN ('approved', 'pending')
            AND lr.start_date <= ?
            AND lr.end_date >= ?"
    );
    $stAggr->execute([$selectedCompanyId, $yearEnd, $yearStart]);
    $allRequests = $stAggr->fetchAll();

    $usageMap = [];
    foreach ($allRequests as $r) {
        $empId  = (int)$r['employee_id'];
        $typeId = (int)$r['leave_type_id'];
        $status = (string)$r['status'];

        $overlapStart = max((string)$r['start_date'], $yearStart);
        $overlapEnd   = min((string)$r['end_date'], $yearEnd);
        $isHalf       = (bool)$r['half_day'];

        if ($isHalf && (string)$r['start_date'] === (string)$r['end_date']) {
            $daysInYear = 0.5;
        } else {
            $daysInYear = (float)count(get_working_days_in_range($overlapStart, $overlapEnd, null, (int)($r['company_id'] ?? 0)));
        }

        if (!isset($usageMap[$empId][$typeId])) {
            $usageMap[$empId][$typeId] = ['taken' => 0.0, 'pending' => 0.0];
        }
        if ($status === 'approved') {
            $usageMap[$empId][$typeId]['taken'] += $daysInYear;
        } elseif ($status === 'pending') {
            $usageMap[$empId][$typeId]['pending'] += $daysInYear;
        }
    }

    $balanceMatrix = [];
    $typesToInclude = $leaveTypes;
    if ($filterType > 0) {
        $typesToInclude = array_filter($leaveTypes, fn($t) => (int)$t['id'] === $filterType);
    }

    foreach ($activeStaff as $staff) {
        $sId = (int)$staff['id'];
        $balanceMatrix[$sId] = [
            'staff' => $staff,
            'types' => [],
        ];
        foreach ($typesToInclude as $t) {
            $tId = (int)$t['id'];
            $allowance   = (float)$t['days_per_year'];
            $isUnlimited = ($allowance <= 0.0);
            $taken       = $usageMap[$sId][$tId]['taken'] ?? 0.0;
            $pending     = $usageMap[$sId][$tId]['pending'] ?? 0.0;
            $remaining   = $isUnlimited ? INF : max(0.0, $allowance - $taken);
            $available   = $isUnlimited ? INF : max(0.0, $allowance - $taken - $pending);

            $balanceMatrix[$sId]['types'][$tId] = [
                'allowance'    => $allowance,
                'is_unlimited' => $isUnlimited,
                'taken'        => $taken,
                'pending'      => $pending,
                'remaining'    => $remaining,
                'available'    => $available,
            ];
        }
    }

    if ($isExport) {
        log_activity('leave.report_export', 'leave_request', null, ['type' => 'balances', 'year' => $selectedYear], $selectedCompanyId);
        $filename = 'leave_balances_' . $selectedYear . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");

        $header = ['Emp Code', 'Employee Name', 'Department'];
        foreach ($typesToInclude as $t) {
            $header[] = $t['name'] . ' (Allowance)';
            $header[] = $t['name'] . ' (Taken)';
            $header[] = $t['name'] . ' (Pending)';
            $header[] = $t['name'] . ' (Remaining)';
        }
        fputcsv($out, $header);

        foreach ($balanceMatrix as $row) {
            $stf = $row['staff'];
            $line = [
                escape_csv_cell($stf['employee_code']),
                escape_csv_cell($stf['first_name'] . ' ' . $stf['last_name']),
                escape_csv_cell($stf['department_name'] ?? 'General'),
            ];
            foreach ($typesToInclude as $t) {
                $b = $row['types'][(int)$t['id']];
                $line[] = $b['is_unlimited'] ? 'Unlimited' : (string)$b['allowance'];
                $line[] = (string)$b['taken'];
                $line[] = (string)$b['pending'];
                $line[] = $b['is_unlimited'] ? 'Unlimited' : (string)$b['remaining'];
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// Report 2: Leave History Per Employee
// -------------------------------------------------------------
if ($reportType === 'history') {
    $where = ["lr.company_id = ?", "lr.start_date <= ?", "lr.end_date >= ?"];
    $params = [$selectedCompanyId, $dateTo, $dateFrom];

    if ($filterDept > 0) {
        $where[] = "e.department_id = ?";
        $params[] = $filterDept;
    }
    if ($filterEmp > 0) {
        $where[] = "lr.employee_id = ?";
        $params[] = $filterEmp;
    }
    if ($filterType > 0) {
        $where[] = "lr.leave_type_id = ?";
        $params[] = $filterType;
    }
    if ($filterStatus !== '') {
        $where[] = "lr.status = ?";
        $params[] = $filterStatus;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT lr.*, lt.name AS leave_type_name,
                   e.employee_code, e.first_name, e.last_name,
                   d.name AS department_name,
                   u.full_name AS approver_name
              FROM leave_requests lr
              JOIN employees e ON e.id = lr.employee_id
              JOIN leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN users u ON u.id = lr.decided_by
             WHERE $whereSql
             ORDER BY lr.requested_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $historyRows = $st->fetchAll();

    if ($isExport) {
        log_activity('leave.report_export', 'leave_request', null, ['type' => 'history', 'from' => $dateFrom, 'to' => $dateTo], $selectedCompanyId);
        $filename = 'leave_history_' . $dateFrom . '_to_' . $dateTo . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Ref #', 'Emp Code', 'Employee Name', 'Department', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Status', 'Reason', 'Decided By', 'Decided At']);

        foreach ($historyRows as $r) {
            fputcsv($out, [
                (int)$r['id'],
                escape_csv_cell($r['employee_code']),
                escape_csv_cell($r['first_name'] . ' ' . $r['last_name']),
                escape_csv_cell($r['department_name'] ?? 'General'),
                escape_csv_cell($r['leave_type_name']),
                escape_csv_cell($r['start_date']),
                escape_csv_cell($r['end_date']),
                (float)$r['total_days'],
                escape_csv_cell(ucfirst((string)$r['status'])),
                escape_csv_cell($r['reason'] ?? ''),
                escape_csv_cell($r['approver_name'] ?? ''),
                escape_csv_cell($r['decided_at'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------
// Report 3: Requests by Status
// -------------------------------------------------------------
if ($reportType === 'status') {
    $where = ["company_id = ?"];
    $params = [$selectedCompanyId];
    if ($dateFrom !== '' && $dateTo !== '') {
        $where[] = "start_date <= ? AND end_date >= ?";
        $params[] = $dateTo;
        $params[] = $dateFrom;
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT status, COUNT(*) AS count_requests, SUM(total_days) AS sum_days
              FROM leave_requests
             WHERE $whereSql
             GROUP BY status";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $statusSummary = $st->fetchAll();

    if ($isExport) {
        log_activity('leave.report_export', 'leave_request', null, ['type' => 'status'], $selectedCompanyId);
        $filename = 'leave_requests_by_status.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Status', 'Total Requests', 'Total Days']);

        foreach ($statusSummary as $r) {
            fputcsv($out, [
                escape_csv_cell(ucfirst((string)$r['status'])),
                (int)$r['count_requests'],
                (float)$r['sum_days'],
            ]);
        }
        fclose($out);
        exit;
    }
}

$pageTitle = 'Leave Reports & Analytics';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1400px;">
    <!-- Top Bar -->
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/leave/index.php')) ?>">Leave</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reports</li>
                </ol>
            </nav>
            <h1 class="page-header-title h3 mb-0">Leave Analytics &amp; Reports</h1>
        </div>
        <div class="page-actions">
            <a href="<?= e(url('modules/leave/index.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar2-range me-1"></i> Applications List
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
            <a class="nav-link <?= $reportType === 'balances' ? 'active' : '' ?>" href="?type=balances&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-wallet2 me-1"></i> Leave Balance Report per Company
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'history' ? 'active' : '' ?>" href="?type=history&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-clock-history me-1"></i> Leave History per Employee
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $reportType === 'status' ? 'active' : '' ?>" href="?type=status&company_id=<?= $selectedCompanyId ?>">
                <i class="bi bi-pie-chart me-1"></i> Requests by Status
            </a>
        </li>
    </ul>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="get" action="<?= e(url('modules/leave/reports.php')) ?>" class="row g-2 align-items-end">
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

                <?php if ($reportType === 'balances'): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Calendar Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Leave Policy</label>
                        <select name="leave_type_id" class="form-select form-select-sm">
                            <option value="">All Leave Types</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?= (int)$lt['id'] ?>" <?= $filterType === (int)$lt['id'] ? 'selected' : '' ?>>
                                    <?= e($lt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($reportType === 'history'): ?>
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
                <?php elseif ($reportType === 'status'): ?>
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

    <!-- Report Table Content -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0">
                <?php if ($reportType === 'balances'): ?>
                    Company Staff Leave Balances: Year <?= e((string)$selectedYear) ?>
                <?php elseif ($reportType === 'history'): ?>
                    Leave Applications History: <?= format_date($dateFrom) ?> to <?= format_date($dateTo) ?> (<?= count($historyRows) ?> records)
                <?php elseif ($reportType === 'status'): ?>
                    Leave Requests Breakdown by Status
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if ($reportType === 'balances'): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Employee</th>
                                <th>Department</th>
                                <?php foreach ($typesToInclude as $t): ?>
                                    <th class="text-center" style="min-width: 130px;">
                                        <?= e($t['name']) ?>
                                        <div class="fw-normal text-muted small" style="font-size:0.75rem;">
                                            (<?= (float)$t['days_per_year'] <= 0 ? '&infin;' : number_format((float)$t['days_per_year'], 1) ?>d)
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($balanceMatrix)): ?>
                                <tr><td colspan="<?= count($typesToInclude) + 2 ?>" class="text-center py-4 text-muted">No active staff members found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($balanceMatrix as $row): ?>
                                    <?php $stf = $row['staff']; ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold text-body">
                                            <?= e($stf['first_name'] . ' ' . $stf['last_name']) ?>
                                            <span class="text-muted small font-monospace ms-1">(<?= e($stf['employee_code']) ?>)</span>
                                        </td>
                                        <td><?= e($stf['department_name'] ?? 'General') ?></td>
                                        <?php foreach ($typesToInclude as $t): ?>
                                            <?php
                                            $b = $row['types'][(int)$t['id']];
                                            $rem = $b['is_unlimited'] ? '&infin;' : number_format($b['remaining'], 1);
                                            ?>
                                            <td class="text-center font-monospace">
                                                <span class="fw-bold <?= ($b['remaining'] > 0 || $b['is_unlimited']) ? 'text-primary' : 'text-danger' ?>">
                                                    <?= $rem ?>
                                                </span>
                                                <div class="text-muted small" style="font-size:0.72rem;">
                                                    Used: <?= number_format($b['taken'], 1) ?>
                                                    <?php if ($b['pending'] > 0): ?>
                                                        &bull; <span class="text-warning"><?= number_format($b['pending'], 1) ?>p</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($reportType === 'history'): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Ref #</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Leave Type</th>
                                <th>Period &amp; Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Decided By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($historyRows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No leave requests found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($historyRows as $hr): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace">#<?= (int)$hr['id'] ?></td>
                                        <td class="fw-semibold text-body">
                                            <?= e($hr['first_name'] . ' ' . $hr['last_name']) ?>
                                            <span class="text-muted small font-monospace ms-1">(<?= e($hr['employee_code']) ?>)</span>
                                        </td>
                                        <td><?= e($hr['department_name'] ?? 'General') ?></td>
                                        <td><?= e($hr['leave_type_name']) ?></td>
                                        <td>
                                            <span class="fw-medium text-body"><?= e(format_date($hr['start_date'])) ?></span>
                                            <?php if ($hr['start_date'] !== $hr['end_date']): ?>
                                                <i class="bi bi-arrow-right mx-1 text-muted"></i> <?= e(format_date($hr['end_date'])) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-body-secondary text-body border font-monospace fw-bold fs-6">
                                                <?= e(number_format((float)$hr['total_days'], 1)) ?>
                                            </span>
                                        </td>
                                        <td><?= leave_status_badge((string)$hr['status']) ?></td>
                                        <td>
                                            <div class="small fw-medium text-body"><?= e($hr['approver_name'] ?? '—') ?></div>
                                            <?php if (!empty($hr['decided_at'])): ?>
                                                <div class="text-muted small"><?= e(format_date($hr['decided_at'])) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($reportType === 'status'): ?>
                <div class="p-4">
                    <div class="row g-3">
                        <?php if (empty($statusSummary)): ?>
                            <div class="col-12 text-center py-4 text-muted">No requests found.</div>
                        <?php else: ?>
                            <?php foreach ($statusSummary as $ss): ?>
                                <div class="col-md-3 col-sm-6">
                                    <div class="card border-0 shadow-sm text-center p-3">
                                        <div class="mb-2">
                                            <?= leave_status_badge((string)$ss['status']) ?>
                                        </div>
                                        <div class="fs-3 fw-bold font-monospace text-body">
                                            <?= (int)$ss['count_requests'] ?>
                                        </div>
                                        <div class="text-muted small">
                                            Total: <span class="fw-bold font-monospace"><?= number_format((float)$ss['sum_days'], 1) ?></span> days
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
