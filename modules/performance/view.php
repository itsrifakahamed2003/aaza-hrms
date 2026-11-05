<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Performance review not found.');
}

$st = $pdo->prepare(
    "SELECT pr.*,
            e.first_name, e.last_name, e.employee_code, e.photo_path, e.email AS employee_email, e.user_id AS employee_user_id,
            d.name AS department_name, des.title AS designation_title,
            c.name AS company_name, c.code AS company_code, c.logo_path,
            u.full_name AS reviewer_name, u.email AS reviewer_email
       FROM performance_reviews pr
       JOIN employees e ON e.id = pr.employee_id
       JOIN companies c ON c.id = pr.company_id
  LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN designations des ON des.id = e.designation_id
  LEFT JOIN users u ON u.id = pr.reviewer_user_id
      WHERE pr.id = ?
      LIMIT 1"
);
$st->execute([$id]);
$review = $st->fetch(PDO::FETCH_ASSOC);

if (!$review) {
    abort(404, 'Performance review not found.');
}

if (!can_user_view_performance_review($pdo, $currentUserId, $review)) {
    abort(403, 'You do not have permission to view this performance review.');
}

$companyId = (int)$review['company_id'];
$empName = $review['first_name'] . ' ' . $review['last_name'];

// Handle state changes (Acknowledge and Reopen)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string)($_POST['action'] ?? '');

    // 1. Employee Acknowledgement Action
    if ($action === 'acknowledge') {
        if (!can_user_acknowledge_performance_review($pdo, $currentUserId, $review)) {
            abort(403, 'You are not authorized to acknowledge this review.');
        }

        $upd = $pdo->prepare('UPDATE performance_reviews SET status = "acknowledged", acknowledged_at = NOW() WHERE id = ?');
        $upd->execute([$id]);

        log_activity('performance.acknowledge', 'performance_review', $id, [
            'period_label' => $review['period_label'],
            'employee_id'  => $review['employee_id'],
        ], $companyId);

        // Notify reviewer
        if (!empty($review['reviewer_user_id'])) {
            $dedupe = "perf:ack:{$id}:" . date('Ymd');
            notify_user(
                $pdo,
                (int)$review['reviewer_user_id'],
                'performance_acknowledged',
                'Performance Review Acknowledged',
                "{$empName} has acknowledged their {$review['period_label']} appraisal.",
                'modules/performance/view.php?id=' . $id,
                $companyId,
                $dedupe
            );
        }

        flash_set('success', 'You have successfully acknowledged your performance review.');
        redirect('modules/performance/view.php?id=' . $id);
    }

    // 2. HR Reopen Action
    if ($action === 'reopen') {
        if (!can_user_reopen_performance_review($pdo, $currentUserId, $review)) {
            abort(403, 'You are not authorized to reopen this review.');
        }

        $reopenReason = trim((string)($_POST['reopen_reason'] ?? ''));
        if ($reopenReason === '') {
            flash_set('danger', 'A valid reason is required to reopen an acknowledged performance review.');
            redirect('modules/performance/view.php?id=' . $id);
        }

        $upd = $pdo->prepare(
            'UPDATE performance_reviews
                SET status = "draft", reopened_at = NOW(), reopen_reason = ?
              WHERE id = ?'
        );
        $upd->execute([$reopenReason, $id]);

        log_activity('performance.reopen', 'performance_review', $id, [
            'period_label'  => $review['period_label'],
            'reopen_reason' => $reopenReason,
        ], $companyId);

        // Notify reviewer that review is unlocked
        if (!empty($review['reviewer_user_id'])) {
            notify_user(
                $pdo,
                (int)$review['reviewer_user_id'],
                'performance_reopened',
                'Performance Review Reopened',
                "Performance review for {$empName} ({$review['period_label']}) was reopened: {$reopenReason}",
                'modules/performance/view.php?id=' . $id,
                $companyId
            );
        }

        flash_set('success', 'The review has been reopened and set back to Draft mode for editing.');
        redirect('modules/performance/view.php?id=' . $id);
    }
}

// Fetch goals
$stGoals = $pdo->prepare('SELECT * FROM performance_goals WHERE review_id = ? ORDER BY id ASC');
$stGoals->execute([$id]);
$goals = $stGoals->fetchAll(PDO::FETCH_ASSOC);

$canEdit = can_user_edit_performance_review($pdo, $currentUserId, $review);
$canAcknowledge = can_user_acknowledge_performance_review($pdo, $currentUserId, $review);
$canReopen = can_user_reopen_performance_review($pdo, $currentUserId, $review);

$pageTitle = 'Review - ' . $empName . ' (' . $review['period_label'] . ')';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb breadcrumb-clean mb-1">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/performance/index.php')) ?>">Performance</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($empName) ?> &middot; <?= e($review['period_label']) ?></li>
        </ol>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar-circle bg-primary-subtle text-primary fw-bold fs-4 d-flex align-items-center justify-content-center shadow-sm"
                 style="width: 54px; height: 54px; border-radius: 50%;">
                <?= e(strtoupper(substr($review['first_name'], 0, 1) . substr($review['last_name'], 0, 1))) ?>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="page-header-title mb-0"><?= e($empName) ?></h1>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($review['employee_code']) ?></span>
                    <?= performance_status_badge((string)$review['status']) ?>
                </div>
                <div class="text-muted small mt-1">
                    <?= e($review['designation_title'] ?? 'Staff') ?> &bull; <?= e($review['department_name'] ?? 'General') ?>
                    &bull; <span class="fw-semibold text-body"><?= e($review['company_name']) ?></span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= e(url('modules/performance/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>List
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <?php if ($canEdit): ?>
                <a href="<?= e(url('modules/performance/form.php?id=' . $id)) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit Review
                </a>
            <?php endif; ?>
            <?php if ($canAcknowledge): ?>
                <form method="post" action="<?= e(url('modules/performance/view.php?id=' . $id)) ?>" class="d-inline"
                      data-confirm="Acknowledge your performance review? Once acknowledged, your feedback will be finalized."
                      data-confirm-type="success">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="acknowledge">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-circle me-1"></i>Acknowledge Review
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canReopen): ?>
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#reopenModal">
                    <i class="bi bi-unlock me-1"></i>Reopen Review
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Workflow Alert Banner -->
<?php if ($review['status'] === 'submitted' && $canAcknowledge): ?>
    <div class="alert alert-warning shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>
                <strong>Action Required:</strong> Your manager has submitted this performance review. Please inspect your appraisal ratings and feedback, then click <strong>Acknowledge Review</strong> above.
            </div>
        </div>
    </div>
<?php elseif ($review['status'] === 'acknowledged'): ?>
    <div class="alert alert-success shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div>
                <strong>Review Acknowledged:</strong> This review was finalized and acknowledged by the employee on
                <strong><?= date('d M Y, h:i A', strtotime((string)$review['acknowledged_at'])) ?></strong>.
                Edits are locked.
            </div>
        </div>
    </div>
<?php elseif ($review['status'] === 'draft'): ?>
    <div class="alert alert-secondary shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-pencil-square fs-5"></i>
            <div>
                <strong>Draft Mode:</strong> This review is currently confidential and visible only to managers and HR administrators. It will become visible to the employee once submitted.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($review['reopen_reason'])): ?>
    <div class="alert alert-info shadow-sm mb-4">
        <div class="d-flex align-items-center gap-2 fw-semibold mb-1">
            <i class="bi bi-arrow-repeat"></i> Reopened by HR on <?= date('d M Y', strtotime((string)$review['reopened_at'])) ?>:
        </div>
        <div class="small"><?= e($review['reopen_reason']) ?></div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Main Review Evaluation Column -->
    <div class="col-lg-8">
        <!-- Overview Scorecard Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0 fs-6 fw-bold">
                    <i class="bi bi-speedometer2 me-2 text-primary"></i>Overall Appraisal Scorecard
                </h5>
                <span class="badge bg-body-secondary text-body border font-monospace">
                    <?= e($review['period_label']) ?>
                </span>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-5 text-center border-end-md pb-3 pb-md-0">
                        <div class="display-3 fw-bold text-warning mb-0">
                            <?= (int)$review['overall_rating'] ?><span class="fs-4 text-muted">/5</span>
                        </div>
                        <div class="fs-6 fw-bold text-body mt-1">
                            <?= performance_rating_label((int)$review['overall_rating']) ?>
                        </div>
                        <div class="mt-2">
                            <?= performance_rating_stars_html((int)$review['overall_rating'], false) ?>
                        </div>
                    </div>
                    <div class="col-md-7 ps-md-4">
                        <dl class="row mb-0 small">
                            <dt class="col-sm-5 text-muted">Review Period:</dt>
                            <dd class="col-sm-7 fw-semibold text-body mb-2">
                                <?= date('d M Y', strtotime($review['period_start'])) ?> &ndash; <?= date('d M Y', strtotime($review['period_end'])) ?>
                            </dd>

                            <dt class="col-sm-5 text-muted">Evaluation Date:</dt>
                            <dd class="col-sm-7 fw-semibold text-body mb-2">
                                <?= date('d M Y', strtotime($review['review_date'])) ?>
                            </dd>

                            <dt class="col-sm-5 text-muted">Reviewed By:</dt>
                            <dd class="col-sm-7 fw-semibold text-body mb-2">
                                <?= e($review['reviewer_name'] ?? 'System') ?>
                            </dd>

                            <dt class="col-sm-5 text-muted">Current Status:</dt>
                            <dd class="col-sm-7 mb-0">
                                <?= performance_status_badge((string)$review['status']) ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Qualitative Feedback Sections -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3">
                <h5 class="card-title mb-0 fs-6 fw-bold">
                    <i class="bi bi-chat-left-quote-fill me-2 text-primary"></i>Manager Appraisal &amp; Feedback
                </h5>
            </div>
            <div class="card-body p-4">
                <div class="mb-4">
                    <h6 class="fw-bold text-secondary text-uppercase small tracking-wide mb-2">
                        Accomplishments &amp; Core Performance
                    </h6>
                    <div class="p-3 rounded bg-body-tertiary text-body lh-base" style="white-space: pre-wrap;">
                        <?= e($review['manager_feedback']) ?>
                    </div>
                </div>

                <?php if (!empty($review['improvement_notes'])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-secondary text-uppercase small tracking-wide mb-2">
                            Areas for Development &amp; Improvement
                        </h6>
                        <div class="p-3 rounded bg-body-tertiary text-body lh-base" style="white-space: pre-wrap;">
                            <?= e($review['improvement_notes']) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($review['training_requirements'])): ?>
                    <div class="mb-0">
                        <h6 class="fw-bold text-secondary text-uppercase small tracking-wide mb-2">
                            Training &amp; Upskilling Recommendations
                        </h6>
                        <div class="p-3 rounded bg-body-tertiary text-body lh-base" style="white-space: pre-wrap;">
                            <?= e($review['training_requirements']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Goals & Objectives Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fs-6 fw-bold">
                    <i class="bi bi-flag-fill me-2 text-info"></i>Developmental Goals &amp; Progress (<?= count($goals) ?>)
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if (empty($goals)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-bullseye fs-2 d-block mb-2 text-secondary opacity-50"></i>
                        <div class="small">No individual goals were linked to this appraisal cycle.</div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($goals as $g): ?>
                            <div class="col-12">
                                <div class="p-3 rounded border bg-body-tertiary">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1 text-body"><?= e($g['title']) ?></h6>
                                            <?php if (!empty($g['description'])): ?>
                                                <div class="small text-muted"><?= e($g['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?= performance_goal_status_badge((string)$g['status']) ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3 mt-3">
                                        <div class="flex-grow-1">
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar <?= (int)$g['progress'] === 100 ? 'bg-success' : 'bg-primary' ?>"
                                                     role="progressbar"
                                                     style="width: <?= (int)$g['progress'] ?>%;"
                                                     aria-valuenow="<?= (int)$g['progress'] ?>"
                                                     aria-valuemin="0"
                                                     aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                        <div class="small fw-bold font-monospace text-nowrap">
                                            <?= (int)$g['progress'] ?>%
                                        </div>
                                    </div>
                                    <?php if (!empty($g['target_date'])): ?>
                                        <div class="small text-muted mt-2">
                                            <i class="bi bi-calendar-event me-1"></i>Target Deadline: <?= date('d M Y', strtotime($g['target_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Metadata & Audit Sidebar Column -->
    <div class="col-lg-4">
        <!-- Employee Profile Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3">
                <h5 class="card-title mb-0 fs-6 fw-bold">Employee Profile</h5>
            </div>
            <div class="card-body p-3">
                <div class="text-center mb-3">
                    <div class="avatar-circle bg-primary-subtle text-primary fw-bold fs-3 mx-auto mb-2 d-flex align-items-center justify-content-center"
                         style="width: 64px; height: 64px; border-radius: 50%;">
                        <?= e(strtoupper(substr($review['first_name'], 0, 1) . substr($review['last_name'], 0, 1))) ?>
                    </div>
                    <h6 class="fw-bold mb-0 text-body"><?= e($empName) ?></h6>
                    <div class="font-monospace text-muted small"><?= e($review['employee_code']) ?></div>
                </div>

                <dl class="mb-0 small">
                    <dt class="text-muted">Company</dt>
                    <dd class="fw-semibold text-body mb-2"><?= e($review['company_name']) ?></dd>

                    <dt class="text-muted">Department</dt>
                    <dd class="fw-semibold text-body mb-2"><?= e($review['department_name'] ?? 'General') ?></dd>

                    <dt class="text-muted">Designation</dt>
                    <dd class="fw-semibold text-body mb-2"><?= e($review['designation_title'] ?? 'Staff') ?></dd>

                    <?php if (!empty($review['employee_email'])): ?>
                        <dt class="text-muted">Email</dt>
                        <dd class="text-body mb-0"><?= e($review['employee_email']) ?></dd>
                    <?php endif; ?>
                </dl>

                <div class="mt-3 pt-3 border-top text-center">
                    <a href="<?= e(url('modules/employees/view.php?id=' . (int)$review['employee_id'] . '&tab=performance')) ?>" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-person-badge me-1"></i>View Full Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Audit & Reviewer Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body py-3">
                <h5 class="card-title mb-0 fs-6 fw-bold">Audit History</h5>
            </div>
            <div class="card-body p-3">
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-2 pb-2 border-bottom">
                        <span class="text-muted d-block">Appraiser / Reviewer:</span>
                        <span class="fw-semibold text-body"><?= e($review['reviewer_name'] ?? 'System') ?></span>
                    </li>
                    <li class="mb-2 pb-2 border-bottom">
                        <span class="text-muted d-block">Created At:</span>
                        <span class="text-body"><?= date('d M Y, h:i A', strtotime($review['created_at'])) ?></span>
                    </li>
                    <li class="mb-2 pb-2 border-bottom">
                        <span class="text-muted d-block">Last Updated:</span>
                        <span class="text-body"><?= date('d M Y, h:i A', strtotime($review['updated_at'])) ?></span>
                    </li>
                    <?php if (!empty($review['acknowledged_at'])): ?>
                        <li class="mb-0">
                            <span class="text-muted d-block">Acknowledged By Employee:</span>
                            <span class="text-success fw-semibold">
                                <i class="bi bi-check-circle me-1"></i><?= date('d M Y, h:i A', strtotime($review['acknowledged_at'])) ?>
                            </span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Reopen Review (HR Only) -->
<?php if ($canReopen): ?>
    <div class="modal fade" id="reopenModal" tabindex="-1" aria-labelledby="reopenModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= e(url('modules/performance/view.php?id=' . $id)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reopen">
                    <div class="modal-header">
                        <h5 class="modal-title fs-6 fw-bold" id="reopenModalLabel">
                            <i class="bi bi-unlock text-warning me-1"></i> Reopen Acknowledged Review
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            Reopening an acknowledged performance review unlocks it for editing and returns its status to Draft mode.
                            Please provide an audit justification.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="reopen_reason">
                                Justification / Reason <span class="text-danger">*</span>
                            </label>
                            <textarea name="reopen_reason" id="reopen_reason" class="form-control" rows="3" required
                                      placeholder="e.g. Employee provided updated certification evidence; revising competency score."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-unlock me-1"></i>Reopen for Editing
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';

