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
$dateFrom     = trim((string)($_GET['date_from'] ?? ''));
$dateTo       = trim((string)($_GET['date_to'] ?? ''));
$searchQ      = trim((string)($_GET['q'] ?? ''));

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

// Support tabbed quick filters as well as explicit status codes
if ($statusFilter === 'pending') {
    $whereClauses[] = "jo.status IN ('draft', 'sent')";
} elseif ($statusFilter === 'ready_to_hire') {
    $whereClauses[] = "jo.status = 'accepted' AND jo.converted_employee_id IS NULL";
} elseif ($statusFilter === 'hired') {
    $whereClauses[] = "jo.converted_employee_id IS NOT NULL";
} elseif ($statusFilter === 'archived') {
    $whereClauses[] = "jo.status IN ('declined', 'expired', 'withdrawn')";
} elseif ($statusFilter !== '' && array_key_exists($statusFilter, offer_status_list())) {
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

// Offer KPI Counters for tab cards
$kpiSql = "SELECT COUNT(*) AS total_offers,
                  SUM(CASE WHEN jo.status = 'draft' THEN 1 ELSE 0 END) AS count_draft,
                  SUM(CASE WHEN jo.status = 'sent' THEN 1 ELSE 0 END) AS count_sent,
                  SUM(CASE WHEN jo.status = 'accepted' AND jo.converted_employee_id IS NULL THEN 1 ELSE 0 END) AS count_ready_to_hire,
                  SUM(CASE WHEN jo.converted_employee_id IS NOT NULL THEN 1 ELSE 0 END) AS count_hired,
                  SUM(CASE WHEN jo.status IN ('declined', 'expired', 'withdrawn') THEN 1 ELSE 0 END) AS count_archived
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
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Job Offers</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="page-header-title mb-0">Job Offers</h1>
                <span class="badge bg-body-secondary text-body border"><?= $totalRecords ?> total offer<?= $totalRecords === 1 ? '' : 's' ?></span>
            </div>
            <p class="text-muted small mb-0 mt-1">Manage offer letters, track candidate acceptances, and onboard newly hired employees into active staff.</p>
        </div>
        <div class="page-actions"></div>
    </div>

    <!-- Interactive KPI Status Filter Cards (5-Column Layout) -->
    <?php
    $cIdParam = $companyFilter > 0 ? '&company_id=' . $companyFilter : '';
    ?>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-5 g-3 mb-4">
        <!-- Tab 1: All Offers -->
        <div class="col">
            <a href="<?= e(url('modules/offers/index.php?status=' . $cIdParam)) ?>" class="card text-decoration-none border shadow-sm h-100 transition-all <?= ($statusFilter === '' || $statusFilter === 'all') ? 'border-primary shadow bg-primary-subtle' : 'bg-body' ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-uppercase fw-semibold <?= ($statusFilter === '' || $statusFilter === 'all') ? 'text-primary' : 'text-muted' ?>">All Offers</span>
                        <i class="bi bi-collection <?= ($statusFilter === '' || $statusFilter === 'all') ? 'text-primary' : 'text-secondary' ?>"></i>
                    </div>
                    <div class="h3 mb-0 fw-bold <?= ($statusFilter === '' || $statusFilter === 'all') ? 'text-primary' : 'text-body' ?>">
                        <?= (int)($kpiData['total_offers'] ?? 0) ?>
                    </div>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Full offer register</div>
                </div>
            </a>
        </div>

        <!-- Tab 2: Drafts -->
        <div class="col">
            <a href="<?= e(url('modules/offers/index.php?status=draft' . $cIdParam)) ?>" class="card text-decoration-none border shadow-sm h-100 transition-all <?= ($statusFilter === 'draft') ? 'border-secondary shadow bg-secondary-subtle' : 'bg-body' ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-uppercase fw-semibold <?= ($statusFilter === 'draft') ? 'text-secondary-emphasis' : 'text-muted' ?>">Drafts</span>
                        <i class="bi bi-pencil-square text-secondary"></i>
                    </div>
                    <div class="h3 mb-0 fw-bold <?= ($statusFilter === 'draft') ? 'text-secondary-emphasis' : 'text-body' ?>">
                        <?= (int)($kpiData['count_draft'] ?? 0) ?>
                    </div>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Under HR review</div>
                </div>
            </a>
        </div>

        <!-- Tab 3: Sent & Pending -->
        <div class="col">
            <a href="<?= e(url('modules/offers/index.php?status=sent' . $cIdParam)) ?>" class="card text-decoration-none border shadow-sm h-100 transition-all <?= ($statusFilter === 'sent') ? 'border-info shadow bg-info-subtle' : 'bg-body' ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-uppercase fw-semibold <?= ($statusFilter === 'sent') ? 'text-info-emphasis' : 'text-muted' ?>">Sent</span>
                        <i class="bi bi-send text-info"></i>
                    </div>
                    <div class="h3 mb-0 fw-bold <?= ($statusFilter === 'sent') ? 'text-info-emphasis' : 'text-info' ?>">
                        <?= (int)($kpiData['count_sent'] ?? 0) ?>
                    </div>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Awaiting candidate reply</div>
                </div>
            </a>
        </div>

        <!-- Tab 4: Ready to Onboard (Accepted but unconverted) -->
        <?php $readyCount = (int)($kpiData['count_ready_to_hire'] ?? 0); ?>
        <div class="col">
            <a href="<?= e(url('modules/offers/index.php?status=ready_to_hire' . $cIdParam)) ?>" class="card text-decoration-none border shadow-sm h-100 transition-all <?= ($statusFilter === 'ready_to_hire') ? 'border-success shadow bg-success-subtle' : ($readyCount > 0 ? 'border-success' : 'bg-body') ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-uppercase fw-semibold <?= ($statusFilter === 'ready_to_hire' || $readyCount > 0) ? 'text-success' : 'text-muted' ?>">Ready to Hire</span>
                        <i class="bi bi-person-check-fill text-success"></i>
                    </div>
                    <div class="h3 mb-0 fw-bold text-success">
                        <?= $readyCount ?>
                        <?php if ($readyCount > 0): ?>
                            <span class="badge bg-success text-white fs-6 ms-1" style="font-size:0.7rem !important;">Action</span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Accepted &bull; Convert now</div>
                </div>
            </a>
        </div>

        <!-- Tab 5: Hired Staff -->
        <div class="col">
            <a href="<?= e(url('modules/offers/index.php?status=hired' . $cIdParam)) ?>" class="card text-decoration-none border shadow-sm h-100 transition-all <?= ($statusFilter === 'hired') ? 'border-primary shadow bg-primary-subtle' : 'bg-body' ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small text-uppercase fw-semibold <?= ($statusFilter === 'hired') ? 'text-primary' : 'text-muted' ?>">Hired</span>
                        <i class="bi bi-person-badge text-primary"></i>
                    </div>
                    <div class="h3 mb-0 fw-bold <?= ($statusFilter === 'hired') ? 'text-primary' : 'text-body' ?>">
                        <?= (int)($kpiData['count_hired'] ?? 0) ?>
                    </div>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Converted to employee</div>
                </div>
            </a>
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

                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-semibold">Status Category</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="ready_to_hire" <?= $statusFilter === 'ready_to_hire' ? 'selected' : '' ?>>⭐ Ready to Onboard (Accepted &amp; Unconverted)</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Action (Draft &amp; Sent)</option>
                        <option value="hired" <?= $statusFilter === 'hired' ? 'selected' : '' ?>>Hired (Converted to Employee)</option>
                        <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="accepted" <?= $statusFilter === 'accepted' ? 'selected' : '' ?>>Accepted (All)</option>
                        <option value="declined" <?= $statusFilter === 'declined' ? 'selected' : '' ?>>Declined</option>
                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="withdrawn" <?= $statusFilter === 'withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
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

                <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                    <?php if ($companyFilter > 0 || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $searchQ !== ''): ?>
                        <a href="<?= e(url('modules/offers/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i> Reset Filters
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-primary px-3">
                        <i class="bi bi-funnel me-1"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Offers Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fs-6 fw-bold">Job Offers Directory</h5>
                <small class="text-muted d-block mt-0.5">
                    <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any offer row to view full offer details.
                </small>
            </div>
            <span class="badge bg-body-secondary text-body border"><?= $totalRecords ?> record<?= $totalRecords === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 offers-table">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3" style="min-width: 200px;">Offer &amp; Candidate</th>
                            <th style="min-width: 180px;">Company &amp; Vacancy</th>
                            <th style="min-width: 150px;">Role</th>
                            <th style="min-width: 120px;">Offered Salary</th>
                            <th style="min-width: 140px;">Status</th>
                            <th class="text-end pe-3 action-col" style="min-width: 190px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($offers)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-file-earmark-x fs-1 d-block mb-2 text-secondary"></i>
                                    <div class="fw-semibold">No job offers found matching your criteria.</div>
                                    <small>Try selecting a different filter or reset all filters above.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($offers as $o): ?>
                                <?php
                                $offerCompanyId = (int)$o['company_id'];
                                $canViewSalary  = user_can('salary.view', $offerCompanyId);
                                $canManageOffer = user_can('offer.manage', $offerCompanyId);
                                $isAccepted     = ((string)$o['status'] === 'accepted');
                                $isHired        = !empty($o['converted_employee_id']);
                                $isReadyToHire  = ($isAccepted && !$isHired);
                                $isExpiredDate  = (!empty($o['expiry_date']) && $o['expiry_date'] < date('Y-m-d') && in_array($o['status'], ['draft', 'sent'], true));
                                $viewUrl        = url('modules/offers/view.php?id=' . (int)$o['id']);
                                ?>
                                <tr class="<?= $isReadyToHire ? 'table-success-subtle' : '' ?> offer-row"
                                    data-href="<?= e($viewUrl) ?>"
                                    title="Double-click to view offer #<?= (int)$o['id'] ?>">
                                    <!-- Offer & Candidate -->
                                    <td class="ps-3 offer-candidate-cell">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-body-secondary text-body border font-monospace" title="Offer ID">#<?= (int)$o['id'] ?></span>
                                            <div>
                                                <a href="<?= e($viewUrl) ?>" class="fw-bold text-decoration-none text-body offer-title-link">
                                                    <?= e($o['first_name'] . ' ' . $o['last_name']) ?>
                                                </a>
                                                <div class="small text-muted font-monospace"><?= e($o['candidate_code']) ?></div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Company & Vacancy -->
                                    <td>
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <span class="badge bg-body-secondary text-body border font-monospace"><?= e($o['company_code']) ?></span>
                                            <a href="<?= e($viewUrl) ?>" class="text-decoration-none fw-semibold text-body">
                                                <?= e($o['vacancy_title']) ?>
                                            </a>
                                        </div>
                                    </td>

                                    <!-- Role -->
                                    <td>
                                        <div class="fw-semibold text-body"><?= e($o['designation_title'] ?? 'General Role') ?></div>
                                    </td>

                                    <!-- Offered Salary -->
                                    <td>
                                        <?php if ($canViewSalary): ?>
                                            <?php if ($o['offered_salary'] !== null): ?>
                                                <div class="fw-bold text-success"><?= e(format_currency((float)$o['offered_salary'])) ?></div>
                                                <small class="text-muted">/ month</small>
                                            <?php else: ?>
                                                <span class="text-muted small">Not specified</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic"><i class="bi bi-shield-lock me-1"></i>Confidential</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Badge -->
                                    <td>
                                        <?php if ($isReadyToHire): ?>
                                            <span class="badge text-bg-success shadow-sm">
                                                <i class="bi bi-person-check-fill me-1"></i>Ready to Onboard
                                            </span>
                                            <div class="small text-success mt-1" style="font-size:0.75rem;">
                                                <i class="bi bi-clock-history me-1"></i>Awaiting Conversion
                                            </div>
                                        <?php elseif ($isHired): ?>
                                            <span class="badge text-bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-check2-all me-1"></i>Hired
                                            </span>
                                            <div class="mt-1">
                                                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$o['converted_employee_id'])) ?>" class="badge bg-body-secondary text-body border text-decoration-none font-monospace" title="View Employee Profile">
                                                    <?= e($o['converted_emp_code'] ?? 'EMP') ?> &rarr;
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <?= offer_status_badge($o['status']) ?>
                                            <?php if ($isExpiredDate): ?>
                                                <div class="badge text-bg-danger mt-1" style="font-size:0.7rem;">Expired Date</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Contextual Primary Action & Secondary Kebab -->
                                    <td class="text-end pe-3 action-col">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="<?= e($viewUrl) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5 text-nowrap" title="View full offer details">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>

                                            <!-- 3-Dot Action Kebab Menu -->
                                            <?= action_menu([
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'View Full Offer Details',
                                                    'url'        => 'modules/offers/view.php?id=' . (int)$o['id'],
                                                    'icon'       => 'bi-eye',
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Candidate Profile',
                                                    'url'        => 'modules/candidates/view.php?id=' . (int)$o['candidate_id'],
                                                    'icon'       => 'bi-person',
                                                    'permission' => user_can('candidate.view'),
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Vacancy Pipeline Board',
                                                    'url'        => 'modules/vacancies/pipeline.php?id=' . (int)$o['vacancy_id'],
                                                    'icon'       => 'bi-kanban',
                                                    'permission' => user_can('vacancy.view'),
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Convert to Employee Record',
                                                    'url'        => 'modules/offers/convert.php?offer_id=' . (int)$o['id'],
                                                    'icon'       => 'bi-person-check',
                                                    'permission' => ($isReadyToHire && $canManageOffer),
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Employee Profile',
                                                    'url'        => 'modules/employees/view.php?id=' . (int)$o['converted_employee_id'],
                                                    'icon'       => 'bi-person-badge',
                                                    'permission' => ($isHired && !empty($o['converted_employee_id']) && user_can('employee.view')),
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
                    Showing <?= $pg['offset'] + 1 ?> to <?= min($pg['offset'] + $pg['per_page'], $totalRecords) ?> of <?= $totalRecords ?> offers
                </span>
                <?= render_pagination($pg) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.offers-table .offer-row {
    cursor: pointer;
    transition: background-color 0.15s ease-in-out;
}
.offers-table .offer-row:hover > td {
    background-color: var(--bs-table-hover-bg);
}
.offers-table .offer-candidate-cell:hover .offer-title-link {
    text-decoration: underline !important;
    color: var(--bs-primary) !important;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
