<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Job offer not found.');
}

$pdo = db();

// Auto-expire old offers before reading
expire_old_offers($pdo);

// Fetch offer with candidate, vacancy, company, department, designation, and converted employee
$st = $pdo->prepare(
    "SELECT jo.*,
            comp.id AS comp_id, comp.name AS company_name, comp.code AS company_code, comp.logo_path AS company_logo,
            ca.id AS app_id, ca.status AS app_status, ca.application_date,
            c.id AS candidate_id, c.candidate_code, c.first_name, c.last_name, c.email, c.phone,
            c.nic_passport, c.dob, c.gender, c.address, c.qualifications, c.experience_years,
            v.id AS vacancy_id, v.vacancy_code, v.title AS vacancy_title, v.status AS vacancy_status,
            v.positions_count,
            dept.name AS department_name,
            desig.title AS designation_title,
            u.full_name AS creator_name,
            emp.id AS emp_id, emp.employee_code AS converted_emp_code, emp.first_name AS emp_fname, emp.last_name AS emp_lname
       FROM job_offers jo
       JOIN companies comp ON comp.id = jo.company_id
       JOIN candidate_applications ca ON ca.id = jo.application_id
       JOIN candidates c ON c.id = ca.candidate_id
       JOIN job_vacancies v ON v.id = ca.vacancy_id
       LEFT JOIN departments dept ON dept.id = jo.department_id
       LEFT JOIN designations desig ON desig.id = jo.designation_id
       LEFT JOIN users u ON u.id = jo.created_by
       LEFT JOIN employees emp ON emp.id = jo.converted_employee_id
      WHERE jo.id = ?
      LIMIT 1"
);
$st->execute([$id]);
$offer = $st->fetch();

if (!$offer) {
    abort(404, 'Job offer not found.');
}

$companyId = (int)$offer['company_id'];
require_company_access($companyId, 'offer.view');

$canManage = user_can('offer.manage', $companyId);
$canSalary = user_can('salary.view', $companyId);

// Check if an existing employee with candidate email or NIC already exists
$sameCompEmployee = null;
$otherCompActiveEmployee = null;
$otherCompPrevEmployee = null;
$candEmail = trim((string)($offer['email'] ?? ''));
$candNic   = trim((string)($offer['nic_passport'] ?? ''));

if (empty($offer['converted_employee_id']) && ($candEmail !== '' || $candNic !== '')) {
    $where = [];
    $params = [$companyId];
    if ($candEmail !== '') {
        $where[] = 'email = ?';
        $params[] = $candEmail;
    }
    if ($candNic !== '') {
        $where[] = 'nic_passport = ?';
        $params[] = $candNic;
    }
    $whereSql = '(' . implode(' OR ', $where) . ')';

    // Same company
    $sameSt = $pdo->prepare("SELECT id, employee_code, first_name, last_name, company_id, employment_status FROM employees WHERE company_id = ? AND {$whereSql} LIMIT 1");
    $sameSt->execute($params);
    $sameCompEmployee = $sameSt->fetch() ?: null;

    // Cross-company
    $otherSt = $pdo->prepare("SELECT id, employee_code, first_name, last_name, company_id, employment_status FROM employees WHERE company_id != ? AND {$whereSql} ORDER BY id DESC");
    $otherSt->execute($params);
    $otherEmps = $otherSt->fetchAll();

    foreach ($otherEmps as $oe) {
        if (in_array($oe['employment_status'], ['active', 'on_leave'], true)) {
            if ($otherCompActiveEmployee === null) {
                $otherCompActiveEmployee = $oe;
            }
        } else {
            if ($otherCompPrevEmployee === null) {
                $otherCompPrevEmployee = $oe;
            }
        }
    }
}

// Fetch candidate CV document
$cvSt = $pdo->prepare("SELECT id, original_name, stored_name FROM candidate_documents WHERE candidate_id = ? AND doc_type = 'cv' ORDER BY id DESC LIMIT 1");
$cvSt->execute([(int)$offer['candidate_id']]);
$cvDoc = $cvSt->fetch();

$empTypes = employment_type_list();
$status = (string)$offer['status'];
$isExpired = (!empty($offer['expiry_date']) && $offer['expiry_date'] < date('Y-m-d'));
$isHired = !empty($offer['converted_employee_id']);
$isReadyToHire = ($status === 'accepted' && !$isHired);

$availableUsers = ($status === 'accepted' && empty($offer['converted_employee_id']))
    ? get_available_users_for_company($pdo, $companyId)
    : [];

$chkOfferDel = check_offer_deletable($id, $pdo);
$canDeleteOffer = $chkOfferDel['can_delete'] && is_group_admin() && user_can('offer.delete');

$pageTitle = 'Offer #' . $offer['id'] . ': ' . $offer['first_name'] . ' ' . $offer['last_name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Job Offers</a></li>
            <li class="breadcrumb-item active" aria-current="page">Offer #<?= (int)$offer['id'] ?></li>
        </ol>
    </nav>

    <!-- Detail Header -->
    <div class="detail-header">
        <div class="detail-header-main">
            <div class="detail-header-title-row">
                <h1 class="detail-header-title">Offer #<?= (int)$offer['id'] ?>: <?= e($offer['first_name'] . ' ' . $offer['last_name']) ?></h1>
                <?php if ($isReadyToHire): ?>
                    <span class="badge text-bg-success shadow-sm fs-6"><i class="bi bi-person-check-fill me-1"></i>Ready to Onboard</span>
                <?php elseif ($isHired): ?>
                    <span class="badge text-bg-success-subtle text-success border border-success-subtle fs-6"><i class="bi bi-check2-all me-1"></i>Hired</span>
                <?php else: ?>
                    <?= offer_status_badge($status) ?>
                <?php endif; ?>

                <?php if ($isExpired && in_array($status, ['draft', 'sent'], true)): ?>
                    <span class="badge text-bg-danger">Expired</span>
                <?php endif; ?>
            </div>
            <p class="detail-header-subtitle">
                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($offer['company_code']) ?></span> <?= e($offer['company_name']) ?> &bull; Vacancy: <strong><?= e($offer['vacancy_title']) ?></strong>
            </p>
        </div>

        <div class="detail-header-actions">
            <!-- ONE Primary Button by State -->
            <?php if ($status === 'draft' && $canManage): ?>
                <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>" class="d-inline" data-confirm="Confirm marking this offer as sent to candidate?" data-confirm-type="primary">
                    <?= csrf_field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                    <input type="hidden" name="action" value="send">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1"></i> Send offer
                    </button>
                </form>
            <?php elseif ($status === 'sent' && $canManage): ?>
                <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>" class="d-inline" data-confirm="Record that the candidate has accepted this offer?" data-confirm-type="success">
                    <?= csrf_field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                    <input type="hidden" name="action" value="accept">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check-circle me-1"></i> Mark accepted
                    </button>
                </form>
            <?php elseif ($isReadyToHire && $canManage): ?>
                <a href="#convert" class="btn btn-success btn-sm">
                    <i class="bi bi-person-check-fill me-1"></i> Convert to employee
                </a>
            <?php elseif ($isHired && !empty($offer['converted_employee_id'])): ?>
                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$offer['converted_employee_id'])) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-person-badge me-1"></i> View Employee Profile
                </a>
            <?php endif; ?>

            <!-- ONE "More" 3-dot menu for secondary actions -->
            <?php
            $moreActions = [];
            if ($status === 'sent' && $canManage) {
                $moreActions[] = [
                    'type' => 'button',
                    'icon' => 'bi-hand-thumbs-down',
                    'label' => 'Record Candidate Decline',
                    'modal' => '#declineModal',
                ];
            }
            if (in_array($status, ['draft', 'sent'], true) && $canManage) {
                $moreActions[] = [
                    'type' => 'button',
                    'icon' => 'bi-x-circle',
                    'label' => 'Withdraw Offer',
                    'modal' => '#withdrawModal',
                ];
            }
            $moreActions[] = [
                'type' => 'link',
                'icon' => 'bi-arrow-left',
                'label' => 'Back to Offers',
                'url' => 'modules/offers/index.php',
            ];
            if ($canDeleteOffer) {
                $moreActions[] = [
                    'type' => 'button',
                    'icon' => 'bi-trash',
                    'label' => 'Delete Offer',
                    'onclick' => 'confirmDeleteOffer(' . (int)$offer['id'] . ')',
                    'danger' => true,
                ];
            }
            echo action_menu($moreActions, [
                'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
                'aria_label' => 'More offer actions',
            ]);
            ?>
        </div>
    </div>

    <!-- Duplicate Employee Warning (if exists) -->
    <?php if ($sameCompEmployee): ?>
        <div class="alert alert-danger d-flex align-items-center justify-content-between mb-3 border-danger shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-5 text-danger flex-shrink-0"></i>
                <div class="small">
                    <strong>Duplicate Employee in Same Company:</strong>
                    An employee with this email or NIC already exists: <span class="fw-semibold text-body"><?= e($sameCompEmployee['first_name'] . ' ' . $sameCompEmployee['last_name']) ?> (<?= e($sameCompEmployee['employee_code']) ?>)</span>. Conversion is blocked.
                </div>
            </div>
            <?php if (user_can('employee.view', $companyId)): ?>
                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$sameCompEmployee['id'])) ?>" class="btn btn-sm btn-outline-danger text-nowrap ms-2">
                    View Existing Profile
                </a>
            <?php endif; ?>
        </div>
    <?php elseif ($otherCompActiveEmployee): ?>
        <?php $canViewOther = user_can('employee.view', (int)$otherCompActiveEmployee['company_id']); ?>
        <div class="alert alert-danger d-flex align-items-center justify-content-between mb-3 border-danger shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-5 text-danger flex-shrink-0"></i>
                <div class="small">
                    <strong>Active Employee in Another Company:</strong>
                    An active employee record with the same email or NIC exists in another AaZa company. Conversion is blocked.
                </div>
            </div>
            <?php if ($canViewOther): ?>
                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$otherCompActiveEmployee['id'])) ?>" class="btn btn-sm btn-outline-danger text-nowrap ms-2">
                    View Existing Profile
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Compact Single-Line Onboarding Status Note (Hired state, no button) -->
    <?php if ($isHired): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 mb-3 border-success-subtle shadow-xs">
            <i class="bi bi-check-circle-fill text-success fs-5 flex-shrink-0"></i>
            <div class="small">
                <strong>Employee Onboarding Complete:</strong> Successfully hired into <strong><?= e($offer['company_name']) ?></strong> as
                <span class="badge text-bg-success-subtle text-success border border-success-subtle font-monospace mx-1"><?= e($offer['converted_emp_code']) ?></span>
                &bull; <?= e($offer['emp_fname'] . ' ' . $offer['emp_lname']) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Body: 2 columns (col-lg-8 main, col-lg-4 side), align-items-start -->
    <div class="row g-3 align-items-start">
        <!-- Main Column: Stage Tracker, Offer Terms, Onboard Form -->
        <div class="col-lg-8">
            <!-- Slim Stage Tracker -->
            <div class="stage-tracker-slim">
                <?php
                $isDraftActive = ($status === 'draft');
                $isDraftDone = in_array($status, ['sent', 'accepted', 'declined', 'expired'], true) || $isHired;
                $isSentActive = ($status === 'sent');
                $isSentDone = in_array($status, ['accepted'], true) || $isHired;
                $isAcceptedActive = ($status === 'accepted' && !$isHired);
                $isAcceptedDone = $isHired;
                $isHiredActive = $isHired;
                ?>
                <span class="stage-step <?= $isDraftActive ? 'active' : ($isDraftDone ? 'completed' : '') ?>">
                    <i class="bi <?= $isDraftDone ? 'bi-check-circle-fill text-success' : ($isDraftActive ? 'bi-dot' : 'bi-circle') ?>"></i>
                    <span>1. Draft</span>
                </span>
                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                <span class="stage-step <?= $isSentActive ? 'active' : ($isSentDone ? 'completed' : '') ?>">
                    <i class="bi <?= $isSentDone ? 'bi-check-circle-fill text-success' : ($isSentActive ? 'bi-dot' : 'bi-circle') ?>"></i>
                    <span>2. Sent</span>
                </span>
                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                <?php if ($status === 'declined'): ?>
                    <span class="stage-step active text-danger border-danger-subtle bg-danger-subtle">
                        <i class="bi bi-x-circle-fill text-danger"></i>
                        <span>3. Declined</span>
                    </span>
                <?php elseif ($status === 'withdrawn'): ?>
                    <span class="stage-step active text-warning-emphasis border-warning-subtle bg-warning-subtle">
                        <i class="bi bi-dash-circle-fill text-warning"></i>
                        <span>3. Withdrawn</span>
                    </span>
                <?php else: ?>
                    <span class="stage-step <?= $isAcceptedActive ? 'active' : ($isAcceptedDone ? 'completed' : '') ?>">
                        <i class="bi <?= $isAcceptedDone ? 'bi-check-circle-fill text-success' : ($isAcceptedActive ? 'bi-dot' : 'bi-circle') ?>"></i>
                        <span>3. Decision</span>
                    </span>
                <?php endif; ?>
                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                <span class="stage-step <?= $isHiredActive ? 'active completed' : '' ?>">
                    <i class="bi <?= $isHiredActive ? 'bi-check2-all text-success' : 'bi-circle' ?>"></i>
                    <span>4. Hired</span>
                </span>
            </div>

            <!-- Offer Terms & Appointment Details (.kv-list) -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Offer Terms &amp; Appointment Details</span>
                    <span class="badge bg-body-secondary text-body border font-monospace">Offer Ref #<?= (int)$offer['id'] ?></span>
                </div>
                <div class="card-body">
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-label">Company</span>
                            <div class="kv-value d-flex align-items-center gap-2">
                                <?= company_logo_html(['logo_path' => $offer['company_logo'], 'name' => $offer['company_name'], 'code' => $offer['company_code']], $offer['company_name'], 'sm') ?>
                                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($offer['company_code']) ?></span>
                                <span><?= e($offer['company_name']) ?></span>
                            </div>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Department</span>
                            <span class="kv-value"><?= e($offer['department_name'] ?? 'General Department') ?></span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Offered Designation</span>
                            <span class="kv-value fw-semibold"><?= e($offer['designation_title'] ?? 'Not specified') ?></span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Employment Type</span>
                            <span class="kv-value">
                                <span class="badge bg-body-secondary text-body border"><?= e($empTypes[$offer['employment_type']] ?? ucfirst($offer['employment_type'])) ?></span>
                            </span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Proposed Joining Date</span>
                            <span class="kv-value">
                                <?= !empty($offer['proposed_join_date']) ? '<i class="bi bi-calendar-event text-primary me-1"></i>' . e(format_date($offer['proposed_join_date'])) : '—' ?>
                            </span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Offer Issue Date</span>
                            <span class="kv-value">
                                <i class="bi bi-calendar3 text-muted me-1"></i><?= e(format_date($offer['offer_date'])) ?>
                            </span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Offer Expiry Date</span>
                            <span class="kv-value">
                                <?php if (!empty($offer['expiry_date'])): ?>
                                    <i class="bi bi-clock-history text-muted me-1"></i><?= e(format_date($offer['expiry_date'])) ?>
                                    <?php if ($offer['expiry_date'] < date('Y-m-d')): ?>
                                        <span class="badge text-bg-danger ms-1">Expired</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Response Timestamp</span>
                            <span class="kv-value">
                                <?= !empty($offer['responded_at']) ? e(format_datetime($offer['responded_at'])) : '<span class="text-muted">Pending candidate response</span>' ?>
                            </span>
                        </div>

                        <!-- Offered Salary (salary.view permission check) -->
                        <div class="kv-item full-width pt-2 border-top">
                            <span class="kv-label">Offered Basic Salary</span>
                            <?php if ($canSalary): ?>
                                <div class="kv-value text-success fw-bold fs-5 mt-1">
                                    <?php if ($offer['offered_salary'] !== null): ?>
                                        <?= e(format_currency((float)$offer['offered_salary'])) ?>
                                        <small class="text-muted fs-6 fw-normal">/ month</small>
                                    <?php else: ?>
                                        <span class="text-muted fs-6 fw-normal">Not specified</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="kv-value text-muted fst-italic">
                                    <i class="bi bi-shield-lock me-1"></i>Confidential (salary.view permission required)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-3 mt-3 border-top small text-muted d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>Created by: <strong class="text-body"><?= e($offer['creator_name'] ?? 'System') ?></strong> on <?= e(format_datetime($offer['created_at'])) ?></div>
                        <div>Offer ID: <code>#<?= (int)$offer['id'] ?></code></div>
                    </div>
                </div>
            </div>

            <!-- In-Page Candidate Onboarding / Employee Conversion Form -->
            <?php if ($isReadyToHire): ?>
                <div class="detail-card border-start border-4 border-success" id="convert">
                    <div class="card-header text-success">
                        <span><i class="bi bi-person-check-fill me-2"></i>Onboard Candidate as Active Employee</span>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">
                            The candidate has formally accepted this offer. Completing onboarding creates an employee record in <strong><?= e($offer['company_name']) ?></strong> and transfers their details.
                        </p>

                        <div class="bg-body-tertiary p-3 rounded border mb-3">
                            <div class="row g-2 small">
                                <div class="col-sm-6">
                                    <span class="text-muted">Candidate:</span> <strong><?= e($offer['first_name'] . ' ' . $offer['last_name']) ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">Target Company:</span> <strong><?= e($offer['company_name']) ?> (<?= e($offer['company_code']) ?>)</strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">Designation:</span> <strong><?= e($offer['designation_title'] ?? 'General Role') ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">Department:</span> <strong><?= e($offer['department_name'] ?? 'General Dept') ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">Joining Date:</span> <strong><?= !empty($offer['proposed_join_date']) ? e(format_date($offer['proposed_join_date'])) : date('Y-m-d') ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">CV / Resume:</span> <strong><?= $cvDoc ? 'Auto-transferred' : 'None uploaded' ?></strong>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <form method="post" action="<?= e(url('modules/offers/convert.php')) ?>" id="convertSection">
                                <?= csrf_field() ?>
                                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">

                                <!-- Clear Login Account Card -->
                                <div class="bg-body-tertiary border rounded p-3 mb-3">
                                    <div class="fw-semibold text-body small mb-1">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> System Login Account (Optional)
                                    </div>
                                    <p class="small text-muted mb-2">
                                        An employee does not need a login account right away. You can invite or assign a login account at any time in <strong>Administration &gt; Users</strong>.
                                    </p>
                                    <?php if (!empty($availableUsers)): ?>
                                        <label class="form-label small fw-semibold" for="convertUserId">Link existing user account now:</label>
                                        <select class="form-select form-select-sm" id="convertUserId" name="user_id">
                                            <option value="0">-- None (Do not link a user account now) --</option>
                                            <?php foreach ($availableUsers as $u): ?>
                                                <option value="<?= (int)$u['id'] ?>">
                                                    <?= e($u['full_name']) ?> (<?= e($u['username']) ?><?= !empty($u['email']) ? ' &bull; ' . e($u['email']) : '' ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="user_id" value="0">
                                        <div class="alert alert-light border small text-muted mb-0 py-2 d-flex align-items-center gap-2">
                                            <i class="bi bi-info-circle text-primary fs-5"></i>
                                            <div>
                                                <strong>No unlinked user accounts currently available.</strong><br>
                                                The employee will be created with <strong>Unlinked Login</strong>.
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm" data-confirm="Confirm converting candidate to active employee?" data-confirm-type="success" data-confirm-title="Convert Candidate to Employee" data-confirm-btn="Convert &amp; Onboard">
                                    <i class="bi bi-person-check-fill me-1"></i> Confirm &amp; Create Employee Record
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="text-muted small fst-italic">You do not have <code>offer.manage</code> permission to convert this candidate.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Side Column: Candidate Profile & Job Vacancy -->
        <div class="col-lg-4">
            <!-- Candidate Profile Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Candidate Profile</span>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($offer['candidate_code']) ?></span>
                </div>
                <div class="card-body">
                    <div class="kv-list kv-list-single">
                        <div class="kv-item">
                            <span class="kv-label">Full Name</span>
                            <span class="kv-value fw-bold"><?= e($offer['first_name'] . ' ' . $offer['last_name']) ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Email Address</span>
                            <span class="kv-value"><?= e($offer['email'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Phone Number</span>
                            <span class="kv-value"><?= e($offer['phone'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">NIC / Passport</span>
                            <span class="kv-value font-monospace"><?= e($offer['nic_passport'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Date of Birth / Gender</span>
                            <span class="kv-value">
                                <?= !empty($offer['dob']) ? e(format_date($offer['dob'])) : '—' ?>
                                &bull;
                                <?= !empty($offer['gender']) ? e(ucfirst($offer['gender'])) : '—' ?>
                            </span>
                        </div>
                        <?php if ($cvDoc): ?>
                            <div class="kv-item pt-2 border-top">
                                <span class="kv-label">Resume / CV</span>
                                <div class="kv-value mt-1">
                                    <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$cvDoc['id'])) ?>" class="btn btn-sm btn-outline-secondary w-100 text-truncate" title="Download CV">
                                        <i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= e($cvDoc['original_name']) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pt-3 mt-3 border-top">
                        <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$offer['candidate_id'])) ?>" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-person-vcard me-1"></i> Full Candidate Record &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Vacancy Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Job Vacancy</span>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($offer['vacancy_code']) ?></span>
                </div>
                <div class="card-body">
                    <div class="kv-list kv-list-single">
                        <div class="kv-item">
                            <span class="kv-label">Position Title</span>
                            <span class="kv-value fw-bold"><?= e($offer['vacancy_title']) ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Target Company</span>
                            <span class="kv-value"><?= e($offer['company_name']) ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Positions Needed</span>
                            <span class="kv-value"><?= (int)$offer['positions_count'] ?> position(s)</span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Vacancy Status</span>
                            <span class="kv-value"><?= vacancy_status_badge($offer['vacancy_status']) ?></span>
                        </div>
                    </div>

                    <div class="pt-3 mt-3 border-top">
                        <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$offer['vacancy_id'])) ?>" class="btn btn-sm btn-outline-info w-100">
                            <i class="bi bi-kanban me-1"></i> Open Pipeline Board &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Withdraw Offer -->
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                <input type="hidden" name="action" value="withdraw">

                <div class="modal-header">
                    <h5 class="modal-title text-danger fs-6 fw-bold" id="withdrawModalLabel"><i class="bi bi-x-circle me-1"></i> Withdraw Job Offer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Are you sure you want to withdraw this offer? Withdrawing will cancel the offer letter and return the candidate application to <strong>Selected</strong> stage.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="withdrawRemarks">Reason / Remarks (Optional)</label>
                        <textarea name="remarks" id="withdrawRemarks" class="form-control form-control-sm" rows="3" placeholder="Explain why the offer is being withdrawn..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger">Confirm Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Decline Offer -->
<div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                <input type="hidden" name="action" value="decline">

                <div class="modal-header">
                    <h5 class="modal-title text-warning-emphasis fs-6 fw-bold" id="declineModalLabel"><i class="bi bi-hand-thumbs-down me-1"></i> Record Candidate Decline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Record that the candidate has declined the offer. This will update the application status to <strong>Offer Declined</strong>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="declineRemarks">Decline Reason (Optional)</label>
                        <textarea name="remarks" id="declineRemarks" class="form-control form-control-sm" rows="3" placeholder="e.g. Accepted another offer, compensation expectations..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning">Confirm Decline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($canDeleteOffer): ?>
<form method="post" action="<?= e(url('modules/offers/delete.php')) ?>" id="deleteOfferForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
</form>
<script>
function confirmDeleteOffer(id) {
    if (typeof showConfirmModal === 'function') {
        showConfirmModal({
            title: 'Delete Job Offer',
            message: 'Are you sure you want to permanently delete this offer letter? Offers can only be deleted if declined, expired, or withdrawn without employee conversion.',
            confirmText: 'Delete Offer',
            variant: 'danger',
            onConfirm: function() {
                document.getElementById('deleteOfferForm').submit();
            }
        });
    } else if (confirm('Are you sure you want to permanently delete this job offer?')) {
        document.getElementById('deleteOfferForm').submit();
    }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
