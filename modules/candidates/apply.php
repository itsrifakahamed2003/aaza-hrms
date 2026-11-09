<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('candidate.manage');

$pdo = db();

$preCandidateId = (int)($_GET['candidate_id'] ?? 0);
$preVacancyId = (int)($_GET['vacancy_id'] ?? 0);

if ($preCandidateId > 0 && !can_access_candidate($pdo, $preCandidateId)) {
    abort(403, 'You do not have permission to access this candidate.');
}

if ($preVacancyId > 0) {
    $vCheck = $pdo->prepare('SELECT id, status FROM job_vacancies WHERE id = ? LIMIT 1');
    $vCheck->execute([$preVacancyId]);
    $preVac = $vCheck->fetch();
    if ($preVac && in_array($preVac['status'], ['closed', 'cancelled'], true)) {
        $preVacancyId = 0;
        $errors['vacancy_id'] = 'The selected vacancy is closed or cancelled and cannot accept applications.';
    }
}

$candidateId = (int)($_POST['candidate_id'] ?? $preCandidateId);
$vacancyId = (int)($_POST['vacancy_id'] ?? $preVacancyId);
$appDate = (string)($_POST['application_date'] ?? date('Y-m-d'));
$initialStatus = (string)($_POST['status'] ?? 'applied');
$notes = (string)($_POST['notes'] ?? '');

$errors = [];

// Determine accessible companies where user has candidate.manage
$manageCompanyIds = accessible_company_ids('candidate.manage');
if (empty($manageCompanyIds)) {
    abort(403, 'You do not have permission to manage candidate applications in any company.');
}

// Fetch available open/active vacancies in accessible companies
$ph = implode(',', array_fill(0, count($manageCompanyIds), '?'));
$vacSt = $pdo->prepare(
    "SELECT v.id, v.vacancy_code, v.title, v.company_id, v.status,
            c.code AS company_code, c.name AS company_name
       FROM job_vacancies v
       JOIN companies c ON c.id = v.company_id
      WHERE v.company_id IN ($ph)
        AND v.status IN ('open', 'on_hold', 'draft')
      ORDER BY c.name ASC, v.title ASC"
);
$vacSt->execute($manageCompanyIds);
$vacancies = $vacSt->fetchAll();

// If candidate is preselected, load details
$selectedCandidate = null;
if ($candidateId > 0) {
    $st = $pdo->prepare('SELECT id, candidate_code, first_name, last_name, email, phone, experience_years FROM candidates WHERE id = ? LIMIT 1');
    $st->execute([$candidateId]);
    $selectedCandidate = $st->fetch();
}

// If no candidate preselected, fetch candidate list for dropdown
$candidatesList = [];
if (!$selectedCandidate) {
    $hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');
    if ($hasCrossCompany) {
        $cSt = $pdo->query('SELECT id, candidate_code, first_name, last_name, email FROM candidates ORDER BY first_name ASC, last_name ASC LIMIT 500');
        $candidatesList = $cSt->fetchAll();
    } else {
        $scopedCompanyIds = scoped_company_ids('candidate.view');
        if (!empty($scopedCompanyIds)) {
            $cph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
            $cSt = $pdo->prepare(
                "SELECT DISTINCT c.id, c.candidate_code, c.first_name, c.last_name, c.email
                   FROM candidates c
                   LEFT JOIN candidate_applications ca ON ca.candidate_id = c.id
                  WHERE (ca.company_id IN ($cph) OR c.created_by = ?)
                  ORDER BY c.first_name ASC, c.last_name ASC LIMIT 500"
            );
            $cSt->execute(array_merge($scopedCompanyIds, [current_user_id()]));
            $candidatesList = $cSt->fetchAll();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $candidateId = (int)($_POST['candidate_id'] ?? 0);
    $vacancyId = (int)($_POST['vacancy_id'] ?? 0);
    $appDate = trim((string)($_POST['application_date'] ?? ''));
    $initialStatus = trim((string)($_POST['status'] ?? 'applied'));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($candidateId <= 0) {
        $errors['candidate_id'] = 'Please select a candidate.';
    } elseif (!can_access_candidate($pdo, $candidateId)) {
        abort(403, 'You do not have permission to access this candidate.');
    } else {
        // Verify candidate exists
        $candCheck = $pdo->prepare('SELECT id, candidate_code, first_name, last_name FROM candidates WHERE id = ? LIMIT 1');
        $candCheck->execute([$candidateId]);
        $candRow = $candCheck->fetch();
        if (!$candRow) {
            $errors['candidate_id'] = 'Selected candidate does not exist.';
        }
    }

    if ($vacancyId <= 0) {
        $errors['vacancy_id'] = 'Please select a vacancy.';
    } else {
        // Verify vacancy exists, belongs to permitted company, and is not closed/cancelled
        $vacCheck = $pdo->prepare('SELECT id, company_id, vacancy_code, title, status FROM job_vacancies WHERE id = ? LIMIT 1');
        $vacCheck->execute([$vacancyId]);
        $vacRow = $vacCheck->fetch();
        if (!$vacRow || !in_array((int)$vacRow['company_id'], $manageCompanyIds, true)) {
            $errors['vacancy_id'] = 'The selected vacancy is invalid or belongs to a company you cannot manage.';
        } elseif (in_array($vacRow['status'], ['closed', 'cancelled'], true)) {
            $errors['vacancy_id'] = 'Cannot apply to a vacancy that is closed or cancelled.';
        }
    }

    $d = DateTime::createFromFormat('Y-m-d', $appDate);
    if (!$d || $d->format('Y-m-d') !== $appDate) {
        $errors['application_date'] = 'Invalid application date format (YYYY-MM-DD).';
    }

    $validStatuses = application_status_list();
    if (!isset($validStatuses[$initialStatus])) {
        $errors['status'] = 'Invalid application status.';
    }

    // BLOCK DUPLICATE APPLICATION TO THE SAME VACANCY
    if (empty($errors)) {
        $dupSt = $pdo->prepare('SELECT id FROM candidate_applications WHERE candidate_id = ? AND vacancy_id = ? LIMIT 1');
        $dupSt->execute([$candidateId, $vacancyId]);
        $existingAppId = $dupSt->fetchColumn();
        if ($existingAppId) {
            $errors['duplicate'] = "This candidate has already applied to this vacancy (Application #$existingAppId). Duplicate applications to the same vacancy are blocked.";
        }
    }

    if (empty($errors) && isset($candRow, $vacRow)) {
        $pdo->beginTransaction();
        try {
            $insSql = 'INSERT INTO candidate_applications
                        (candidate_id, company_id, vacancy_id, application_date, status, notes, created_by, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
            $insSt = $pdo->prepare($insSql);
            $insSt->execute([
                $candidateId,
                (int)$vacRow['company_id'],
                $vacancyId,
                $appDate,
                $initialStatus,
                $notes !== '' ? $notes : null,
                current_user_id(),
            ]);
            $newAppId = (int)$pdo->lastInsertId();

            // Write initial entry to candidate_status_history
            $histSt = $pdo->prepare(
                'INSERT INTO candidate_status_history (application_id, old_status, new_status, remarks, changed_by, changed_at)
                 VALUES (?, NULL, ?, ?, ?, NOW())'
            );
            $histSt->execute([
                $newAppId,
                $initialStatus,
                'Initial application submitted' . ($notes !== '' ? ': ' . mb_substr($notes, 0, 100) : ''),
                current_user_id(),
            ]);

            $pdo->commit();

            // Notify HR about new application
            $applicantName = trim($candRow['first_name'] . ' ' . $candRow['last_name']);
            notify_permission(
                $pdo,
                (int)$vacRow['company_id'],
                'candidate.view',
                'candidate_application',
                'New Job Application',
                "New application created for {$applicantName} ({$vacRow['title']}).",
                'modules/candidates/view.php?id=' . $candidateId,
                'app:new:' . $newAppId,
                current_user_id()
            );

            log_activity('candidate.apply', 'candidate_applications', $newAppId, [
                'candidate_id'   => $candidateId,
                'candidate_code' => $candRow['candidate_code'],
                'vacancy_id'     => $vacancyId,
                'vacancy_code'   => $vacRow['vacancy_code'],
                'status'         => $initialStatus,
            ], (int)$vacRow['company_id']);

            flash_set('success', "Application for {$candRow['first_name']} {$candRow['last_name']} ({$vacRow['vacancy_code']}) created successfully.");
            if (isset($_SESSION['linkable_candidates'][$candidateId])) {
                unset($_SESSION['linkable_candidates'][$candidateId]);
            }
            redirect('modules/candidates/view.php?id=' . $candidateId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error saving candidate application: ' . $e->getMessage());
            $errors['general'] = 'Failed to create application due to a system error. Please try again.';
        }
    }
}

$pageTitle = 'Apply Candidate to Vacancy';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 800px;">
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/index.php')) ?>">Candidates</a></li>
                    <?php if ($selectedCandidate): ?>
                        <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$selectedCandidate['id'])) ?>"><?= e($selectedCandidate['candidate_code']) ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active" aria-current="page">New Application</li>
                </ol>
            </nav>
            <h1 class="page-header-title h3 mb-0">Apply Candidate to Vacancy</h1>
            <p class="text-muted small mb-0 mt-1">One candidate can apply to multiple vacancies across group companies. Duplicate applications to the same vacancy are blocked.</p>
        </div>
        <div class="page-actions">
            <a href="<?= e($selectedCandidate ? url('modules/candidates/view.php?id=' . (int)$selectedCandidate['id']) : url('modules/candidates/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <?php if (isset($errors['duplicate'])): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4">
            <div class="me-2 fw-bold">&#9888;</div>
            <div><?= e($errors['duplicate']) ?></div>
        </div>
    <?php endif; ?>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger mb-4"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('modules/candidates/apply.php' . ($candidateId > 0 ? '?candidate_id=' . $candidateId : ''))) ?>" autocomplete="off">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <!-- Candidate Selection -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Candidate <span class="text-danger">*</span></label>
                        <?php if ($selectedCandidate): ?>
                            <?php
                            $hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');
                            $isLinkableOnly = !$hasCrossCompany && !empty($_SESSION['linkable_candidates'][$selectedCandidate['id']]);
                            $dispName = $isLinkableOnly
                                ? e($selectedCandidate['first_name']) . ' ' . (mb_substr((string)$selectedCandidate['last_name'], 0, 1) !== '' ? e(mb_substr((string)$selectedCandidate['last_name'], 0, 1)) . '.' : '')
                                : e($selectedCandidate['first_name'] . ' ' . $selectedCandidate['last_name']);
                            $dispEmail = $isLinkableOnly ? mask_candidate_email($selectedCandidate['email']) : ($selectedCandidate['email'] ?? 'No email');
                            $dispPhone = $isLinkableOnly ? mask_candidate_phone($selectedCandidate['phone']) : ($selectedCandidate['phone'] ?? 'No phone');
                            ?>
                            <div class="card bg-body-tertiary border p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold text-body fs-6">
                                            <?= $dispName ?>
                                            <span class="badge text-bg-secondary ms-1"><?= e($selectedCandidate['candidate_code']) ?></span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <span><?= e($dispEmail) ?></span> &bull;
                                            <span><?= e($dispPhone) ?></span>
                                            <?php if ($selectedCandidate['experience_years'] !== null): ?>
                                                &bull; <span><?= e((string)$selectedCandidate['experience_years']) ?> yrs exp</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="<?= e(url('modules/candidates/apply.php')) ?>" class="btn btn-sm btn-outline-secondary">
                                        Change Candidate
                                    </a>
                                </div>
                            </div>
                            <input type="hidden" name="candidate_id" value="<?= (int)$selectedCandidate['id'] ?>">
                        <?php else: ?>
                            <select name="candidate_id" class="form-select <?= isset($errors['candidate_id']) ? 'is-invalid' : '' ?>" required>
                                <option value="">-- Choose Candidate from Pool --</option>
                                <?php foreach ($candidatesList as $cand): ?>
                                    <option value="<?= (int)$cand['id'] ?>"<?= $candidateId === (int)$cand['id'] ? ' selected' : '' ?>>
                                        <?= e($cand['first_name'] . ' ' . $cand['last_name'] . ' (' . $cand['candidate_code'] . ') - ' . ($cand['email'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['candidate_id'])): ?>
                                <div class="invalid-feedback"><?= e($errors['candidate_id']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                Don't see the candidate? <a href="<?= e(url('modules/candidates/form.php')) ?>" target="_blank">Register a new profile</a> first.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Vacancy Selection -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Target Vacancy <span class="text-danger">*</span></label>
                        <select name="vacancy_id" class="form-select <?= isset($errors['vacancy_id']) ? 'is-invalid' : '' ?>" required>
                            <option value="">-- Choose Job Vacancy --</option>
                            <?php foreach ($vacancies as $vac): ?>
                                <option value="<?= (int)$vac['id'] ?>"<?= $vacancyId === (int)$vac['id'] ? ' selected' : '' ?>>
                                    [<?= e($vac['company_code']) ?>] <?= e($vac['vacancy_code']) ?> &ndash; <?= e($vac['title']) ?> (<?= e(ucfirst($vac['status'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['vacancy_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['vacancy_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Application Date -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Application Date <span class="text-danger">*</span></label>
                        <input type="date" name="application_date" class="form-control <?= isset($errors['application_date']) ? 'is-invalid' : '' ?>"
                               value="<?= e($appDate) ?>" required>
                        <?php if (isset($errors['application_date'])): ?>
                            <div class="invalid-feedback"><?= e($errors['application_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Initial Pipeline Stage -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Initial Stage <span class="text-danger">*</span></label>
                        <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>" required>
                            <?php foreach (pipeline_stages() as $stgKey => $stgLabel): ?>
                                <option value="<?= e($stgKey) ?>"<?= $initialStatus === $stgKey ? ' selected' : '' ?>>
                                    <?= e($stgLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <div class="invalid-feedback"><?= e($errors['status']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Notes -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Application Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Recruiter notes, referral details, initial impressions..."><?= e($notes) ?></textarea>
                    </div>

                    <!-- Submit -->
                    <div class="col-12 mt-4 d-flex justify-content-between align-items-center">
                        <a href="<?= $selectedCandidate ? e(url('modules/candidates/view.php?id=' . (int)$selectedCandidate['id'])) : e(url('modules/candidates/index.php')) ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            Submit Application
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
