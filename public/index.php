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

// Compute active filter chips
$activeChips = [];
if ($filters['company_id'] > 0) {
    $compName = 'Company #' . $filters['company_id'];
    foreach ($filterCompanies as $c) {
        if ((int)$c['id'] === $filters['company_id']) {
            $compName = $c['code'] . ' - ' . $c['name'];
            break;
        }
    }
    $remParams = $_GET;
    $remParams['company_id'] = 0;
    $activeChips[] = [
        'label' => 'Company: ' . $compName,
        'remove_url' => url('public/index.php') . (!empty($remParams) ? '?' . http_build_query($remParams) : ''),
    ];
}
if ($filters['department_id'] > 0) {
    $deptName = 'Department #' . $filters['department_id'];
    foreach ($filterDepts as $d) {
        if ((int)$d['id'] === $filters['department_id']) {
            $deptName = $d['name'];
            break;
        }
    }
    $remParams = $_GET;
    unset($remParams['department_id']);
    $activeChips[] = [
        'label' => 'Department: ' . $deptName,
        'remove_url' => url('public/index.php') . (!empty($remParams) ? '?' . http_build_query($remParams) : ''),
    ];
}
if ($filters['vacancy_id'] > 0) {
    $vacName = 'Position #' . $filters['vacancy_id'];
    foreach ($filterVacancies as $v) {
        if ((int)$v['id'] === $filters['vacancy_id']) {
            $vacName = $v['vacancy_code'] . ' - ' . $v['title'];
            break;
        }
    }
    $remParams = $_GET;
    unset($remParams['vacancy_id']);
    $activeChips[] = [
        'label' => 'Position: ' . $vacName,
        'remove_url' => url('public/index.php') . (!empty($remParams) ? '?' . http_build_query($remParams) : ''),
    ];
}
if (!empty($filters['date_from'])) {
    $remParams = $_GET;
    unset($remParams['date_from']);
    $activeChips[] = [
        'label' => 'From: ' . $filters['date_from'],
        'remove_url' => url('public/index.php') . (!empty($remParams) ? '?' . http_build_query($remParams) : ''),
    ];
}
if (!empty($filters['date_to'])) {
    $remParams = $_GET;
    unset($remParams['date_to']);
    $activeChips[] = [
        'label' => 'To: ' . $filters['date_to'],
        'remove_url' => url('public/index.php') . (!empty($remParams) ? '?' . http_build_query($remParams) : ''),
    ];
}

$pageTitle = $isGroupView ? 'Group Dashboard & Analytics' : 'Company Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<!-- Scoped Dashboard Stylesheet -->
<link rel="stylesheet" href="<?= e(url('assets/css/dashboard.css')) ?>">

<div class="dashboard-page">
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
            <!-- Desktop secondary outline buttons -->
            <div class="header-actions-desktop">
                <?php if (user_can('report.view')): ?>
                    <a href="<?= e(url('modules/reports/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-bar-chart-line me-1"></i> Reports Catalog
                    </a>
                <?php endif; ?>
                <?php if (user_can('employee.create')): ?>
                    <a href="<?= e(url('modules/employees/form.php')) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-person-plus me-1"></i> Add Employee
                    </a>
                <?php endif; ?>
            </div>

            <!-- Primary Action: Post Vacancy -->
            <?php if (user_can('vacancy.manage')): ?>
                <a href="<?= e(url('modules/vacancies/form.php')) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Post Vacancy
                </a>
            <?php endif; ?>

            <!-- Mobile secondary actions inside action_menu() -->
            <div class="header-actions-mobile">
                <?php
                $headerSecondary = [];
                if (user_can('report.view')) {
                    $headerSecondary[] = [
                        'label' => 'Reports Catalog',
                        'url'   => url('modules/reports/index.php'),
                        'icon'  => 'bi-bar-chart-line',
                    ];
                }
                if (user_can('employee.create')) {
                    $headerSecondary[] = [
                        'label' => 'Add Employee',
                        'url'   => url('modules/employees/form.php'),
                        'icon'  => 'bi-person-plus',
                    ];
                }
                if (!empty($headerSecondary)) {
                    echo action_menu($headerSecondary, [
                        'btn_class' => 'btn-outline-secondary btn-sm',
                        'aria_label' => 'More dashboard actions',
                    ]);
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Dashboard Filter Bar -->
    <div class="dashboard-filter-card">
        <form method="get" action="<?= e(url('public/index.php')) ?>" class="filter-form-compact">
            <!-- Company Filter -->
            <div class="filter-col">
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
            <div class="filter-col">
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
            <div class="filter-col">
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
            <div class="filter-col-date">
                <label class="filter-label-inline" for="dateFromFilter">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>From
                </label>
                <input type="date" id="dateFromFilter" name="date_from" class="form-control filter-control" aria-label="From date" value="<?= e((string)($filters['date_from'] ?? '')) ?>">
            </div>

            <!-- Date To -->
            <div class="filter-col-date">
                <label class="filter-label-inline" for="dateToFilter">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>To
                </label>
                <input type="date" id="dateToFilter" name="date_to" class="form-control filter-control" aria-label="To date" value="<?= e((string)($filters['date_to'] ?? '')) ?>">
            </div>

            <!-- Action Buttons: Apply (primary) + Reset (ghost) with 8px gap -->
            <div class="filter-col-actions">
                <button type="submit" class="btn btn-primary btn-apply" title="Apply Filters">
                    <i class="bi bi-filter me-1"></i> Apply
                </button>
                <a href="<?= e(url('public/index.php?company_id=0')) ?>" class="btn-ghost" title="Reset Filters">
                    Reset
                </a>
            </div>
        </form>

        <!-- Active Filter Chips -->
        <?php if (!empty($activeChips)): ?>
            <div class="active-filter-chips">
                <span class="active-filter-title"><i class="bi bi-funnel-fill me-1"></i>Active filters:</span>
                <?php foreach ($activeChips as $chip): ?>
                    <span class="filter-chip">
                        <?= e($chip['label']) ?>
                        <a href="<?= e($chip['remove_url']) ?>" class="filter-chip-remove" aria-label="Remove filter" title="Remove filter">&times;</a>
                    </span>
                <?php endforeach; ?>
                <a href="<?= e(url('public/index.php?company_id=0')) ?>" class="btn-clear-all" title="Clear all filters">Clear all</a>
            </div>
        <?php endif; ?>
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

    <!-- ========================================================================= -->
    <!-- SECTION 1: 8 KPI STAT CARDS (4 DESKTOP / 2 MOBILE, TINTED ICONS) -->
    <!-- ========================================================================= -->
    <div class="kpi-grid">
        <!-- Card 1: Total Companies (bi-building) -->
        <?php if (is_group_admin()): ?>
            <a href="<?= e(url('modules/companies/index.php')) ?>" class="kpi-card" title="View active companies">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Active companies</div>
                    <div class="kpi-number"><?= (int)$cardStats['total_companies'] ?></div>
                    <div class="kpi-sublabel">Operating units</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-building"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (is_group_admin()): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php else: ?>
                    <span class="text-muted small">AaZa Group</span>
                <?php endif; ?>
            </div>
        <?= is_group_admin() ? '</a>' : '</div>' ?>

        <!-- Card 2: Total Active Employees (bi-people) -->
        <?php if (user_can('employee.view')): ?>
            <a href="<?= e(url('modules/employees/index.php?status=active')) ?>" class="kpi-card" title="View active employees">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Active employees</div>
                    <?php if ($cardStats['active_employees'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['active_employees'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Regular &amp; contract staff</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-people"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('employee.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= user_can('employee.view') ? '</a>' : '</div>' ?>

        <!-- Card 3: Open Vacancies (bi-briefcase) -->
        <?php if (user_can('vacancy.view')): ?>
            <a href="<?= e(url('modules/vacancies/index.php?status=open')) ?>" class="kpi-card" title="View open vacancies">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Open vacancies</div>
                    <?php if ($cardStats['open_vacancies'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['open_vacancies'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Actively recruiting</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-briefcase"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('vacancy.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= user_can('vacancy.view') ? '</a>' : '</div>' ?>

        <!-- Card 4: Total Candidates (bi-person-lines-fill) -->
        <?php if (user_can('candidate.view')): ?>
            <a href="<?= e(url('modules/candidates/index.php')) ?>" class="kpi-card" title="View candidates">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Total candidates</div>
                    <?php if ($cardStats['total_candidates'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['total_candidates'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Talent repository</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-person-lines-fill"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('candidate.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= user_can('candidate.view') ? '</a>' : '</div>' ?>

        <!-- Card 5: Shortlisted / Selected for Interview (bi-star) -->
        <?php if (user_can('candidate.view') && user_can('report.view')): ?>
            <a href="<?= e(url('modules/reports/candidates_selected.php')) ?>" class="kpi-card" title="View shortlisted candidates">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Shortlisted candidates</div>
                    <?php if ($cardStats['shortlisted_selected'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['shortlisted_selected'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Interview pipeline</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-star"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('candidate.view') && user_can('report.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= (user_can('candidate.view') && user_can('report.view')) ? '</a>' : '</div>' ?>

        <!-- Card 6: Upcoming Interviews (Next 7 Days, bi-calendar-event) -->
        <?php if (user_can('interview.view')): ?>
            <a href="<?= e(url('modules/interviews/index.php')) ?>" class="kpi-card" title="View upcoming interviews">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">Upcoming interviews</div>
                    <?php if ($cardStats['upcoming_interviews'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['upcoming_interviews'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Next 7 calendar days</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-calendar-event"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('interview.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= user_can('interview.view') ? '</a>' : '</div>' ?>

        <!-- Card 7: Pending Offers (Draft + Sent, bi-envelope-paper) -->
        <?php if (user_can('offer.view')): ?>
            <a href="<?= e(url('modules/offers/index.php?status=pending')) ?>" class="kpi-card" title="View pending offers">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">
                        Pending offers
                        <?php if (!empty($cardStats['pending_offers']) && (int)$cardStats['pending_offers'] > 0): ?>
                            <span class="status-warning-dot" title="Requires attention"></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cardStats['pending_offers'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['pending_offers'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Draft &amp; sent offers</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-envelope-paper"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('offer.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= user_can('offer.view') ? '</a>' : '</div>' ?>

        <!-- Card 8: Pending Leave Requests (bi-calendar-heart) -->
        <?php if (user_can('leave.approve') || user_can('leave.view')): ?>
            <a href="<?= e(url('modules/leave/index.php?status=pending')) ?>" class="kpi-card" title="View pending leave requests">
        <?php else: ?>
            <div class="kpi-card">
        <?php endif; ?>
            <div class="kpi-top">
                <div class="kpi-content">
                    <div class="kpi-label">
                        Pending leaves
                        <?php if (!empty($cardStats['pending_leaves']) && (int)$cardStats['pending_leaves'] > 0): ?>
                            <span class="status-warning-dot" title="Requires attention"></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cardStats['pending_leaves'] !== null): ?>
                        <div class="kpi-number"><?= (int)$cardStats['pending_leaves'] ?></div>
                    <?php else: ?>
                        <div class="fs-6 fw-bold text-muted my-2"><i class="bi bi-lock me-1"></i>Restricted</div>
                    <?php endif; ?>
                    <div class="kpi-sublabel">Awaiting decision</div>
                </div>
                <div class="kpi-icon-circle">
                    <i class="bi bi-calendar-heart"></i>
                </div>
            </div>
            <div class="kpi-bottom">
                <?php if (user_can('leave.approve') || user_can('leave.view')): ?>
                    <span class="kpi-view-indicator">View <i class="bi bi-arrow-right-short"></i></span>
                <?php endif; ?>
            </div>
        <?= (user_can('leave.approve') || user_can('leave.view')) ? '</a>' : '</div>' ?>
    </div>

    <!-- ========================================================================= -->
    <!-- SECTION 2: CHARTS 2 x 2 LAYOUT -->
    <!-- ========================================================================= -->
    <?php if (!empty($companyEmpCounts) || !empty($companyRecActivity)): ?>
        <div class="charts-2x2-grid">
            <?php if (!empty($companyEmpCounts)): ?>
                <!-- Chart 1: Company-Wise Employee Count (DOUGHNUT) -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-pie-chart text-primary"></i> Company-wise employee count
                        </h2>
                        <span class="chart-card-caption">Active staff distribution</span>
                    </div>
                    <div class="chart-card-body">
                        <!-- Doughnut Canvas Wrap -->
                        <div class="chart-canvas-wrap-doughnut mb-3">
                            <?php
                            $chart1Labels = array_column($companyEmpCounts, 'company_name');
                            $chart1Active = array_map('intval', array_column($companyEmpCounts, 'active_count'));
                            $chart1Total  = array_map('intval', array_column($companyEmpCounts, 'total_count'));
                            $totalActiveStaff = array_sum($chart1Active);
                            
                            $chart1Data = [
                                'type' => 'doughnut',
                                'labels' => $chart1Labels,
                                'total_active' => $totalActiveStaff,
                                'datasets' => [
                                    [
                                        'data' => $chart1Active,
                                        'cutout' => '68%',
                                    ],
                                ],
                            ];
                            ?>
                            <canvas id="chartCompanyEmployees" data-chart="<?= e(json_encode($chart1Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                        </div>

                        <!-- Plain HTML Table Displayed Together (No table-light, card theme styling) -->
                        <div class="chart-table-wrap table-responsive">
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
            <?php endif; ?>

            <?php if (!empty($companyRecActivity)): ?>
                <!-- Chart 2: Company-Wise Recruitment Activity (GROUPED BAR) -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-bar-chart-steps text-primary"></i> Recruitment activity by company
                        </h2>
                        <span class="chart-card-caption">Pipeline stages</span>
                    </div>
                    <div class="chart-card-body">
                        <!-- Grouped Bar Canvas Wrap -->
                        <div class="chart-canvas-wrap mb-3">
                            <?php
                            $chart2Labels = array_column($companyRecActivity, 'company_name');
                            $chart2Apps   = array_map('intval', array_column($companyRecActivity, 'applications_count'));
                            $chart2Ints   = array_map('intval', array_column($companyRecActivity, 'interviews_count'));
                            $chart2Offers = array_map('intval', array_column($companyRecActivity, 'offers_count'));
                            $chart2Hires  = array_map('intval', array_column($companyRecActivity, 'hires_count'));
                            $chart2Data = [
                                'type' => 'bar',
                                'labels' => $chart2Labels,
                                'datasets' => [
                                    ['label' => 'Applications', 'data' => $chart2Apps,   'borderRadius' => 4, 'minBarLength' => 3, 'paletteKey' => 'primary'],
                                    ['label' => 'Interviews',   'data' => $chart2Ints,   'borderRadius' => 4, 'minBarLength' => 3, 'paletteKey' => 'third'],
                                    ['label' => 'Offers Sent',  'data' => $chart2Offers, 'borderRadius' => 4, 'minBarLength' => 3, 'paletteKey' => 'secondary'],
                                    ['label' => 'Hires',        'data' => $chart2Hires,  'borderRadius' => 4, 'minBarLength' => 3, 'paletteKey' => 'fourth'],
                                ],
                            ];
                            ?>
                            <canvas id="chartCompanyRecruitment" data-chart="<?= e(json_encode($chart2Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                        </div>

                        <!-- Plain HTML Table Displayed Together -->
                        <div class="chart-table-wrap table-responsive">
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
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- 12-Month Trends Row (2x2 Row 2) -->
    <?php if (!empty($monthlyHiring) || !empty($monthlyJoining)): ?>
        <div class="charts-2x2-grid">
            <?php if (!empty($monthlyHiring)): ?>
                <?php
                $mHiringLabels = array_column($monthlyHiring, 'label');
                $mHiringApps   = array_map('intval', array_column($monthlyHiring, 'applications'));
                $mHiringHired  = array_map('intval', array_column($monthlyHiring, 'hired'));
                $totalHiringApps  = array_sum($mHiringApps);
                $totalHiringHired = array_sum($mHiringHired);
                ?>
                <!-- Chart 3: Hiring Statistics by Month (Last 12 Mos: LINE/AREA) -->
                <div class="chart-card" id="cardMonthlyHiring">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-graph-up text-primary"></i> Hiring statistics (last 12 months)
                        </h2>
                        <!-- Segmented Switch for Long Month Table -->
                        <div class="chart-view-toggle btn-group btn-group-sm" role="group" aria-label="Toggle Chart or Table">
                            <button type="button" class="btn btn-sm active" data-toggle-target="#cardMonthlyHiring" data-view="chart">
                                <i class="bi bi-graph-up me-1"></i>Chart
                            </button>
                            <button type="button" class="btn btn-sm" data-toggle-target="#cardMonthlyHiring" data-view="table">
                                <i class="bi bi-table me-1"></i>Table
                            </button>
                        </div>
                    </div>
                    <div class="chart-card-body">
                        <!-- Summary Line Above Chart -->
                        <div class="chart-summary-line">
                            <i class="bi bi-info-circle me-1 text-primary"></i>
                            <span><strong><?= (int)$totalHiringApps ?></strong> applications, <strong><?= (int)$totalHiringHired ?></strong> hired in the last 12 months</span>
                        </div>

                        <!-- Chart View Panel -->
                        <div class="chart-panel-view chart-panel-chart">
                            <div class="chart-canvas-wrap">
                                <?php
                                $chart3Data = [
                                    'type' => 'line',
                                    'labels' => $mHiringLabels,
                                    'datasets' => [
                                        [
                                            'label' => 'Applications Received',
                                            'data' => $mHiringApps,
                                            'borderColor' => '#3b82f6',
                                            'backgroundColor' => 'rgba(59, 130, 246, 0.12)',
                                            'tension' => 0.25,
                                            'fill' => true,
                                            'pointRadius' => 3,
                                            'pointHoverRadius' => 5,
                                        ],
                                        [
                                            'label' => 'Candidates Hired',
                                            'data' => $mHiringHired,
                                            'borderColor' => '#64748b',
                                            'backgroundColor' => 'rgba(100, 116, 139, 0.12)',
                                            'tension' => 0.25,
                                            'fill' => true,
                                            'pointRadius' => 3,
                                            'pointHoverRadius' => 5,
                                        ],
                                    ],
                                ];
                                ?>
                                <canvas id="chartMonthlyHiring" data-chart="<?= e(json_encode($chart3Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                            </div>
                        </div>

                        <!-- Table View Panel (Hidden by default, segmented switch toggled) -->
                        <div class="chart-panel-view chart-panel-table d-none">
                            <div class="chart-table-wrap table-responsive" id="tableMonthlyHiringWrap">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
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
                                            <?php foreach ($monthlyHiring as $idx => $mh): ?>
                                                <tr class="<?= $idx >= 4 ? 'extra-month-row d-none' : '' ?>">
                                                    <td class="fw-medium"><?= e($mh['label']) ?></td>
                                                    <td class="text-end"><?= (int)$mh['applications'] ?></td>
                                                    <td class="text-end fw-semibold"><?= (int)$mh['hired'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php if (count($monthlyHiring) > 4): ?>
                                    <div class="text-center py-2 table-footer-bar">
                                        <button type="button" class="btn btn-link btn-sm btn-toggle-months" data-table-id="tableMonthlyHiringWrap">
                                            Show all 12 months (<?= count($monthlyHiring) - 4 ?> more)
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($monthlyJoining)): ?>
                <?php
                $mJoinLabels = array_column($monthlyJoining, 'label');
                $mJoinCounts = array_map('intval', array_column($monthlyJoining, 'joinings'));
                $totalJoinCount = array_sum($mJoinCounts);
                ?>
                <!-- Chart 4: Employee Joining History by Month (Last 12 Mos: BAR) -->
                <div class="chart-card" id="cardMonthlyJoining">
                    <div class="chart-card-header">
                        <h2 class="chart-card-title">
                            <i class="bi bi-person-check text-primary"></i> Employee joining history (last 12 months)
                        </h2>
                        <!-- Segmented Switch for Long Month Table -->
                        <div class="chart-view-toggle btn-group btn-group-sm" role="group" aria-label="Toggle Chart or Table">
                            <button type="button" class="btn btn-sm active" data-toggle-target="#cardMonthlyJoining" data-view="chart">
                                <i class="bi bi-graph-up me-1"></i>Chart
                            </button>
                            <button type="button" class="btn btn-sm" data-toggle-target="#cardMonthlyJoining" data-view="table">
                                <i class="bi bi-table me-1"></i>Table
                            </button>
                        </div>
                    </div>
                    <div class="chart-card-body">
                        <!-- Summary Line Above Chart -->
                        <div class="chart-summary-line">
                            <i class="bi bi-info-circle me-1 text-primary"></i>
                            <span><strong><?= (int)$totalJoinCount ?></strong> new staff joined in the last 12 months</span>
                        </div>

                        <!-- Chart View Panel -->
                        <div class="chart-panel-view chart-panel-chart">
                            <div class="chart-canvas-wrap">
                                <?php
                                $chart4Data = [
                                    'type' => 'bar',
                                    'labels' => $mJoinLabels,
                                    'datasets' => [
                                        [
                                            'label' => 'New Joinings',
                                            'data' => $mJoinCounts,
                                            'backgroundColor' => '#3b82f6',
                                            'borderRadius' => 4,
                                            'minBarLength' => 3,
                                        ],
                                    ],
                                ];
                                ?>
                                <canvas id="chartMonthlyJoining" data-chart="<?= e(json_encode($chart4Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                            </div>
                        </div>

                        <!-- Table View Panel (Hidden by default, segmented switch toggled) -->
                        <div class="chart-panel-view chart-panel-table d-none">
                            <div class="chart-table-wrap table-responsive" id="tableMonthlyJoiningWrap">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-end">New joinings</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($monthlyJoining)): ?>
                                            <tr><td colspan="2" class="text-center py-2 text-muted">No data available.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($monthlyJoining as $idx => $mj): ?>
                                                <tr class="<?= $idx >= 4 ? 'extra-month-row d-none' : '' ?>">
                                                    <td class="fw-medium"><?= e($mj['label']) ?></td>
                                                    <td class="text-end fw-semibold"><?= (int)$mj['joinings'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php if (count($monthlyJoining) > 4): ?>
                                    <div class="text-center py-2 table-footer-bar">
                                        <button type="button" class="btn btn-link btn-sm btn-toggle-months" data-table-id="tableMonthlyJoiningWrap">
                                            Show all 12 months (<?= count($monthlyJoining) - 4 ?> more)
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- SECTION 3: RECRUITMENT FUNNEL CONVERSION (CHART LEFT, TABLE RIGHT) -->
    <!-- ========================================================================= -->
    <div class="chart-card mb-4">
        <div class="chart-card-header">
            <h2 class="chart-card-title">
                <i class="bi bi-funnel text-primary"></i> Recruitment funnel conversion
            </h2>
            <span class="chart-card-caption"><?= e($filters['date_range_label']) ?></span>
        </div>
        <div class="chart-card-body">
            <div class="funnel-grid">
                <!-- Funnel Horizontal Bar Chart (Left) -->
                <div>
                    <div class="chart-canvas-wrap" style="height: 270px;">
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
                        $chart5Data = [
                            'type' => 'bar',
                            'labels' => $funnelLabels,
                            'datasets' => [
                                [
                                    'label' => 'Candidates Count',
                                    'data' => $funnelCounts,
                                    'borderRadius' => 4,
                                    'minBarLength' => 3,
                                ],
                            ],
                        ];
                        ?>
                        <canvas id="chartRecruitmentFunnel" data-chart="<?= e(json_encode($chart5Data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"></canvas>
                    </div>
                </div>

                <!-- Funnel Progression Table (Right) -->
                <div>
                    <div class="chart-table-wrap table-responsive">
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

    <!-- ========================================================================= -->
    <!-- SECTION 4: AUTHORIZATION AND SCOPE (SLIM ROW AT BOTTOM) -->
    <!-- ========================================================================= -->
    <div class="scope-card">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="kpi-icon-circle">
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
</div>

<!-- Chart.js Version 4.4.7 Local Vendor Asset -->
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;

    const chartInstances = {};

    function isDarkMode() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function getCardBackgroundColor() {
        const card = document.querySelector('.dashboard-page .chart-card');
        if (card) {
            const bg = window.getComputedStyle(card).backgroundColor;
            if (bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)') {
                return bg;
            }
        }
        return isDarkMode() ? '#131923' : '#ffffff';
    }

    function getThemeColors() {
        const dark = isDarkMode();
        const cardBg = getCardBackgroundColor();
        return {
            isDark: dark,
            textColor: dark ? '#cbd5e1' : '#334155',
            mutedColor: dark ? '#94a3b8' : '#64748b',
            gridColor: dark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(100, 116, 139, 0.20)',
            primary: '#3b82f6',
            secondarySlate: dark ? '#94a3b8' : '#8fa3bd',
            thirdTone: '#64748b',
            fourthColor: dark ? '#cbd5e1' : '#334155',
            fifthColor: '#22c55e',
            surfaceText: dark ? '#f8fafc' : '#0f172a',
            cardBg: cardBg
        };
    }

    function getDoughnutPalette(labels = []) {
        const colors = getThemeColors();
        const basePalette = [
            '#3b82f6',              // 1. Tech blue
            colors.secondarySlate,  // 2. Lifestyle slate
            '#64748b',              // 3. Masala third tone
            colors.fourthColor,     // 4. Fourth tone
            '#22c55e'               // 5. Fifth green
        ];
        return labels.map((lbl, idx) => {
            const lower = (lbl || '').toLowerCase();
            if (lower.includes('tech')) return '#3b82f6';
            if (lower.includes('lifestyle')) return colors.secondarySlate;
            if (lower.includes('masala')) return '#64748b';
            return basePalette[idx % basePalette.length];
        });
    }

    // Custom Plugin 1: Bar Value Labels
    const barValueLabelsPlugin = {
        id: 'barValueLabels',
        afterDatasetsDraw(chart) {
            if (chart.config.type !== 'bar') return;
            const ctx = chart.ctx;
            const isHorizontal = chart.config.options?.indexAxis === 'y';
            const colors = getThemeColors();

            ctx.save();
            ctx.font = '600 11px Inter, sans-serif';
            ctx.fillStyle = colors.textColor;

            chart.data.datasets.forEach((dataset, datasetIndex) => {
                const meta = chart.getDatasetMeta(datasetIndex);
                if (meta.hidden) return;
                meta.data.forEach((element, index) => {
                    const val = dataset.data[index];
                    if (val === undefined || val === null || val === 0) return;
                    const text = String(val);
                    if (isHorizontal) {
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(text, element.x + 5, element.y);
                    } else {
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(text, element.x, element.y - 3);
                    }
                });
            });
            ctx.restore();
        }
    };

    // Custom Plugin 2: Doughnut Center Text (Total Active Staff)
    const doughnutCenterPlugin = {
        id: 'doughnutCenterText',
        afterDraw(chart) {
            if (chart.config.type !== 'doughnut') return;
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            const { top, bottom, left, right } = chartArea;
            const centerX = (left + right) / 2;
            const centerY = (top + bottom) / 2;
            const colors = getThemeColors();

            const total = chart.config._totalActive !== undefined
                ? chart.config._totalActive
                : (chart.data.datasets[0]?.data?.reduce((a, b) => a + (Number(b) || 0), 0) || 0);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            // Bold Total Number
            ctx.font = '700 24px Inter, sans-serif';
            ctx.fillStyle = colors.surfaceText;
            ctx.fillText(String(total), centerX, centerY - 9);

            // Muted Subtitle
            ctx.font = '500 11px Inter, sans-serif';
            ctx.fillStyle = colors.mutedColor;
            ctx.fillText('Active Staff', centerX, centerY + 13);
            ctx.restore();
        }
    };

    Chart.register(barValueLabelsPlugin, doughnutCenterPlugin);

    // Apply global Chart.js defaults
    function applyGlobalDefaults() {
        const colors = getThemeColors();
        Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = colors.textColor;
        Chart.defaults.borderColor = colors.gridColor;
        if (Chart.defaults.plugins && Chart.defaults.plugins.legend) {
            Chart.defaults.plugins.legend.labels.color = colors.textColor;
            Chart.defaults.plugins.legend.labels.font = { family: 'Inter', size: 12 };
        }
    }
    applyGlobalDefaults();

    // Chart 1: Company Employees (Doughnut)
    function initChart1() {
        const el = document.getElementById('chartCompanyEmployees');
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;
        const config = JSON.parse(raw);
        const totalActive = config.total_active || 0;
        const isMobileScreen = window.innerWidth <= 576;
        const colors = getThemeColors();
        const labels = config.labels || [];
        const bgColors = getDoughnutPalette(labels);

        chartInstances['chartCompanyEmployees'] = new Chart(el, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [
                    {
                        data: config.datasets[0]?.data || [],
                        backgroundColor: bgColors,
                        borderColor: colors.cardBg,
                        borderWidth: 3,
                        cutout: '68%',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: isMobileScreen ? 'bottom' : 'right',
                        labels: {
                            boxWidth: 12,
                            boxHeight: 12,
                            padding: 12,
                            color: colors.textColor,
                            font: { family: 'Inter', size: 12 },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (!data.labels.length || !data.datasets.length) return [];
                                const dataset = data.datasets[0];
                                const currentColors = getThemeColors();
                                const total = chart.config._totalActive !== undefined
                                    ? chart.config._totalActive
                                    : dataset.data.reduce((a, b) => a + (Number(b) || 0), 0);

                                return data.labels.map((label, i) => {
                                    const val = Number(dataset.data[i]) || 0;
                                    const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
                                    const bg = Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor[i] : dataset.backgroundColor;
                                    return {
                                        text: `${label}: ${val} (${pct}%)`,
                                        fillStyle: bg,
                                        strokeStyle: 'transparent',
                                        lineWidth: 0,
                                        fontColor: currentColors.textColor,
                                        hidden: isNaN(dataset.data[i]) || (chart.getDatasetMeta(0).data[i] && chart.getDatasetMeta(0).data[i].hidden),
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = Number(context.raw) || 0;
                                const dataset = context.dataset;
                                const total = dataset.data.reduce((a, b) => a + (Number(b) || 0), 0);
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0.0';
                                return ` ${context.label}: ${val} active (${pct}%)`;
                            }
                        }
                    }
                }
            },
            plugins: [doughnutCenterPlugin]
        });
        chartInstances['chartCompanyEmployees'].config._totalActive = totalActive;
    }

    // Chart 2: Recruitment Activity by Company (Grouped Bar)
    function initChart2() {
        const el = document.getElementById('chartCompanyRecruitment');
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;
        const config = JSON.parse(raw);
        const colors = getThemeColors();

        const datasets = (config.datasets || []).map(ds => {
            let bg = colors.primary;
            if (ds.paletteKey === 'third') bg = colors.thirdTone;
            else if (ds.paletteKey === 'secondary') bg = colors.secondarySlate;
            else if (ds.paletteKey === 'fourth') bg = colors.fourthColor;
            return {
                ...ds,
                backgroundColor: bg
            };
        });

        chartInstances['chartCompanyRecruitment'] = new Chart(el, {
            type: 'bar',
            data: {
                labels: config.labels || [],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            color: colors.textColor,
                            font: { family: 'Inter', size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: colors.gridColor },
                        ticks: { color: colors.mutedColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor },
                        ticks: {
                            precision: 0,
                            color: colors.mutedColor
                        }
                    }
                }
            }
        });
    }

    // Chart 3: Monthly Hiring (Line / Area)
    function initChart3() {
        const el = document.getElementById('chartMonthlyHiring');
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;
        const config = JSON.parse(raw);
        const colors = getThemeColors();

        chartInstances['chartMonthlyHiring'] = new Chart(el, {
            type: 'line',
            data: {
                labels: config.labels || [],
                datasets: config.datasets || []
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            color: colors.textColor,
                            font: { family: 'Inter', size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: colors.gridColor },
                        ticks: { color: colors.mutedColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor },
                        ticks: {
                            precision: 0,
                            color: colors.mutedColor
                        }
                    }
                }
            }
        });
    }

    // Chart 4: Monthly Joining (Bar)
    function initChart4() {
        const el = document.getElementById('chartMonthlyJoining');
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;
        const config = JSON.parse(raw);
        const colors = getThemeColors();

        chartInstances['chartMonthlyJoining'] = new Chart(el, {
            type: 'bar',
            data: {
                labels: config.labels || [],
                datasets: config.datasets || []
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            padding: 12,
                            color: colors.textColor,
                            font: { family: 'Inter', size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: colors.gridColor },
                        ticks: { color: colors.mutedColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor },
                        ticks: {
                            precision: 0,
                            color: colors.mutedColor
                        }
                    }
                }
            }
        });
    }

    // Chart 5: Recruitment Funnel (Horizontal Bar)
    function initChart5() {
        const el = document.getElementById('chartRecruitmentFunnel');
        if (!el) return;
        const raw = el.getAttribute('data-chart');
        if (!raw) return;
        const config = JSON.parse(raw);
        const colors = getThemeColors();

        const funnelPalette = [
            '#1d4ed8',
            '#2563eb',
            '#3b82f6',
            '#64748b',
            colors.secondarySlate,
            colors.fourthColor
        ];

        chartInstances['chartRecruitmentFunnel'] = new Chart(el, {
            type: 'bar',
            data: {
                labels: config.labels || [],
                datasets: [
                    {
                        label: 'Candidates Count',
                        data: config.datasets[0]?.data || [],
                        backgroundColor: funnelPalette,
                        borderRadius: 4,
                        minBarLength: 3
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor },
                        ticks: {
                            precision: 0,
                            color: colors.mutedColor
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: colors.textColor, font: { weight: 600 } }
                    }
                }
            }
        });
    }

    // Initialize all charts
    initChart1();
    initChart2();
    initChart3();
    initChart4();
    initChart5();

    // Segmented Switch ("Chart | Table") for Monthly Cards
    document.querySelectorAll('.chart-view-toggle button').forEach(btn => {
        btn.addEventListener('click', function() {
            const cardSel = this.getAttribute('data-toggle-target');
            const viewType = this.getAttribute('data-view');
            const card = document.querySelector(cardSel);
            if (!card) return;

            card.querySelectorAll('.chart-view-toggle button').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-pressed', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-pressed', 'true');

            const chartPanel = card.querySelector('.chart-panel-chart');
            const tablePanel = card.querySelector('.chart-panel-table');

            if (viewType === 'table') {
                if (chartPanel) chartPanel.classList.add('d-none');
                if (tablePanel) tablePanel.classList.remove('d-none');
            } else {
                if (tablePanel) tablePanel.classList.add('d-none');
                if (chartPanel) {
                    chartPanel.classList.remove('d-none');
                    const canvas = chartPanel.querySelector('canvas');
                    if (canvas && chartInstances[canvas.id]) {
                        chartInstances[canvas.id].resize();
                    }
                }
            }
        });
    });

    // Month table "Show all 12 months" toggle
    document.querySelectorAll('.btn-toggle-months').forEach(btn => {
        btn.addEventListener('click', function() {
            const tableWrapId = this.getAttribute('data-table-id');
            const wrap = document.getElementById(tableWrapId);
            if (!wrap) return;

            const extraRows = wrap.querySelectorAll('.extra-month-row');
            if (!extraRows.length) return;

            const isCurrentlyHidden = extraRows[0].classList.contains('d-none');
            extraRows.forEach(r => {
                if (isCurrentlyHidden) {
                    r.classList.remove('d-none');
                } else {
                    r.classList.add('d-none');
                }
            });

            this.textContent = isCurrentlyHidden
                ? 'Show less (4 months)'
                : `Show all 12 months (${extraRows.length} more)`;
        });
    });

    // Theme Re-rendering: Listen to data-bs-theme changes
    function reapplyThemeToCharts() {
        const colors = getThemeColors();
        applyGlobalDefaults();

        // 1. Doughnut
        const donut = chartInstances['chartCompanyEmployees'];
        if (donut && donut.data.datasets.length) {
            donut.data.datasets[0].backgroundColor = getDoughnutPalette(donut.data.labels);
            donut.data.datasets[0].borderColor = colors.cardBg;
            if (donut.options.plugins?.legend?.labels) {
                donut.options.plugins.legend.labels.color = colors.textColor;
            }
            donut.update();
        }

        // 2. Recruitment Activity
        const rec = chartInstances['chartCompanyRecruitment'];
        if (rec && rec.data.datasets.length) {
            rec.data.datasets.forEach(ds => {
                if (ds.paletteKey === 'third') ds.backgroundColor = colors.thirdTone;
                else if (ds.paletteKey === 'secondary') ds.backgroundColor = colors.secondarySlate;
                else if (ds.paletteKey === 'fourth') ds.backgroundColor = colors.fourthColor;
            });
            if (rec.options.scales?.x?.grid) rec.options.scales.x.grid.color = colors.gridColor;
            if (rec.options.scales?.x?.ticks) rec.options.scales.x.ticks.color = colors.mutedColor;
            if (rec.options.scales?.y?.grid) rec.options.scales.y.grid.color = colors.gridColor;
            if (rec.options.scales?.y?.ticks) rec.options.scales.y.ticks.color = colors.mutedColor;
            if (rec.options.plugins?.legend?.labels) rec.options.plugins.legend.labels.color = colors.textColor;
            rec.update();
        }

        // 3. Monthly Hiring
        const hiring = chartInstances['chartMonthlyHiring'];
        if (hiring) {
            if (hiring.options.scales?.x?.grid) hiring.options.scales.x.grid.color = colors.gridColor;
            if (hiring.options.scales?.x?.ticks) hiring.options.scales.x.ticks.color = colors.mutedColor;
            if (hiring.options.scales?.y?.grid) hiring.options.scales.y.grid.color = colors.gridColor;
            if (hiring.options.scales?.y?.ticks) hiring.options.scales.y.ticks.color = colors.mutedColor;
            if (hiring.options.plugins?.legend?.labels) hiring.options.plugins.legend.labels.color = colors.textColor;
            hiring.update();
        }

        // 4. Monthly Joining
        const joining = chartInstances['chartMonthlyJoining'];
        if (joining) {
            if (joining.options.scales?.x?.grid) joining.options.scales.x.grid.color = colors.gridColor;
            if (joining.options.scales?.x?.ticks) joining.options.scales.x.ticks.color = colors.mutedColor;
            if (joining.options.scales?.y?.grid) joining.options.scales.y.grid.color = colors.gridColor;
            if (joining.options.scales?.y?.ticks) joining.options.scales.y.ticks.color = colors.mutedColor;
            if (joining.options.plugins?.legend?.labels) joining.options.plugins.legend.labels.color = colors.textColor;
            joining.update();
        }

        // 5. Funnel
        const funnel = chartInstances['chartRecruitmentFunnel'];
        if (funnel && funnel.data.datasets.length) {
            funnel.data.datasets[0].backgroundColor = [
                '#1d4ed8',
                '#2563eb',
                '#3b82f6',
                '#64748b',
                colors.secondarySlate,
                colors.fourthColor
            ];
            if (funnel.options.scales?.x?.grid) funnel.options.scales.x.grid.color = colors.gridColor;
            if (funnel.options.scales?.x?.ticks) funnel.options.scales.x.ticks.color = colors.mutedColor;
            if (funnel.options.scales?.y?.ticks) funnel.options.scales.y.ticks.color = colors.textColor;
            funnel.update();
        }
    }

    const themeObserver = new MutationObserver(mutations => {
        for (const m of mutations) {
            if (m.type === 'attributes' && m.attributeName === 'data-bs-theme') {
                reapplyThemeToCharts();
            }
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

    // Responsive doughnut legend position adjustments on window resize
    let resizeTimer = null;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const doughnut = chartInstances['chartCompanyEmployees'];
            if (doughnut) {
                const isMobile = window.innerWidth <= 576;
                const currentPos = doughnut.options.plugins.legend.position;
                const newPos = isMobile ? 'bottom' : 'right';
                if (currentPos !== newPos) {
                    doughnut.options.plugins.legend.position = newPos;
                    doughnut.update();
                }
            }
        }, 150);
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
