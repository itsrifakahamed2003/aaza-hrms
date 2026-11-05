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
$reportSlug = 'applicants-by-position';
$pageTitle  = 'Applicants by Job Position';

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

    $rows = report_applicants_by_position($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Company Code', 'Company Name', 'Department', 'Vacancy Code', 'Job Title',
        'Total Applications', 'Shortlisted', 'Selected', 'Rejected', 'Hired'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['department_name'] ?? 'General',
            $r['vacancy_code'],
            $r['vacancy_title'],
            $r['applications_count'],
            $r['shortlisted_count'],
            $r['selected_count'],
            $r['rejected_count'],
            $r['hired_count'],
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = report_applicants_by_position($pdo, $scopedCompanyIds, $filters);

// Compute totals
$totalApps        = 0;
$totalShortlisted = 0;
$totalSelected    = 0;
$totalRejected    = 0;
$totalHired       = 0;
foreach ($reportRows as $r) {
    $totalApps        += (int)$r['applications_count'];
    $totalShortlisted += (int)$r['shortlisted_count'];
    $totalSelected    += (int)$r['selected_count'];
    $totalRejected    += (int)$r['rejected_count'];
    $totalHired       += (int)$r['hired_count'];
}

$showDeptFilter = true;
$showVacFilter  = true;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Applicants per Vacancy</h5>
        <span class="badge bg-body-secondary text-body border"><?= count($reportRows) ?> positions</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active">
                    <tr>
                        <th class="ps-3 font-sans">Company</th>
                        <th class="font-sans">Department</th>
                        <th class="font-sans">Position / Code</th>
                        <th class="text-end font-sans">Applied</th>
                        <th class="text-end font-sans">Shortlisted</th>
                        <th class="text-end font-sans">Selected</th>
                        <th class="text-end font-sans">Rejected</th>
                        <th class="text-end pe-3 font-sans">Hired</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted font-sans">No data available for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans fw-semibold">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td class="font-sans"><?= e($row['department_name'] ?? '—') ?></td>
                                <td class="font-sans">
                                    <span class="fw-semibold text-body"><?= e($row['vacancy_title']) ?></span>
                                    <span class="text-muted small ms-1">(<?= e($row['vacancy_code']) ?>)</span>
                                </td>
                                <td class="text-end fw-bold text-primary"><?= (int)$row['applications_count'] ?></td>
                                <td class="text-end text-warning"><?= (int)$row['shortlisted_count'] ?></td>
                                <td class="text-end text-info"><?= (int)$row['selected_count'] ?></td>
                                <td class="text-end text-danger"><?= (int)$row['rejected_count'] ?></td>
                                <td class="text-end pe-3 fw-bold text-success"><?= (int)$row['hired_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($reportRows)): ?>
                    <tfoot class="table-active fw-bold">
                        <tr>
                            <td colspan="3" class="ps-3 font-sans">Total</td>
                            <td class="text-end text-primary font-monospace"><?= $totalApps ?></td>
                            <td class="text-end text-warning font-monospace"><?= $totalShortlisted ?></td>
                            <td class="text-end text-info font-monospace"><?= $totalSelected ?></td>
                            <td class="text-end text-danger font-monospace"><?= $totalRejected ?></td>
                            <td class="text-end pe-3 text-success font-monospace"><?= $totalHired ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
