<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0;
$review = null;
$existingGoals = [];

$preselectEmpId = (int)($_GET['employee_id'] ?? 0);

if ($editing) {
    $st = $pdo->prepare('SELECT * FROM performance_reviews WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $review = $st->fetch(PDO::FETCH_ASSOC);

    if (!$review) {
        abort(404, 'Performance review not found.');
    }

    if (!can_user_edit_performance_review($pdo, $currentUserId, $review)) {
        if (($review['status'] ?? '') === 'acknowledged') {
            flash_set('warning', 'This performance review has already been acknowledged by the employee and is locked. An HR Manager must reopen it before changes can be made.');
            redirect('modules/performance/view.php?id=' . $id);
        }
        abort(403, 'You do not have permission to edit this performance review.');
    }

    // Load existing goals
    $stGoals = $pdo->prepare('SELECT * FROM performance_goals WHERE review_id = ? ORDER BY id ASC');
    $stGoals->execute([$id]);
    $existingGoals = $stGoals->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch list of candidate employees this user can review
$myEmp = get_linked_employee_for_user($pdo, $currentUserId);
$myEmpId = $myEmp ? (int)$myEmp['id'] : 0;
$directReportIds = $myEmpId > 0 ? get_direct_report_employee_ids($pdo, $currentUserId) : [];

$manageCompanyIds = accessible_company_ids('performance.manage');
$isAdmin = is_group_admin();

$empConditions = [];
$empParams = [];

if ($isAdmin) {
    $empConditions[] = "e.employment_status = 'active'";
} elseif (!empty($manageCompanyIds)) {
    $ph = implode(',', array_fill(0, count($manageCompanyIds), '?'));
    $empConditions[] = "(e.company_id IN ($ph) AND e.employment_status = 'active')";
    foreach ($manageCompanyIds as $cid) {
        $empParams[] = $cid;
    }
}

if (!empty($directReportIds)) {
    $phReports = implode(',', array_fill(0, count($directReportIds), '?'));
    $empConditions[] = "(e.id IN ($phReports) AND e.employment_status = 'active')";
    foreach ($directReportIds as $rid) {
        $empParams[] = $rid;
    }
}

if (empty($empConditions)) {
    abort(403, 'You do not have permission to create performance reviews.');
}

$empWhere = implode(' OR ', $empConditions);
$stEmps = $pdo->prepare(
    "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.company_id,
            c.code AS company_code, c.name AS company_name,
            d.name AS department_name, des.title AS designation_title
       FROM employees e
       JOIN companies c ON c.id = e.company_id
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN designations des ON des.id = e.designation_id
      WHERE {$empWhere}
   ORDER BY c.name, e.first_name, e.last_name"
);
$stEmps->execute($empParams);
$availableEmployees = $stEmps->fetchAll(PDO::FETCH_ASSOC);
$availableEmpMap = array_column($availableEmployees, null, 'id');

if ($editing && !isset($availableEmpMap[(int)$review['employee_id']])) {
    // If editing, ensure reviewed employee is present in the list
    $stSingle = $pdo->prepare(
        "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.company_id,
                c.code AS company_code, c.name AS company_name,
                d.name AS department_name, des.title AS designation_title
           FROM employees e
           JOIN companies c ON c.id = e.company_id
      LEFT JOIN departments d ON d.id = e.department_id
      LEFT JOIN designations des ON des.id = e.designation_id
          WHERE e.id = ?"
    );
    $stSingle->execute([(int)$review['employee_id']]);
    $singleRow = $stSingle->fetch(PDO::FETCH_ASSOC);
    if ($singleRow) {
        $availableEmployees[] = $singleRow;
        $availableEmpMap[$singleRow['id']] = $singleRow;
    }
}

// Default form state
$form = [
    'employee_id'           => $editing ? (int)$review['employee_id'] : $preselectEmpId,
    'period_label'          => $editing ? (string)$review['period_label'] : 'Annual Review ' . date('Y'),
    'period_start'          => $editing ? (string)$review['period_start'] : date('Y-01-01'),
    'period_end'            => $editing ? (string)$review['period_end'] : date('Y-12-31'),
    'review_date'           => $editing ? (string)$review['review_date'] : date('Y-m-d'),
    'overall_rating'        => $editing ? (int)$review['overall_rating'] : 3,
    'manager_feedback'      => $editing ? (string)($review['manager_feedback'] ?? '') : '',
    'improvement_notes'     => $editing ? (string)($review['improvement_notes'] ?? '') : '',
    'training_requirements' => $editing ? (string)($review['training_requirements'] ?? '') : '',
    'status'                => $editing ? (string)$review['status'] : 'draft',
];

$goals = $existingGoals ?: [
    [
        'title'       => '',
        'description' => '',
        'target_date' => '',
        'progress'    => 0,
        'status'      => 'open',
    ],
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $empId          = (int)($_POST['employee_id'] ?? $form['employee_id']);
    $periodLabel    = trim((string)($_POST['period_label'] ?? ''));
    $periodStart    = trim((string)($_POST['period_start'] ?? ''));
    $periodEnd      = trim((string)($_POST['period_end'] ?? ''));
    $reviewDate     = trim((string)($_POST['review_date'] ?? ''));
    $overallRating  = (int)($_POST['overall_rating'] ?? 0);
    $feedback       = trim((string)($_POST['manager_feedback'] ?? ''));
    $improvement    = trim((string)($_POST['improvement_notes'] ?? ''));
    $training       = trim((string)($_POST['training_requirements'] ?? ''));
    $submitAction   = (string)($_POST['action'] ?? 'save_draft');
    $newStatus      = ($submitAction === 'submit_review') ? 'submitted' : 'draft';

    // Posted goals
    $postedGoals = (array)($_POST['goals'] ?? []);
    $cleanedGoals = [];
    foreach ($postedGoals as $g) {
        $gTitle = trim((string)($g['title'] ?? ''));
        if ($gTitle === '') {
            continue; // Skip empty rows
        }
        $cleanedGoals[] = [
            'id'          => (int)($g['id'] ?? 0),
            'title'       => $gTitle,
            'description' => trim((string)($g['description'] ?? '')),
            'target_date' => trim((string)($g['target_date'] ?? '')) ?: null,
            'progress'    => max(0, min(100, (int)($g['progress'] ?? 0))),
            'status'      => in_array($g['status'] ?? '', ['open', 'achieved', 'missed'], true) ? (string)$g['status'] : 'open',
        ];
    }

    // Validation
    if ($empId <= 0 || !isset($availableEmpMap[$empId])) {
        $errors[] = 'Please select a valid employee whom you are authorized to review.';
    }

    if ($periodLabel === '' || mb_strlen($periodLabel) > 100) {
        $errors[] = 'Review period label is required (e.g., "Q1 2026", max 100 characters).';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
        $errors[] = 'Period start and end dates must be valid calendar dates (YYYY-MM-DD).';
    } elseif ($periodStart > $periodEnd) {
        $errors[] = 'Period start date cannot be later than period end date.';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDate)) {
        $errors[] = 'Review date must be a valid calendar date (YYYY-MM-DD).';
    }

    if ($overallRating < 1 || $overallRating > 5) {
        $errors[] = 'Overall rating must be between 1 (Unsatisfactory) and 5 (Outstanding).';
    }

    if ($feedback === '') {
        $errors[] = 'Manager feedback / appraisal comments are required.';
    }

    $targetEmp = $availableEmpMap[$empId] ?? null;
    $targetCompanyId = $targetEmp ? (int)$targetEmp['company_id'] : 0;

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($editing) {
                $upd = $pdo->prepare(
                    'UPDATE performance_reviews
                        SET period_label = ?, period_start = ?, period_end = ?, review_date = ?,
                            overall_rating = ?, manager_feedback = ?, improvement_notes = ?,
                            training_requirements = ?, status = ?, updated_at = NOW()
                      WHERE id = ?'
                );
                $upd->execute([
                    $periodLabel, $periodStart, $periodEnd, $reviewDate,
                    $overallRating, $feedback, $improvement ?: null,
                    $training ?: null, $newStatus, $id
                ]);
                $reviewId = $id;

                log_activity('performance.update', 'performance_review', $reviewId, [
                    'employee_id'    => $empId,
                    'period_label'   => $periodLabel,
                    'overall_rating' => $overallRating,
                    'status'         => $newStatus,
                ], $targetCompanyId);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO performance_reviews (
                        company_id, employee_id, reviewer_user_id, period_label,
                        period_start, period_end, review_date, overall_rating,
                        manager_feedback, improvement_notes, training_requirements,
                        status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $ins->execute([
                    $targetCompanyId, $empId, $currentUserId, $periodLabel,
                    $periodStart, $periodEnd, $reviewDate, $overallRating,
                    $feedback, $improvement ?: null, $training ?: null,
                    $newStatus
                ]);
                $reviewId = (int)$pdo->lastInsertId();

                log_activity('performance.create', 'performance_review', $reviewId, [
                    'employee_id'    => $empId,
                    'period_label'   => $periodLabel,
                    'overall_rating' => $overallRating,
                    'status'         => $newStatus,
                ], $targetCompanyId);
            }

            // Sync goals
            // Remove goals not in the posted list
            $keptGoalIds = array_filter(array_column($cleanedGoals, 'id'));
            if ($editing && !empty($keptGoalIds)) {
                $phKept = implode(',', array_fill(0, count($keptGoalIds), '?'));
                $delSt = $pdo->prepare("DELETE FROM performance_goals WHERE review_id = ? AND id NOT IN ($phKept)");
                $delSt->execute(array_merge([$reviewId], array_values($keptGoalIds)));
            } elseif ($editing) {
                $pdo->prepare('DELETE FROM performance_goals WHERE review_id = ?')->execute([$reviewId]);
            }

            // Upsert goals
            $insGoal = $pdo->prepare(
                'INSERT INTO performance_goals (
                    review_id, employee_id, company_id, title, description, target_date, progress, status, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $updGoal = $pdo->prepare(
                'UPDATE performance_goals
                    SET title = ?, description = ?, target_date = ?, progress = ?, status = ?, updated_at = NOW()
                  WHERE id = ? AND review_id = ?'
            );

            foreach ($cleanedGoals as $cg) {
                if ($cg['id'] > 0 && $editing) {
                    $updGoal->execute([
                        $cg['title'], $cg['description'] ?: null, $cg['target_date'],
                        $cg['progress'], $cg['status'], $cg['id'], $reviewId
                    ]);
                } else {
                    $insGoal->execute([
                        $reviewId, $empId, $targetCompanyId,
                        $cg['title'], $cg['description'] ?: null, $cg['target_date'],
                        $cg['progress'], $cg['status']
                    ]);
                }
            }

            // Dispatch notification if submitted to employee
            if ($newStatus === 'submitted') {
                $stUser = $pdo->prepare('SELECT user_id, first_name, last_name FROM employees WHERE id = ?');
                $stUser->execute([$empId]);
                $empUser = $stUser->fetch(PDO::FETCH_ASSOC);

                if (!empty($empUser['user_id'])) {
                    $dedupe = "perf:submit:{$reviewId}:" . date('Ymd');
                    notify_user(
                        $pdo,
                        (int)$empUser['user_id'],
                        'performance_submitted',
                        'Performance Review Ready for Acknowledgment',
                        "Your performance review for {$periodLabel} has been submitted by your manager. Please review and acknowledge it.",
                        'modules/performance/view.php?id=' . $reviewId,
                        $targetCompanyId,
                        $dedupe
                    );
                }
            }

            $pdo->commit();

            $msg = ($newStatus === 'submitted')
                ? 'Performance review submitted successfully to the employee.'
                : 'Performance review draft saved successfully.';
            flash_set('success', $msg);
            redirect('modules/performance/view.php?id=' . $reviewId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'An error occurred while saving the review: ' . $e->getMessage();
        }
    }

    // Repopulate form with posted values on validation error
    $form = [
        'employee_id'           => $empId,
        'period_label'          => $periodLabel,
        'period_start'          => $periodStart,
        'period_end'            => $periodEnd,
        'review_date'           => $reviewDate,
        'overall_rating'        => $overallRating,
        'manager_feedback'      => $feedback,
        'improvement_notes'     => $improvement,
        'training_requirements' => $training,
        'status'                => $newStatus,
    ];
    $goals = $cleanedGoals ?: [
        [
            'title'       => '',
            'description' => '',
            'target_date' => '',
            'progress'    => 0,
            'status'      => 'open',
        ],
    ];
}

$pageTitle = $editing ? 'Edit Performance Review' : 'Create Performance Review';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb breadcrumb-clean mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/performance/index.php')) ?>">Performance</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $editing ? 'Edit Review' : 'New Review' ?></li>
        </ol>
    </nav>
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="page-header-title-block">
            <h1 class="page-header-title mb-0"><?= $editing ? 'Edit Performance Review' : 'New Performance Review' ?></h1>
        </div>
        <div class="page-actions">
            <a href="<?= e(url('modules/performance/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to List
            </a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger shadow-sm mb-4">
        <div class="d-flex align-items-center gap-2 fw-semibold mb-2">
            <i class="bi bi-exclamation-triangle-fill"></i> Please fix the following errors:
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('modules/performance/form.php' . ($editing ? '?id=' . $id : ''))) ?>" novalidate autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>

    <div class="row g-4">
        <!-- Reviewer & Scope Card -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">
                        <i class="bi bi-person-lines-fill me-2 text-primary"></i>Employee &amp; Review Period
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold" for="employee_id">
                                Employee Under Review <span class="text-danger">*</span>
                            </label>
                            <?php if ($editing): ?>
                                <input type="hidden" name="employee_id" value="<?= (int)$form['employee_id'] ?>">
                                <input type="text" class="form-control bg-body-secondary" readonly
                                       value="<?= e($availableEmpMap[(int)$form['employee_id']]['first_name'] . ' ' . $availableEmpMap[(int)$form['employee_id']]['last_name'] . ' (' . $availableEmpMap[(int)$form['employee_id']]['employee_code'] . ') - ' . $availableEmpMap[(int)$form['employee_id']]['company_name']) ?>">
                            <?php else: ?>
                                <select name="employee_id" id="employee_id" class="form-select" required>
                                    <option value="">-- Select Employee --</option>
                                    <?php foreach ($availableEmployees as $emp): ?>
                                        <option value="<?= (int)$emp['id'] ?>" <?= (int)$form['employee_id'] === (int)$emp['id'] ? 'selected' : '' ?>>
                                            <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?> (<?= e($emp['employee_code']) ?>) &bull; <?= e($emp['department_name'] ?? 'General') ?> [<?= e($emp['company_code']) ?>]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">You can review active employees in your assigned companies or your direct reporting staff.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="period_label">
                                Period Label <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="period_label" id="period_label" class="form-control"
                                   value="<?= e($form['period_label']) ?>" placeholder="e.g. Q1 2026, Annual Review 2026" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="review_date">
                                Appraisal Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="review_date" id="review_date" class="form-control"
                                   value="<?= e($form['review_date']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="period_start">
                                Period Start Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="period_start" id="period_start" class="form-control"
                                   value="<?= e($form['period_start']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="period_end">
                                Period End Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="period_end" id="period_end" class="form-control"
                                   value="<?= e($form['period_end']) ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Evaluation & Feedback Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">
                        <i class="bi bi-star-fill me-2 text-warning"></i>Appraisal Rating &amp; Qualitative Assessment
                    </h5>
                </div>
                <div class="card-body p-4">
                    <!-- Rating Selector -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold d-block mb-2">
                            Overall Rating <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            <?php for ($r = 5; $r >= 1; $r--): ?>
                                <?php
                                $rLabel = performance_rating_label($r);
                                $isChecked = (int)$form['overall_rating'] === $r;
                                ?>
                                <div class="col-12 col-sm">
                                    <input type="radio" class="btn-check" name="overall_rating" id="rating_<?= $r ?>" value="<?= $r ?>" <?= $isChecked ? 'checked' : '' ?> required>
                                    <label class="btn btn-outline-secondary w-100 py-2 d-flex flex-column align-items-center justify-content-center text-center h-100" for="rating_<?= $r ?>">
                                        <div class="fw-bold fs-5 text-warning">
                                            <?= str_repeat('★', $r) ?>
                                        </div>
                                        <div class="small fw-semibold mt-1 text-body"><?= $r ?> &middot; <?= e($rLabel) ?></div>
                                    </label>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="manager_feedback">
                            Manager Feedback &amp; Key Achievements <span class="text-danger">*</span>
                        </label>
                        <textarea name="manager_feedback" id="manager_feedback" class="form-control" rows="4"
                                  placeholder="Summarize the employee's work output, accomplishments, core competency execution, and team impact..." required><?= e($form['manager_feedback']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="improvement_notes">
                            Areas for Improvement &amp; Development
                        </label>
                        <textarea name="improvement_notes" id="improvement_notes" class="form-control" rows="3"
                                  placeholder="Specific areas, workflows, or behaviors where performance can be elevated..."><?= e($form['improvement_notes']) ?></textarea>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold" for="training_requirements">
                            Training &amp; Upskilling Requirements
                        </label>
                        <textarea name="training_requirements" id="training_requirements" class="form-control" rows="2"
                                  placeholder="Recommended internal/external training courses, certifications, or mentoring programs..."><?= e($form['training_requirements']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Repeatable Goals Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fs-6 fw-bold">
                        <i class="bi bi-flag-fill me-2 text-info"></i>Developmental Goals &amp; OKRs
                    </h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addGoalBtn">
                        <i class="bi bi-plus-lg me-1"></i>Add Goal
                    </button>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">Define measurable performance objectives, target deadlines, and progress percentages.</p>

                    <div id="goalsContainer">
                        <?php foreach ($goals as $idx => $g): ?>
                            <div class="goal-row p-3 rounded border bg-body-tertiary mb-3 position-relative">
                                <?php if (!empty($g['id'])): ?>
                                    <input type="hidden" name="goals[<?= $idx ?>][id]" value="<?= (int)$g['id'] ?>">
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold small text-primary"><i class="bi bi-bullseye me-1"></i>Goal #<span class="goal-num"><?= $idx + 1 ?></span></span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-goal-btn" title="Remove this goal">
                                        <i class="bi bi-trash"></i> Remove
                                    </button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-7">
                                        <label class="form-label small fw-medium mb-1">Goal Title</label>
                                        <input type="text" name="goals[<?= $idx ?>][title]" class="form-control form-control-sm"
                                               value="<?= e($g['title']) ?>" placeholder="e.g. Lead ERP migration on schedule">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-medium mb-1">Target Date</label>
                                        <input type="date" name="goals[<?= $idx ?>][target_date]" class="form-control form-control-sm"
                                               value="<?= e((string)($g['target_date'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label small fw-medium mb-1">Description / Key Results</label>
                                        <input type="text" name="goals[<?= $idx ?>][description]" class="form-control form-control-sm"
                                               value="<?= e((string)($g['description'] ?? '')) ?>" placeholder="Expected deliverables, milestones, or measurable targets">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-medium mb-1">Progress: <span class="progress-val fw-bold"><?= (int)($g['progress'] ?? 0) ?>%</span></label>
                                        <input type="range" name="goals[<?= $idx ?>][progress]" class="form-range progress-slider"
                                               min="0" max="100" step="5" value="<?= (int)($g['progress'] ?? 0) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-medium mb-1">Status</label>
                                        <select name="goals[<?= $idx ?>][status]" class="form-select form-select-sm">
                                            <option value="open" <?= ($g['status'] ?? '') === 'open' ? 'selected' : '' ?>>Open / In Progress</option>
                                            <option value="achieved" <?= ($g['status'] ?? '') === 'achieved' ? 'selected' : '' ?>>Achieved</option>
                                            <option value="missed" <?= ($g['status'] ?? '') === 'missed' ? 'selected' : '' ?>>Missed</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submission Actions & Guidelines Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4 sticky-top" style="top: 80px;">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">Publish &amp; Workflow</h5>
                </div>
                <div class="card-body p-3">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Review Status</label>
                        <div>
                            <?= performance_status_badge((string)$form['status']) ?>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Workflow Guidelines:</div>
                        <ul class="mb-0 ps-3">
                            <li><strong>Save as Draft:</strong> Keeps the appraisal private between HR and the reviewer. The employee cannot see it yet.</li>
                            <li><strong>Submit to Employee:</strong> Publishes the appraisal to the employee. They will be notified to review and acknowledge it.</li>
                            <li><strong>Locked after Acknowledgment:</strong> Once acknowledged, edits are locked. Only an authorized HR Manager can reopen with a logged justification.</li>
                        </ul>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" name="action" value="submit_review" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Submit to Employee
                        </button>
                        <button type="submit" name="action" value="save_draft" class="btn btn-outline-secondary">
                            <i class="bi bi-save me-1"></i>Save as Draft
                        </button>
                        <a href="<?= e(url('modules/performance/index.php')) ?>" class="btn btn-link text-muted btn-sm">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('goalsContainer');
    const addBtn = document.getElementById('addGoalBtn');

    // Handle range slider text updates
    function attachSliderListeners(row) {
        const slider = row.querySelector('.progress-slider');
        const display = row.querySelector('.progress-val');
        if (slider && display) {
            slider.addEventListener('input', function () {
                display.textContent = this.value + '%';
            });
        }
    }

    // Attach listeners to initial rows
    container.querySelectorAll('.goal-row').forEach(attachSliderListeners);

    // Remove goal button handler
    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-goal-btn')) {
            const row = e.target.closest('.goal-row');
            if (container.querySelectorAll('.goal-row').length > 1) {
                row.remove();
                renumberGoals();
            } else {
                // If only 1 row, clear inputs instead of removing
                row.querySelectorAll('input').forEach(i => i.value = '');
                const slider = row.querySelector('.progress-slider');
                if (slider) slider.value = 0;
                const display = row.querySelector('.progress-val');
                if (display) display.textContent = '0%';
            }
        }
    });

    // Renumber goal labels & input names
    function renumberGoals() {
        container.querySelectorAll('.goal-row').forEach((row, idx) => {
            const numSpan = row.querySelector('.goal-num');
            if (numSpan) numSpan.textContent = idx + 1;
            row.querySelectorAll('input, select').forEach(input => {
                if (input.name) {
                    input.name = input.name.replace(/goals\[\d+\]/, 'goals[' + idx + ']');
                }
            });
        });
    }

    // Add Goal handler
    addBtn.addEventListener('click', function () {
        const count = container.querySelectorAll('.goal-row').length;
        const newRow = document.createElement('div');
        newRow.className = 'goal-row p-3 rounded border bg-body-tertiary mb-3 position-relative';
        newRow.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small text-primary"><i class="bi bi-bullseye me-1"></i>Goal #<span class="goal-num">${count + 1}</span></span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-goal-btn" title="Remove this goal">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
            <div class="row g-2">
                <div class="col-md-7">
                    <label class="form-label small fw-medium mb-1">Goal Title</label>
                    <input type="text" name="goals[${count}][title]" class="form-control form-control-sm" placeholder="e.g. Expand automated test coverage to 95%">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-medium mb-1">Target Date</label>
                    <input type="date" name="goals[${count}][target_date]" class="form-control form-control-sm">
                </div>
                <div class="col-md-12">
                    <label class="form-label small fw-medium mb-1">Description / Key Results</label>
                    <input type="text" name="goals[${count}][description]" class="form-control form-control-sm" placeholder="Expected deliverables, milestones, or measurable targets">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-medium mb-1">Progress: <span class="progress-val fw-bold">0%</span></label>
                    <input type="range" name="goals[${count}][progress]" class="form-range progress-slider" min="0" max="100" step="5" value="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-medium mb-1">Status</label>
                    <select name="goals[${count}][status]" class="form-select form-select-sm">
                        <option value="open" selected>Open / In Progress</option>
                        <option value="achieved">Achieved</option>
                        <option value="missed">Missed</option>
                    </select>
                </div>
            </div>
        `;
        container.appendChild(newRow);
        attachSliderListeners(newRow);
        newRow.querySelector('input[type="text"]').focus();
    });
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';

