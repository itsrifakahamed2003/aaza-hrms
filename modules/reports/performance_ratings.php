<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Permissions: report.view AND (performance.view OR performance.manage)
$accessibleCompanies = report_scope_companies(['performance.view', 'performance.manage']);
if (empty($accessibleCompanies)) {
    abort(403, 'You do not have permission to view performance reports.');
}

$pdo = db();
$reportSlug = 'performance-ratings';
$pageTitle  = 'Rating Distribution by Department';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);

// Helper to query rating distribution
function get_performance_ratings_data(PDO $pdo, array $companyIds, array $filters): array
{
    if (empty($companyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $params = $companyIds;
    $where = ["pr.company_id IN ($ph)"];

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = "pr.review_date >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = "pr.review_date <= ?";
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT c.code AS company_code, c.name AS company_name,
                   COALESCE(d.name, 'Unassigned') AS department_name,
                   COUNT(pr.id) AS total_reviews,
                   SUM(CASE WHEN pr.overall_rating = 5 THEN 1 ELSE 0 END) AS r5_count,
                   SUM(CASE WHEN pr.overall_rating = 4 THEN 1 ELSE 0 END) AS r4_count,
                   SUM(CASE WHEN pr.overall_rating = 3 THEN 1 ELSE 0 END) AS r3_count,
                   SUM(CASE WHEN pr.overall_rating = 2 THEN 1 ELSE 0 END) AS r2_count,
                   SUM(CASE WHEN pr.overall_rating = 1 THEN 1 ELSE 0 END) AS r1_count,
                   AVG(pr.overall_rating) AS avg_rating
              FROM performance_reviews pr
              JOIN companies c ON c.id = pr.company_id
              JOIN employees e ON e.id = pr.employee_id
         LEFT JOIN departments d ON d.id = e.department_id
             WHERE {$whereSql}
          GROUP BY c.id, c.code, c.name, d.id, d.name
          ORDER BY c.name, department_name";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Handle POST CSV Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_csv'])) {
    csrf_require();
    $exportCompanyIds = array_values(array_filter($scopedCompanyIds, fn($cid) => user_can('report.export', (int)$cid)));
    if (empty($exportCompanyIds)) {
        abort(403, 'You do not have permission to export reports for the selected company scope.');
    }
    $exportFilters = $filters;
    $exportFilters['scoped_company_ids'] = $exportCompanyIds;

    $rows = get_performance_ratings_data($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Company Code', 'Company Name', 'Department',
        'Total Reviews',
        'Rating 5 (Outstanding)', 'Rating 4 (Exceeds)', 'Rating 3 (Meets)',
        'Rating 2 (Needs Improvement)', 'Rating 1 (Unsatisfactory)', 'Average Rating'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['company_code'],
            $r['company_name'],
            $r['department_name'],
            (int)$r['total_reviews'],
            (int)$r['r5_count'],
            (int)$r['r4_count'],
            (int)$r['r3_count'],
            (int)$r['r2_count'],
            (int)$r['r1_count'],
            number_format((float)$r['avg_rating'], 2),
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = get_performance_ratings_data($pdo, $scopedCompanyIds, $filters);

// Totals
$totReviews = 0;
$totR5 = 0;
$totR4 = 0;
$totR3 = 0;
$totR2 = 0;
$totR1 = 0;
$sumRating = 0.0;
foreach ($reportRows as $r) {
    $totReviews += (int)$r['total_reviews'];
    $totR5      += (int)$r['r5_count'];
    $totR4      += (int)$r['r4_count'];
    $totR3      += (int)$r['r3_count'];
    $totR2      += (int)$r['r2_count'];
    $totR1      += (int)$r['r1_count'];
    $sumRating  += (float)$r['avg_rating'] * (int)$r['total_reviews'];
}
$overallAvg = $totReviews > 0 ? ($sumRating / $totReviews) : 0.0;

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = true;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<!-- KPI Stat Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-primary-subtle text-primary">
                    <i class="bi bi-award fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Reviews</div>
                    <div class="fs-4 fw-bold text-body"><?= $totReviews ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-warning-subtle text-warning">
                    <i class="bi bi-star-fill fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Group Average</div>
                    <div class="fs-4 fw-bold text-body"><?= number_format($overallAvg, 2) ?> / 5.0</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-success-subtle text-success">
                    <i class="bi bi-graph-up fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">High Performers (4-5)</div>
                    <div class="fs-4 fw-bold text-body"><?= $totR5 + $totR4 ?></div>
                    <div class="text-muted small"><?= $totReviews > 0 ? round((($totR5 + $totR4) / $totReviews) * 100) : 0 ?>% of workforce</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-danger-subtle text-danger">
                    <i class="bi bi-exclamation-triangle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Needs Focus (1-2)</div>
                    <div class="fs-4 fw-bold text-body"><?= $totR2 + $totR1 ?></div>
                    <div class="text-muted small"><?= $totReviews > 0 ? round((($totR2 + $totR1) / $totReviews) * 100) : 0 ?>% of workforce</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-table me-2 text-primary"></i>Department Performance Breakdown</span>
        <span class="small text-muted"><?= count($reportRows) ?> departments</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
                <tr>
                    <th>Company</th>
                    <th>Department</th>
                    <th class="text-center">Reviews</th>
                    <th class="text-center">5 &middot; Outstanding</th>
                    <th class="text-center">4 &middot; Exceeds</th>
                    <th class="text-center">3 &middot; Meets</th>
                    <th class="text-center">2 &middot; Needs Imp.</th>
                    <th class="text-center">1 &middot; Unsat.</th>
                    <th class="text-end pe-3">Average Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportRows)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            No performance review records match your selected scope and filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reportRows as $row): ?>
                        <tr>
                            <td>
                                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($row['company_code']) ?></span>
                                <span class="small text-muted ms-1"><?= e($row['company_name']) ?></span>
                            </td>
                            <td class="fw-semibold text-body"><?= e($row['department_name']) ?></td>
                            <td class="text-center font-monospace fw-bold"><?= (int)$row['total_reviews'] ?></td>
                            <td class="text-center">
                                <span class="badge badge-soft-success"><?= (int)$row['r5_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-soft-primary"><?= (int)$row['r4_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-soft-info"><?= (int)$row['r3_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-soft-warning"><?= (int)$row['r2_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-soft-danger"><?= (int)$row['r1_count'] ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <span class="fw-bold text-body me-2"><?= number_format((float)$row['avg_rating'], 2) ?></span>
                                <?= performance_rating_stars_html((int)round((float)$row['avg_rating']), false) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportRows)): ?>
                <tfoot class="table-active fw-bold">
                    <tr>
                        <td colspan="2">Group Total / Weighted Average</td>
                        <td class="text-center font-monospace"><?= $totReviews ?></td>
                        <td class="text-center"><?= $totR5 ?></td>
                        <td class="text-center"><?= $totR4 ?></td>
                        <td class="text-center"><?= $totR3 ?></td>
                        <td class="text-center"><?= $totR2 ?></td>
                        <td class="text-center"><?= $totR1 ?></td>
                        <td class="text-end pe-3 font-monospace"><?= number_format($overallAvg, 2) ?> / 5.0</td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';

