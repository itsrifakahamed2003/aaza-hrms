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
$reportSlug = 'employment-type-summary';
$pageTitle  = 'Employment-Type Summary';

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

    $rows = report_employment_type_summary($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Company Code', 'Company Name', 'Full-Time', 'Part-Time',
        'Contract', 'Intern', 'Probation', 'Total Staff'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['full_time_count'],
            $r['part_time_count'],
            $r['contract_count'],
            $r['intern_count'],
            $r['probation_count'],
            $r['total_count'],
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_employment_type_summary($pdo, $scopedCompanyIds, $filters);

// Compute totals
$totFT = 0;
$totPT = 0;
$totCT = 0;
$totIN = 0;
$totPR = 0;
$totAll = 0;
foreach ($reportRows as $r) {
    $totFT  += (int)$r['full_time_count'];
    $totPT  += (int)$r['part_time_count'];
    $totCT  += (int)$r['contract_count'];
    $totIN  += (int)$r['intern_count'];
    $totPR  += (int)$r['probation_count'];
    $totAll += (int)$r['total_count'];
}

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = false;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Contract Category Breakdown per Company</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> entities</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Company Entity</th>
                        <th class="text-end">Full-Time</th>
                        <th class="text-end">Part-Time</th>
                        <th class="text-end">Contract</th>
                        <th class="text-end">Intern</th>
                        <th class="text-end">Probation</th>
                        <th class="text-end pe-3">Total Staff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted font-sans">No staff records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small font-monospace">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td class="text-end fw-bold text-primary font-monospace"><?= (int)$row['full_time_count'] ?></td>
                                <td class="text-end text-secondary font-monospace"><?= (int)$row['part_time_count'] ?></td>
                                <td class="text-end text-info font-monospace"><?= (int)$row['contract_count'] ?></td>
                                <td class="text-end text-warning font-monospace"><?= (int)$row['intern_count'] ?></td>
                                <td class="text-end text-danger font-monospace"><?= (int)$row['probation_count'] ?></td>
                                <td class="text-end pe-3 fw-bold text-body font-monospace"><?= (int)$row['total_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td class="ps-3">Group Total</td>
                            <td class="text-end text-primary font-monospace"><?= $totFT ?></td>
                            <td class="text-end text-secondary font-monospace"><?= $totPT ?></td>
                            <td class="text-end text-info font-monospace"><?= $totCT ?></td>
                            <td class="text-end text-warning font-monospace"><?= $totIN ?></td>
                            <td class="text-end text-danger font-monospace"><?= $totPR ?></td>
                            <td class="text-end pe-3 text-body font-monospace"><?= $totAll ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
