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
$reportSlug = 'employee-status-summary';
$pageTitle  = 'Active and Inactive Employees';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);

// Handle POST CSV Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = report_employee_status_summary($pdo, $exportCompanyIds, $exportFilters);
    $headers = ['Employment Status', 'Status Code', 'Employee Headcount', 'Percentage Share (%)'];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['status_label'],
            $r['employment_status'],
            $r['status_count'],
            $r['percentage'] . '%',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_employee_status_summary($pdo, $scopedCompanyIds, $filters);
$totalStaff = array_sum(array_column($reportRows, 'status_count'));

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = false;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Workforce Activity &amp; Separation Distribution</h5>
        <span class="badge bg-body-secondary text-body border"><?= $totalStaff ?> total staff records</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Status Label</th>
                        <th>System Code</th>
                        <th class="text-end">Employee Headcount</th>
                        <th class="text-end pe-3">Percentage Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted font-sans">No employee records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans">
                                    <?= employee_status_badge((string)$row['employment_status']) ?>
                                </td>
                                <td><?= e($row['employment_status']) ?></td>
                                <td class="text-end fw-bold font-monospace text-body"><?= (int)$row['status_count'] ?></td>
                                <td class="text-end pe-3 font-monospace">
                                    <span class="badge bg-body-secondary text-body border fw-bold text-secondary">
                                        <?= number_format((float)$row['percentage'], 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td colspan="2" class="ps-3">Total Headcount</td>
                            <td class="text-end text-primary font-monospace"><?= $totalStaff ?></td>
                            <td class="text-end pe-3 text-primary font-monospace">100.0%</td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
