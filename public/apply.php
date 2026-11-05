<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$preVacancyId = (int)($_GET['vacancy_id'] ?? 0);
$successApp = null;
$errors = [];

// Fetch list of currently open vacancies across active companies
$vacSt = $pdo->query(
    "SELECT v.id, v.vacancy_code, v.title, v.company_id,
            c.name AS company_name, c.code AS company_code,
            d.name AS department_name
       FROM job_vacancies v
       JOIN companies c ON c.id = v.company_id AND c.is_active = 1
  LEFT JOIN departments d ON d.id = v.department_id
      WHERE v.status = 'open'
   ORDER BY c.name ASC, v.title ASC"
);
$openVacancies = $vacSt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $ip = client_ip();

    // 1. Honeypot check
    if (!empty($_POST['hp_website'])) {
        // Honeypot filled by bot: terminate with 400
        abort(400, 'Invalid request submission.');
    }

    // 2. IP Rate limit: at most 5 submissions per IP per hour
    $stRate = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
          WHERE ip_address = ? AND action = 'public_apply' AND created_at >= NOW() - INTERVAL 1 HOUR"
    );
    $stRate->execute([$ip]);
    if ((int)$stRate->fetchColumn() >= 5) {
        $errors[] = 'You have reached the maximum number of job applications allowed per hour from this network. Please try again later.';
    }

    $vacancyId   = (int)($_POST['vacancy_id'] ?? $preVacancyId);
    $firstName   = trim((string)($_POST['first_name'] ?? ''));
    $lastName    = trim((string)($_POST['last_name'] ?? ''));
    $email       = trim((string)($_POST['email'] ?? ''));
    $phone       = trim((string)($_POST['phone'] ?? ''));
    $nicPassport = trim((string)($_POST['nic_passport'] ?? ''));
    $expYears    = (string)($_POST['experience_years'] ?? '0');
    $notes       = trim((string)($_POST['notes'] ?? ''));

    // Validate Vacancy
    $vSt = $pdo->prepare('SELECT id, vacancy_code, title, company_id, status FROM job_vacancies WHERE id = ? LIMIT 1');
    $vSt->execute([$vacancyId]);
    $vacancy = $vSt->fetch();
    if (!$vacancy || $vacancy['status'] !== 'open') {
        $errors[] = 'The selected vacancy is invalid or no longer open for applications.';
    }

    if ($firstName === '' || mb_strlen($firstName) > 50) {
        $errors[] = 'First name is required (max 50 characters).';
    }
    if ($lastName === '' || mb_strlen($lastName) > 50) {
        $errors[] = 'Last name is required (max 50 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'A valid email address is required.';
    }
    if ($phone === '' || mb_strlen($phone) > 30) {
        $errors[] = 'A contact phone number is required (max 30 characters).';
    }
    if (!is_numeric($expYears) || (float)$expYears < 0 || (float)$expYears > 50) {
        $errors[] = 'Experience years must be a valid number between 0 and 50.';
    }

    // Resume Upload Validation
    $hasFile = isset($_FILES['resume']) && $_FILES['resume']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        $file = $_FILES['resume'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code: ' . (int)$file['error'];
        } else {
            $maxBytes = 5 * 1024 * 1024; // 5 MB
            if ($file['size'] > $maxBytes) {
                $errors[] = 'Resume file exceeds the maximum allowed size of 5 MB.';
            }
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'doc', 'docx'];
            if (!in_array($ext, $allowedExts, true)) {
                $errors[] = 'Only PDF, DOC, and DOCX resume formats are supported.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowedMimes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/octet-stream',
                ];
                if (!in_array($mime, $allowedMimes, true)) {
                    $errors[] = 'Uploaded file MIME type is invalid or disallowed.';
                }
            }
        }
    }

    if (empty($errors)) {
        $uploadedFilePath = null;
        try {
            $pdo->beginTransaction();

            // Find or create Candidate
            $candSt = $pdo->prepare('SELECT id, candidate_code FROM candidates WHERE email = ? LIMIT 1');
            $candSt->execute([$email]);
            $cand = $candSt->fetch();

            if ($cand) {
                $candidateId = (int)$cand['id'];
                $candidateCode = (string)$cand['candidate_code'];
                // Update latest phone / NIC if provided
                $candUp = $pdo->prepare('UPDATE candidates SET first_name=?, last_name=?, phone=?, nic_passport=COALESCE(NULLIF(?, ""), nic_passport), experience_years=? WHERE id=?');
                $candUp->execute([$firstName, $lastName, $phone, $nicPassport, (float)$expYears, $candidateId]);
            } else {
                // Generate unique candidate code
                $codeSt = $pdo->query("SELECT MAX(id) FROM candidates");
                $nextNum = ((int)$codeSt->fetchColumn()) + 1;
                $candidateCode = 'CAND-' . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);

                $insCand = $pdo->prepare(
                    'INSERT INTO candidates (candidate_code, first_name, last_name, email, phone, nic_passport, experience_years)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insCand->execute([$candidateCode, $firstName, $lastName, $email, $phone, $nicPassport !== '' ? $nicPassport : null, (float)$expYears]);
                $candidateId = (int)$pdo->lastInsertId();

                log_activity('candidate.create', 'candidates', $candidateId, [
                    'candidate_code' => $candidateCode,
                    'email'          => $email,
                    'via'            => 'public_apply',
                ]);
            }

            // Check if application for this vacancy already exists
            $dupSt = $pdo->prepare('SELECT id, status FROM candidate_applications WHERE candidate_id = ? AND vacancy_id = ? LIMIT 1');
            $dupSt->execute([$candidateId, $vacancyId]);
            $existingApp = $dupSt->fetch();

            if ($existingApp) {
                throw new RuntimeException('An application has already been submitted for this position using this email address (Status: ' . $existingApp['status'] . ').');
            }

            // Insert Application
            $companyId = (int)$vacancy['company_id'];
            $insApp = $pdo->prepare(
                'INSERT INTO candidate_applications (candidate_id, vacancy_id, company_id, application_date, status, notes)
                 VALUES (?, ?, ?, CURDATE(), "applied", ?)'
            );
            $insApp->execute([$candidateId, $vacancyId, $companyId, $notes !== '' ? $notes : null]);
            $appId = (int)$pdo->lastInsertId();

            // Status history
            $histSt = $pdo->prepare(
                'INSERT INTO candidate_status_history (application_id, old_status, new_status, remarks, changed_by, changed_at)
                 VALUES (?, NULL, "applied", "Public online application submission", NULL, NOW())'
            );
            $histSt->execute([$appId]);

            // Save resume document if attached
            if ($hasFile) {
                $targetDir = STORAGE_PATH . '/uploads/candidates';
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                $destPath = $targetDir . '/' . $storedName;

                if (!move_uploaded_file($_FILES['resume']['tmp_name'], $destPath)) {
                    throw new RuntimeException('Failed to securely store uploaded resume document.');
                }
                $uploadedFilePath = $destPath;

                $docSt = $pdo->prepare(
                    'INSERT INTO candidate_documents (candidate_id, doc_type, original_name, stored_name, mime_type, file_size, uploaded_by, uploaded_at)
                     VALUES (?, "cv", ?, ?, ?, ?, NULL, NOW())'
                );
                $docSt->execute([
                    $candidateId,
                    basename((string)$_FILES['resume']['name']),
                    $storedName,
                    $mime,
                    (int)$_FILES['resume']['size'],
                ]);
                $docId = (int)$pdo->lastInsertId();

                log_activity('candidate.document_upload', 'candidate_documents', $docId, [
                    'candidate_id'  => $candidateId,
                    'document_type' => 'cv',
                    'original_name' => basename((string)$_FILES['resume']['name']),
                ], $companyId);
            }

            // Record Rate Limit
            $pdo->prepare("INSERT INTO rate_limits (ip_address, action, created_at) VALUES (?, 'public_apply', NOW())")
                ->execute([$ip]);

            // Log activity
            log_activity('candidate.apply', 'candidate_applications', $appId, [
                'candidate_id'   => $candidateId,
                'candidate_code' => $candidateCode,
                'vacancy_id'     => $vacancyId,
                'vacancy_code'   => $vacancy['vacancy_code'],
                'via'            => 'public_form',
            ], $companyId);

            $pdo->commit();

            // Notify HR about new application
            $applicantName = trim($firstName . ' ' . $lastName);
            notify_permission(
                $pdo,
                $companyId,
                'candidate.view',
                'candidate_application',
                'New Job Application',
                "New application received from {$applicantName} for {$vacancy['title']}.",
                'modules/candidates/view.php?id=' . $candidateId,
                'app:new:' . $appId
            );

            $successApp = [
                'app_id'         => $appId,
                'candidate_code' => $candidateCode,
                'vacancy_title'  => $vacancy['title'],
                'vacancy_code'   => $vacancy['vacancy_code'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($uploadedFilePath !== null && file_exists($uploadedFilePath)) {
                @unlink($uploadedFilePath);
            }
            error_log('Public application error: ' . $e->getMessage());
            $errors[] = ($e instanceof RuntimeException) ? $e->getMessage() : 'An error occurred while submitting your application. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Job Application | <?= e(APP_NAME) ?></title>
    <!-- Prevent theme flash -->
    <script>
    (function() {
        try {
            var stored = localStorage.getItem('aaza-theme');
            var theme = stored;
            if (!theme || theme === 'auto') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/img/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= e(url('assets/img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>

<div class="container py-4" style="max-width: 860px;">
    <!-- Top utility bar with theme toggle -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="<?= e(url('public/login.php')) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Staff Login
        </a>
        <button type="button" class="theme-toggle-btn" aria-label="Toggle theme" title="Toggle theme">
            <i class="bi bi-moon-stars"></i>
        </button>
    </div>

    <!-- Brand / Header -->
    <div class="text-center mb-4">
        <img src="<?= e(url('assets/img/aaza-icon.png')) ?>" alt="<?= e(APP_NAME) ?>" style="height: 52px;" class="mb-3">
        <h2 class="fw-bold font-sans"><?= e(APP_NAME) ?> Careers Portal</h2>
        <p class="text-muted small">Submit your application to join our growing group companies.</p>
    </div>

    <?php if ($successApp !== null): ?>
        <div class="card shadow-sm border-0 text-center p-5">
            <div class="mb-3">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
            </div>
            <h3 class="fw-bold text-body font-sans mb-2">Application Submitted Successfully!</h3>
            <p class="text-muted lead mb-4 font-sans">
                Thank you for applying for <strong><?= e($successApp['vacancy_title']) ?></strong> (<?= e($successApp['vacancy_code']) ?>).
            </p>
            <div class="p-3 bg-body-tertiary rounded border d-inline-block mx-auto mb-4 font-monospace">
                <span class="text-muted">Application Reference:</span>
                <span class="fw-bold text-primary fs-5 ms-2">#<?= (int)$successApp['app_id'] ?></span>
                <span class="text-muted ms-3">Candidate Code:</span>
                <span class="fw-bold text-body ms-2"><?= e($successApp['candidate_code']) ?></span>
            </div>
            <div>
                <a href="<?= e(url('public/apply.php')) ?>" class="btn btn-outline-primary">Submit Another Application</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-body border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold text-body font-sans d-flex align-items-center">
                    <i class="bi bi-person-lines-fill text-primary me-2"></i>Job Application Form
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('public/apply.php')) ?>" enctype="multipart/form-data" class="font-sans">
                    <?= csrf_field() ?>

                    <!-- Honeypot anti-spam trap (invisible to human users) -->
                    <div style="display:none !important;" aria-hidden="true">
                        <input type="text" name="hp_website" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Target Vacancy -->
                    <div class="mb-3">
                        <label for="vacancy_id" class="form-label fw-semibold small text-muted">Position / Vacancy <span class="text-danger">*</span></label>
                        <select name="vacancy_id" id="vacancy_id" class="form-select" required>
                            <option value="">-- Select Open Vacancy --</option>
                            <?php foreach ($openVacancies as $v): ?>
                                <option value="<?= (int)$v['id'] ?>"<?= ($preVacancyId === (int)$v['id']) ? ' selected' : '' ?>>
                                    <?= e($v['title']) ?> (<?= e($v['vacancy_code']) ?>) &bull; <?= e($v['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label fw-semibold small text-muted">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required
                                   maxlength="50" value="<?= e((string)($_POST['first_name'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label fw-semibold small text-muted">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required
                                   maxlength="50" value="<?= e((string)($_POST['last_name'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold small text-muted">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="email" class="form-control" required
                                   maxlength="150" value="<?= e((string)($_POST['email'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold small text-muted">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" id="phone" class="form-control" required
                                   maxlength="30" value="<?= e((string)($_POST['phone'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="nic_passport" class="form-label fw-semibold small text-muted">NIC / Passport Number</label>
                            <input type="text" name="nic_passport" id="nic_passport" class="form-control"
                                   maxlength="30" value="<?= e((string)($_POST['nic_passport'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="experience_years" class="form-label fw-semibold small text-muted">Total Experience (Years)</label>
                            <input type="number" step="0.5" min="0" max="50" name="experience_years" id="experience_years" class="form-control"
                                   value="<?= e((string)($_POST['experience_years'] ?? '0')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="resume" class="form-label fw-semibold small text-muted">Resume / CV (PDF, DOC, DOCX - Max 5MB)</label>
                        <input type="file" name="resume" id="resume" class="form-control" accept=".pdf,.doc,.docx">
                    </div>

                    <div class="mb-4">
                        <label for="notes" class="form-label fw-semibold small text-muted">Cover Letter / Additional Information</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3"
                                  placeholder="Introduce yourself or highlight key accomplishments..."><?= e((string)($_POST['notes'] ?? '')) ?></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary py-2 fw-semibold">
                            <i class="bi bi-send me-1"></i>Submit Application
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer border-top py-2 px-3 small text-muted font-sans text-center">
                <i class="bi bi-shield-lock me-1"></i>Your information is protected by AaZa HRMS enterprise privacy controls.
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
