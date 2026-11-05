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
$reportSlug = 'employees-by-company';
$pageTitle  = 'Employees by Company';

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

    $rows = report_employees_by_company($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Company Code', 'Company Name', 'Active Staff', 'On Leave',
        'Transferred', 'Resigned', 'Terminated', 'Retired', 'Total Headcount'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['active_count'],
            $r['on_leave_count'],
            $r['transferred_count'],
            $r['resigned_count'],
            $r['terminated_count'],
            $r['retired_count'],
            $r['total_count'],
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_employees_by_company($pdo, $scopedCompanyIds, $filters);

// Compute totals
$totActive      = 0;
$totLeave       = 0;
$totTransferred = 0;
$totResigned    = 0;
$totTerminated  = 0;
$totRetired     = 0;
$totAll         = 0;
foreach ($reportRows as $r) {
    $totActive      += (int)$r['active_count'];
    $totLeave       += (int)$r['on_leave_count'];
    $totTransferred += (int)$r['transferred_count'];
    $totResigned    += (int)$r['resigned_count'];
    $totTerminated  += (int)$r['terminated_count'];
    $totRetired     += (int)$r['retired_count'];
    $totAll         += (int)$r['total_count'];
}

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = false;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Company Headcount &amp; Retention Breakdown</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> entities</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Company Entity</th>
                        <th class="text-end">Active Staff</th>
                        <th class="text-end">On Leave</th>
                        <th class="text-end">Transferred</th>
                        <th class="text-end">Resigned</th>
                        <th class="text-end">Terminated</th>
                        <th class="text-end">Retired</th>
                        <th class="text-end pe-3">Total Records</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted font-sans">No employee records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small font-monospace">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td class="text-end fw-bold text-success"><?= (int)$row['active_count'] ?></td>
                                <td class="text-end text-warning"><?= (int)$row['on_leave_count'] ?></td>
                                <td class="text-end text-info"><?= (int)$row['transferred_count'] ?></td>
                                <td class="text-end text-secondary"><?= (int)$row['resigned_count'] ?></td>
                                <td class="text-end text-danger"><?= (int)$row['terminated_count'] ?></td>
                                <td class="text-end text-secondary"><?= (int)$row['retired_count'] ?></td>
                                <td class="text-end pe-3 fw-bold text-primary"><?= (int)$row['total_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td class="ps-3">Group Total</td>
                            <td class="text-end text-success font-monospace"><?= $totActive ?></td>
                            <td class="text-end text-warning font-monospace"><?= $totLeave ?></td>
                            <td class="text-end text-info font-monospace"><?= $totTransferred ?></td>
                            <td class="text-end text-secondary font-monospace"><?= $totResigned ?></td>
                            <td class="text-end text-danger font-monospace"><?= $totTerminated ?></td>
                            <td class="text-end text-secondary font-monospace"><?= $totRetired ?></td>
                            <td class="text-end pe-3 text-primary font-monospace"><?= $totAll ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
