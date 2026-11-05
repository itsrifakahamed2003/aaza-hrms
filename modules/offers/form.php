<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();

$appId = (int)($_GET['application_id'] ?? ($_POST['application_id'] ?? 0));
if ($appId <= 0) {
    abort(404, 'Candidate application not specified.');
}

// Fetch application, candidate and vacancy details
$st = $pdo->prepare(
    "SELECT ca.*,
            c.id AS cand_id, c.candidate_code, c.first_name, c.last_name, c.email, c.phone,
            v.id AS vac_id, v.vacancy_code, v.title AS vacancy_title, v.department_id AS vac_dept_id,
            v.designation_id AS vac_desig_id, v.employment_type AS vac_emp_type,
            comp.id AS comp_id, comp.name AS company_name, comp.code AS company_code
       FROM candidate_applications ca
       JOIN candidates c ON c.id = ca.candidate_id
       JOIN job_vacancies v ON v.id = ca.vacancy_id
       JOIN companies comp ON comp.id = ca.company_id
      WHERE ca.id = ?
      LIMIT 1"
);
$st->execute([$appId]);
$application = $st->fetch();

if (!$application) {
    abort(404, 'Candidate application not found.');
}

$companyId = (int)$application['company_id'];
require_company_access($companyId, 'offer.manage');

// Rule: An offer can ONLY be created for an application in 'selected' status
if ((string)$application['status'] !== 'selected') {
    flash_set('danger', "Job offers can only be created for applications in 'Selected' status (current status: " . e(application_status_list()[$application['status']] ?? $application['status']) . ").");
    redirect('modules/candidates/view.php?id=' . (int)$application['cand_id']);
}

// Rule: One active offer (draft/sent) per application
$activeSt = $pdo->prepare(
    "SELECT id FROM job_offers WHERE application_id = ? AND status IN ('draft', 'sent') LIMIT 1"
);
$activeSt->execute([$appId]);
$existingActiveOfferId = (int)$activeSt->fetchColumn();
if ($existingActiveOfferId > 0) {
    flash_set('danger', 'An active job offer (Draft or Sent) already exists for this application.');
    redirect('modules/offers/view.php?id=' . $existingActiveOfferId);
}

// Check salary.view permission on this company
$canSalary = user_can('salary.view', $companyId);

// Fetch active departments & designations for the application's company
$deptSt = $pdo->prepare('SELECT id, name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY name');
$deptSt->execute([$companyId]);
$departments = $deptSt->fetchAll();

$desigSt = $pdo->prepare('SELECT id, title FROM designations WHERE company_id = ? AND is_active = 1 ORDER BY title');
$desigSt->execute([$companyId]);
$designations = $desigSt->fetchAll();

// Default values
$offerValidityDays = function_exists('get_company_setting')
    ? (int)get_company_setting($pdo, $companyId, 'recruitment.offer_validity_days', 14)
    : 14;
if ($offerValidityDays <= 0) {
    $offerValidityDays = 14;
}

$form = [
    'department_id'      => (int)($application['vac_dept_id'] ?? 0),
    'designation_id'     => (int)($application['vac_desig_id'] ?? 0),
    'employment_type'    => (string)($application['vac_emp_type'] ?? 'full_time'),
    'offer_date'         => date('Y-m-d'),
    'expiry_date'        => date('Y-m-d', strtotime("+{$offerValidityDays} days")),
    'proposed_join_date' => date('Y-m-d', strtotime('+30 days')),
    'offered_salary'     => '',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $departmentId      = (int)($_POST['department_id'] ?? 0);
    $designationId     = (int)($_POST['designation_id'] ?? 0);
    $employmentType    = trim((string)($_POST['employment_type'] ?? 'full_time'));
    $offerDate         = trim((string)($_POST['offer_date'] ?? ''));
    $expiryDate        = trim((string)($_POST['expiry_date'] ?? ''));
    $proposedJoinDate  = trim((string)($_POST['proposed_join_date'] ?? ''));
    $offeredSalaryVal  = trim((string)($_POST['offered_salary'] ?? ''));

    // Update in-memory form state
    $form['department_id']      = $departmentId;
    $form['designation_id']     = $designationId;
    $form['employment_type']    = $employmentType;
    $form['offer_date']         = $offerDate;
    $form['expiry_date']        = $expiryDate;
    $form['proposed_join_date'] = $proposedJoinDate;
    $form['offered_salary']     = $offeredSalaryVal;

    // 1. Department validation: must belong to the SAME company and be active
    if ($departmentId > 0) {
        $chkDept = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $chkDept->execute([$departmentId, $companyId]);
        if (!$chkDept->fetch()) {
            $errors[] = 'The selected department is invalid or does not belong to ' . $application['company_name'] . '.';
        }
    } else {
        $departmentId = null;
    }

    // 2. Designation validation: must belong to the SAME company and be active
    if ($designationId > 0) {
        $chkDesig = $pdo->prepare('SELECT id FROM designations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $chkDesig->execute([$designationId, $companyId]);
        if (!$chkDesig->fetch()) {
            $errors[] = 'The selected designation is invalid or does not belong to ' . $application['company_name'] . '.';
        }
    } else {
        $designationId = null;
    }

    // 3. Employment type
    if (!array_key_exists($employmentType, employment_type_list())) {
        $errors[] = 'Please select a valid employment type.';
    }

    // 4. Offer date
    if ($offerDate === '') {
        $errors[] = 'Offer date is required.';
    } else {
        $od = DateTime::createFromFormat('Y-m-d', $offerDate);
        if (!$od || $od->format('Y-m-d') !== $offerDate) {
            $errors[] = 'Offer date must be a valid date in YYYY-MM-DD format.';
        }
    }

    // 5. Expiry date (must be after the offer date)
    if ($expiryDate !== '') {
        $ed = DateTime::createFromFormat('Y-m-d', $expiryDate);
        if (!$ed || $ed->format('Y-m-d') !== $expiryDate) {
            $errors[] = 'Expiry date must be a valid date in YYYY-MM-DD format.';
        } elseif ($offerDate !== '' && $expiryDate <= $offerDate) {
            $errors[] = 'Expiry date must be after the offer date.';
        }
    } else {
        $expiryDate = null;
    }

    // 6. Proposed join date (not in the past)
    if ($proposedJoinDate !== '') {
        $jd = DateTime::createFromFormat('Y-m-d', $proposedJoinDate);
        if (!$jd || $jd->format('Y-m-d') !== $proposedJoinDate) {
            $errors[] = 'Proposed join date must be a valid date in YYYY-MM-DD format.';
        } elseif ($proposedJoinDate < date('Y-m-d')) {
            $errors[] = 'Proposed join date cannot be in the past.';
        }
    } else {
        $proposedJoinDate = null;
    }

    // 7. Offered salary (only users with salary.view can see or edit it)
    $finalSalary = null;
    if ($canSalary) {
        if ($offeredSalaryVal !== '') {
            if (!is_numeric($offeredSalaryVal) || (float)$offeredSalaryVal < 0 || (float)$offeredSalaryVal > 9999999999.99) {
                $errors[] = 'Offered salary must be a valid non-negative number up to 9,999,999,999.99.';
            } else {
                $finalSalary = round((float)$offeredSalaryVal, 2);
            }
        }
    }

    // Re-check active offer inside transaction
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock application row and re-verify status
            $lockApp = $pdo->prepare('SELECT id, status FROM candidate_applications WHERE id = ? FOR UPDATE');
            $lockApp->execute([$appId]);
            $curApp = $lockApp->fetch();
            if (!$curApp || (string)$curApp['status'] !== 'selected') {
                $pdo->rollBack();
                flash_set('danger', "Application is no longer in Selected status. Offer creation cancelled.");
                redirect('modules/candidates/view.php?id=' . (int)$application['cand_id']);
            }

            // Check if active offer was added concurrently
            $chkActive = $pdo->prepare("SELECT id FROM job_offers WHERE application_id = ? AND status IN ('draft', 'sent') FOR UPDATE");
            $chkActive->execute([$appId]);
            if ($chkActive->fetch()) {
                $pdo->rollBack();
                flash_set('danger', 'An active job offer already exists for this application.');
                redirect('modules/candidates/view.php?id=' . (int)$application['cand_id']);
            }

            $currentUserId = current_user_id();
            $insSt = $pdo->prepare(
                'INSERT INTO job_offers (
                    application_id, company_id, designation_id, department_id,
                    offered_salary, employment_type, proposed_join_date,
                    offer_date, expiry_date, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, NOW())'
            );
            $insSt->execute([
                $appId,
                $companyId,
                $designationId,
                $departmentId,
                $finalSalary,
                $employmentType,
                $proposedJoinDate,
                $offerDate,
                $expiryDate,
                $currentUserId,
            ]);

            $newOfferId = (int)$pdo->lastInsertId();

            $pdo->commit();

            // Notify HR about created offer
            $candFullName = trim($application['first_name'] . ' ' . $application['last_name']);
            notify_permission(
                $pdo,
                $companyId,
                'offer.manage',
                'offer_created',
                'Job Offer Created',
                "A job offer draft #{$newOfferId} was created for {$candFullName}.",
                'modules/offers/view.php?id=' . $newOfferId,
                'offer:create:' . $newOfferId,
                $currentUserId
            );

            log_activity('offer.create', 'job_offer', $newOfferId, [
                'application_id'     => $appId,
                'candidate_id'       => (int)$application['cand_id'],
                'vacancy_id'         => (int)$application['vac_id'],
                'department_id'      => $departmentId,
                'designation_id'     => $designationId,
                'employment_type'    => $employmentType,
                'offer_date'         => $offerDate,
                'expiry_date'        => $expiryDate,
                'proposed_join_date' => $proposedJoinDate,
                'has_salary'         => $finalSalary !== null,
            ], $companyId);

            flash_set('success', 'Job offer draft created successfully.');
            redirect('modules/offers/view.php?id=' . $newOfferId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Offer creation error: ' . $e->getMessage());
            $errors[] = 'A database error occurred while creating the job offer.';
        }
    }
}

$pageTitle = 'Draft Job Offer';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 900px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Offers</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['cand_id'])) ?>"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">New Job Offer</li>
        </ol>
    </nav>

    <!-- Candidate Context Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="badge bg-body-secondary text-body border me-1"><?= e($application['company_code']) ?></span>
                    <strong class="fs-5 text-body"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></strong>
                    <span class="badge text-bg-primary ms-2"><?= e($application['candidate_code']) ?></span>
                    <div class="small text-muted mt-1">
                        Vacancy: <strong class="text-body"><?= e($application['vacancy_title']) ?></strong> (<?= e($application['vacancy_code']) ?>) &bull; <?= e($application['company_name']) ?>
                    </div>
                </div>
                <div>
                    <?= application_status_badge((string)$application['status']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Alerts -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i>Please correct the following errors:</div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Offer Form Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0">Create Job Offer Details</h5>
        </div>
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('modules/offers/form.php?application_id=' . $appId)) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="application_id" value="<?= $appId ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Department <span class="text-muted small">(for <?= e($application['company_name']) ?>)</span></label>
                        <select name="department_id" class="form-select">
                            <option value="0">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)$form['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Department must belong to <?= e($application['company_name']) ?>.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Designation / Position <span class="text-muted small">(for <?= e($application['company_name']) ?>)</span></label>
                        <select name="designation_id" class="form-select">
                            <option value="0">-- Select Designation --</option>
                            <?php foreach ($designations as $des): ?>
                                <option value="<?= (int)$des['id'] ?>" <?= (int)$form['designation_id'] === (int)$des['id'] ? 'selected' : '' ?>>
                                    <?= e($des['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Designation must belong to <?= e($application['company_name']) ?>.</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                        <select name="employment_type" class="form-select" required>
                            <?php foreach (employment_type_list() as $etKey => $etLbl): ?>
                                <option value="<?= e($etKey) ?>" <?= $form['employment_type'] === $etKey ? 'selected' : '' ?>>
                                    <?= e($etLbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($canSalary): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Offered Basic Salary</label>
                            <div class="input-group">
                                <span class="input-group-text">LKR</span>
                                <input type="number" step="0.01" min="0" max="9999999999.99" name="offered_salary"
                                       class="form-control" placeholder="e.g. 150000.00"
                                       value="<?= e((string)$form['offered_salary']) ?>">
                            </div>
                            <div class="form-text">Visible only to users with <code>salary.view</code>. Leave blank if not determined.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Offer Date <span class="text-danger">*</span></label>
                        <input type="date" name="offer_date" class="form-control" required value="<?= e($form['offer_date']) ?>">
                        <div class="form-text">Date the formal offer is issued.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Offer Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= e($form['expiry_date']) ?>">
                        <div class="form-text">Must be after the offer date. Auto-expires when passed.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Proposed Joining Date</label>
                        <input type="date" name="proposed_join_date" class="form-control" value="<?= e($form['proposed_join_date']) ?>">
                        <div class="form-text">Cannot be in the past.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['cand_id'])) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-file-earmark-plus me-1"></i> Save as Draft Offer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
