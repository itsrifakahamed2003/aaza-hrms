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
$reportSlug = 'employees-by-department';
$pageTitle  = 'Department-Wise Employee Count';

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

    $rows = report_department_employee_counts($pdo, $exportCompanyIds, $exportFilters);
    $headers = ['Company Code', 'Company Name', 'Department Name', 'Active Staff Count', 'Total Staff Records'];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['department_name'],
            $r['active_count'],
            $r['total_count'],
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_department_employee_counts($pdo, $scopedCompanyIds, $filters);

// Compute totals
$totActive = 0;
$totAll    = 0;
foreach ($reportRows as $r) {
    $totActive += (int)$r['active_count'];
    $totAll    += (int)$r['total_count'];
}

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = false;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Departmental Staff Distribution</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> departments</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Company</th>
                        <th>Department</th>
                        <th class="text-end">Active Staff</th>
                        <th class="text-end pe-3">Total Registered Staff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted font-sans">No departmental records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small font-monospace">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td class="font-sans">
                                    <span class="fw-semibold text-body"><?= e($row['department_name']) ?></span>
                                </td>
                                <td class="text-end fw-bold text-success font-monospace"><?= (int)$row['active_count'] ?></td>
                                <td class="text-end pe-3 text-secondary font-monospace"><?= (int)$row['total_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td colspan="2" class="ps-3">Total</td>
                            <td class="text-end text-success font-monospace"><?= $totActive ?></td>
                            <td class="text-end pe-3 text-primary font-monospace"><?= $totAll ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
