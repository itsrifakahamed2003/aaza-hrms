<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Permissions: report.view AND (candidate.view OR vacancy.view) via report_scope_companies()
$accessibleCompanies = report_scope_companies(['candidate.view', 'vacancy.view']);
if (empty($accessibleCompanies)) {
    abort(403, 'You do not have permission to view recruitment reports.');
}

$pdo = db();
$reportSlug = 'recruitment-source-analysis';
$pageTitle  = 'Recruitment Source Analysis';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);
$filterVacancies = report_get_filter_vacancies($pdo, $scopedCompanyIds);

// Handle POST CSV Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = report_recruitment_source_analysis($pdo, $exportCompanyIds, $exportFilters);
    $headers = ['Recruitment Channel / Source', 'Candidates Sourced', 'Applications Submitted', 'Candidates Hired', 'Conversion Rate (%)'];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['source_name'],
            $r['candidates_count'],
            $r['applications_count'],
            $r['hired_count'],
            $r['conversion_rate'] . '%',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_recruitment_source_analysis($pdo, $scopedCompanyIds, $filters);

// Compute totals with division-by-zero protection
$totalCandidates = 0;
$totalApps       = 0;
$totalHires      = 0;
foreach ($reportRows as $r) {
    $totalCandidates += (int)$r['candidates_count'];
    $totalApps       += (int)$r['applications_count'];
    $totalHires      += (int)$r['hired_count'];
}
$overallConversion = ($totalApps > 0) ? round(($totalHires / $totalApps) * 100, 1) : 0.0;

$showDeptFilter = true;
$showVacFilter  = true;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Candidate Sourcing Channels &amp; Conversion</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> channels</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Source Channel</th>
                        <th class="text-end">Candidate Profiles</th>
                        <th class="text-end">Applications</th>
                        <th class="text-end">Hires</th>
                        <th class="text-end pe-3">Conversion Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted font-sans">No candidate source data found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <i class="bi bi-tag text-muted me-1"></i>
                                    <?= e($row['source_name']) ?>
                                </td>
                                <td class="text-end font-monospace text-primary"><?= (int)$row['candidates_count'] ?></td>
                                <td class="text-end font-monospace text-info"><?= (int)$row['applications_count'] ?></td>
                                <td class="text-end font-monospace text-success fw-bold"><?= (int)$row['hired_count'] ?></td>
                                <td class="text-end pe-3 font-monospace">
                                    <span class="badge bg-body-secondary text-body border fs-6 fw-bold <?= (float)$row['conversion_rate'] > 0 ? 'text-success' : 'text-muted' ?>">
                                        <?= number_format((float)$row['conversion_rate'], 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td class="ps-3">Total</td>
                            <td class="text-end text-primary font-monospace"><?= $totalCandidates ?></td>
                            <td class="text-end text-info font-monospace"><?= $totalApps ?></td>
                            <td class="text-end text-success font-monospace"><?= $totalHires ?></td>
                            <td class="text-end pe-3 text-success font-monospace"><?= number_format($overallConversion, 1) ?>%</td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
