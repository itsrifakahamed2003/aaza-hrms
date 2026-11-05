<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$interviewId = (int)($_GET['id'] ?? $_POST['interview_id'] ?? 0);

if ($interviewId <= 0) {
    abort(404, 'Interview ID is required.');
}

// Fetch interview details - looking up company_id strictly from DB record
$intSt = $pdo->prepare(
    'SELECT i.*,
            ca.candidate_id, ca.vacancy_id,
            c.first_name, c.last_name, c.candidate_code,
            v.title AS vacancy_title, v.vacancy_code,
            comp.name AS company_name, comp.code AS company_code
       FROM interviews i
       JOIN candidate_applications ca ON ca.id = i.application_id
       JOIN candidates c ON c.id = ca.candidate_id
       JOIN job_vacancies v ON v.id = ca.vacancy_id
       JOIN companies comp ON comp.id = i.company_id
      WHERE i.id = ?
      LIMIT 1'
);
$intSt->execute([$interviewId]);
$interview = $intSt->fetch();

if (!$interview) {
    abort(404, 'Interview record not found.');
}

$companyId = (int)$interview['company_id'];
$currentUserId = current_user_id();

// RULE: Only a panel member of that interview can submit
$panelCheck = $pdo->prepare(
    'SELECT 1 FROM interview_panel WHERE interview_id = ? AND user_id = ? LIMIT 1'
);
$panelCheck->execute([$interviewId, $currentUserId]);
if (!$panelCheck->fetchColumn()) {
    log_activity('access.denied', 'interview', $interviewId, ['reason' => 'not_panel_member'], $companyId);
    abort(403, 'You are not assigned to the interview panel for this session.');
}

// RULE: Evaluation allowed only when interview status is 'scheduled' or 'completed'
if (!in_array($interview['status'], ['scheduled', 'completed'], true)) {
    flash_set('danger', 'Evaluation is allowed only when the interview is scheduled or completed.');
    redirect('modules/interviews/view.php?id=' . $interviewId);
}

// RULE: Allowed only after scheduled_at has passed
if (strtotime((string)$interview['scheduled_at']) > time()) {
    flash_set('warning', 'Evaluations can only be submitted after the scheduled interview time has passed.');
    redirect('modules/interviews/view.php?id=' . $interviewId);
}

// RULE: One evaluation per evaluator
$existingSt = $pdo->prepare(
    'SELECT id FROM interview_evaluations WHERE interview_id = ? AND evaluator_id = ? LIMIT 1'
);
$existingSt->execute([$interviewId, $currentUserId]);
if ($existingSt->fetch()) {
    flash_set('info', 'You have already submitted your evaluation for this interview.');
    redirect('modules/interviews/view.php?id=' . $interviewId);
}

$scoreScale = function_exists('get_company_setting')
    ? (int)get_company_setting($pdo, $companyId, 'recruitment.interview_score_scale', 5)
    : 5;
if ($scoreScale !== 10) {
    $scoreScale = 5;
}

$scoreOptions = [];
if ($scoreScale === 10) {
    for ($i = 10; $i >= 1; $i--) {
        $scoreOptions[$i] = match ($i) {
            10, 9 => "$i - Exceptional",
            8, 7  => "$i - Strong",
            6, 5  => "$i - Competent",
            4, 3  => "$i - Needs Improvement",
            default => "$i - Unsatisfactory",
        };
    }
} else {
    $scoreOptions = [
        5 => '5 - Exceptional',
        4 => '4 - Strong',
        3 => '3 - Competent',
        2 => '2 - Needs Improvement',
        1 => '1 - Unsatisfactory',
    ];
}

$errors = [];
$formData = [
    'technical_score'     => '',
    'communication_score' => '',
    'attitude_score'      => '',
    'recommendation'      => '',
    'comments'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $techScore = (int)($_POST['technical_score'] ?? 0);
    $commScore = (int)($_POST['communication_score'] ?? 0);
    $attScore  = (int)($_POST['attitude_score'] ?? 0);
    $recommendation = trim((string)($_POST['recommendation'] ?? ''));
    $comments = trim((string)($_POST['comments'] ?? ''));

    $formData = [
        'technical_score'     => (string)$techScore,
        'communication_score' => (string)$commScore,
        'attitude_score'      => (string)$attScore,
        'recommendation'      => $recommendation,
        'comments'            => $comments,
    ];

    // Validate scores against company score scale
    if ($techScore < 1 || $techScore > $scoreScale) {
        $errors[] = "Technical competency score must be between 1 and {$scoreScale}.";
    }
    if ($commScore < 1 || $commScore > $scoreScale) {
        $errors[] = "Communication score must be between 1 and {$scoreScale}.";
    }
    if ($attScore < 1 || $attScore > $scoreScale) {
        $errors[] = "Attitude / Culture fit score must be between 1 and {$scoreScale}.";
    }

    // Validate recommendation
    $validRecs = evaluation_recommendation_list();
    if (!isset($validRecs[$recommendation])) {
        $errors[] = 'Please select a valid hiring recommendation.';
    }

    if (empty($comments)) {
        $errors[] = 'Please provide evaluation feedback comments.';
    }

    if (empty($errors)) {
        // Overall score is computed server-side as the average, never trusted from form
        $overallScore = round(($techScore + $commScore + $attScore) / 3.0, 2);

        try {
            $pdo->beginTransaction();

            // Lock interview row
            $intLockSt = $pdo->prepare('SELECT * FROM interviews WHERE id = ? FOR UPDATE');
            $intLockSt->execute([$interviewId]);
            $lockedInt = $intLockSt->fetch();

            if (!$lockedInt || !in_array($lockedInt['status'], ['scheduled', 'completed'], true)) {
                throw new RuntimeException('Evaluation is allowed only when the interview is scheduled or completed.');
            }

            if (strtotime((string)$lockedInt['scheduled_at']) > time()) {
                throw new RuntimeException('Evaluations can only be submitted after the scheduled interview time has passed.');
            }

            // Check duplicate submission under lock
            $existingLockSt = $pdo->prepare('SELECT id FROM interview_evaluations WHERE interview_id = ? AND evaluator_id = ? LIMIT 1');
            $existingLockSt->execute([$interviewId, $currentUserId]);
            if ($existingLockSt->fetch()) {
                throw new RuntimeException('You have already submitted your evaluation for this interview.');
            }

            $insSt = $pdo->prepare(
                'INSERT INTO interview_evaluations (
                    interview_id, evaluator_id,
                    technical_score, communication_score, attitude_score,
                    overall_score, recommendation, comments,
                    submitted_at, evaluated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $insSt->execute([
                $interviewId,
                $currentUserId,
                $techScore,
                $commScore,
                $attScore,
                $overallScore,
                $recommendation,
                $comments,
            ]);

            // Log activity for audit
            log_activity('interview.evaluate', 'interview', $interviewId, [
                'evaluator_id'   => $currentUserId,
                'overall_score'  => $overallScore,
                'recommendation' => $recommendation,
            ], $companyId);

            // Check if every panel member has evaluated
            $totalPanelSt = $pdo->prepare('SELECT COUNT(*) FROM interview_panel WHERE interview_id = ?');
            $totalPanelSt->execute([$interviewId]);
            $totalPanelCount = (int)$totalPanelSt->fetchColumn();

            $evalCountSt = $pdo->prepare('SELECT COUNT(*) FROM interview_evaluations WHERE interview_id = ?');
            $evalCountSt->execute([$interviewId]);
            $evalCount = (int)$evalCountSt->fetchColumn();

            if ($totalPanelCount > 0 && $evalCount >= $totalPanelCount) {
                // Auto-complete the interview if it is currently 'scheduled'
                if ($lockedInt['status'] === 'scheduled') {
                    $compSt = $pdo->prepare("UPDATE interviews SET status = 'completed', updated_at = NOW() WHERE id = ?");
                    $compSt->execute([$interviewId]);

                    log_activity('interview.complete', 'interview', $interviewId, [
                        'auto_completed' => true,
                        'reason'         => 'all_panel_members_evaluated',
                    ], $companyId);
                }

                // Apply rule in B:
                // Set application status to 'interviewed' if currently 'interview_scheduled' and no other 'scheduled' interviews
                $appId = (int)$lockedInt['application_id'];
                $appSt = $pdo->prepare('SELECT status FROM candidate_applications WHERE id = ? FOR UPDATE');
                $appSt->execute([$appId]);
                $currAppStatus = (string)$appSt->fetchColumn();

                if ($currAppStatus === 'interview_scheduled') {
                    $otherSchedSt = $pdo->prepare("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND status = 'scheduled' AND id != ?");
                    $otherSchedSt->execute([$appId, $interviewId]);
                    if ((int)$otherSchedSt->fetchColumn() === 0) {
                        update_candidate_application_status(
                            $pdo,
                            $appId,
                            'interviewed',
                            'All panel evaluations completed for interview #' . $interviewId,
                            $currentUserId
                        );
                    }
                }
            }

            $pdo->commit();

            // Notify HR about evaluation submission
            $evaluatorName = current_user()['full_name'];
            $candName = trim($interview['first_name'] . ' ' . $interview['last_name']);
            notify_permission(
                $pdo,
                $companyId,
                'interview.manage',
                'evaluation_submitted',
                'Interview Evaluation Submitted',
                "{$evaluatorName} submitted an evaluation for {$candName} (Round {$interview['round_no']}).",
                'modules/interviews/view.php?id=' . $interviewId,
                'eval:sub:' . $interviewId . ':' . $currentUserId,
                $currentUserId
            );

            flash_set('success', 'Your evaluation has been submitted successfully.');
            redirect('modules/interviews/view.php?id=' . $interviewId);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Evaluation submission error: ' . $e->getMessage());
            $errors[] = 'A system error occurred while recording the evaluation.';
        }
    }
}

$pageTitle = 'Submit Evaluation | Round ' . $interview['round_no'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4" style="max-width: 820px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('modules/interviews/index.php')) ?>">Interviews</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">Round <?= (int)$interview['round_no'] ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Evaluate</li>
        </ol>
    </nav>

    <!-- Header Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1 text-body">
                        Candidate Evaluation: <?= e($interview['first_name'] . ' ' . $interview['last_name']) ?>
                    </h4>
                    <div class="text-secondary small">
                        Vacancy: <strong><?= e($interview['vacancy_title']) ?></strong> (<?= e($interview['vacancy_code']) ?>)
                        &bull; Round <?= (int)$interview['round_no'] ?> (<?= e(interview_type_list()[$interview['interview_type']] ?? $interview['interview_type']) ?>)
                        &bull; Conducted: <?= e(format_datetime($interview['scheduled_at'])) ?>
                    </div>
                </div>
                <span class="badge bg-body-secondary text-body border px-3 py-2">
                    <i class="bi bi-shield-lock me-1 text-success"></i>Confidential Assessment
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-circle-fill me-1"></i>Please fix the following issues:</div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Evaluation Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0 fw-semibold">
                <i class="bi bi-star text-warning me-2"></i>Performance Scores &amp; Hiring Recommendation
            </h5>
        </div>
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('modules/interviews/evaluate.php?id=' . $interviewId)) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="interview_id" value="<?= $interviewId ?>">

                <p class="text-muted small mb-4">
                    Score each area from <strong>1 (Unsatisfactory)</strong> to <strong><?= $scoreScale ?> (Exceptional)</strong>. The overall score will be computed automatically as the exact average of your ratings.
                </p>

                <!-- Scoring Section -->
                <div class="row g-4 mb-4">
                    <!-- Technical Score -->
                    <div class="col-md-4">
                        <label for="technical_score" class="form-label fw-semibold small text-secondary">
                            Technical Competency <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="technical_score" name="technical_score" required>
                            <option value="">Select (1 - <?= $scoreScale ?>)</option>
                            <?php foreach ($scoreOptions as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= (int)$formData['technical_score'] === $val ? 'selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Domain skills, problem solving, role depth.</div>
                    </div>

                    <!-- Communication Score -->
                    <div class="col-md-4">
                        <label for="communication_score" class="form-label fw-semibold small text-secondary">
                            Communication Skills <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="communication_score" name="communication_score" required>
                            <option value="">Select (1 - <?= $scoreScale ?>)</option>
                            <?php foreach ($scoreOptions as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= (int)$formData['communication_score'] === $val ? 'selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Clarity, articulation, listening, English proficiency.</div>
                    </div>

                    <!-- Attitude Score -->
                    <div class="col-md-4">
                        <label for="attitude_score" class="form-label fw-semibold small text-secondary">
                            Attitude &amp; Culture Fit <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="attitude_score" name="attitude_score" required>
                            <option value="">Select (1 - <?= $scoreScale ?>)</option>
                            <?php foreach ($scoreOptions as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= (int)$formData['attitude_score'] === $val ? 'selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Team spirit, enthusiasm, values alignment.</div>
                    </div>
                </div>

                <!-- Recommendation Section -->
                <div class="mb-4">
                    <label class="form-label fw-semibold small text-secondary">
                        Overall Hiring Recommendation <span class="text-danger">*</span>
                    </label>
                    <div class="row g-2">
                        <?php foreach (evaluation_recommendation_list() as $rKey => $rLabel): ?>
                            <?php
                            $badgeColor = match($rKey) {
                                'strong_hire' => 'border-success text-success',
                                'hire'        => 'border-primary text-primary',
                                'hold'        => 'border-warning text-body',
                                'reject'      => 'border-danger text-danger',
                                default       => 'border-secondary',
                            };
                            ?>
                            <div class="col-md-3 col-sm-6">
                                <div class="form-check border rounded p-2 text-center h-100">
                                    <input class="form-check-input ms-0 me-2" type="radio" name="recommendation"
                                           id="rec_<?= e($rKey) ?>" value="<?= e($rKey) ?>"
                                           <?= $formData['recommendation'] === $rKey ? 'checked' : '' ?> required>
                                    <label class="form-check-label fw-semibold small <?= $badgeColor ?>" for="rec_<?= e($rKey) ?>">
                                        <?= e($rLabel) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Qualitative Comments -->
                <div class="mb-4">
                    <label for="comments" class="form-label fw-semibold small text-secondary">
                        Detailed Evaluation Feedback &amp; Justification <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="comments" name="comments" rows="5"
                              placeholder="Provide specific observations, candidate strengths, concerns, and justification for your recommendation..." required><?= e($formData['comments']) ?></textarea>
                </div>

                <!-- Submission Controls -->
                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <a href="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-success px-4" data-confirm="Submit this evaluation? You will not be able to edit it afterwards." data-confirm-type="warning" data-confirm-title="Submit Confidential Evaluation" data-confirm-btn="Submit Evaluation">
                        <i class="bi bi-check2-circle me-1"></i>Submit Confidential Evaluation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
