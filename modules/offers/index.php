<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();

// Auto-expire old offers before listing
expire_old_offers($pdo);

// Access control: User must have 'offer.view' on at least one company
$scopedCompanyIds = accessible_company_ids('offer.view');
if (empty($scopedCompanyIds)) {
    abort(403, 'You do not have permission to view job offers in any company.');
}

// Active company filter
$activeCompany = active_company_id();
if ($activeCompany !== null && in_array($activeCompany, $scopedCompanyIds, true)) {
    $defaultCompany = $activeCompany;
} else {
    $defaultCompany = 0;
}

$companyFilter = (int)($_GET['company_id'] ?? $defaultCompany);
if ($companyFilter > 0 && !in_array($companyFilter, $scopedCompanyIds, true)) {
    abort(403, 'You do not have access to view offers for the selected company.');
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$searchQ = trim((string)($_GET['q'] ?? ''));

// Build query
$whereClauses = [];
$params = [];

if ($companyFilter > 0) {
    $whereClauses[] = 'jo.company_id = ?';
    $params[] = $companyFilter;
} else {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $whereClauses[] = "jo.company_id IN ($ph)";
    $params = array_merge($params, $scopedCompanyIds);
}

if ($statusFilter !== '' && array_key_exists($statusFilter, offer_status_list())) {
    $whereClauses[] = 'jo.status = ?';
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if ($d && $d->format('Y-m-d') === $dateFrom) {
        $whereClauses[] = 'jo.offer_date >= ?';
        $params[] = $dateFrom;
    }
}

if ($dateTo !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $dateTo);
    if ($d && $d->format('Y-m-d') === $dateTo) {
        $whereClauses[] = 'jo.offer_date <= ?';
        $params[] = $dateTo;
    }
}

if ($searchQ !== '') {
    $whereClauses[] = '(c.candidate_code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR v.title LIKE ? OR v.vacancy_code LIKE ?)';
    $like = '%' . $searchQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $whereClauses);

// Count total matching records
$countSql = "SELECT COUNT(*)
               FROM job_offers jo
               JOIN candidate_applications ca ON ca.id = jo.application_id
               JOIN candidates c ON c.id = ca.candidate_id
               JOIN job_vacancies v ON v.id = ca.vacancy_id
              WHERE $whereSql";
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRecords = (int)$countSt->fetchColumn();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$pg = paginate($totalRecords, $page, 15);

// Fetch paginated offers
$listSql = "SELECT jo.*,
                   comp.name AS company_name, comp.code AS company_code,
                   c.id AS candidate_id, c.candidate_code, c.first_name, c.last_name, c.email, c.phone,
                   v.id AS vacancy_id, v.title AS vacancy_title, v.vacancy_code,
                   dept.name AS department_name,
                   desig.title AS designation_title,
                   u.full_name AS creator_name,
                   emp.employee_code AS converted_emp_code
              FROM job_offers jo
              JOIN companies comp ON comp.id = jo.company_id
              JOIN candidate_applications ca ON ca.id = jo.application_id
              JOIN candidates c ON c.id = ca.candidate_id
              JOIN job_vacancies v ON v.id = ca.vacancy_id
              LEFT JOIN departments dept ON dept.id = jo.department_id
              LEFT JOIN designations desig ON desig.id = jo.designation_id
              LEFT JOIN users u ON u.id = jo.created_by
              LEFT JOIN employees emp ON emp.id = jo.converted_employee_id
             WHERE $whereSql
             ORDER BY jo.id DESC
             LIMIT ? OFFSET ?";

$listParams = array_merge($params, [(int)$pg['per_page'], (int)$pg['offset']]);
$listSt = $pdo->prepare($listSql);
$listSt->execute($listParams);
$offers = $listSt->fetchAll();

// Fetch accessible companies for dropdown
$phAcc = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
$compSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($phAcc) ORDER BY name");
$compSt->execute($scopedCompanyIds);
$accessibleCompanies = $compSt->fetchAll();

// Offer KPI Counters for header banner
$kpiSql = "SELECT COUNT(*) AS total_offers,
                  SUM(CASE WHEN jo.status = 'draft' THEN 1 ELSE 0 END) AS count_draft,
                  SUM(CASE WHEN jo.status = 'sent' THEN 1 ELSE 0 END) AS count_sent,
                  SUM(CASE WHEN jo.status = 'accepted' THEN 1 ELSE 0 END) AS count_accepted,
                  SUM(CASE WHEN jo.status = 'declined' THEN 1 ELSE 0 END) AS count_declined,
                  SUM(CASE WHEN jo.status = 'expired' THEN 1 ELSE 0 END) AS count_expired,
                  SUM(CASE WHEN jo.status = 'withdrawn' THEN 1 ELSE 0 END) AS count_withdrawn
             FROM job_offers jo
            WHERE jo.company_id IN ($phAcc)";
$kpiSt = $pdo->prepare($kpiSql);
$kpiSt->execute($scopedCompanyIds);
$kpiData = $kpiSt->fetch() ?: [];

$empTypes = employment_type_list();
$pageTitle = 'Job Offers';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Job Offers</h1>
            <p class="text-muted small mb-0">Manage job offers, track candidate acceptances, and onboard newly hired employees.</p>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Total Offers</div>
                    <div class="h2 mb-0 text-body"><?= (int)($kpiData['total_offers'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Sent &amp; Pending</div>
                    <div class="h2 mb-0 text-info"><?= (int)($kpiData['count_sent'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Accepted</div>
                    <div class="h2 mb-0 text-success"><?= (int)($kpiData['count_accepted'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-secondary">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Drafts</div>
                    <div class="h2 mb-0 text-secondary"><?= (int)($kpiData['count_draft'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="<?= e(url('modules/offers/index.php')) ?>" class="row g-2 align-items-end">
                <?php if (count($accessibleCompanies) > 1): ?>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label small fw-semibold">Company</label>
                        <select name="company_id" class="form-select form-select-sm">
                            <option value="0">All Accessible Companies</option>
                            <?php foreach ($accessibleCompanies as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $companyFilter === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (offer_status_list() as $stKey => $stLbl): ?>
                            <option value="<?= e($stKey) ?>" <?= $statusFilter === $stKey ? 'selected' : '' ?>>
                                <?= e($stLbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Offer Date From</label>
                    <input type="date" name="date_from" class="form-control form-select-sm" value="<?= e($dateFrom) ?>">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Offer Date To</label>
                    <input type="date" name="date_to" class="form-control form-select-sm" value="<?= e($dateTo) ?>">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-semibold">Search Candidate / Vacancy</label>
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Code, Name, Vacancy..." value="<?= e($searchQ) ?>">
                </div>

                <div class="col-md-1 col-sm-6 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <?php if ($companyFilter > 0 || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $searchQ !== ''): ?>
                        <a href="<?= e(url('modules/offers/index.php')) ?>" class="btn btn-sm btn-outline-secondary" title="Clear Filters">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Offers Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Job Offers List</h5>
            <span class="badge bg-body-secondary text-body border"><?= $totalRecords ?> total offer<?= $totalRecords === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Candidate</th>
                            <th>Company &amp; Vacancy</th>
                            <th>Role &amp; Type</th>
                            <th>Offered Salary</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($offers)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-file-earmark-x fs-1 d-block mb-2"></i>
                                    No job offers found matching your filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($offers as $o): ?>
                                <?php
                                $offerCompanyId = (int)$o['company_id'];
                                $canViewSalary = user_can('salary.view', $offerCompanyId);
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold text-body">
                                            <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$o['candidate_id'])) ?>" class="text-decoration-none">
                                                <?= e($o['first_name'] . ' ' . $o['last_name']) ?>
                                            </a>
                                        </div>
                                        <div class="small text-muted">
                                            Code: <span class="fw-medium text-primary"><?= e($o['candidate_code']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge bg-body-secondary text-body border me-1"><?= e($o['company_code']) ?></span>
                                            <a href="<?= e(url('modules/vacancies/view.php?id=' . (int)$o['vacancy_id'])) ?>" class="text-decoration-none fw-medium text-body">
                                                <?= e($o['vacancy_title']) ?>
                                            </a>
                                        </div>
                                        <div class="small text-muted"><?= e($o['vacancy_code']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-body"><?= e($o['designation_title'] ?? 'Unassigned Designation') ?></div>
                                        <div class="small text-muted">
                                            <?= e($o['department_name'] ?? 'General') ?> &bull; <?= e($empTypes[$o['employment_type']] ?? ucfirst($o['employment_type'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($canViewSalary): ?>
                                            <?php if ($o['offered_salary'] !== null): ?>
                                                <span class="fw-semibold text-body"><?= e(format_currency((float)$o['offered_salary'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not specified</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Confidential</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <span class="text-muted">Offered:</span> <?= e(format_date($o['offer_date'])) ?>
                                        </div>
                                        <?php if (!empty($o['expiry_date'])): ?>
                                            <div class="small">
                                                <span class="text-muted">Expiry:</span> <?= e(format_date($o['expiry_date'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($o['proposed_join_date'])): ?>
                                            <div class="small text-muted">
                                                Join: <?= e(format_date($o['proposed_join_date'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= offer_status_badge($o['status']) ?>
                                        <?php if (!empty($o['converted_employee_id'])): ?>
                                            <div class="mt-1">
                                                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$o['converted_employee_id'])) ?>" class="badge text-bg-success-subtle text-success text-decoration-none border border-success-subtle" title="Converted to Employee">
                                                    <i class="bi bi-person-check me-1"></i>Hired (<?= e($o['converted_emp_code'] ?? 'EMP') ?>)
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= e(url('modules/offers/view.php?id=' . (int)$o['id'])) ?>" class="btn btn-outline-primary" title="View Offer Details">
                                                View
                                            </a>
                                            <?php if ($o['status'] === 'accepted' && empty($o['converted_employee_id']) && user_can('offer.manage', $offerCompanyId)): ?>
                                                <a href="<?= e(url('modules/offers/view.php?id=' . (int)$o['id'] . '#convert')) ?>" class="btn btn-success" title="Convert to Employee">
                                                    Convert
                                                </a>
                                            <?php endif; ?>
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
                    Showing <?= $pg['offset'] + 1 ?> to <?= min($pg['offset'] + $pg['per_page'], $totalRecords) ?> of <?= $totalRecords ?> offers
                </span>
                <?= render_pagination($pg) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
