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
$reportSlug = 'candidates-selected-for-interview';
$pageTitle  = 'Candidates Selected for Interview';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);
$filterVacancies = report_get_filter_vacancies($pdo, $scopedCompanyIds);

// Handle POST CSV Export (all rows up to 50k, ignoring pagination)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = report_candidates_selected_for_interview($pdo, $exportCompanyIds, $exportFilters, 50000, 0);
    $headers = [
        'Candidate Code', 'Candidate Name', 'Company Name', 'Job Vacancy',
        'Application Status', 'Application Date', 'Latest Interview Round',
        'Interview Scheduled At', 'Interview Status'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['candidate_code'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['company_name'],
            $r['vacancy_title'] . ' (' . $r['vacancy_code'] . ')',
            application_status_list()[$r['application_status']] ?? ucfirst((string)$r['application_status']),
            $r['application_date'],
            $r['round_no'] ? 'Round ' . $r['round_no'] : 'Not scheduled',
            $r['scheduled_at'] ?? '—',
            $r['interview_status'] ? ucfirst((string)$r['interview_status']) : '—',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Pagination setup
$totalRows = count_candidates_selected_for_interview($pdo, $scopedCompanyIds, $filters);
$perPage = 25;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$pg = paginate($totalRows, $currentPage, $perPage);

$reportRows = report_candidates_selected_for_interview(
    $pdo,
    $scopedCompanyIds,
    $filters,
    $pg['per_page'],
    $pg['offset']
);

$showDeptFilter = true;
$showVacFilter  = true;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fs-6 fw-bold">Candidate Interview Pipeline</h5>
        <span class="badge bg-body-secondary text-body border"><?= $totalRows ?> candidates</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Candidate</th>
                        <th>Vacancy / Position</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Interview Date</th>
                        <th>Interview Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-sans">No candidate interview records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <td class="ps-3 font-sans">
                                    <span class="fw-semibold text-body"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></span>
                                    <div class="text-muted small font-monospace"><?= e($row['candidate_code']) ?></div>
                                </td>
                                <td class="font-sans">
                                    <div><?= e($row['vacancy_title']) ?></div>
                                    <div class="text-muted small font-monospace"><?= e($row['vacancy_code']) ?></div>
                                </td>
                                <td class="font-sans">
                                    <?= e($row['company_name']) ?>
                                    <span class="text-muted small">(<?= e($row['company_code']) ?>)</span>
                                </td>
                                <td>
                                    <?= application_status_badge((string)$row['application_status']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['scheduled_at'])): ?>
                                        <div class="fw-medium text-body"><?= e(format_date($row['scheduled_at'], 'd M Y, H:i')) ?></div>
                                        <div class="text-muted small">Round <?= (int)$row['round_no'] ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['interview_status'])): ?>
                                        <span class="badge bg-body-secondary text-body border text-body font-sans"><?= e(ucfirst((string)$row['interview_status'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
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
