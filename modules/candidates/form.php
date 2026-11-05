<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('candidate.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$isEdit = ($id > 0);

$candidate = null;
if ($isEdit) {
    $st = $pdo->prepare('SELECT * FROM candidates WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $candidate = $st->fetch();
    if (!$candidate) {
        abort(404, 'Candidate not found.');
    }

    // Check visibility if not cross-company and not group admin
    if (!is_group_admin() && !user_can('candidate.cross_company')) {
        $scopedCompanyIds = scoped_company_ids('candidate.view');
        $cph = implode(',', array_fill(0, count($scopedCompanyIds ?: [0]), '?'));
        $permSt = $pdo->prepare(
            "SELECT 1 FROM candidate_applications WHERE candidate_id = ? AND company_id IN ($cph) LIMIT 1"
        );
        $permSt->execute(array_merge([$id], $scopedCompanyIds ?: [0]));
        $hasAccess = (bool)$permSt->fetchColumn() || ((int)$candidate['created_by'] === current_user_id());
        if (!$hasAccess) {
            abort(403, 'You do not have access to manage this candidate.');
        }
    }
}

$errors = [];
$duplicatesFound = [];

// Form values
$firstName = (string)($candidate['first_name'] ?? ($_POST['first_name'] ?? ''));
$lastName = (string)($candidate['last_name'] ?? ($_POST['last_name'] ?? ''));
$email = (string)($candidate['email'] ?? ($_POST['email'] ?? ''));
$phone = (string)($candidate['phone'] ?? ($_POST['phone'] ?? ''));
$nicPassport = (string)($candidate['nic_passport'] ?? ($_POST['nic_passport'] ?? ''));
$dob = (string)($candidate['dob'] ?? ($_POST['dob'] ?? ''));
$gender = (string)($candidate['gender'] ?? ($_POST['gender'] ?? ''));
$address = (string)($candidate['address'] ?? ($_POST['address'] ?? ''));
$qualifications = (string)($candidate['qualifications'] ?? ($_POST['qualifications'] ?? ''));
$experienceYears = (string)($candidate['experience_years'] ?? ($_POST['experience_years'] ?? ''));
$skills = (string)($candidate['skills'] ?? ($_POST['skills'] ?? ''));
$source = (string)($candidate['source'] ?? ($_POST['source'] ?? 'job_portal'));
$consentGiven = isset($candidate['consent_given']) ? (bool)$candidate['consent_given'] : !empty($_POST['consent_given']);
$confirmDuplicate = (string)($_POST['confirm_duplicate'] ?? '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $nicPassport = trim((string)($_POST['nic_passport'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? ''));
    $gender = trim((string)($_POST['gender'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $qualifications = trim((string)($_POST['qualifications'] ?? ''));
    $experienceYears = trim((string)($_POST['experience_years'] ?? ''));
    $skills = trim((string)($_POST['skills'] ?? ''));
    $source = trim((string)($_POST['source'] ?? 'job_portal'));
    $consentGiven = !empty($_POST['consent_given']);
    $confirmDuplicate = (string)($_POST['confirm_duplicate'] ?? '0');

    // Validation
    if (!$isEdit && !$consentGiven) {
        $errors['consent_given'] = 'Candidate privacy consent is required to register a profile in the talent pool.';
    }

    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($dob !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $errors['dob'] = 'Invalid date format for Date of Birth (YYYY-MM-DD).';
        }
    } else {
        $dob = null;
    }

    if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
        $errors['gender'] = 'Invalid gender selected.';
    } else {
        $gender = $gender !== '' ? $gender : null;
    }

    $expVal = null;
    if ($experienceYears !== '') {
        if (!is_numeric($experienceYears) || (float)$experienceYears < 0) {
            $errors['experience_years'] = 'Experience must be a positive number of years.';
        } else {
            $expVal = round((float)$experienceYears, 1);
        }
    }

    // Check duplicates on create (or on edit if email/phone/nic changed)
    if (empty($errors)) {
        $dupes = check_candidate_duplicates(
            $pdo,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $nicPassport !== '' ? $nicPassport : null,
            $isEdit ? $id : null
        );

        if (!empty($dupes) && !$isEdit && $confirmDuplicate !== '1') {
            $duplicatesFound = $dupes;
            if (!$hasCrossCompany) {
                if (!isset($_SESSION['linkable_candidates']) || !is_array($_SESSION['linkable_candidates'])) {
                    $_SESSION['linkable_candidates'] = [];
                }
                foreach ($dupes as $dupe) {
                    $_SESSION['linkable_candidates'][(int)$dupe['id']] = true;
                }
            }
        }
    }

    // File validation if CV uploaded
    $uploadedDoc = null;
    if (empty($errors) && empty($duplicatesFound) && !empty($_FILES['cv_file']['name'])) {
        [$ok, $res] = validate_candidate_document_upload($_FILES['cv_file']);
        if (!$ok) {
            $errors['cv_file'] = (string)$res;
        } else {
            $uploadedDoc = $res;
        }
    }

    if (empty($errors) && empty($duplicatesFound)) {
        if ($isEdit) {
            // Update candidate
            $upSql = 'UPDATE candidates
                         SET first_name = ?, last_name = ?, email = ?, phone = ?, nic_passport = ?,
                             dob = ?, gender = ?, address = ?, qualifications = ?, experience_years = ?,
                             skills = ?, source = ?, consent_given = ?, updated_at = NOW()
                       WHERE id = ?';
            $st = $pdo->prepare($upSql);
            $st->execute([
                $firstName,
                $lastName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $nicPassport !== '' ? $nicPassport : null,
                $dob,
                $gender,
                $address !== '' ? $address : null,
                $qualifications !== '' ? $qualifications : null,
                $expVal,
                $skills !== '' ? $skills : null,
                $source !== '' ? $source : null,
                $consentGiven ? 1 : 0,
                $id,
            ]);

            // Save CV document if uploaded
            if ($uploadedDoc) {
                $destPath = STORAGE_PATH . '/uploads/candidates/' . basename($uploadedDoc['stored_name']);
                if (move_uploaded_file($uploadedDoc['tmp_path'], $destPath)) {
                    $docSt = $pdo->prepare(
                        "INSERT INTO candidate_documents (candidate_id, doc_type, original_name, stored_name, mime_type, file_size, uploaded_by, uploaded_at)
                         VALUES (?, 'cv', ?, ?, ?, ?, ?, NOW())"
                    );
                    $docSt->execute([
                        $id,
                        $uploadedDoc['original_name'],
                        $uploadedDoc['stored_name'],
                        $uploadedDoc['mime_type'],
                        $uploadedDoc['file_size'],
                        current_user_id(),
                    ]);
                }
            }

            log_activity('candidate.update', 'candidates', $id, [
                'candidate_code' => $candidate['candidate_code'],
                'first_name'     => $firstName,
                'last_name'      => $lastName,
            ]);

            flash_set('success', 'Candidate profile updated successfully.');
            redirect('modules/candidates/view.php?id=' . $id);
        } else {
            // Create new candidate in transaction
            $pdo->beginTransaction();
            try {
                $candidateCode = generate_candidate_code($pdo);

                $insSql = 'INSERT INTO candidates
                            (candidate_code, first_name, last_name, email, phone, nic_passport,
                             dob, gender, address, qualifications, experience_years, skills,
                             source, consent_given, created_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
                $st = $pdo->prepare($insSql);
                $st->execute([
                    $candidateCode,
                    $firstName,
                    $lastName,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $nicPassport !== '' ? $nicPassport : null,
                    $dob,
                    $gender,
                    $address !== '' ? $address : null,
                    $qualifications !== '' ? $qualifications : null,
                    $expVal,
                    $skills !== '' ? $skills : null,
                    $source !== '' ? $source : null,
                    $consentGiven ? 1 : 0,
                    current_user_id(),
                ]);
                $newId = (int)$pdo->lastInsertId();

                if ($uploadedDoc) {
                    $destPath = STORAGE_PATH . '/uploads/candidates/' . basename($uploadedDoc['stored_name']);
                    if (move_uploaded_file($uploadedDoc['tmp_path'], $destPath)) {
                        $docSt = $pdo->prepare(
                            "INSERT INTO candidate_documents (candidate_id, doc_type, original_name, stored_name, mime_type, file_size, uploaded_by, uploaded_at)
                             VALUES (?, 'cv', ?, ?, ?, ?, ?, NOW())"
                        );
                        $docSt->execute([
                            $newId,
                            $uploadedDoc['original_name'],
                            $uploadedDoc['stored_name'],
                            $uploadedDoc['mime_type'],
                            $uploadedDoc['file_size'],
                            current_user_id(),
                        ]);
                    }
                }

                $pdo->commit();

                log_activity('candidate.create', 'candidates', $newId, [
                    'candidate_code' => $candidateCode,
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                ]);

                flash_set('success', "Candidate $candidateCode registered successfully.");
                redirect('modules/candidates/view.php?id=' . $newId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error registering candidate: ' . $e->getMessage());
                $errors['general'] = 'Failed to register candidate due to a system error. Please try again.';
            }
        }
    }
}

$pageTitle = $isEdit ? 'Edit Candidate: ' . ($candidate['candidate_code'] ?? '') : 'Register Candidate';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 900px;">
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/index.php')) ?>">Candidates</a></li>
                <?php if ($isEdit): ?>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$candidate['id'])) ?>"><?= e($candidate['candidate_code']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit</li>
                <?php else: ?>
                    <li class="breadcrumb-item active" aria-current="page">New Candidate</li>
                <?php endif; ?>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= $isEdit ? 'Edit Candidate Profile (' . e($candidate['candidate_code']) . ')' : 'Register Candidate to Talent Pool' ?></h1>
    </div>

    <!-- Duplicate Warning Box -->
    <?php if (!empty($duplicatesFound)): ?>
        <div class="card border-warning shadow-sm mb-4">
            <div class="card-header bg-warning-subtle text-body border-warning d-flex justify-content-between align-items-center">
                <div class="fw-bold">
                    <span class="me-1">&#9888;</span> Potential Duplicate Profile(s) Found
                </div>
                <span class="badge bg-warning text-body"><?= count($duplicatesFound) ?> Match<?= count($duplicatesFound) === 1 ? '' : 'es' ?></span>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    We found existing profile(s) with matching email, phone number, or NIC/Passport. To avoid duplicate entries across companies, you can <strong>reuse the existing profile</strong> or apply directly for vacancies:
                </p>
                <div class="list-group mb-4">
                    <?php foreach ($duplicatesFound as $dupe): ?>
                        <?php
                        $masked = !$hasCrossCompany;
                        $dupeName = $masked
                            ? e($dupe['first_name']) . ' ' . (mb_substr((string)$dupe['last_name'], 0, 1) !== '' ? e(mb_substr((string)$dupe['last_name'], 0, 1)) . '.' : '')
                            : e($dupe['first_name'] . ' ' . $dupe['last_name']);
                        $dupeEmail = $masked ? e(mask_candidate_email($dupe['email'])) : e($dupe['email'] ?? '—');
                        $dupePhone = $masked ? e(mask_candidate_phone($dupe['phone'])) : e($dupe['phone'] ?? '—');
                        ?>
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6 class="mb-1 text-primary fw-bold">
                                    <?= $dupeName ?>
                                    <span class="badge bg-body-secondary text-body border text-muted ms-1"><?= e($dupe['candidate_code']) ?></span>
                                </h6>
                                <div class="small text-muted">
                                    <span><strong>Email:</strong> <?= $dupeEmail ?></span> &bull;
                                    <span><strong>Phone:</strong> <?= $dupePhone ?></span>
                                    <?php if (!$masked): ?>
                                        &bull; <span><strong>NIC/Passport:</strong> <?= e($dupe['nic_passport'] ?? '—') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-secondary">
                                    Registered: <?= e(format_date($dupe['created_at'])) ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . (int)$dupe['id'])) ?>" class="btn btn-sm btn-success">
                                    Reuse & Apply to Vacancy
                                </a>
                                <?php if (!$masked): ?>
                                    <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$dupe['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        View Existing Profile
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="p-3 bg-body-tertiary rounded border">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <div class="fw-semibold">Are you certain this is a different individual?</div>
                            <small class="text-muted">You can force creation of a new separate profile with the button on the right.</small>
                        </div>
                        <button type="button" class="btn btn-outline-danger" onclick="document.getElementById('confirmDuplicateField').value='1'; document.getElementById('candidateForm').submit();">
                            Create As New Profile Anyway
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="post" action="<?= $isEdit ? e(url('modules/candidates/form.php?id=' . (int)$id)) : e(url('modules/candidates/form.php')) ?>" id="candidateForm" enctype="multipart/form-data" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="confirm_duplicate" id="confirmDuplicateField" value="0">

                <div class="row g-3">
                    <!-- Name Fields -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($firstName) ?>" required maxlength="80">
                        <?php if (isset($errors['first_name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['first_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($lastName) ?>" required maxlength="80">
                        <?php if (isset($errors['last_name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['last_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Contact & Identity -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($email) ?>" placeholder="candidate@example.com" maxlength="150">
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="phone" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                               value="<?= e($phone) ?>" placeholder="+94 77 123 4567" maxlength="30">
                        <?php if (isset($errors['phone'])): ?>
                            <div class="invalid-feedback"><?= e($errors['phone']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">NIC / Passport</label>
                        <input type="text" name="nic_passport" class="form-control <?= isset($errors['nic_passport']) ? 'is-invalid' : '' ?>"
                               value="<?= e($nicPassport) ?>" placeholder="NIC or Passport No." maxlength="30">
                        <?php if (isset($errors['nic_passport'])): ?>
                            <div class="invalid-feedback"><?= e($errors['nic_passport']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Personal Demographics -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date of Birth</label>
                        <input type="date" name="dob" class="form-control <?= isset($errors['dob']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string)$dob) ?>">
                        <?php if (isset($errors['dob'])): ?>
                            <div class="invalid-feedback"><?= e($errors['dob']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Gender</label>
                        <select name="gender" class="form-select <?= isset($errors['gender']) ? 'is-invalid' : '' ?>">
                            <option value="">-- Select Gender --</option>
                            <?php foreach (candidate_gender_list() as $gk => $gl): ?>
                                <option value="<?= e($gk) ?>"<?= $gender === $gk ? ' selected' : '' ?>><?= e($gl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['gender'])): ?>
                            <div class="invalid-feedback"><?= e($errors['gender']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Source / Channel</label>
                        <select name="source" class="form-select <?= isset($errors['source']) ? 'is-invalid' : '' ?>">
                            <?php foreach (candidate_source_list() as $sk => $sl): ?>
                                <option value="<?= e($sk) ?>"<?= $source === $sk ? ' selected' : '' ?>><?= e($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['source'])): ?>
                            <div class="invalid-feedback"><?= e($errors['source']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Address -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Residential Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Full address..."><?= e($address) ?></textarea>
                    </div>

                    <!-- Experience & Qualifications -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Experience (Years)</label>
                        <input type="number" step="0.5" min="0" max="60" name="experience_years"
                               class="form-control <?= isset($errors['experience_years']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string)$experienceYears) ?>" placeholder="e.g. 4.5">
                        <?php if (isset($errors['experience_years'])): ?>
                            <div class="invalid-feedback"><?= e($errors['experience_years']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Key Skills</label>
                        <input type="text" name="skills" class="form-control"
                               value="<?= e($skills) ?>" placeholder="e.g. PHP, Laravel, MySQL, Project Management">
                        <div class="form-text">Comma-separated list of core competencies.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Qualifications & Education</label>
                        <textarea name="qualifications" class="form-control" rows="3" placeholder="Degrees, diplomas, certifications, notable achievements..."><?= e($qualifications) ?></textarea>
                    </div>

                    <!-- CV Upload -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Upload CV / Resume (Optional)</label>
                        <input type="file" name="cv_file" class="form-control <?= isset($errors['cv_file']) ? 'is-invalid' : '' ?>"
                               accept=".pdf,.docx,.jpg,.jpeg,.png">
                        <div class="form-text">Allowed formats: PDF, DOCX, JPG, PNG (Max 5 MB). Stored securely outside public root.</div>
                        <?php if (isset($errors['cv_file'])): ?>
                            <div class="invalid-feedback"><?= e($errors['cv_file']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Consent -->
                    <div class="col-12 mt-3">
                        <div class="form-check">
                            <input class="form-check-input <?= isset($errors['consent_given']) ? 'is-invalid' : '' ?>" type="checkbox" name="consent_given" id="consentGiven" value="1" <?= $consentGiven ? 'checked' : '' ?><?= !$isEdit ? ' required' : '' ?>>
                            <label class="form-check-label small" for="consentGiven">
                                Candidate has provided privacy consent for AaZa Group to retain profile data and documents in the talent pool for recruitment purposes. <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
                            </label>
                            <?php if (isset($errors['consent_given'])): ?>
                                <div class="invalid-feedback"><?= e($errors['consent_given']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="col-12 mt-4 d-flex justify-content-between align-items-center">
                        <a href="<?= $isEdit ? e(url('modules/candidates/view.php?id=' . (int)$id)) : e(url('modules/candidates/index.php')) ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <?= $isEdit ? 'Update Candidate' : 'Save Candidate' ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
