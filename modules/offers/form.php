<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();

$appId = (int)($_GET['application_id'] ?? ($_POST['application_id'] ?? 0));

// If no application specified, show candidate selector or guided instructions
if ($appId <= 0) {
    $scopedManageIds = accessible_company_ids('offer.manage');
    if (empty($scopedManageIds)) {
        abort(403, 'You do not have permission to manage job offers in any company.');
    }
    $phAcc = implode(',', array_fill(0, count($scopedManageIds), '?'));
    $readySt = $pdo->prepare(
        "SELECT ca.id AS app_id, ca.company_id, ca.vacancy_id,
                c.id AS candidate_id, c.candidate_code, c.first_name, c.last_name,
                v.vacancy_code, v.title AS vacancy_title,
                comp.name AS company_name, comp.code AS company_code
           FROM candidate_applications ca
           JOIN candidates c ON c.id = ca.candidate_id
           JOIN job_vacancies v ON v.id = ca.vacancy_id
           JOIN companies comp ON comp.id = ca.company_id
          WHERE ca.company_id IN ($phAcc)
            AND ca.status = 'selected'
            AND NOT EXISTS (
                SELECT 1 FROM job_offers jo
                 WHERE jo.application_id = ca.id
                   AND jo.status IN ('draft', 'sent', 'accepted')
            )
          ORDER BY c.first_name, c.last_name"
    );
    $readySt->execute($scopedManageIds);
    $eligibleCandidates = $readySt->fetchAll();

    $pageTitle = 'Issue New Job Offer';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container-fluid py-4" style="max-width: 800px;">
        <div class="page-header mb-4">
            <div class="page-header-title-block">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Job Offers</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Issue Offer</li>
                    </ol>
                </nav>
                <h1 class="page-header-title mb-0">Issue New Job Offer</h1>
                <p class="page-header-desc">Generate an official appointment offer letter for a selected candidate.</p>
            </div>
            <div class="page-actions">
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/offers/index.php')) ?>">
                    <i class="bi bi-arrow-left me-1"></i> Back to Offers
                </a>
            </div>
        </div>

        <?php if (!empty($eligibleCandidates)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">Select Candidate to Issue Offer</h5>
                </div>
                <div class="card-body p-4">
                    <form method="get" action="<?= e(url('modules/offers/form.php')) ?>">
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="appSelect">Candidates in Selected Stage (<?= count($eligibleCandidates) ?> available):</label>
                            <select name="application_id" id="appSelect" class="form-select" required>
                                <option value="">-- Choose Candidate Application --</option>
                                <?php foreach ($eligibleCandidates as $ec): ?>
                                    <option value="<?= (int)$ec['app_id'] ?>">
                                        <?= e($ec['first_name'] . ' ' . $ec['last_name']) ?> &bull; <?= e($ec['candidate_code']) ?> &mdash; <?= e($ec['vacancy_title']) ?> (<?= e($ec['company_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only candidates who have been moved to 'Selected' stage appear here.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?= e(url('modules/offers/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 shadow-sm">
                                Continue to Offer Details &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm text-center p-4">
                <div class="card-body py-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-3" style="width: 72px; height: 72px;">
                        <i class="bi bi-file-earmark-check fs-2"></i>
                    </div>
                    <h5 class="fw-bold mb-2">No Candidates Currently Waiting for an Offer</h5>
                    <p class="text-muted small mx-auto mb-4" style="max-width: 520px;">
                        In AaZa HRMS, offer letters are generated for candidates who have completed interview evaluations and been advanced to the <strong>Selected</strong> stage on the vacancy pipeline.
                    </p>

                    <div class="bg-body-tertiary p-3 rounded border text-start mx-auto mb-4" style="max-width: 540px;">
                        <h6 class="small fw-bold text-uppercase text-muted mb-2">Recruitment Workflow:</h6>
                        <ol class="small mb-0 ps-3 text-secondary">
                            <li class="mb-1"><strong>Applied:</strong> Candidate applies to an open vacancy.</li>
                            <li class="mb-1"><strong>Interviews:</strong> Schedule and evaluate candidate interviews.</li>
                            <li class="mb-1"><strong>Selected:</strong> Advance the successful candidate to <strong>Selected</strong> on the Pipeline Board.</li>
                            <li><strong>Issue Offer:</strong> Configure designation, salary, and joining date to generate the official offer.</li>
                        </ol>
                    </div>

                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a href="<?= e(url('modules/vacancies/index.php')) ?>" class="btn btn-primary">
                            <i class="bi bi-kanban me-1"></i> Open Vacancies &amp; Pipeline Board
                        </a>
                        <a href="<?= e(url('modules/candidates/index.php')) ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-people me-1"></i> View Candidates Pool
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
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
        $errors[] = 'Invalid employment type selected.';
    }

    // 4. Offer date
    if ($offerDate === '') {
        $errors[] = 'Offer date is required.';
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $offerDate);
        if (!$d || $d->format('Y-m-d') !== $offerDate) {
            $errors[] = 'Offer date must be a valid date in YYYY-MM-DD format.';
        }
    }

    // 5. Expiry date
    if ($expiryDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $expiryDate);
        if (!$d || $d->format('Y-m-d') !== $expiryDate) {
            $errors[] = 'Expiry date must be a valid date in YYYY-MM-DD format.';
        } elseif ($offerDate !== '' && $expiryDate <= $offerDate) {
            $errors[] = 'Offer expiry date must be strictly after the offer date.';
        }
    } else {
        $expiryDate = null;
    }

    // 6. Proposed join date
    if ($proposedJoinDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $proposedJoinDate);
        if (!$d || $d->format('Y-m-d') !== $proposedJoinDate) {
            $errors[] = 'Proposed joining date must be a valid date in YYYY-MM-DD format.';
        }
    } else {
        $proposedJoinDate = null;
    }

    // 7. Salary validation
    $finalSalary = null;
    if ($offeredSalaryVal !== '') {
        if (!is_numeric($offeredSalaryVal) || (float)$offeredSalaryVal < 0) {
            $errors[] = 'Offered salary must be a positive number.';
        } elseif ((float)$offeredSalaryVal > 9999999999.99) {
            $errors[] = 'Offered salary cannot exceed 9,999,999,999.99.';
        } else {
            $finalSalary = round((float)$offeredSalaryVal, 2);
        }
    }

    // Save offer
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $reCheck = $pdo->prepare(
                "SELECT id FROM job_offers WHERE application_id = ? AND status IN ('draft', 'sent') LIMIT 1 FOR UPDATE"
            );
            $reCheck->execute([$appId]);
            if ($reCheck->fetch()) {
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
    <div class="page-header mb-3">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Job Offers</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['cand_id'])) ?>"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">New Offer</li>
                </ol>
            </nav>
            <h1 class="page-header-title mb-0">Create Job Offer Letter</h1>
            <p class="page-header-desc">Configure employment terms, salary, and joining date for this candidate.</p>
        </div>
        <div class="page-actions">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['cand_id'])) ?>">
                <i class="bi bi-arrow-left me-1"></i> Back to Candidate
            </a>
        </div>
    </div>

    <!-- Candidate Context Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary text-white p-3 rounded-circle shadow-sm">
                        <i class="bi bi-person fs-4"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-body-secondary text-body border font-monospace"><?= e($application['company_code']) ?></span>
                            <strong class="fs-5 text-body"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></strong>
                            <span class="badge text-bg-primary font-monospace"><?= e($application['candidate_code']) ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            Vacancy: <strong class="text-body"><?= e($application['vacancy_title']) ?></strong> (<code><?= e($application['vacancy_code']) ?></code>) &bull; <?= e($application['company_name']) ?>
                        </div>
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
            <h5 class="card-title mb-0 fs-6 fw-bold">Appointment &amp; Compensation Terms</h5>
        </div>
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('modules/offers/form.php?application_id=' . $appId)) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="application_id" value="<?= $appId ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Department <span class="text-muted small">(in <?= e($application['company_name']) ?>)</span></label>
                        <select name="department_id" class="form-select">
                            <option value="0">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)$form['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Assigned organizational unit for this role.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Designation / Title <span class="text-muted small">(in <?= e($application['company_name']) ?>)</span></label>
                        <select name="designation_id" class="form-select">
                            <option value="0">-- Select Designation --</option>
                            <?php foreach ($designations as $des): ?>
                                <option value="<?= (int)$des['id'] ?>" <?= (int)$form['designation_id'] === (int)$des['id'] ? 'selected' : '' ?>>
                                    <?= e($des['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Official position title on the employment contract.</div>
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
                            <div class="form-text">Visible only to authorized HR officers. Leave blank if to be confirmed.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Offer Issue Date <span class="text-danger">*</span></label>
                        <input type="date" name="offer_date" class="form-control" required value="<?= e($form['offer_date']) ?>">
                        <div class="form-text">Official issuance date.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Offer Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= e($form['expiry_date']) ?>">
                        <div class="form-text">Auto-expires if candidate does not reply.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Proposed Joining Date</label>
                        <input type="date" name="proposed_join_date" class="form-control" value="<?= e($form['proposed_join_date']) ?>">
                        <div class="form-text">Target start date for the employee.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['cand_id'])) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-file-earmark-plus me-1"></i> Save as Draft Offer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
