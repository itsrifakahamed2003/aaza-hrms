<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('vacancy.view');

$pdo = db();

// Company scoping
$scopedCompanyIds = scoped_company_ids('vacancy.view');
[$scopedSql, $scopedParams] = company_in_sql($scopedCompanyIds, 'v.company_id');

$whereClauses = [$scopedSql];
$params = $scopedParams;

// Search: code, title
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $whereClauses[] = '(v.vacancy_code LIKE ? OR v.title LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

// Filter: company
$filterCompany = (int)($_GET['company_id'] ?? 0);
if ($filterCompany > 0) {
    if (in_array($filterCompany, $scopedCompanyIds, true)) {
        $whereClauses[] = 'v.company_id = ?';
        $params[] = $filterCompany;
    } else {
        $whereClauses[] = '1 = 0';
    }
}

// Filter: department
$filterDept = (int)($_GET['department_id'] ?? 0);
if ($filterDept > 0) {
    $whereClauses[] = 'v.department_id = ?';
    $params[] = $filterDept;
}

// Filter: status
$filterStatus = trim((string)($_GET['status'] ?? ''));
$statusOptions = vacancy_status_list();
if ($filterStatus !== '' && isset($statusOptions[$filterStatus])) {
    $whereClauses[] = 'v.status = ?';
    $params[] = $filterStatus;
}

// Filter: closing date scope
$filterClosing = trim((string)($_GET['closing'] ?? ''));
if ($filterClosing === 'open_active') {
    $whereClauses[] = '(v.closing_date >= CURDATE() OR v.closing_date IS NULL)';
} elseif ($filterClosing === 'closing_soon') {
    $whereClauses[] = '(v.closing_date >= CURDATE() AND v.closing_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))';
} elseif ($filterClosing === 'expired') {
    $whereClauses[] = '(v.closing_date < CURDATE() AND v.closing_date IS NOT NULL)';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Count total
$countSt = $pdo->prepare("SELECT COUNT(*) FROM job_vacancies v $whereSql");
$countSt->execute($params);
$totalCount = (int)$countSt->fetchColumn();

$pg = paginate($totalCount, (int)($_GET['page'] ?? 1), 15);

// Fetch vacancies with company, department, designation and applicant count
$sql = "SELECT v.*,
               c.code AS company_code, c.name AS company_name,
               d.name AS department_name, des.title AS designation_title,
               COUNT(ca.id) AS applications_count
          FROM job_vacancies v
          JOIN companies c ON c.id = v.company_id
          LEFT JOIN departments d ON d.id = v.department_id
          LEFT JOIN designations des ON des.id = v.designation_id
          LEFT JOIN candidate_applications ca ON ca.vacancy_id = v.id
         $whereSql
         GROUP BY v.id
         ORDER BY (CASE WHEN v.status = 'open' THEN 1 WHEN v.status = 'on_hold' THEN 2 WHEN v.status = 'draft' THEN 3 ELSE 4 END),
                  v.closing_date ASC, v.created_at DESC
         LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";

$st = $pdo->prepare($sql);
$st->execute($params);
$vacancies = $st->fetchAll();

// Accessible companies for dropdown
$filterCompanies = [];
if ($scopedCompanyIds) {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) ORDER BY name");
    $st->execute($scopedCompanyIds);
    $filterCompanies = $st->fetchAll();
}

// Departments for dropdown
$deptQueryIds = ($filterCompany > 0 && in_array($filterCompany, $scopedCompanyIds, true))
    ? [$filterCompany]
    : $scopedCompanyIds;

$filterDepartments = [];
if ($deptQueryIds) {
    $ph = implode(',', array_fill(0, count($deptQueryIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, name FROM departments WHERE company_id IN ($ph) AND is_active = 1 ORDER BY name");
    $st->execute($deptQueryIds);
    $filterDepartments = $st->fetchAll();
}

// KPI stats for the top cards
$statsSt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_vacancies,
        SUM(CASE WHEN v.status = 'open' THEN 1 ELSE 0 END) AS open_vacancies,
        SUM(CASE WHEN v.status = 'open' THEN v.positions_count ELSE 0 END) AS open_positions
       FROM job_vacancies v
      WHERE $scopedSql"
);
$statsSt->execute($scopedParams);
$kpis = $statsSt->fetch() ?: ['total_vacancies' => 0, 'open_vacancies' => 0, 'open_positions' => 0];

// All accessible vacancies for the top Pipeline Board dropdown switcher (open ones first)
$allVacanciesSt = $pdo->prepare("
    SELECT v.id, v.vacancy_code, v.title, v.status,
           COUNT(ca.id) AS applications_count
      FROM job_vacancies v
      LEFT JOIN candidate_applications ca ON ca.vacancy_id = v.id
     WHERE $scopedSql
     GROUP BY v.id
     ORDER BY (CASE WHEN v.status = 'open' THEN 1 WHEN v.status = 'on_hold' THEN 2 WHEN v.status = 'draft' THEN 3 ELSE 4 END),
              v.title ASC
");
$allVacanciesSt->execute($scopedParams);
$accessibleVacancies = $allVacanciesSt->fetchAll();

$types = employment_type_list();
$canCreate = false;
foreach ($scopedCompanyIds as $cid) {
    if (user_can('vacancy.manage', $cid)) {
        $canCreate = true;
        break;
    }
}

$pageTitle = 'Job Vacancies';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Job Vacancies</h1>
            <p class="text-muted small mb-0">Manage job postings, positions count, closing dates, and candidate pipelines.</p>
        </div>
        <div class="page-actions d-flex gap-2 align-items-center flex-wrap">
            <?php if (empty($accessibleVacancies)): ?>
                <button type="button" class="btn btn-outline-secondary" disabled data-bs-toggle="tooltip" title="No vacancies yet">
                    <i class="bi bi-kanban me-1"></i> Pipeline Board
                </button>
            <?php elseif (count($accessibleVacancies) === 1): ?>
                <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$accessibleVacancies[0]['id'])) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-kanban me-1"></i> Pipeline Board
                </a>
            <?php else: ?>
                <div class="dropdown">
                    <button class="btn btn-outline-primary dropdown-toggle" type="button" id="pipelineBoardDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-kanban me-1"></i> Pipeline Board
                    </button>
                    <ul class="dropdown-menu dropdown-menu-start dropdown-menu-md-end shadow-sm" aria-labelledby="pipelineBoardDropdown" style="max-height: 380px; overflow-y: auto; min-width: 280px; max-width: 90vw;">
                        <li class="dropdown-header text-uppercase small">Select Vacancy Board</li>
                        <?php foreach ($accessibleVacancies as $av): ?>
                            <li>
                                <a class="dropdown-item d-flex justify-content-between align-items-center gap-2" href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$av['id'])) ?>">
                                    <span class="text-truncate">
                                        <strong><?= e($av['vacancy_code']) ?></strong> - <?= e($av['title']) ?>
                                    </span>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill flex-shrink-0">
                                        <?= (int)$av['applications_count'] ?> candidate<?= (int)$av['applications_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($canCreate): ?>
                <a href="<?= e(url('modules/vacancies/form.php')) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> New Vacancy
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total Vacancies</div>
                    <div class="h2 mb-0 text-body"><?= (int)($kpis['total_vacancies'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Open Vacancies</div>
                    <div class="h2 mb-0 text-success"><?= (int)($kpis['open_vacancies'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Open Positions to Fill</div>
                    <div class="h2 mb-0 text-primary"><?= (int)($kpis['open_positions'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="<?= e(url('modules/vacancies/index.php')) ?>" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" name="q" class="form-control" placeholder="Search code or title..." value="<?= e($q) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Company</label>
                    <select name="company_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Companies</option>
                        <?php foreach ($filterCompanies as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"<?= $filterCompany === (int)$c['id'] ? ' selected' : '' ?>>
                                <?= e($c['code'] . ' - ' . $c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="0">All Departments</option>
                        <?php foreach ($filterDepartments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"<?= $filterDept === (int)$d['id'] ? ' selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $val => $lbl): ?>
                            <option value="<?= e($val) ?>"<?= $filterStatus === $val ? ' selected' : '' ?>>
                                <?= e($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Closing Date</label>
                    <select name="closing" class="form-select">
                        <option value="">All Dates</option>
                        <option value="open_active"<?= $filterClosing === 'open_active' ? ' selected' : '' ?>>Active / Not Expired</option>
                        <option value="closing_soon"<?= $filterClosing === 'closing_soon' ? ' selected' : '' ?>>Closing Soon (&le; 7 days)</option>
                        <option value="expired"<?= $filterClosing === 'expired' ? ' selected' : '' ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <?php if ($q !== '' || $filterCompany > 0 || $filterDept > 0 || $filterStatus !== '' || $filterClosing !== ''): ?>
                        <a href="<?= e(url('modules/vacancies/index.php')) ?>" class="btn btn-outline-secondary" title="Clear filters">&times;</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Vacancies Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fs-6 fw-bold">Vacancies Directory</h5>
                <small class="text-muted d-block mt-0.5">
                    <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any vacancy row to view full details.
                </small>
            </div>
            <span class="badge bg-body-secondary text-body border"><?= (int)$totalCount ?> vacanc<?= (int)$totalCount === 1 ? 'y' : 'ies' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Code</th>
                            <th>Title & Organization</th>
                            <th>Type & Positions</th>
                            <th>Closing Date</th>
                            <th>Applicants</th>
                            <th>Status</th>
                            <th class="text-end pe-3 action-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vacancies)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    No vacancies found matching your criteria.
                                    <?php if ($canCreate): ?>
                                        <div class="mt-2">
                                            <a href="<?= e(url('modules/vacancies/form.php')) ?>" class="btn btn-sm btn-outline-primary">Post New Vacancy</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vacancies as $v): ?>
                                <?php
                                $isClosingSoon = false;
                                $isExpired = false;
                                if (!empty($v['closing_date'])) {
                                    $today = date('Y-m-d');
                                    if ($v['closing_date'] < $today) {
                                        $isExpired = true;
                                    } elseif ($v['closing_date'] <= date('Y-m-d', strtotime('+7 days'))) {
                                        $isClosingSoon = true;
                                    }
                                }
                                $canManageThis = user_can('vacancy.manage', (int)$v['company_id']);
                                $vacViewUrl = url('modules/vacancies/view.php?id=' . (int)$v['id']);
                                ?>
                                <tr class="clickable-row vacancy-row" data-href="<?= e($vacViewUrl) ?>" title="Double-click to view vacancy <?= e($v['vacancy_code']) ?>">
                                    <td class="ps-3 fw-semibold text-primary">
                                        <a href="<?= e(url('modules/vacancies/view.php?id=' . (int)$v['id'])) ?>" class="text-decoration-none">
                                            <?= e($v['vacancy_code']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-body"><?= e($v['title']) ?></div>
                                        <div class="small text-muted">
                                            <span class="badge bg-body-secondary text-body border me-1"><?= e($v['company_code']) ?></span>
                                            <?= e($v['department_name'] ?? 'No Department') ?>
                                            <?php if (!empty($v['designation_title'])): ?>
                                                &bull; <?= e($v['designation_title']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?= e($types[$v['employment_type']] ?? ucfirst($v['employment_type'])) ?></div>
                                        <small class="text-muted"><?= (int)$v['positions_count'] ?> position<?= (int)$v['positions_count'] === 1 ? '' : 's' ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($v['closing_date'])): ?>
                                            <div class="<?= $isExpired ? 'text-danger fw-semibold' : ($isClosingSoon ? 'text-warning-emphasis fw-semibold' : '') ?>">
                                                <?= e(format_date($v['closing_date'])) ?>
                                            </div>
                                            <?php if ($isExpired && $v['status'] === 'open'): ?>
                                                <span class="badge badge-soft-danger" style="font-size:0.7rem;">Expired</span>
                                            <?php elseif ($isClosingSoon && $v['status'] === 'open'): ?>
                                                <span class="badge badge-soft-warning" style="font-size:0.7rem;">Closing Soon</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">No deadline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body border px-2 py-1" title="Application Count">
                                            <strong><?= (int)$v['applications_count'] ?></strong> candidate<?= (int)$v['applications_count'] === 1 ? '' : 's' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= vacancy_status_badge($v['status']) ?>
                                    </td>
                                    <td class="text-end pe-3 action-col">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="<?= e($vacViewUrl) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5 text-nowrap" title="View vacancy details">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$v['id'])) ?>" class="btn btn-sm btn-primary text-nowrap" title="Open Pipeline Board">
                                                <i class="bi bi-kanban me-1"></i><span class="d-none d-sm-inline">Pipeline Board</span><span class="d-sm-none">Pipeline</span>
                                            </a>
                                            <?= action_menu([
                                                [
                                                    'type'  => 'link',
                                                    'label' => 'View Details',
                                                    'url'   => 'modules/vacancies/view.php?id=' . (int)$v['id'],
                                                    'icon'  => 'bi-eye',
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Edit Vacancy',
                                                    'url'        => 'modules/vacancies/form.php?id=' . (int)$v['id'],
                                                    'icon'       => 'bi-pencil',
                                                    'permission' => (bool)$canManageThis,
                                                ],
                                            ]) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($pg['pages'] > 1): ?>
            <div class="card-footer bg-body d-flex justify-content-between align-items-center py-3">
                <span class="small text-muted">
                    Showing <?= $pg['offset'] + 1 ?> to <?= min($pg['offset'] + $pg['per_page'], $totalCount) ?> of <?= $totalCount ?> vacancies
                </span>
                <?= render_pagination($pg) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
