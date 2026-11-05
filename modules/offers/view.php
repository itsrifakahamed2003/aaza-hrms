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
$availableUsers = ($status === 'accepted' && empty($offer['converted_employee_id']))
    ? get_available_users_for_company($pdo, $companyId)
    : [];

$pageTitle = 'Job Offer #' . $offer['id'] . ' - ' . $offer['first_name'] . ' ' . $offer['last_name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1100px;">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Job Offers</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Offer #<?= (int)$offer['id'] ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <h1 class="h3 mb-0">Job Offer #<?= (int)$offer['id'] ?></h1>
                <?= offer_status_badge($status) ?>
                <?php if ($isExpired && in_array($status, ['draft', 'sent'], true)): ?>
                    <span class="badge text-bg-danger">Expired Date Passed</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$offer['candidate_id'])) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-person me-1"></i> View Candidate
            </a>
            <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$offer['vacancy_id'])) ?>" class="btn btn-outline-primary">
                <i class="bi bi-kanban me-1"></i> Vacancy Pipeline
            </a>
            <?php if (is_group_admin() && user_can('offer.delete')): ?>
                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteOffer(<?= $id ?>)">
                    <i class="bi bi-trash me-1"></i> Delete Offer
                </button>
                <form method="post" action="<?= e(url('modules/offers/delete.php')) ?>" id="deleteOfferForm" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                </form>
                <script>
                function confirmDeleteOffer(id) {
                    if (typeof showConfirmModal === 'function') {
                        showConfirmModal({
                            title: 'Delete Job Offer',
                            message: 'Are you sure you want to permanently delete this offer letter? Offers can only be deleted if declined, expired, or withdrawn without employee conversion. This action cannot be undone.',
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
        </div>
    </div>

    <!-- Duplicate Employee Warning (if exists) -->
    <?php if ($sameCompEmployee): ?>
        <div class="alert alert-danger d-flex align-items-center justify-content-between mb-4 border-danger shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-4 text-danger"></i>
                <div>
                    <strong>Duplicate Employee in Same Company:</strong>
                    <?php if (user_can('employee.view', $companyId)): ?>
                        An employee with this email or NIC already exists: <span class="fw-semibold text-body"><?= e($sameCompEmployee['first_name'] . ' ' . $sameCompEmployee['last_name']) ?> (<?= e($sameCompEmployee['employee_code']) ?>)</span>. Conversion is blocked.
                    <?php else: ?>
                        An employee with this email or NIC already exists in this company. Conversion is blocked.
                    <?php endif; ?>
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
        <div class="alert alert-danger d-flex align-items-center justify-content-between mb-4 border-danger shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-4 text-danger"></i>
                <div>
                    <strong>Active Employee in Another Company:</strong>
                    <?php if ($canViewOther): ?>
                        An active employee record with the same email or NIC exists in another AaZa company: <span class="fw-semibold text-body"><?= e($otherCompActiveEmployee['first_name'] . ' ' . $otherCompActiveEmployee['last_name']) ?> (<?= e($otherCompActiveEmployee['employee_code']) ?>)</span>. Conversion is blocked. Contact Group HR.
                    <?php else: ?>
                        An active employee record with the same email or NIC exists in another AaZa company. Contact Group HR.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($canViewOther): ?>
                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$otherCompActiveEmployee['id'])) ?>" class="btn btn-sm btn-outline-danger text-nowrap ms-2">
                    View Existing Profile
                </a>
            <?php endif; ?>
        </div>
    <?php elseif ($otherCompPrevEmployee && user_can('employee.view', (int)$otherCompPrevEmployee['company_id'])): ?>
        <div class="alert alert-info d-flex align-items-center justify-content-between mb-4 border-info shadow-sm" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-info-circle-fill fs-4 text-info"></i>
                <div>
                    <strong>Previous Employment Record:</strong> A previous record exists in another company (<?= e($otherCompPrevEmployee['employee_code']) ?> - <?= e(ucfirst((string)$otherCompPrevEmployee['employment_status'])) ?>). Converting will create a new employee profile in this company.
                </div>
            </div>
            <a href="<?= e(url('modules/employees/view.php?id=' . (int)$otherCompPrevEmployee['id'])) ?>" class="btn btn-sm btn-outline-info text-nowrap ms-2">
                View Past Profile
            </a>
        </div>
    <?php endif; ?>

    <!-- Action Workflow Banner -->
    <?php if ($canManage): ?>
        <div class="card border-0 shadow-sm mb-4 bg-body-tertiary">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold d-block">Current Offer State</span>
                        <span class="fw-bold fs-6 text-body"><?= e(offer_status_list()[$status] ?? ucfirst($status)) ?></span>
                        <?php if ($status === 'draft'): ?>
                            <span class="text-muted small ms-2">&bull; Review offer details below before sending to the candidate.</span>
                        <?php elseif ($status === 'sent'): ?>
                            <span class="text-muted small ms-2">&bull; Waiting for candidate response.</span>
                        <?php elseif ($status === 'accepted' && empty($offer['converted_employee_id'])): ?>
                            <span class="text-success small ms-2">&bull; Candidate accepted! Ready to onboard and convert to an active employee.</span>
                        <?php elseif (!empty($offer['converted_employee_id'])): ?>
                            <span class="text-success small ms-2">&bull; Candidate hired as <strong><?= e($offer['converted_emp_code']) ?></strong>.</span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <!-- Draft Actions -->
                        <?php if ($status === 'draft'): ?>
                            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                                <input type="hidden" name="action" value="send">
                                <button type="submit" class="btn btn-primary" data-confirm="Confirm sending this job offer to the candidate? This will update the application status to Offered." data-confirm-type="primary">
                                    <i class="bi bi-send me-1"></i> Mark as Sent
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                                <i class="bi bi-x-circle me-1"></i> Withdraw Offer
                            </button>
                        <?php endif; ?>

                        <!-- Sent Actions -->
                        <?php if ($status === 'sent'): ?>
                            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                                <input type="hidden" name="action" value="accept">
                                <button type="submit" class="btn btn-success" data-confirm="Record candidate acceptance of this offer? Application will update to Offer Accepted." data-confirm-type="success">
                                    <i class="bi bi-check-circle me-1"></i> Record Acceptance
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-warning text-body" data-bs-toggle="modal" data-bs-target="#declineModal">
                                <i class="bi bi-hand-thumbs-down me-1"></i> Record Decline
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                                <i class="bi bi-x-circle me-1"></i> Withdraw Offer
                            </button>
                        <?php endif; ?>

                        <!-- Accepted and Ready to Convert -->
                        <?php if ($status === 'accepted' && empty($offer['converted_employee_id'])): ?>
                            <a href="#convertSection" class="btn btn-success px-3">
                                <i class="bi bi-person-check-fill me-1"></i> Convert to Employee
                            </a>
                        <?php endif; ?>

                        <!-- Converted Employee Link -->
                        <?php if (!empty($offer['converted_employee_id'])): ?>
                            <a href="<?= e(url('modules/employees/view.php?id=' . (int)$offer['converted_employee_id'])) ?>" class="btn btn-outline-success">
                                <i class="bi bi-person-badge me-1"></i> View Employee (<?= e($offer['converted_emp_code']) ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Main Offer Terms -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Offer Terms &amp; Appointment Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="text-muted small">Company</div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <?= company_logo_html(['logo_path' => $offer['company_logo'], 'name' => $offer['company_name'], 'code' => $offer['company_code']], $offer['company_name'], 'sm') ?>
                                <span class="badge bg-body-secondary text-body border me-1 font-monospace"><?= e($offer['company_code']) ?></span>
                                <span class="fw-semibold text-body"><?= e($offer['company_name']) ?></span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Department</div>
                            <div class="fw-semibold text-body"><?= e($offer['department_name'] ?? 'Not specified / General') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Offered Designation / Title</div>
                            <div class="fw-semibold text-body"><?= e($offer['designation_title'] ?? 'Not specified') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Employment Type</div>
                            <div class="fw-semibold text-body"><?= e($empTypes[$offer['employment_type']] ?? ucfirst($offer['employment_type'])) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Proposed Joining Date</div>
                            <div class="fw-semibold text-body">
                                <?= !empty($offer['proposed_join_date']) ? e(format_date($offer['proposed_join_date'])) : 'To be confirmed' ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Offer Issue Date</div>
                            <div class="fw-semibold text-body"><?= e(format_date($offer['offer_date'])) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Offer Expiry Date</div>
                            <div class="fw-semibold text-body">
                                <?php if (!empty($offer['expiry_date'])): ?>
                                    <?= e(format_date($offer['expiry_date'])) ?>
                                    <?php if ($offer['expiry_date'] < date('Y-m-d')): ?>
                                        <span class="badge text-bg-danger ms-1">Expired</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">No expiration specified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Response Timestamp</div>
                            <div class="fw-semibold text-body">
                                <?= !empty($offer['responded_at']) ? e(format_datetime($offer['responded_at'])) : 'Pending candidate response' ?>
                            </div>
                        </div>

                        <!-- Offered Salary (Restricted to users with salary.view) -->
                        <div class="col-12 pt-2 border-top">
                            <div class="text-muted small">Offered Basic Salary</div>
                            <?php if ($canSalary): ?>
                                <div class="h4 mb-0 text-success fw-bold">
                                    <?php if ($offer['offered_salary'] !== null): ?>
                                        <?= e(format_currency((float)$offer['offered_salary'])) ?>
                                        <small class="text-muted fs-6 fw-normal">/ month</small>
                                    <?php else: ?>
                                        <span class="text-muted fs-6 fw-normal">Not specified</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted fst-italic">
                                    <i class="bi bi-shield-lock me-1"></i>Confidential (salary.view permission required)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-3 border-top small text-muted d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>Created by: <strong class="text-body"><?= e($offer['creator_name'] ?? 'System') ?></strong> on <?= e(format_datetime($offer['created_at'])) ?></div>
                        <div>Offer ID: <code>#<?= (int)$offer['id'] ?></code></div>
                    </div>
                </div>
            </div>

            <!-- Conversion Section if Accepted -->
            <?php if ($status === 'accepted'): ?>
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-success" id="convert">
                    <div class="card-header bg-body py-3">
                        <h5 class="card-title mb-0 text-success"><i class="bi bi-person-check-fill me-2"></i>Candidate Onboarding &amp; Employee Conversion</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($offer['converted_employee_id'])): ?>
                            <p class="text-muted small mb-3">
                                The candidate has formally accepted this offer. Converting the candidate will automatically:
                            </p>
                            <ul class="small text-muted mb-4">
                                <li>Create an active employee record in <strong><?= e($offer['company_name']) ?></strong> with an auto-generated employee code.</li>
                                <li>Transfer the candidate's personal data (Name, Contact, NIC, DOB, Gender, Address) to the employee profile.</li>
                                <li>Assign the agreed Department, Designation, Employment Type, Joining Date, and Basic Salary.</li>
                                <li>Create an initial Employment History record.</li>
                                <li>Automatically copy the candidate's CV document to the employee's document repository.</li>
                                <li>Update application status to <span class="badge text-bg-success">Hired</span> and check if vacancy positions are filled.</li>
                            </ul>

                            <?php if ($canManage): ?>
                                <form method="post" action="<?= e(url('modules/offers/convert.php')) ?>" id="convertSection">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">

                                    <div class="mb-3" style="max-width: 480px;">
                                        <label class="form-label fw-semibold" for="convertUserId">Login account</label>
                                        <select class="form-select" id="convertUserId" name="user_id">
                                            <option value="0">-- None (Unlinked) --</option>
                                            <?php foreach ($availableUsers as $u): ?>
                                                <option value="<?= (int)$u['id'] ?>">
                                                    <?= e($u['full_name']) ?> (<?= e($u['username']) ?><?= !empty($u['email']) ? ' &mdash; ' . e($u['email']) : '' ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Optional. Active user assigned to this company who is not already linked to another employee.</div>
                                    </div>

                                    <button type="submit" class="btn btn-success" data-confirm="Confirm candidate conversion to employee?" data-confirm-type="success" data-confirm-title="Convert Candidate to Employee" data-confirm-btn="Convert">
                                        <i class="bi bi-person-plus-fill me-1"></i> Proceed with Employee Conversion
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="text-muted small fst-italic">You do not have <code>offer.manage</code> permission to convert this candidate.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-success text-white p-3 rounded-circle">
                                    <i class="bi bi-check2-all fs-3"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-body">Candidate Successfully Converted!</h6>
                                    <p class="small text-muted mb-2">
                                        Converted employee: <strong><?= e($offer['emp_fname'] . ' ' . $offer['emp_lname']) ?></strong> (<code><?= e($offer['converted_emp_code']) ?></code>).
                                    </p>
                                    <a href="<?= e(url('modules/employees/view.php?id=' . (int)$offer['converted_employee_id'])) ?>" class="btn btn-sm btn-outline-success">
                                        Open Employee Profile &rarr;
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Details -->
        <div class="col-lg-4">
            <!-- Candidate Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Candidate Profile</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="text-muted small">Candidate Name</div>
                        <div class="fw-bold text-body fs-6"><?= e($offer['first_name'] . ' ' . $offer['last_name']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Candidate Code</div>
                        <div class="fw-semibold text-primary"><?= e($offer['candidate_code']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Email</div>
                        <div class="fw-semibold"><?= e($offer['email'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Phone</div>
                        <div class="fw-semibold"><?= e($offer['phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">NIC / Passport</div>
                        <div class="fw-semibold"><?= e($offer['nic_passport'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="text-muted small">DOB</div>
                            <div class="fw-semibold"><?= !empty($offer['dob']) ? e(format_date($offer['dob'])) : '—' ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Gender</div>
                            <div class="fw-semibold"><?= !empty($offer['gender']) ? e(ucfirst($offer['gender'])) : '—' ?></div>
                        </div>
                    </div>

                    <?php if ($cvDoc): ?>
                        <div class="pt-3 border-top mb-3">
                            <div class="text-muted small mb-1">Resume / CV Document</div>
                            <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$cvDoc['id'])) ?>" class="btn btn-sm btn-outline-secondary w-100 text-truncate" title="Download CV">
                                <i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= e($cvDoc['original_name']) ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="pt-2 border-top">
                        <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$offer['candidate_id'])) ?>" class="btn btn-sm btn-outline-primary w-100">
                            Full Candidate Record &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Vacancy Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Job Vacancy</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="text-muted small">Job Title</div>
                        <div class="fw-bold text-body"><?= e($offer['vacancy_title']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Vacancy Code</div>
                        <div class="fw-semibold text-primary"><?= e($offer['vacancy_code']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Positions Needed</div>
                        <div class="fw-semibold"><?= (int)$offer['positions_count'] ?> position(s)</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Vacancy Status</div>
                        <?= vacancy_status_badge($offer['vacancy_status']) ?>
                    </div>
                    <div class="pt-2 border-top">
                        <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$offer['vacancy_id'])) ?>" class="btn btn-sm btn-outline-info w-100">
                            Open Pipeline Kanban &rarr;
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
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                <input type="hidden" name="action" value="withdraw">

                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="withdrawModalLabel"><i class="bi bi-x-circle me-1"></i> Withdraw Job Offer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Are you sure you want to withdraw this offer? Withdrawing will transition the offer to Withdrawn and return the candidate application to Selected status.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Withdrawal <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required maxlength="255" placeholder="e.g. Headcount freeze, candidate requirements changed, revised terms..."></textarea>
                        <div class="form-text">A withdrawal reason is required and will be logged to audit history.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Record Candidate Decline -->
<div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/offers/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                <input type="hidden" name="action" value="decline">

                <div class="modal-header">
                    <h5 class="modal-title" id="declineModalLabel"><i class="bi bi-hand-thumbs-down me-1"></i> Record Candidate Decline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Record that the candidate declined the offer. This will update the application status to Offer Declined.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Candidate Reason / Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" maxlength="255" placeholder="e.g. Accepted another offer, salary expectations mismatch, relocation issues..."></textarea>
                        <div class="form-text">Remarks are recorded in candidate status history.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-body">Confirm Decline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
