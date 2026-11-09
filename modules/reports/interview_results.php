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
$reportSlug = 'interview-results';
$pageTitle  = 'Interview Results & Evaluations';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);
$filterVacancies = report_get_filter_vacancies($pdo, $scopedCompanyIds);

// Determine company IDs where user has interview.view_evaluations
$evalCompanyIds = array_values(array_filter($accessibleCompanies, fn($cid) => user_can('interview.view_evaluations', (int)$cid)));

// Recommendation labels from evaluation_recommendation_list()
$recList = evaluation_recommendation_list();

// Handle POST CSV Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = report_interview_results($pdo, $exportCompanyIds, $exportFilters, $evalCompanyIds, 50000, 0);
    $headers = [
        'Interview Date', 'Round', 'Candidate Code', 'Candidate Name', 'Company', 'Job Vacancy',
        'Interview Status', 'Average Score', 'Evaluations Count',
        $recList['strong_hire'] ?? 'Strong Hire',
        $recList['hire'] ?? 'Hire',
        $recList['hold'] ?? 'Hold',
        $recList['reject'] ?? 'Reject',
        'Other'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $canRowScore = in_array((int)$r['company_id'], $evalCompanyIds, true);
        $exportData[] = [
            $r['scheduled_at'],
            'Round ' . $r['round_no'],
            $r['candidate_code'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['company_name'],
            $r['vacancy_title'] . ' (' . $r['vacancy_code'] . ')',
            ucfirst((string)$r['interview_status']),
            $canRowScore ? ($r['avg_score'] !== null ? number_format((float)$r['avg_score'], 1) . ' / 5' : 'N/A') : '[Restricted]',
            $canRowScore ? (string)$r['eval_count'] : '[Restricted]',
            $canRowScore ? (string)$r['rec_strong_hire'] : '[Restricted]',
            $canRowScore ? (string)$r['rec_hire'] : '[Restricted]',
            $canRowScore ? (string)$r['rec_hold'] : '[Restricted]',
            $canRowScore ? (string)$r['rec_reject'] : '[Restricted]',
            $canRowScore ? (string)$r['rec_other'] : '[Restricted]',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Pagination setup
$totalRows = count_interview_results($pdo, $scopedCompanyIds, $filters);
$perPage = 25;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$pg = paginate($totalRows, $currentPage, $perPage);

$reportRows = report_interview_results(
    $pdo,
    $scopedCompanyIds,
    $filters,
    $evalCompanyIds,
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
        <h5 class="card-title mb-0 fs-6 fw-bold">Interview Records</h5>
        <span class="badge bg-body-secondary text-body border"><?= $totalRows ?> interviews</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-monospace small">
                <thead class="table-active font-sans">
                    <tr>
                        <th class="ps-3">Date &bull; Round</th>
                        <th>Candidate</th>
                        <th>Position / Company</th>
                        <th>Status</th>
                        <th class="text-center">Average Score</th>
                        <th class="text-end pe-3">Recommendations</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-sans">No interview records found for the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <?php $canRowScore = in_array((int)$row['company_id'], $evalCompanyIds, true); ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-medium text-body font-sans"><?= e(format_date($row['scheduled_at'], 'd M Y, H:i')) ?></div>
                                    <span class="badge bg-body-secondary text-body border text-secondary font-monospace">Round <?= (int)$row['round_no'] ?></span>
                                </td>
                                <td class="font-sans">
                                    <div class="fw-semibold text-body"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                    <div class="text-muted small font-monospace"><?= e($row['candidate_code']) ?></div>
                                </td>
                                <td class="font-sans">
                                    <div><?= e($row['vacancy_title']) ?></div>
                                    <div class="text-muted small"><?= e($row['company_name']) ?></div>
                                </td>
                                <td>
                                    <?php
                                    $iBadge = match((string)$row['interview_status']) {
                                        'completed'   => 'text-bg-success',
                                        'scheduled'   => 'text-bg-primary',
                                        'cancelled'   => 'text-bg-secondary',
                                        'no_show'     => 'text-bg-danger',
                                        'rescheduled' => 'text-bg-warning',
                                        default       => 'bg-body-secondary text-body',
                                    };
                                    ?>
                                    <span class="badge <?= $iBadge ?> font-sans"><?= e(ucfirst((string)$row['interview_status'])) ?></span>
                                </td>
                                <td class="text-center font-monospace">
                                    <?php if ($canRowScore): ?>
                                        <?php if ($row['avg_score'] !== null): ?>
                                            <?php
                                            $sc = (float)$row['avg_score'];
                                            $scColor = $sc >= 4.0 ? 'text-success' : ($sc >= 3.0 ? 'text-warning' : 'text-danger');
                                            ?>
                                            <span class="fw-bold fs-6 <?= $scColor ?>">
                                                <?= number_format($sc, 1) ?> / 5
                                            </span>
                                            <div class="text-muted small" style="font-size:0.75rem;"><?= (int)$row['eval_count'] ?> review(s)</div>
                                        <?php else: ?>
                                            <span class="text-muted small font-sans">No evaluation</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small font-sans"><i class="bi bi-lock me-1"></i>Restricted</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 font-sans">
                                    <?php if ($canRowScore): ?>
                                        <?php if ((int)$row['eval_count'] > 0): ?>
                                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                <?php if ((int)$row['rec_strong_hire'] > 0): ?>
                                                    <span class="badge text-bg-success" title="<?= e($recList['strong_hire'] ?? 'Strong Hire') ?>"><?= (int)$row['rec_strong_hire'] ?> <?= e($recList['strong_hire'] ?? 'Strong Hire') ?></span>
                                                <?php endif; ?>
                                                <?php if ((int)$row['rec_hire'] > 0): ?>
                                                    <span class="badge text-bg-primary" title="<?= e($recList['hire'] ?? 'Hire') ?>"><?= (int)$row['rec_hire'] ?> <?= e($recList['hire'] ?? 'Hire') ?></span>
                                                <?php endif; ?>
                                                <?php if ((int)$row['rec_hold'] > 0): ?>
                                                    <span class="badge text-bg-warning" title="<?= e($recList['hold'] ?? 'Hold') ?>"><?= (int)$row['rec_hold'] ?> <?= e($recList['hold'] ?? 'Hold') ?></span>
                                                <?php endif; ?>
                                                <?php if ((int)$row['rec_reject'] > 0): ?>
                                                    <span class="badge text-bg-danger" title="<?= e($recList['reject'] ?? 'Reject') ?>"><?= (int)$row['rec_reject'] ?> <?= e($recList['reject'] ?? 'Reject') ?></span>
                                                <?php endif; ?>
                                                <?php if ((int)$row['rec_other'] > 0): ?>
                                                    <span class="badge text-bg-secondary" title="Other"><?= (int)$row['rec_other'] ?> Other</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">Pending</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-lock me-1"></i>Restricted</span>
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
