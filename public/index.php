<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$pdo  = db();
$dashUser = current_user();

// Determine view mode: Group View (dashboard.group) vs Company View
$isGroupView = user_can('dashboard.group');

// Metric-level company scoping using report_scope_companies()
$metricScopes = [
    'employee'  => report_scope_companies('employee.view'),
    'vacancy'   => report_scope_companies('vacancy.view'),
    'candidate' => report_scope_companies('candidate.view'),
    'interview' => report_scope_companies('interview.view'),
    'offer'     => report_scope_companies('offer.view'),
    'leave'     => report_scope_companies(['leave.view', 'leave.approve']),
];

$accessibleCompanyIds = [];
foreach ($metricScopes as $scope) {
    $accessibleCompanyIds = array_merge($accessibleCompanyIds, $scope);
}
$accessibleCompanyIds = array_values(array_unique($accessibleCompanyIds));

if (empty($accessibleCompanyIds)) {
    // If user does not have management-level analytics permissions, render the Employee Self-Service Dashboard
    $linkedEmp = get_linked_employee_for_user($pdo, (int)$dashUser['id']);
    $pageTitle = 'Employee Dashboard';
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/_employee_dashboard.php';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Validate and sanitize filters from GET input
$filters = validate_report_filters($pdo, $accessibleCompanyIds, $_GET, true);
$scopedCompanyIds = $filters['scoped_company_ids'];

// Fetch dropdown options for filter bar
$filterCompanies = report_get_filter_companies($pdo, $accessibleCompanyIds);
$filterDepts     = report_get_filter_departments($pdo, $scopedCompanyIds);
$filterVacancies = report_get_filter_vacancies($pdo, $scopedCompanyIds);

// Fetch KPI Cards metrics via single aggregated queries scoped per metric
$cardStats = report_dashboard_cards($pdo, $scopedCompanyIds, $filters, $metricScopes);

// Fetch analytics charts & tables if in Group View (or accessible company analytics)
$companyEmpCounts     = [];
$companyRecActivity   = [];
$monthlyHiring        = [];
$monthlyJoining       = [];
$funnelStats          = [];

if ($isGroupView || !empty($scopedCompanyIds)) {
    if (!empty($metricScopes['employee']) && ($isGroupView || count($scopedCompanyIds) > 1)) {
        $empScoped = array_values(array_intersect($scopedCompanyIds, $metricScopes['employee']));
        if (!empty($empScoped)) {
            $companyEmpCounts = report_company_employee_counts($pdo, $empScoped);
        }
    }
    $recScopes = array_values(array_unique(array_merge($metricScopes['candidate'], $metricScopes['vacancy'])));
    if (!empty($recScopes)) {
        $recScoped = array_values(array_intersect($scopedCompanyIds, $recScopes));
        if (!empty($recScoped) && ($isGroupView || count($scopedCompanyIds) > 1)) {
            $companyRecActivity = report_company_recruitment_activity($pdo, $recScoped, $filters);
        }
        if (!empty($recScoped)) {
            $monthlyHiring = report_monthly_hiring_stats($pdo, $recScoped);
            $funnelStats   = report_recruitment_funnel_stats($pdo, $recScoped, $filters);
        }
    }
    if (!empty($metricScopes['employee'])) {
        $empScoped = array_values(array_intersect($scopedCompanyIds, $metricScopes['employee']));
        if (!empty($empScoped)) {
            $monthlyJoining = report_monthly_joining_stats($pdo, $empScoped);
        }
    }
}

// User role assignments for the "Your scope" card in Company View
$access = [];
if (!is_group_admin()) {
    $st = $pdo->prepare(
        'SELECT c.name, GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ", ") AS roles
           FROM company_user_assignments cua
           JOIN companies c ON c.id = cua.company_id AND c.is_active = 1
           JOIN roles r ON r.id = cua.role_id
          WHERE cua.user_id = ?
          GROUP BY c.id, c.name
          ORDER BY c.name'
    );
    $st->execute([$dashUser['id']]);
    $access = $st->fetchAll();
}

$pageTitle = $isGroupView ? 'Group Dashboard & Analytics' : 'Company Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<!-- Page Header -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean page-header-breadcrumb">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
                <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
            </ol>
        </nav>
        <h1 class="page-header-title"><?= e($pageTitle) ?></h1>
        <p class="page-header-desc">
            <?= $isGroupView
                ? 'Group Scope &bull; Consolidated human resource analytics, talent pipeline, and workforce statistics across group entities.'
                : 'Company View &bull; Performance metrics, open requisitions, and workforce summary for your assigned company.' ?>
        </p>
    </div>
    <div class="page-header-actions page-actions">
        <?php if (user_can('report.view')): ?>
            <a href="<?= e(url('modules/reports/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-bar-chart-line"></i> Reports Catalog
            </a>
        <?php endif; ?>
        <?php if (user_can('employee.create')): ?>
            <a href="<?= e(url('modules/employees/form.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-person-plus"></i> Add Employee
            </a>
        <?php endif; ?>
        <?php if (user_can('vacancy.manage')): ?>
            <a href="<?= e(url('modules/vacancies/form.php')) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Post Vacancy
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Dashboard Filter Bar -->
<div class="dashboard-filter-card">
    <div class="d-md-none mb-2">
        <button type="button" class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#dashboardFiltersCollapse" aria-expanded="false" aria-controls="dashboardFiltersCollapse">
            <span><i class="bi bi-funnel me-1"></i> Filters</span>
            <i class="bi bi-chevron-down"></i>
        </button>
    </div>
    <div class="collapse d-md-block" id="dashboardFiltersCollapse">
        <form method="get" action="<?= e(url('public/index.php')) ?>" class="row g-2 align-items-end">
            <!-- Company Filter -->
            <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                <label class="filter-label-inline">Company</label>
                <select name="company_id" class="form-select filter-control" onchange="this.form.submit()">
                    <?php if (count($accessibleCompanyIds) > 1): ?>
                        <option value="0" <?= $filters['company_id'] === 0 ? 'selected' : '' ?>>All Companies (<?= count($accessibleCompanyIds) ?>)</option>
                    <?php endif; ?>
                    <?php foreach ($filterCompanies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filters['company_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['code']) ?> - <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Department Filter -->
            <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6">
                <label class="filter-label-inline">Department</label>
                <select name="department_id" class="form-select filter-control">
                    <option value="0">All Departments</option>
                    <?php foreach ($filterDepts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $filters['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Job Position / Vacancy Filter -->
            <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6">
                <label class="filter-label-inline">Job position</label>
                <select name="vacancy_id" class="form-select filter-control">
                    <option value="0">All Positions</option>
                    <?php foreach ($filterVacancies as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $filters['vacancy_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= e($v['vacancy_code']) ?> - <?= e($v['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date From -->
            <div class="col-xl-2 col-lg-2 col-md-3 col-sm-6">
                <label class="filter-label-inline" for="dateFromFilter">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>From
                </label>
                <input type="date" id="dateFromFilter" name="date_from" class="form-control filter-control" aria-label="From date" value="<?= e((string)($filters['date_from'] ?? '')) ?>">
            </div>

            <!-- Date To -->
            <div class="col-xl-2 col-lg-2 col-md-3 col-sm-6">
                <label class="filter-label-inline" for="dateToFilter">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>To
                </label>
                <input type="date" id="dateToFilter" name="date_to" class="form-control filter-control" aria-label="To date" value="<?= e((string)($filters['date_to'] ?? '')) ?>">
            </div>

            <!-- Action Buttons -->
            <div class="col-xl-2 col-lg-12 col-md-6 col-sm-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Apply Filters">
                    <i class="bi bi-filter"></i> Apply
                </button>
                <a href="<?= e(url('public/index.php')) ?>" class="btn btn-outline-secondary" title="Reset Filters">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<?php if (is_group_admin() && APP_ENV === 'production' && file_exists(__DIR__ . '/setup.php')): ?>
    <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-shield-slash fs-3 me-3 text-danger"></i>
        <div>
            <h5 class="alert-heading fw-bold mb-1 fs-6">CRITICAL SECURITY WARNING: setup.php Exists in Production</h5>
            <p class="mb-0 small">The initial installer file <code>public/setup.php</code> is present on the server while <code>APP_ENV</code> is set to <strong>production</strong>. You must delete or remove <code>public/setup.php</code> immediately to prevent unauthorized administrative takeover.</p>
        </div>
    </div>
<?php endif; ?>

<!-- 8 KPI STAT CARDS GRID -->
<div class="stat-cards-grid">
    <!-- Card 1: Total Companies -->
    <?php if (is_group_admin()): ?>
        <a href="<?= e(url('modules/companies/index.php')) ?>" class="stat-card" title="View active companies">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Active companies</div>
                <div class="stat-number"><?= (int)$cardStats['total_companies'] ?></div>
            </div>
            <div class="stat-icon">
                <i class="bi bi-buildings"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Operating units</span>
            <?php if (is_group_admin()): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php else: ?>
                <span>AaZa Group</span>
            <?php endif; ?>
        </div>
    <?= is_group_admin() ? '</a>' : '</div>' ?>

    <!-- Card 2: Total Active Employees -->
    <?php if (user_can('employee.view')): ?>
        <a href="<?= e(url('modules/employees/index.php?status=active')) ?>" class="stat-card" title="View active employees">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Active employees</div>
                <?php if ($cardStats['active_employees'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['active_employees'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-people"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Regular &amp; contract staff</span>
            <?php if (user_can('employee.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= user_can('employee.view') ? '</a>' : '</div>' ?>

    <!-- Card 3: Open Vacancies -->
    <?php if (user_can('vacancy.view')): ?>
        <a href="<?= e(url('modules/vacancies/index.php?status=open')) ?>" class="stat-card" title="View open vacancies">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Open vacancies</div>
                <?php if ($cardStats['open_vacancies'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['open_vacancies'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-briefcase"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Actively recruiting</span>
            <?php if (user_can('vacancy.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= user_can('vacancy.view') ? '</a>' : '</div>' ?>

    <!-- Card 4: Total Candidates -->
    <?php if (user_can('candidate.view')): ?>
        <a href="<?= e(url('modules/candidates/index.php')) ?>" class="stat-card" title="View candidates">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Total candidates</div>
                <?php if ($cardStats['total_candidates'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['total_candidates'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-person-badge"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Talent repository</span>
            <?php if (user_can('candidate.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= user_can('candidate.view') ? '</a>' : '</div>' ?>

    <!-- Card 5: Shortlisted / Selected for Interview -->
    <?php if (user_can('candidate.view') && user_can('report.view')): ?>
        <a href="<?= e(url('modules/reports/candidates_selected.php')) ?>" class="stat-card" title="View shortlisted candidates">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Shortlisted candidates</div>
                <?php if ($cardStats['shortlisted_selected'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['shortlisted_selected'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-person-check"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Interview pipeline</span>
            <?php if (user_can('candidate.view') && user_can('report.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= (user_can('candidate.view') && user_can('report.view')) ? '</a>' : '</div>' ?>

    <!-- Card 6: Upcoming Interviews (Next 7 Days) -->
    <?php if (user_can('interview.view')): ?>
        <a href="<?= e(url('modules/interviews/index.php')) ?>" class="stat-card" title="View upcoming interviews">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Upcoming interviews</div>
                <?php if ($cardStats['upcoming_interviews'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['upcoming_interviews'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-calendar-event"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Next 7 calendar days</span>
            <?php if (user_can('interview.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= user_can('interview.view') ? '</a>' : '</div>' ?>

    <!-- Card 7: Pending Offers (Draft + Sent) -->
    <?php if (user_can('offer.view')): ?>
        <a href="<?= e(url('modules/offers/index.php?status=pending')) ?>" class="stat-card" title="View pending offers">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">
                    Pending offers
                    <?php if (!empty($cardStats['pending_offers']) && (int)$cardStats['pending_offers'] > 0): ?>
                        <span class="status-warning-dot" title="Requires attention"></span>
                    <?php endif; ?>
                </div>
                <?php if ($cardStats['pending_offers'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['pending_offers'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-file-earmark-check"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Draft &amp; sent offers</span>
            <?php if (user_can('offer.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= user_can('offer.view') ? '</a>' : '</div>' ?>

    <!-- Card 8: Pending Leave Requests -->
    <?php if (user_can('leave.approve') || user_can('leave.view')): ?>
        <a href="<?= e(url('modules/leave/index.php?status=pending')) ?>" class="stat-card" title="View pending leave requests">
    <?php else: ?>
        <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card-header">
            <div>
                <div class="stat-label">
                    Pending leaves
                    <?php if (!empty($cardStats['pending_leaves']) && (int)$cardStats['pending_leaves'] > 0): ?>
                        <span class="status-warning-dot" title="Requires attention"></span>
                    <?php endif; ?>
                </div>
                <?php if ($cardStats['pending_leaves'] !== null): ?>
                    <div class="stat-number"><?= (int)$cardStats['pending_leaves'] ?></div>
                <?php else: ?>
                    <div class="fs-6 fw-bold text-muted"><i class="bi bi-lock me-1"></i>Restricted</div>
                <?php endif; ?>
            </div>
            <div class="stat-icon">
                <i class="bi bi-calendar2-range"></i>
            </div>
        </div>
        <div class="stat-card-footer">
            <span>Awaiting decision</span>
            <?php if (user_can('leave.approve') || user_can('leave.view')): ?>
                <i class="bi bi-chevron-right stat-card-chevron" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
    <?= (user_can('leave.approve') || user_can('leave.view')) ? '</a>' : '</div>' ?>
</div>

<!-- ========================================================================= -->
<!-- CHARTS & DETAILED ANALYTICS (WITH PLAIN HTML TABLE FALLBACKS) -->
<!-- ========================================================================= -->

<!-- SECTION 1: Multi-Company Comparisons (Group View or Multi-Company Scope) -->
<?php if (!empty($companyEmpCounts) || !empty($companyRecActivity)): ?>
    <div class="dashboard-grid">
        <?php if (!empty($companyEmpCounts)): ?>
            <!-- Chart 1: Company-Wise Employee Count -->
            <div class="<?= empty($companyRecActivity) ? 'grid-col-12' : 'grid-col-6' ?>">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-bar-chart"></i> Company-wise employee count
                        </h2>
                        <span class="chart-card-caption">Aggregated count</span>
                    </div>
                    <div class="chart-card-body">
                        <!-- Chart.js Canvas -->
                        <div class="chart-canvas-container">
                            <?php
                            $chart1Labels = array_column($companyEmpCounts, 'company_name');
                            $chart1Active = array_map('intval', array_column($companyEmpCounts, 'active_count'));
                            $chart1Total  = array_map('intval', array_column($companyEmpCounts, 'total_count'));
                            $hasChart1Data = !empty(array_filter($chart1Active)) || !empty(array_filter($chart1Total));
                            $chart1Data = [
                                'type' => 'bar',
                                'labels' => $chart1Labels,
                                'datasets' => [
                                    ['label' => 'Active Employees', 'data' => $chart1Active, 'backgroundColor' => '#2563EB'],
                                    ['label' => 'Total Staff Records', 'data' => $chart1Total, 'backgroundColor' => '#64748B'],
                                ],
                            ];
                            ?>
                            <?php if ($hasChart1Data): ?>
                                <canvas id="chartCompanyEmployees" data-chart="<?= e(json_encode($chart1Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                            <?php else: ?>
                                <div class="chart-empty-state">No data for this period</div>
                            <?php endif; ?>
                        </div>

                        <!-- Plain HTML Table Fallback -->
                        <div class="chart-fallback-table table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th class="text-end">Active staff</th>
                                        <th class="text-end">Total staff</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companyEmpCounts)): ?>
                                        <tr><td colspan="3" class="text-center py-2 text-muted">No data available.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($companyEmpCounts as $row): ?>
                                             <tr>
                                                <td class="fw-medium"><?= e($row['company_name']) ?></td>
                                                <td class="text-end fw-semibold"><?= (int)$row['active_count'] ?></td>
                                                <td class="text-end text-muted"><?= (int)$row['total_count'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($companyRecActivity)): ?>
            <!-- Chart 2: Company-Wise Recruitment Activity -->
            <div class="<?= empty($companyEmpCounts) ? 'grid-col-12' : 'grid-col-6' ?>">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-briefcase"></i> Recruitment activity by company
                        </h2>
                        <span class="chart-card-caption">Pipeline stages</span>
                    </div>
                    <div class="chart-card-body">
                        <!-- Chart.js Canvas -->
                        <div class="chart-canvas-container">
                            <?php
                            $chart2Labels = array_column($companyRecActivity, 'company_name');
                            $chart2Apps   = array_map('intval', array_column($companyRecActivity, 'applications_count'));
                            $chart2Ints   = array_map('intval', array_column($companyRecActivity, 'interviews_count'));
                            $chart2Offers = array_map('intval', array_column($companyRecActivity, 'offers_count'));
                            $chart2Hires  = array_map('intval', array_column($companyRecActivity, 'hires_count'));
                            $hasChart2Data = !empty(array_filter($chart2Apps)) || !empty(array_filter($chart2Ints)) || !empty(array_filter($chart2Offers)) || !empty(array_filter($chart2Hires));
                            $chart2Data = [
                                'type' => 'bar',
                                'labels' => $chart2Labels,
                                'datasets' => [
                                    ['label' => 'Applications', 'data' => $chart2Apps,   'backgroundColor' => '#2563EB'],
                                    ['label' => 'Interviews',   'data' => $chart2Ints,   'backgroundColor' => '#64748B'],
                                    ['label' => 'Offers Sent',  'data' => $chart2Offers, 'backgroundColor' => '#94A3B8'],
                                    ['label' => 'Hires',        'data' => $chart2Hires,  'backgroundColor' => '#CBD5E1'],
                                ],
                            ];
                            ?>
                            <?php if ($hasChart2Data): ?>
                                <canvas id="chartCompanyRecruitment" data-chart="<?= e(json_encode($chart2Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                            <?php else: ?>
                                <div class="chart-empty-state">No data for this period</div>
                            <?php endif; ?>
                        </div>

                        <!-- Plain HTML Table Fallback -->
                        <div class="chart-fallback-table table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th class="text-end">Applied</th>
                                        <th class="text-end">Interviews</th>
                                        <th class="text-end">Offers</th>
                                        <th class="text-end">Hired</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companyRecActivity)): ?>
                                        <tr><td colspan="5" class="text-center py-2 text-muted">No data available.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($companyRecActivity as $row): ?>
                                            <tr>
                                                <td class="fw-medium"><?= e($row['company_name']) ?></td>
                                                <td class="text-end"><?= (int)$row['applications_count'] ?></td>
                                                <td class="text-end"><?= (int)$row['interviews_count'] ?></td>
                                                <td class="text-end"><?= (int)$row['offers_count'] ?></td>
                                                <td class="text-end fw-semibold"><?= (int)$row['hires_count'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- SECTION 2: 12-Month Trends -->
<?php if (!empty($monthlyHiring) || !empty($monthlyJoining)): ?>
<div class="dashboard-grid">
    <?php if (!empty($monthlyHiring)): ?>
    <!-- Chart 3: Hiring Statistics by Month (Last 12 Mos: Applications Received vs Hired) -->
    <div class="<?= empty($monthlyJoining) ? 'grid-col-12' : 'grid-col-6' ?>">
        <div class="chart-card">
            <div class="chart-card-header">
                <h2 class="chart-card-title">
                    <i class="bi bi-graph-up"></i> Hiring statistics (last 12 months)
                </h2>
                <span class="chart-card-caption">Applied vs hired</span>
            </div>
            <div class="chart-card-body">
                <div class="chart-canvas-container">
                    <?php
                    $mHiringLabels = array_column($monthlyHiring, 'label');
                    $mHiringApps   = array_map('intval', array_column($monthlyHiring, 'applications'));
                    $mHiringHired  = array_map('intval', array_column($monthlyHiring, 'hired'));
                    $hasChart3Data = !empty(array_filter($mHiringApps)) || !empty(array_filter($mHiringHired));
                    $chart3Data = [
                        'type' => 'line',
                        'labels' => $mHiringLabels,
                        'datasets' => [
                            [
                                'label' => 'Applications Received',
                                'data' => $mHiringApps,
                                'borderColor' => '#2563EB',
                                'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                                'tension' => 0.2,
                                'fill' => true,
                            ],
                            [
                                'label' => 'Candidates Hired',
                                'data' => $mHiringHired,
                                'borderColor' => '#64748B',
                                'backgroundColor' => 'rgba(100, 116, 139, 0.1)',
                                'tension' => 0.2,
                                'fill' => true,
                            ],
                        ],
                    ];
                    ?>
                    <?php if ($hasChart3Data): ?>
                        <canvas id="chartMonthlyHiring" data-chart="<?= e(json_encode($chart3Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                    <?php else: ?>
                        <div class="chart-empty-state">No data for this period</div>
                    <?php endif; ?>
                </div>

                <!-- HTML Table Fallback -->
                <div class="chart-fallback-table table-responsive" style="max-height: 180px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="sticky-top">
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Applications</th>
                                <th class="text-end">Hired</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthlyHiring)): ?>
                                <tr><td colspan="3" class="text-center py-2 text-muted">No data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthlyHiring as $mh): ?>
                                    <tr>
                                        <td><?= e($mh['label']) ?></td>
                                        <td class="text-end"><?= (int)$mh['applications'] ?></td>
                                        <td class="text-end fw-semibold"><?= (int)$mh['hired'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($monthlyJoining)): ?>
    <!-- Chart 4: Employee Joining Statistics by Month (Last 12 Mos) -->
    <div class="<?= empty($monthlyHiring) ? 'grid-col-12' : 'grid-col-6' ?>">
        <div class="chart-card">
            <div class="chart-card-header">
                <h2 class="chart-card-title">
                    <i class="bi bi-person-plus"></i> Employee joining history (last 12 months)
                </h2>
                <span class="chart-card-caption">New staff count</span>
            </div>
            <div class="chart-card-body">
                <div class="chart-canvas-container">
                    <?php
                    $mJoinLabels = array_column($monthlyJoining, 'label');
                    $mJoinCounts = array_map('intval', array_column($monthlyJoining, 'joinings'));
                    $hasChart4Data = !empty(array_filter($mJoinCounts));
                    $chart4Data = [
                        'type' => 'bar',
                        'labels' => $mJoinLabels,
                        'datasets' => [
                            [
                                'label' => 'New Joinings',
                                'data' => $mJoinCounts,
                                'backgroundColor' => '#2563EB',
                            ],
                        ],
                    ];
                    ?>
                    <?php if ($hasChart4Data): ?>
                        <canvas id="chartMonthlyJoining" data-chart="<?= e(json_encode($chart4Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                    <?php else: ?>
                        <div class="chart-empty-state">No data for this period</div>
                    <?php endif; ?>
                </div>

                <!-- HTML Table Fallback -->
                <div class="chart-fallback-table table-responsive" style="max-height: 180px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="sticky-top">
                            <tr>
                                <th>Month</th>
                                <th class="text-end">New joinings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthlyJoining)): ?>
                                <tr><td colspan="2" class="text-center py-2 text-muted">No data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthlyJoining as $mj): ?>
                                    <tr>
                                        <td><?= e($mj['label']) ?></td>
                                        <td class="text-end fw-semibold"><?= (int)$mj['joinings'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- SECTION 3: Recruitment Funnel -->
<div class="dashboard-grid">
    <div class="grid-col-12">
        <div class="chart-card">
            <div class="chart-card-header">
                <h2 class="chart-card-title">
                    <i class="bi bi-funnel"></i> Recruitment funnel conversion
                </h2>
                <span class="chart-card-caption"><?= e($filters['date_range_label']) ?></span>
            </div>
            <div class="chart-card-body">
                <div class="row align-items-center g-3">
                    <div class="col-lg-7">
                        <div class="chart-canvas-container" style="height: 260px;">
                            <?php
                            $funnelLabels = ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Offered', 'Hired'];
                            $funnelCounts = [
                                (int)$funnelStats['applied'],
                                (int)$funnelStats['shortlisted'],
                                (int)$funnelStats['interviewed'],
                                (int)$funnelStats['selected'],
                                (int)$funnelStats['offered'],
                                (int)$funnelStats['hired'],
                            ];
                            $hasFunnelData = !empty(array_filter($funnelCounts));
                            $chart5Data = [
                                'type' => 'bar',
                                'labels' => $funnelLabels,
                                'datasets' => [
                                    [
                                        'label' => 'Candidates Count',
                                        'data' => $funnelCounts,
                                        'backgroundColor' => ['#1D4ED8', '#2563EB', '#3B82F6', '#64748B', '#94A3B8', '#CBD5E1'],
                                    ],
                                ],
                            ];
                            ?>
                            <?php if ($hasFunnelData): ?>
                                <canvas id="chartRecruitmentFunnel" data-chart="<?= e(json_encode($chart5Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                            <?php else: ?>
                                <div class="chart-empty-state">No data for this period</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <!-- Funnel Progression Table Fallback -->
                        <div class="chart-fallback-table table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Funnel stage</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Conversion %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $appliedBase = max(1, (int)$funnelStats['applied']);
                                    $stages = [
                                        'Applied'     => $funnelStats['applied'],
                                        'Shortlisted' => $funnelStats['shortlisted'],
                                        'Interviewed' => $funnelStats['interviewed'],
                                        'Selected'    => $funnelStats['selected'],
                                        'Offered'     => $funnelStats['offered'],
                                        'Hired'       => $funnelStats['hired'],
                                    ];
                                    foreach ($stages as $stageLabel => $cnt):
                                        $pct = round(($cnt / $appliedBase) * 100, 1);
                                    ?>
                                        <tr>
                                            <td class="fw-medium"><?= e($stageLabel) ?></td>
                                            <td class="text-end fw-semibold"><?= (int)$cnt ?></td>
                                            <td class="text-end text-muted"><?= $pct ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SECTION 4: Authorization and Scope (Slim row) -->
<div class="card mb-4" style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-card);">
    <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="stat-icon">
                <i class="bi bi-shield-check"></i>
            </div>
            <div>
                <div class="fw-semibold text-body" style="font-size: 13.5px;">Authorization &amp; Scope</div>
                <div class="text-muted" style="font-size: 12.5px;">
                    <?php if (is_group_admin()): ?>
                        Superuser privileges across all registered companies, users, and audit records in the AaZa Group.
                    <?php elseif (!empty($access)): ?>
                        Assigned entities: <?= e(implode(', ', array_map(fn($a) => $a['name'] . ' (' . $a['roles'] . ')', $access))) ?>
                    <?php else: ?>
                        No company assignments found for your login account yet.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (user_can('report.view')): ?>
                <a href="<?= e(url('modules/reports/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-bar-chart-line me-1"></i> Reports
                </a>
            <?php endif; ?>
            <?php if (user_can('employee.view')): ?>
                <a href="<?= e(url('modules/employees/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-people me-1"></i> Directory
                </a>
            <?php endif; ?>
            <?php if (user_can('vacancy.view')): ?>
                <a href="<?= e(url('modules/vacancies/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-briefcase me-1"></i> Vacancies
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js Version 4.4.7 Local Vendor Asset -->
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;

    // Apply theme-aware text & grid colors
    function updateChartThemeDefaults() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        Chart.defaults.color = isDark ? '#94a3b8' : '#64748b';
        Chart.defaults.borderColor = isDark ? '#232D3F' : '#e2e8f0';
        if (Chart.defaults.plugins && Chart.defaults.plugins.legend) {
            Chart.defaults.plugins.legend.labels.color = isDark ? '#f1f5f9' : '#0f172a';
            Chart.defaults.plugins.legend.labels.font = { family: 'Inter', size: 12 };
        }
    }
    updateChartThemeDefaults();

    // Helper to safely initialize chart from canvas data-chart attribute
    function initChart(canvasId, optionsOverride = {}) {
        const el = document.getElementById(canvasId);
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;

        try {
            const config = JSON.parse(raw);
            const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            const mergedOptions = Object.assign({
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: isDark ? '#f1f5f9' : '#0f172a',
                            boxWidth: 10,
                            padding: 12,
                            font: { family: 'Inter', size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: isDark ? '#1a2230' : '#f1f5f9' },
                        ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Inter', size: 11 } }
                    },
                    y: {
                        grid: { color: isDark ? '#1a2230' : '#f1f5f9' },
                        ticks: { color: isDark ? '#94a3b8' : '#64748b', font: { family: 'Inter', size: 11 } }
                    }
                }
            }, optionsOverride);

            new Chart(el, {
                type: config.type || 'bar',
                data: {
                    labels: config.labels || [],
                    datasets: config.datasets || []
                },
                options: mergedOptions
            });
        } catch (e) {
            console.error('Failed to parse chart config for ' + canvasId, e);
        }
    }

    initChart('chartCompanyEmployees');
    initChart('chartCompanyRecruitment');
    initChart('chartMonthlyHiring');
    initChart('chartMonthlyJoining');
    initChart('chartRecruitmentFunnel', {
        indexAxis: 'y'
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
