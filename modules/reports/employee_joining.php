<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Permissions: report.view AND employee.view via report_scope_companies()
$accessibleCompanies = report_scope_companies('employee.view');
if (empty($accessibleCompanies)) {
    abort(403, 'You do not have permission to view employee reports.');
}

$pdo = db();
$reportSlug = 'employee-joining-history';
$pageTitle  = 'Employee Joining History';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);

// Handle POST CSV Export (strictly omitting NIC, DOB, address, salary)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = report_employee_joining_history_rows($pdo, $exportCompanyIds, $exportFilters, 50000, 0);
    $headers = [
        'Joining Date', 'Employee Code', 'Employee Name', 'Company Code',
        'Company Name', 'Department', 'Designation', 'Contract Type', 'Employment Status'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['joining_date'],
            $r['employee_code'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['company_code'],
            $r['company_name'],
            $r['department_name'] ?? 'General',
            $r['designation_title'] ?? 'Staff',
            ucwords(str_replace('_', ' ', (string)$r['employment_type'])),
            employee_status_list()[$r['employment_status']] ?? ucfirst((string)$r['employment_status']),
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch monthly summary
$monthlySummary = report_employee_joining_history_monthly($pdo, $scopedCompanyIds, $filters);

// Pagination setup for row-level details
$totalRows = count_employee_joining_history($pdo, $scopedCompanyIds, $filters);
$perPage = 25;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$pg = paginate($totalRows, $currentPage, $perPage);

$reportRows = report_employee_joining_history_rows(
    $pdo,
    $scopedCompanyIds,
    $filters,
    $pg['per_page'],
    $pg['offset']
);

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<!-- Part A: Monthly Joining Summary -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Monthly Onboarding Aggregates</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($monthlySummary) ?> calendar periods</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 240px; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active sticky-top font-sans">
                    <tr>
                        <th class="ps-3">Month (YYYY-MM)</th>
                        <th>Calendar Period</th>
                        <th class="text-end pe-3">New Joinings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlySummary)): ?>
                        <tr><td colspan="3" class="text-center py-3 text-muted font-sans">No joinings recorded in the selected period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($monthlySummary as $ms): ?>
                            <tr>
                                <td class="ps-3 fw-bold text-body"><?= e($ms['ym']) ?></td>
                                <td class="font-sans"><?= e(date('F Y', strtotime($ms['ym'] . '-01'))) ?></td>
                                <td class="text-end pe-3 text-success fw-bold"><?= (int)$ms['joinings_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Part B: Chronological Staff Registry -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Staff Onboarding Registry</h5>
        <span class="badge bg-body-secondary text-body border"><?= $totalRows ?> total employees</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Joining Date</th>
                        <th>Employee</th>
                        <th>Company &bull; Department</th>
                        <th>Designation</th>
                        <th>Contract</th>
                        <th class="text-end pe-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-sans">No staff records found for the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans">
                                    <div class="fw-semibold text-body"><?= e(format_date($row['joining_date'])) ?></div>
                                    <span class="text-muted small font-monospace"><?= e($row['joining_date']) ?></span>
                                </td>
                                <td class="font-sans">
                                    <span class="fw-semibold text-body"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></span>
                                    <div class="text-muted small font-monospace"><?= e($row['employee_code']) ?></div>
                                </td>
                                <td class="font-sans">
                                    <div><?= e($row['company_name']) ?></div>
                                    <div class="text-muted small"><?= e($row['department_name'] ?? 'General') ?></div>
                                </td>
                                <td class="font-sans"><?= e($row['designation_title'] ?? 'Staff') ?></td>
                                <td class="font-sans">
                                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e(ucwords(str_replace('_', ' ', (string)$row['employment_type']))) ?></span>
                                </td>
                                <td class="text-end pe-3 font-sans">
                                    <?= employee_status_badge((string)$row['employment_status']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer bg-body d-flex justify-content-between align-items-center py-2 no-print">
            <span class="small text-muted font-monospace">Showing <?= count($reportRows) ?> of <?= $totalRows ?> records</span>
            <?= render_pagination($pg) ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
