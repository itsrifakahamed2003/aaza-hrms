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
$reportSlug = 'candidate-selection-rate';
$pageTitle  = 'Candidate Selection Rate';

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

    $rows = report_candidate_selection_rate($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Company Code', 'Company Name', 'Department', 'Vacancy Code', 'Job Title',
        'Total Applicants', 'Selected or Hired', 'Selection Rate (%)'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['department_name'] ?? 'General',
            $r['vacancy_code'],
            $r['vacancy_title'],
            $r['total_applicants'],
            $r['selected_or_hired'],
            $r['selection_rate'] . '%',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report rows
$reportRows = report_candidate_selection_rate($pdo, $scopedCompanyIds, $filters);

// Compute totals with strict division-by-zero protection
$totalApplicants = 0;
$totalSelected   = 0;
foreach ($reportRows as $r) {
    $totalApplicants += (int)$r['total_applicants'];
    $totalSelected   += (int)$r['selected_or_hired'];
}
$overallRate = ($totalApplicants > 0) ? round(($totalSelected / $totalApplicants) * 100, 1) : 0.0;

$showDeptFilter = true;
$showVacFilter  = true;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Candidate Selection Ratio per Job Position</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> positions</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Company</th>
                        <th>Department</th>
                        <th>Vacancy Position</th>
                        <th class="text-end">Total Applicants</th>
                        <th class="text-end">Selected / Hired</th>
                        <th class="text-end pe-3">Selection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-sans">No vacancy records found for the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small font-monospace">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td class="font-sans"><?= e($row['department_name'] ?? '—') ?></td>
                                <td class="font-sans">
                                    <span class="fw-semibold text-body"><?= e($row['vacancy_title']) ?></span>
                                    <span class="text-muted small font-monospace ms-1">(<?= e($row['vacancy_code']) ?>)</span>
                                </td>
                                <td class="text-end text-primary fw-bold"><?= (int)$row['total_applicants'] ?></td>
                                <td class="text-end text-success fw-bold"><?= (int)$row['selected_or_hired'] ?></td>
                                <td class="text-end pe-3 font-monospace">
                                    <span class="badge bg-body-secondary text-body border fs-6 fw-bold <?= (float)$row['selection_rate'] > 0 ? 'text-success' : 'text-muted' ?>">
                                        <?= number_format((float)$row['selection_rate'], 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold font-sans">
                        <tr>
                            <td colspan="3" class="ps-3">Overall Total</td>
                            <td class="text-end text-primary font-monospace"><?= $totalApplicants ?></td>
                            <td class="text-end text-success font-monospace"><?= $totalSelected ?></td>
                            <td class="text-end pe-3 font-monospace text-success"><?= number_format($overallRate, 1) ?>%</td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
