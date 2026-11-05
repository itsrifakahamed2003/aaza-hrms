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
$reportSlug = 'performance-due';
$pageTitle  = 'Reviews Due & Pending Appraisals';

// Filters
$filters = validate_report_filters($pdo, $accessibleCompanies, $_REQUEST, false);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanies);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);

// Helper to query employees with reviews due or pending
function get_performance_due_data(PDO $pdo, array $companyIds, array $filters): array
{
    if (empty($companyIds)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $params = $companyIds;
    $where = ["e.company_id IN ($ph)", "e.employment_status = 'active'"];

    if (!empty($filters['department_id'])) {
        $where[] = "e.department_id = ?";
        $params[] = (int)$filters['department_id'];
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT e.id AS employee_id, e.employee_code, e.first_name, e.last_name, e.joining_date,
                   c.code AS company_code, c.name AS company_name,
                   COALESCE(d.name, 'Unassigned') AS department_name,
                   COALESCE(des.title, 'Staff') AS designation_title,
                   CONCAT(m.first_name, ' ', m.last_name) AS manager_name,
                   latest.id AS review_id,
                   latest.period_label,
                   latest.review_date,
                   latest.overall_rating,
                   latest.status AS review_status
              FROM employees e
              JOIN companies c ON c.id = e.company_id
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
         LEFT JOIN employees m ON m.id = e.reporting_manager_id
         LEFT JOIN (
             SELECT pr1.*
               FROM performance_reviews pr1
               JOIN (
                   SELECT employee_id, MAX(id) AS max_id
                     FROM performance_reviews
                    GROUP BY employee_id
               ) pr2 ON pr1.id = pr2.max_id
         ) latest ON latest.employee_id = e.id
             WHERE {$whereSql}
             ORDER BY c.name, d.name, e.first_name, e.last_name";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $allRows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Flag rows: No review, Draft, Submitted (Awaiting Ack), Acknowledged
    $results = [];
    foreach ($allRows as $row) {
        $statusKey = 'no_review';
        $statusLabel = 'Appraisal Due';
        if (!empty($row['review_status'])) {
            if ($row['review_status'] === 'draft') {
                $statusKey = 'draft';
                $statusLabel = 'Draft in Progress';
            } elseif ($row['review_status'] === 'submitted') {
                $statusKey = 'submitted';
                $statusLabel = 'Awaiting Acknowledgment';
            } elseif ($row['review_status'] === 'acknowledged') {
                $statusKey = 'acknowledged';
                $statusLabel = 'Completed';
            }
        }
        $row['appraisal_status_key']   = $statusKey;
        $row['appraisal_status_label'] = $statusLabel;
        $results[] = $row;
    }

    return $results;
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

    $rows = get_performance_due_data($pdo, $exportCompanyIds, $exportFilters);
    $headers = [
        'Employee Code', 'Employee Name', 'Company Code', 'Company Name',
        'Department', 'Designation', 'Joining Date', 'Reporting Manager',
        'Appraisal Status', 'Latest Period', 'Last Review Date', 'Rating'
    ];
    $exportData = [];
    foreach ($rows as $r) {
        $exportData[] = [
            $r['employee_code'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['company_code'],
            $r['company_name'],
            $r['department_name'],
            $r['designation_title'],
            $r['joining_date'],
            $r['manager_name'] ?: 'None',
            $r['appraisal_status_label'],
            $r['period_label'] ?: 'N/A',
            $r['review_date'] ?: 'N/A',
            !empty($r['overall_rating']) ? (string)$r['overall_rating'] : 'N/A',
        ];
    }
    report_stream_csv($reportSlug, $headers, $exportData, $exportFilters, $exportCompanyIds, $filters['company_id']);
}

// Fetch report data
$reportRows = get_performance_due_data($pdo, $scopedCompanyIds, $filters);

// Status counts
$countDue = 0;
$countDraft = 0;
$countSubmitted = 0;
$countCompleted = 0;
foreach ($reportRows as $r) {
    match ($r['appraisal_status_key']) {
        'no_review'    => $countDue++,
        'draft'        => $countDraft++,
        'submitted'    => $countSubmitted++,
        'acknowledged' => $countCompleted++,
        default        => null,
    };
}

$showDeptFilter = true;
$showVacFilter  = false;
$showDateFilter = false;

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_report_header.php';
?>

<!-- KPI Stat Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-danger-subtle text-danger">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Reviews Due</div>
                    <div class="fs-4 fw-bold text-body"><?= $countDue ?></div>
                    <div class="small text-muted">No review on file</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-secondary-subtle text-secondary">
                    <i class="bi bi-pencil fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Draft in Progress</div>
                    <div class="fs-4 fw-bold text-body"><?= $countDraft ?></div>
                    <div class="small text-muted">Unsubmitted drafts</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Awaiting Employee</div>
                    <div class="fs-4 fw-bold text-body"><?= $countSubmitted ?></div>
                    <div class="small text-muted">Submitted to employee</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="rounded p-3 bg-success-subtle text-success">
                    <i class="bi bi-check-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Completed</div>
                    <div class="fs-4 fw-bold text-body"><?= $countCompleted ?></div>
                    <div class="small text-muted">Acknowledged reviews</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-people me-2 text-primary"></i>Active Staff Appraisal Status</span>
        <span class="small text-muted"><?= count($reportRows) ?> active employees</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
                <tr>
                    <th>Employee</th>
                    <th>Department &amp; Role</th>
                    <th>Reporting Manager</th>
                    <th>Status</th>
                    <th>Latest Period</th>
                    <th>Last Rating</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportRows)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            No employees match your selected filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reportRows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-body">
                                    <?= e($row['first_name'] . ' ' . $row['last_name']) ?>
                                </div>
                                <div class="small text-muted font-monospace">
                                    <?= e($row['employee_code']) ?>
                                    <span class="badge bg-body-secondary text-body border ms-1"><?= e($row['company_code']) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="small fw-medium text-body"><?= e($row['department_name']) ?></div>
                                <div class="small text-muted"><?= e($row['designation_title']) ?></div>
                            </td>
                            <td>
                                <span class="small text-muted"><?= e($row['manager_name'] ?: 'None') ?></span>
                            </td>
                            <td>
                                <?php if ($row['appraisal_status_key'] === 'no_review'): ?>
                                    <span class="badge badge-soft-danger"><i class="bi bi-exclamation-circle me-1"></i>Due</span>
                                <?php elseif ($row['appraisal_status_key'] === 'draft'): ?>
                                    <span class="badge badge-soft-secondary"><i class="bi bi-pencil me-1"></i>Draft</span>
                                <?php elseif ($row['appraisal_status_key'] === 'submitted'): ?>
                                    <span class="badge badge-soft-warning"><i class="bi bi-hourglass-split me-1"></i>Awaiting Ack</span>
                                <?php elseif ($row['appraisal_status_key'] === 'acknowledged'): ?>
                                    <span class="badge badge-soft-success"><i class="bi bi-check-circle me-1"></i>Completed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['period_label'])): ?>
                                    <span class="small text-body fw-medium"><?= e($row['period_label']) ?></span>
                                    <div class="small text-muted"><?= date('d M Y', strtotime((string)$row['review_date'])) ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['overall_rating'])): ?>
                                    <?= performance_rating_stars_html((int)$row['overall_rating']) ?>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if (!empty($row['review_id'])): ?>
                                    <a href="<?= e(url('modules/performance/view.php?id=' . (int)$row['review_id'])) ?>" class="btn btn-sm btn-outline-secondary">
                                        View Review
                                    </a>
                                <?php else: ?>
                                    <a href="<?= e(url('modules/performance/form.php?employee_id=' . (int)$row['employee_id'])) ?>" class="btn btn-sm btn-outline-primary">
                                        Create Review
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';

