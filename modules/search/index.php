<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Filter Sanitization
$departmentId      = (int)($_GET['department_id'] ?? 0);
$designationId     = (int)($_GET['designation_id'] ?? 0);
$vacancyId         = (int)($_GET['vacancy_id'] ?? 0);
$recruitmentStatus = trim((string)($_GET['recruitment_status'] ?? ''));
$interviewDateFrom = parse_ymd((string)($_GET['interview_date_from'] ?? ''));
$interviewDateTo   = parse_ymd((string)($_GET['interview_date_to'] ?? ''));
$employmentStatus  = trim((string)($_GET['employment_status'] ?? ''));

$filters = [
    'department_id'       => $departmentId ?: null,
    'designation_id'      => $designationId ?: null,
    'vacancy_id'          => $vacancyId ?: null,
    'recruitment_status'  => $recruitmentStatus ?: null,
    'interview_date_from' => $interviewDateFrom ?: null,
    'interview_date_to'   => $interviewDateTo ?: null,
    'employment_status'   => $employmentStatus ?: null,
];

// Determine tab permissions
$tabDefs = [
    'all'          => ['label' => 'All Results',   'icon' => 'bi-grid-fill',              'perm' => null],
    'employees'    => ['label' => 'Employees',     'icon' => 'bi-person',                 'perm' => 'employee.view'],
    'candidates'   => ['label' => 'Candidates',    'icon' => 'bi-person-badge',           'perm' => 'candidate.view'],
    'vacancies'    => ['label' => 'Vacancies',     'icon' => 'bi-briefcase',              'perm' => 'vacancy.view'],
    'applications' => ['label' => 'Applications',  'icon' => 'bi-file-earmark-person',    'perm' => 'candidate.view'],
    'interviews'   => ['label' => 'Interviews',    'icon' => 'bi-calendar-event',         'perm' => 'interview.view'],
    'offers'       => ['label' => 'Job Offers',    'icon' => 'bi-file-earmark-check',     'perm' => 'offer.view'],
    'leave'        => ['label' => 'Leave Requests','icon' => 'bi-calendar2-range',        'perm' => 'leave.view'],
    'companies'    => ['label' => 'Companies',     'icon' => 'bi-buildings',              'perm' => 'company.view'],
    'users'        => ['label' => 'Users',         'icon' => 'bi-person-gear',            'perm' => 'user.view'],
];

$activeTab = (string)($_GET['tab'] ?? 'all');
if (!isset($tabDefs[$activeTab])) {
    $activeTab = 'all';
}
if ($activeTab !== 'all' && $tabDefs[$activeTab]['perm'] !== null) {
    $perm = $tabDefs[$activeTab]['perm'];
    $allowed = ($perm === 'company.view' || $perm === 'user.view')
        ? (is_group_admin() || user_can($perm))
        : user_can($perm);
    if (!$allowed) {
        $activeTab = 'all';
    }
}

// Fetch filter options scoped to accessible companies
$accessibleCompanies = accessible_company_ids();
$filterDepartments = [];
$filterDesignations = [];
$filterVacancies = [];

if (!empty($accessibleCompanies)) {
    [$compSql, $compParams] = company_in_sql($accessibleCompanies, 'company_id');

    $deptSt = $pdo->prepare("SELECT id, name FROM departments WHERE is_active = 1 AND ($compSql) ORDER BY name");
    $deptSt->execute($compParams);
    $filterDepartments = $deptSt->fetchAll();

    $desigSt = $pdo->prepare("SELECT id, title FROM designations WHERE is_active = 1 AND ($compSql) ORDER BY title");
    $desigSt->execute($compParams);
    $filterDesignations = $desigSt->fetchAll();

    $vacSt = $pdo->prepare("SELECT id, vacancy_code, title FROM job_vacancies WHERE ($compSql) ORDER BY title");
    $vacSt->execute($compParams);
    $filterVacancies = $vacSt->fetchAll();
}

$rateLimited = false;
$searchResult = [
    'query'       => $q,
    'groups'      => [],
    'total_count' => 0,
];

// Perform search if query is provided
if ($q !== '') {
    if (!check_rate_limit('global_search', 60, 60)) {
        $rateLimited = true;
    } else {
        if ($activeTab === 'all') {
            $searchResult = search_global($q, [
                'limit_per_group' => 5,
                'filters'         => $filters,
            ]);
        } else {
            $searchResult = search_global($q, [
                'group'    => $activeTab,
                'page'     => $page,
                'per_page' => $perPage,
                'filters'  => $filters,
            ]);
        }
    }
}

// If on a specific tab, calculate pagination
$activeGroupTotal = 0;
$activeGroupItems = [];
if ($activeTab !== 'all' && isset($searchResult['groups'][$activeTab])) {
    $activeGroupTotal = $searchResult['groups'][$activeTab]['total'];
    $activeGroupItems = $searchResult['groups'][$activeTab]['items'];
}
$pagination = paginate($activeGroupTotal, $page, $perPage);

$pageTitle = $q !== '' ? 'Search: ' . $q : 'Global Search';
require __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Top Header -->
    <div class="page-header mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Global Search</li>
            </ol>
        </nav>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h1 class="page-header-title mb-1">
                    <i class="bi bi-search me-2 text-primary"></i>Global Search
                </h1>
                <p class="page-header-desc mb-0">Search across employees, candidates, vacancies, interviews, offers, and master data.</p>
            </div>
            <?php if ($q !== ''): ?>
                <div class="text-body-secondary small d-flex align-items-center flex-wrap gap-2">
                    <span><?= number_format($searchResult['total_count']) ?> matching result<?= $searchResult['total_count'] === 1 ? '' : 's' ?> found</span>
                    <?php if ($activeCompanyId = active_company_id()):
                        $actCompSt = $pdo->prepare('SELECT code, name FROM companies WHERE id = ?');
                        $actCompSt->execute([(int)$activeCompanyId]);
                        $actCompRow = $actCompSt->fetch();
                        if ($actCompRow): ?>
                            <span class="badge badge-soft-info" title="Results scoped to active company">
                                <i class="bi bi-buildings me-1"></i><?= e($actCompRow['name']) ?> (<?= e($actCompRow['code']) ?>)
                            </span>
                        <?php endif;
                    endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search Box Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="get" action="<?= e(url('modules/search/index.php')) ?>" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                <div class="col-12 col-md">
                    <div class="input-group input-group-lg search-input-group">
                        <span class="input-group-text bg-transparent border-end-0 text-muted">
                            <i class="bi bi-search fs-5"></i>
                        </span>
                        <input type="text"
                               name="q"
                               class="form-control border-start-0 ps-0"
                               placeholder="Search..."
                               value="<?= e($q) ?>"
                               maxlength="60"
                               autofocus
                               required>
                        <?php if ($q !== ''): ?>
                            <a href="<?= e(url('modules/search/index.php?tab=' . urlencode($activeTab))) ?>"
                               class="input-group-text bg-transparent border-start-0 text-muted text-decoration-none"
                               title="Clear search">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-lg px-4">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                </div>
            </form>

            <!-- Filters Bar (Collapsible) -->
            <div class="mt-3 pt-3 border-top">
                <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#searchFiltersCollapse"
                        aria-expanded="false"
                        aria-controls="searchFiltersCollapse">
                    <i class="bi bi-funnel"></i>
                    <span>Search Filters</span>
                    <?php
                    $activeFilterCount = count(array_filter($filters, fn($v) => $v !== null && $v !== ''));
                    if ($activeFilterCount > 0): ?>
                        <span class="badge bg-primary rounded-pill ms-1"><?= $activeFilterCount ?></span>
                    <?php endif; ?>
                </button>

                <?php if ($activeFilterCount > 0): ?>
                    <a href="<?= e(url('modules/search/index.php?q=' . urlencode($q) . '&tab=' . urlencode($activeTab))) ?>"
                       class="btn btn-sm btn-link text-decoration-none text-muted ms-2">
                        <i class="bi bi-x-lg me-1"></i>Reset Filters
                    </a>
                <?php endif; ?>

                <div class="collapse <?= $activeFilterCount > 0 ? 'show' : '' ?> mt-3" id="searchFiltersCollapse">
                    <form method="get" action="<?= e(url('modules/search/index.php')) ?>" class="row g-2">
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

                        <!-- Department Filter -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Department</label>
                            <select name="department_id" class="form-select form-select-sm">
                                <option value="">All Departments</option>
                                <?php foreach ($filterDepartments as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"<?= $departmentId === (int)$d['id'] ? ' selected' : '' ?>>
                                        <?= e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Designation Filter -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Designation</label>
                            <select name="designation_id" class="form-select form-select-sm">
                                <option value="">All Designations</option>
                                <?php foreach ($filterDesignations as $des): ?>
                                    <option value="<?= (int)$des['id'] ?>"<?= $designationId === (int)$des['id'] ? ' selected' : '' ?>>
                                        <?= e($des['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Vacancy Filter -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Job Position / Vacancy</label>
                            <select name="vacancy_id" class="form-select form-select-sm">
                                <option value="">All Vacancies</option>
                                <?php foreach ($filterVacancies as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"<?= $vacancyId === (int)$v['id'] ? ' selected' : '' ?>>
                                        <?= e($v['title']) ?> (<?= e($v['vacancy_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Employment Status Filter -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Employment Status</label>
                            <select name="employment_status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <?php foreach (['active', 'probation', 'on_leave', 'transferred', 'resigned', 'terminated', 'retired'] as $es): ?>
                                    <option value="<?= e($es) ?>"<?= $employmentStatus === $es ? ' selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $es)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Recruitment Status Filter -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Recruitment Status</label>
                            <select name="recruitment_status" class="form-select form-select-sm">
                                <option value="">All Pipeline Stages</option>
                                <?php foreach (['applied', 'under_review', 'shortlisted', 'interview_scheduled', 'interviewed', 'selected', 'offered', 'offer_accepted', 'hired', 'rejected'] as $rs): ?>
                                    <option value="<?= e($rs) ?>"<?= $recruitmentStatus === $rs ? ' selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $rs)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Interview Date Range -->
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Interview Date From</label>
                            <input type="date" name="interview_date_from" class="form-control form-control-sm" value="<?= e($interviewDateFrom ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Interview Date To</label>
                            <input type="date" name="interview_date_to" class="form-control form-control-sm" value="<?= e($interviewDateTo ?? '') ?>">
                        </div>

                        <div class="col-12 col-sm-6 col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-secondary w-100">
                                <i class="bi bi-filter me-1"></i>Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($rateLimited): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Rate limit exceeded.</strong> You have performed more than 60 search requests in the last minute. Please wait a few seconds and try again.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($q === ''): ?>
        <!-- Initial Blank State with Helpful Search Tips -->
        <div class="card shadow-sm border-0 text-center py-5 px-3">
            <div class="card-body" style="max-width: 600px; margin: 0 auto;">
                <div class="search-empty-icon mb-3 text-primary opacity-75">
                    <i class="bi bi-search" style="font-size: 3rem;"></i>
                </div>
                <h3 class="h5 fw-bold mb-2">Instant Global Lookup</h3>
                <p class="text-muted mb-4">
                    Type at least 2 characters above to query live records across your permitted subsidiaries.
                </p>
                <div class="row g-3 text-start">
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border bg-body-tertiary h-100">
                            <div class="fw-semibold text-primary mb-1">
                                <i class="bi bi-keyboard me-1"></i>Quick Shortcuts
                            </div>
                            <ul class="small text-muted mb-0 ps-3">
                                <li>Press <kbd>/</kbd> or <kbd>Ctrl+K</kbd> anywhere to focus search</li>
                                <li>Use <kbd>&uarr;</kbd> and <kbd>&darr;</kbd> to navigate live dropdown</li>
                                <li>Press <kbd>Enter</kbd> to view full results</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border bg-body-tertiary h-100">
                            <div class="fw-semibold text-success mb-1">
                                <i class="bi bi-lightbulb me-1"></i>Supported Queries
                            </div>
                            <ul class="small text-muted mb-0 ps-3">
                                <li>Employee Code: <code>AZT-0001</code></li>
                                <li>Candidate Code: <code>CAN-000100</code></li>
                                <li>Applicant name, email or phone</li>
                                <li>Vacancy titles and requisition codes</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Entity Tabs Navigation -->
        <ul class="nav nav-tabs nav-tabs-bordered mb-4 flex-nowrap overflow-x-auto">
            <?php foreach ($tabDefs as $tKey => $tData):
                if ($tData['perm'] !== null) {
                    $perm = $tData['perm'];
                    $hasPerm = ($perm === 'company.view' || $perm === 'user.view')
                        ? (is_group_admin() || user_can($perm))
                        : user_can($perm);
                    if (!$hasPerm) {
                        continue;
                    }
                }
                $isActive = ($activeTab === $tKey);
                $tabCount = 0;
                if ($tKey === 'all') {
                    $tabCount = $searchResult['total_count'];
                } elseif (isset($searchResult['groups'][$tKey])) {
                    $tabCount = $searchResult['groups'][$tKey]['total'];
                }
                $tabParams = array_merge($_GET, ['tab' => $tKey, 'page' => 1]);
                $tabUrl = '?' . http_build_query($tabParams);
            ?>
                <li class="nav-item">
                    <a class="nav-link text-nowrap d-flex align-items-center gap-1.5 <?= $isActive ? 'active' : '' ?>"
                       href="<?= e($tabUrl) ?>">
                        <i class="bi <?= e($tData['icon']) ?>"></i>
                        <span><?= e($tData['label']) ?></span>
                        <span class="badge rounded-pill <?= $isActive ? 'bg-primary text-white' : 'badge-soft-secondary' ?> ms-1">
                            <?= number_format($tabCount) ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($searchResult['total_count'] <= 0): ?>
            <!-- No Results Empty State -->
            <div class="card shadow-sm border-0 text-center py-5 px-3">
                <div class="card-body" style="max-width: 560px; margin: 0 auto;">
                    <div class="mb-3 text-muted">
                        <i class="bi bi-inbox fs-1"></i>
                    </div>
                    <h3 class="h5 fw-bold mb-2">No matching records found</h3>
                    <p class="text-muted small mb-4">
                        We couldn't find any records matching "<strong><?= e($q) ?></strong>" within your authorized subsidiaries and active filters.
                    </p>

                    <?php if (active_company_id() !== null): ?>
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between flex-wrap gap-2 text-start mb-4">
                            <div>
                                <i class="bi bi-info-circle me-1"></i>
                                Search is currently restricted to your active subsidiary.
                            </div>
                            <form method="post" action="<?= e(url('public/switch_company.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="company_id" value="0">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-buildings me-1"></i>Search All Companies
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="card border bg-body-tertiary p-3 text-start small text-muted">
                        <div class="fw-semibold text-body mb-2"><i class="bi bi-info-circle me-1"></i>Suggestions:</div>
                        <ul class="mb-0 ps-3">
                            <li>Double-check spelling or try broader search keywords (minimum 2 characters).</li>
                            <li>Ensure you have selected the appropriate company from the top switcher.</li>
                            <li>Reset any active department, status, or date range filters.</li>
                            <li>Verify your account holds view permissions for the entity you are searching.</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php elseif ($activeTab === 'all'): ?>
            <!-- All Tab: Display Group Sections with Top 5 Preview -->
            <div class="d-flex flex-column gap-4">
                <?php foreach ($searchResult['groups'] as $gKey => $group):
                    if ($group['total'] <= 0) continue;
                    $viewAllParams = array_merge($_GET, ['tab' => $gKey, 'page' => 1]);
                    $viewAllUrl = '?' . http_build_query($viewAllParams);
                ?>
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi <?= e($group['icon']) ?> fs-5 text-primary"></i>
                                <h2 class="h6 mb-0 fw-bold"><?= e($group['label']) ?></h2>
                                <span class="badge badge-soft-primary rounded-pill"><?= number_format($group['total']) ?></span>
                            </div>
                            <?php if ($group['total'] > count($group['items'])): ?>
                                <a href="<?= e($viewAllUrl) ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                                    <span>View all <?= number_format($group['total']) ?></span>
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($group['items'] as $item): ?>
                                <a href="<?= e($item['url']) ?>" class="list-group-item list-group-item-action py-3 px-3 px-md-4 d-flex align-items-center justify-content-between gap-3">
                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                        <div class="search-item-icon-box flex-shrink-0">
                                            <i class="bi <?= e($item['icon']) ?>"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-body text-truncate">
                                                <?= search_highlight($item['title'], $q) ?>
                                            </div>
                                            <div class="small mt-0.5 d-flex align-items-center flex-wrap gap-1">
                                                <?= $item['subtitle'] ?>
                                            </div>
                                        </div>
                                    </div>
                                    <i class="bi bi-chevron-right text-muted flex-shrink-0"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Single Entity Tab with Full 20-per-page Pagination -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= e($tabDefs[$activeTab]['icon']) ?> fs-5 text-primary"></i>
                        <h2 class="h6 mb-0 fw-bold"><?= e($tabDefs[$activeTab]['label']) ?></h2>
                        <span class="badge badge-soft-primary rounded-pill"><?= number_format($activeGroupTotal) ?></span>
                    </div>
                    <div class="small text-muted">
                        Showing <?= count($activeGroupItems) ?> of <?= number_format($activeGroupTotal) ?> records
                    </div>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($activeGroupItems as $item): ?>
                        <a href="<?= e($item['url']) ?>" class="list-group-item list-group-item-action py-3 px-3 px-md-4 d-flex align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="search-item-icon-box flex-shrink-0">
                                    <i class="bi <?= e($item['icon']) ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-body text-truncate">
                                        <?= search_highlight($item['title'], $q) ?>
                                    </div>
                                    <div class="small mt-0.5 d-flex align-items-center flex-wrap gap-1">
                                        <?= $item['subtitle'] ?>
                                    </div>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted flex-shrink-0"></i>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($pagination['pages'] > 1): ?>
                    <div class="card-footer bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2 border-top">
                        <span class="small text-muted">
                            Page <?= (int)$pagination['page'] ?> of <?= (int)$pagination['pages'] ?>
                        </span>
                        <div>
                            <?= render_pagination($pagination) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

