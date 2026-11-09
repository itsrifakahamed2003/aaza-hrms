<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$interviewId = (int)($_GET['id'] ?? 0);

if ($interviewId <= 0) {
    abort(404, 'Interview ID is required.');
}

// Fetch interview details - looking up company_id strictly from DB record
$intSt = $pdo->prepare(
    'SELECT i.*,
            ca.candidate_id, ca.vacancy_id, ca.status AS app_status, ca.application_date,
            c.first_name, c.last_name, c.candidate_code, c.email AS candidate_email, c.phone AS candidate_phone,
            v.title AS vacancy_title, v.vacancy_code,
            comp.name AS company_name, comp.code AS company_code,
            u_creator.full_name AS creator_name
       FROM interviews i
       JOIN candidate_applications ca ON ca.id = i.application_id
       JOIN candidates c ON c.id = ca.candidate_id
       JOIN job_vacancies v ON v.id = ca.vacancy_id
       JOIN companies comp ON comp.id = i.company_id
       LEFT JOIN users u_creator ON u_creator.id = i.created_by
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

// Fetch panel members
$panelSt = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.username
       FROM users u
       JOIN interview_panel ip ON ip.user_id = u.id
      WHERE ip.interview_id = ?
      ORDER BY u.full_name'
);
$panelSt->execute([$interviewId]);
$panelMembers = $panelSt->fetchAll();
$panelUserIds = array_map('intval', array_column($panelMembers, 'id'));
$isUserOnPanel = in_array($currentUserId, $panelUserIds, true);

// Access check: User must have interview.view for this company OR be on the interview panel
$canView = user_can('interview.view', $companyId) || $isUserOnPanel;
if (!$canView) {
    log_activity('access.denied', 'interview', $interviewId, ['reason' => 'not_permitted_to_view'], $companyId);
    abort(403, 'You do not have permission to view this interview.');
}

$canManage = user_can('interview.manage', $companyId);
$canViewAllEvaluations = user_can('interview.view_evaluations', $companyId);

// Confidential Evaluations: Only visible to users with interview.view_evaluations or the evaluator themself
$evaluations = [];
if ($canViewAllEvaluations) {
    $eSt = $pdo->prepare(
        'SELECT ie.*, u.full_name AS evaluator_name, u.email AS evaluator_email
           FROM interview_evaluations ie
           JOIN users u ON u.id = ie.evaluator_id
          WHERE ie.interview_id = ?
          ORDER BY ie.submitted_at ASC'
    );
    $eSt->execute([$interviewId]);
    $evaluations = $eSt->fetchAll();
} elseif ($isUserOnPanel) {
    $eSt = $pdo->prepare(
        'SELECT ie.*, u.full_name AS evaluator_name, u.email AS evaluator_email
           FROM interview_evaluations ie
           JOIN users u ON u.id = ie.evaluator_id
          WHERE ie.interview_id = ? AND ie.evaluator_id = ?
          LIMIT 1'
    );
    $eSt->execute([$interviewId, $currentUserId]);
    $evaluations = $eSt->fetchAll();
}

$evaluatedUserIds = array_map('intval', array_column($evaluations, 'evaluator_id'));
$hasUserEvaluated = in_array($currentUserId, $evaluatedUserIds, true);

// Check if scheduled time has passed
$isPastScheduled = strtotime((string)$interview['scheduled_at']) <= time();

// Active users for reschedule panel selection
$eligibleInterviewers = [];
if ($canManage) {
    $pSt = $pdo->prepare(
        'SELECT DISTINCT u.id, u.full_name, u.username
           FROM users u
           LEFT JOIN company_user_assignments cua ON cua.user_id = u.id
          WHERE u.is_active = 1
            AND (u.is_group_admin = 1 OR cua.company_id = ?)
          ORDER BY u.full_name'
    );
    $pSt->execute([$companyId]);
    $eligibleInterviewers = $pSt->fetchAll();
}

$canCandidateManage = user_can('candidate.manage', $companyId);
$canEmailCandidate  = $canCandidateManage || $canManage || user_can('interview.schedule', $companyId);
$candidateHasEmail  = trim((string)($interview['candidate_email'] ?? '')) !== '';
$mailIsConfigured   = mail_is_configured();
$emailTemplateLabels = [
    'application_received' => 'Application received',
    'shortlisted'          => 'Shortlisted',
    'interview_invite'     => 'Interview invite',
    'selected'             => 'Selected',
    'rejected'             => 'Rejected',
    'offer_sent'           => 'Offer',
];

$pageTitle = 'Interview Round ' . $interview['round_no'] . ' | ' . $interview['first_name'] . ' ' . $interview['last_name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/interviews/index.php')) ?>">Interviews</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$interview['candidate_id'])) ?>"><?= e($interview['first_name'] . ' ' . $interview['last_name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Round <?= (int)$interview['round_no'] ?></li>
        </ol>
    </nav>

    <!-- Detail Header -->
    <div class="detail-header">
        <div class="detail-header-main">
            <div class="detail-header-title-row">
                <h1 class="detail-header-title">Interview Round <?= (int)$interview['round_no'] ?></h1>
                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($interview['company_code']) ?></span>
                <?= interview_status_badge((string)$interview['status']) ?>
            </div>
            <p class="detail-header-subtitle">
                Candidate: <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$interview['candidate_id'])) ?>" class="text-decoration-none fw-semibold"><?= e($interview['first_name'] . ' ' . $interview['last_name']) ?></a> (<?= e($interview['candidate_code']) ?>)
                &bull; Vacancy: <strong><?= e($interview['vacancy_title']) ?></strong>
            </p>
        </div>

        <div class="detail-header-actions">
            <!-- ONE Primary Button -->
            <?php if ($isUserOnPanel && !$hasUserEvaluated && $interview['status'] !== 'cancelled'): ?>
                <?php if ($isPastScheduled): ?>
                    <a href="<?= e(url('modules/interviews/evaluate.php?id=' . $interviewId)) ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-pencil-square me-1"></i> Submit Evaluation
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Evaluations can only be submitted after the scheduled interview time">
                        <i class="bi bi-clock me-1"></i> Evaluation Opens After Start
                    </button>
                <?php endif; ?>
            <?php elseif ($canManage): ?>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#rescheduleModal">
                    <i class="bi bi-calendar2-range me-1"></i> Reschedule
                </button>
            <?php else: ?>
                <a href="<?= e(url('modules/interviews/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> All Interviews
                </a>
            <?php endif; ?>

            <!-- ONE "More" 3-dot menu -->
            <?php
            $intMore = [];
            if ($canManage && !($isUserOnPanel && !$hasUserEvaluated && $interview['status'] !== 'cancelled' && !$canManage)) {
                $intMore[] = [
                    'type' => 'button',
                    'icon' => 'bi-calendar2-range',
                    'label' => 'Reschedule / Edit Session',
                    'modal' => '#rescheduleModal',
                ];
            }
            if ($canManage && $interview['status'] === 'scheduled') {
                $intMore[] = [
                    'type' => 'button',
                    'icon' => 'bi-x-circle',
                    'label' => 'Cancel Interview',
                    'modal' => '#cancelModal',
                ];
            }
            $intMore[] = [
                'type' => 'link',
                'icon' => 'bi-arrow-left',
                'label' => 'All Interviews',
                'url' => 'modules/interviews/index.php',
            ];
            if (is_group_admin() && user_can('interview.delete')) {
                $intMore[] = [
                    'type' => 'button',
                    'icon' => 'bi-trash',
                    'label' => 'Delete Interview',
                    'onclick' => 'confirmDeleteInterview(' . $interviewId . ')',
                    'danger' => true,
                ];
            }
            echo action_menu($intMore, [
                'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
                'aria_label' => 'More interview actions',
            ]);
            ?>
        </div>
    </div>

    <?php if (is_group_admin() && user_can('interview.delete')): ?>
        <form method="post" action="<?= e(url('modules/interviews/delete.php')) ?>" id="deleteInterviewForm" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $interviewId ?>">
        </form>
        <script>
        function confirmDeleteInterview(id) {
            if (typeof showConfirmModal === 'function') {
                showConfirmModal({
                    title: 'Delete Interview Record',
                    message: 'Are you sure you want to permanently delete this interview? Only cancelled interviews can be deleted. This action cannot be undone.',
                    confirmText: 'Delete Interview',
                    variant: 'danger',
                    onConfirm: function() {
                        document.getElementById('deleteInterviewForm').submit();
                    }
                });
            } else if (confirm('Are you sure you want to permanently delete this interview?')) {
                document.getElementById('deleteInterviewForm').submit();
            }
        }
        </script>
    <?php endif; ?>

    <div class="row g-3 align-items-start">
        <!-- Main Interview Details Column -->
        <div class="col-lg-8">
            <!-- Session Overview Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Schedule &amp; Meeting Details</span>
                    <span class="badge bg-body-secondary text-body border">
                        <?= e(interview_type_list()[$interview['interview_type']] ?? ucfirst($interview['interview_type'])) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-label">Scheduled Date &amp; Time</span>
                            <span class="kv-value fw-bold text-body">
                                <i class="bi bi-calendar3 me-1 text-primary"></i>
                                <?= e(format_datetime($interview['scheduled_at'])) ?>
                            </span>
                        </div>

                        <div class="kv-item">
                            <span class="kv-label">Duration</span>
                            <span class="kv-value">
                                <i class="bi bi-stopwatch me-1 text-secondary"></i>
                                <?= (int)$interview['duration_min'] ?> minutes
                            </span>
                        </div>

                        <div class="kv-item full-width">
                            <span class="kv-label">Location / Meeting Link</span>
                            <div class="kv-value">
                                <?php if (!empty($interview['location_or_link'])): ?>
                                    <?php if (str_starts_with($interview['location_or_link'], 'http://') || str_starts_with($interview['location_or_link'], 'https://')): ?>
                                        <a href="<?= e($interview['location_or_link']) ?>" target="_blank" rel="noopener noreferrer" class="text-primary fw-semibold text-break">
                                            <i class="bi bi-camera-video me-1"></i><?= e($interview['location_or_link']) ?>
                                            <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                                        </a>
                                    <?php else: ?>
                                        <span><i class="bi bi-geo-alt me-1 text-danger"></i><?= e($interview['location_or_link']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">No location or link specified.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($interview['notes'])): ?>
                            <div class="kv-item full-width pt-2 border-top">
                                <span class="kv-label">Preparation Notes &amp; History</span>
                                <div class="kv-value small text-secondary" style="white-space: pre-line;">
                                    <?= e($interview['notes']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center text-muted small flex-wrap gap-2">
                        <span>Created by: <strong><?= e($interview['creator_name'] ?? 'System') ?></strong></span>
                        <span>Created: <?= e(format_datetime($interview['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Confidential Evaluations Section -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-0 fw-semibold">
                            <i class="bi bi-clipboard2-check text-success me-2"></i>Panel Evaluations (<?= count($evaluations) ?>)
                        </h5>
                        <small class="text-muted">Confidential assessment scores and recommendations.</small>
                    </div>
                    <?php if ($isUserOnPanel && !$hasUserEvaluated && $interview['status'] !== 'cancelled'): ?>
                        <?php if ($isPastScheduled): ?>
                            <a href="<?= e(url('modules/interviews/evaluate.php?id=' . $interviewId)) ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-pencil-square me-1"></i>Submit My Evaluation
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="card-body p-4">
                    <?php if (!$canViewAllEvaluations && !$isUserOnPanel): ?>
                        <div class="alert alert-secondary mb-0 small" role="alert">
                            <i class="bi bi-lock-fill me-1"></i>Evaluations are confidential and restricted to authorized HR personnel and assigned panel evaluators.
                        </div>
                    <?php elseif (empty($evaluations)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-clipboard-x fs-2 opacity-50 d-block mb-2"></i>
                            <p class="mb-1 fw-medium">No evaluations submitted yet.</p>
                            <small>
                                <?php if (!$isPastScheduled): ?>
                                    Evaluations will become accessible once the interview session has commenced.
                                <?php else: ?>
                                    Awaiting submissions from assigned panel members.
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($evaluations as $eval): ?>
                                <div class="border rounded p-3 bg-body-tertiary">
                                    <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                                        <div>
                                            <div class="fw-bold text-body fs-6"><?= e($eval['evaluator_name']) ?></div>
                                            <small class="text-muted">Submitted on <?= e(format_datetime($eval['submitted_at'] ?? $eval['evaluated_at'])) ?></small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?= evaluation_recommendation_badge($eval['recommendation']) ?>
                                            <span class="badge bg-dark fs-6">
                                                Score: <?= number_format((float)$eval['overall_score'], 1) ?> / 5.0
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Score Breakdown Pills -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-sm-4">
                                            <div class="p-2 bg-body rounded border text-center">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Technical</small>
                                                <span class="fw-bold text-primary"><?= (int)$eval['technical_score'] ?> / 5</span>
                                            </div>
                                        </div>
                                        <div class="col-sm-4">
                                            <div class="p-2 bg-body rounded border text-center">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Communication</small>
                                                <span class="fw-bold text-primary"><?= (int)$eval['communication_score'] ?> / 5</span>
                                            </div>
                                        </div>
                                        <div class="col-sm-4">
                                            <div class="p-2 bg-body rounded border text-center">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Attitude</small>
                                                <span class="fw-bold text-primary"><?= (int)$eval['attitude_score'] ?> / 5</span>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!empty($eval['comments'])): ?>
                                        <div class="small">
                                            <strong class="text-secondary d-block mb-1">Evaluator Comments:</strong>
                                            <div class="p-2 bg-body rounded border" style="white-space: pre-line;"><?= e($eval['comments']) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Side Column: Candidate, Panel, Actions -->
        <div class="col-lg-4">
            <!-- Candidate Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h6 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-person-badge text-primary me-2"></i>Candidate Profile
                    </h6>
                </div>
                <div class="card-body p-3">
                    <h5 class="fw-bold text-body mb-1">
                        <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$interview['candidate_id'])) ?>" class="text-decoration-none">
                            <?= e($interview['first_name'] . ' ' . $interview['last_name']) ?>
                        </a>
                    </h5>
                    <div class="small text-muted mb-3"><?= e($interview['candidate_code']) ?></div>

                    <div class="small mb-2">
                        <strong class="text-secondary">Applying for:</strong><br>
                        <?= e($interview['vacancy_title']) ?> (<?= e($interview['vacancy_code']) ?>)
                    </div>

                    <div class="small mb-2">
                        <strong class="text-secondary">Application Status:</strong><br>
                        <?= application_status_badge((string)$interview['app_status']) ?>
                    </div>

                    <div class="small mb-3">
                        <strong class="text-secondary">Company:</strong><br>
                        <span class="badge bg-body-secondary text-body border"><?= e($interview['company_code']) ?> - <?= e($interview['company_name']) ?></span>
                    </div>

                    <div class="d-grid gap-2 pt-2 border-top">
                        <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$interview['candidate_id'])) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-person me-1"></i>Full Candidate Profile
                        </a>
                        <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$interview['vacancy_id'])) ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-kanban me-1"></i>Vacancy Pipeline
                        </a>
                    </div>
                </div>
            </div>

            <!-- Panel Members Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h6 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-people text-primary me-2"></i>Interview Panel (<?= count($panelMembers) ?>)
                    </h6>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($panelMembers)): ?>
                        <p class="text-muted small mb-0">No panel members assigned.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($panelMembers as $pm): ?>
                                <?php $isEvalDone = in_array((int)$pm['id'], $evaluatedUserIds, true); ?>
                                <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold text-body"><?= e($pm['full_name']) ?></div>
                                        <span class="text-muted" style="font-size:0.75rem;"><?= e($pm['email']) ?></span>
                                    </div>
                                    <?php if ($isEvalDone): ?>
                                        <span class="badge text-bg-success"><i class="bi bi-check2"></i> Evaluated</span>
                                    <?php else: ?>
                                        <span class="badge bg-body-secondary text-body border">Pending</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Management / State Machine Card -->
            <?php if ($canManage): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-body py-3">
                        <h6 class="card-title mb-0 fw-semibold">
                            <i class="bi bi-gear text-secondary me-2"></i>Interview Actions
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <?php if ($interview['status'] === 'scheduled'): ?>
                            <div class="d-grid gap-2">
                                <!-- Mark Completed Button -->
                                <form method="post" action="<?= e(url('modules/interviews/status_change.php')) ?>" data-confirm="Mark this interview as completed?" data-confirm-type="success" data-confirm-title="Complete Interview" data-confirm-btn="Mark Completed">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="interview_id" value="<?= $interviewId ?>">
                                    <input type="hidden" name="action" value="completed">
                                    <input type="hidden" name="return_to" value="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                        <i class="bi bi-check2-circle me-1"></i>Mark Completed
                                    </button>
                                </form>

                                <!-- Mark No Show Button -->
                                <form method="post" action="<?= e(url('modules/interviews/status_change.php')) ?>" data-confirm="Mark candidate as No Show for this session?" data-confirm-type="danger" data-confirm-title="Mark No Show" data-confirm-btn="Mark No Show">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="interview_id" value="<?= $interviewId ?>">
                                    <input type="hidden" name="action" value="no_show">
                                    <input type="hidden" name="return_to" value="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                        <i class="bi bi-person-x me-1"></i>Mark No Show
                                    </button>
                                </form>

                                <!-- Reschedule Modal Trigger -->
                                <button type="button" class="btn btn-sm btn-outline-warning w-100 text-body" data-bs-toggle="modal" data-bs-target="#rescheduleModal">
                                    <i class="bi bi-calendar-range me-1"></i>Reschedule Interview
                                </button>

                                <!-- Cancel Modal Trigger -->
                                <button type="button" class="btn btn-sm btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                    <i class="bi bi-x-octagon me-1"></i>Cancel Interview
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">
                                This interview is in a terminal/closed state (<strong><?= e($interview['status']) ?></strong>). No further state transitions are permitted.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canManage && $interview['status'] === 'scheduled'): ?>
<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/interviews/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="interview_id" value="<?= $interviewId ?>">
                <input type="hidden" name="action" value="cancelled">
                <input type="hidden" name="return_to" value="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelModalLabel">Cancel Interview Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger small mb-3">Cancelling an interview is permanent and cannot be undone.</p>
                    <div class="mb-3">
                        <label for="cancel_reason" class="form-label small fw-semibold">Reason for Cancellation</label>
                        <textarea class="form-control form-control-sm" id="cancel_reason" name="cancel_reason" rows="3"
                                  placeholder="e.g. Candidate requested cancellation, vacancy filled, etc." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-danger">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reschedule Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/interviews/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="interview_id" value="<?= $interviewId ?>">
                <input type="hidden" name="action" value="rescheduled">
                <input type="hidden" name="return_to" value="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="rescheduleModalLabel">Reschedule Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Rescheduling will mark the current record as rescheduled and generate a new linked session record to maintain full audit history.</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="new_scheduled_at" class="form-label small fw-semibold">New Date &amp; Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="new_scheduled_at" name="new_scheduled_at"
                                   min="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="new_duration_min" class="form-label small fw-semibold">Duration (Minutes)</label>
                            <select class="form-select form-select-sm" id="new_duration_min" name="new_duration_min">
                                <?php foreach ([15, 30, 45, 60, 90, 120] as $mins): ?>
                                    <option value="<?= $mins ?>" <?= (int)$interview['duration_min'] === $mins ? 'selected' : '' ?>>
                                        <?= $mins ?> minutes
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="new_location_or_link" class="form-label small fw-semibold">Location / Video Link</label>
                            <input type="text" class="form-control form-control-sm" id="new_location_or_link" name="new_location_or_link"
                                   value="<?= e($interview['location_or_link']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Interview Panel</label>
                            <div class="border rounded p-2 bg-body-tertiary" style="max-height: 140px; overflow-y: auto;">
                                <?php foreach ($eligibleInterviewers as $eliv): ?>
                                    <?php $isAssigned = in_array((int)$eliv['id'], $panelUserIds, true); ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="new_panel[]" value="<?= (int)$eliv['id'] ?>"
                                               id="resched_panel_<?= (int)$eliv['id'] ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="resched_panel_<?= (int)$eliv['id'] ?>">
                                            <?= e($eliv['full_name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="reschedule_reason" class="form-label small fw-semibold">Reason for Rescheduling</label>
                            <textarea class="form-control form-control-sm" id="reschedule_reason" name="reschedule_reason" rows="2"
                                      placeholder="Reason for change in date or panel..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-warning text-body">Confirm Reschedule</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Email Candidate Preview & Send Modal -->
<div class="modal fade" id="emailCandidateModal" tabindex="-1" aria-labelledby="emailCandidateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/candidates/email.php')) ?>" id="emailCandidateForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="application_id" id="emailModalAppId" value="<?= (int)$interview['application_id'] ?>">
                <input type="hidden" name="interview_id" id="emailModalInterviewId" value="<?= (int)$interviewId ?>">
                <input type="hidden" name="template" id="emailModalTemplate" value="interview_invite">
                <input type="hidden" name="redirect_to" value="<?= e(url('modules/interviews/view.php?id=' . $interviewId)) ?>">

                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="emailCandidateModalLabel">
                        <i class="bi bi-envelope-paper text-primary"></i>
                        <span>Email Candidate</span>
                        <span class="badge bg-body-secondary text-body border small fw-normal" id="emailModalTemplateBadge"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="emailModalErrorAlert" class="alert alert-danger small d-none mb-3"></div>
                    <div id="emailModalLoading" class="text-center py-4 d-none">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted small">Loading template preview...</span>
                    </div>
                    <div id="emailModalFields">
                        <div class="mb-3">
                            <label for="emailModalTo" class="form-label small fw-semibold text-muted mb-1">To (Candidate Email)</label>
                            <input type="email" id="emailModalTo" class="form-control form-control-sm bg-body-tertiary" readonly value="<?= e((string)($interview['candidate_email'] ?? '')) ?>">
                        </div>
                        <div class="mb-3">
                            <label for="emailModalSubject" class="form-label small fw-semibold mb-1">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="emailModalSubject" class="form-control" maxlength="200" required oninput="updateEmailModalMailtoLink()">
                        </div>
                        <div class="mb-2">
                            <label for="emailModalBody" class="form-label small fw-semibold mb-1">Message Body <span class="text-danger">*</span></label>
                            <textarea name="body" id="emailModalBody" class="form-control font-monospace small" rows="10" required oninput="updateEmailModalMailtoLink()"></textarea>
                            <div class="form-text small">
                                You can customize the subject and body before sending. All HTML is safely escaped and wrapped in the company stationery layout.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <div class="small text-muted">
                        <?php if ($mailIsConfigured): ?>
                            <i class="bi bi-check-circle text-success me-1"></i>Direct email delivery enabled
                        <?php else: ?>
                            <i class="bi bi-info-circle text-secondary me-1"></i>Email not configured &mdash; opens in your default mail client
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if ($mailIsConfigured): ?>
                            <button type="submit" id="emailModalSendBtn" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Send
                            </button>
                        <?php else: ?>
                            <a href="mailto:" id="emailModalMailtoBtn" class="btn btn-primary">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open in mail app
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const EMAIL_PREVIEW_URL = <?= json_encode(url('modules/candidates/email.php')) ?>;
const CSRF_TOKEN_VAL = <?= json_encode(csrf_token()) ?>;

function buildSafeMailtoHref(toEmail, subject, body) {
    const cleanTo = (toEmail || '').trim();
    const cleanSubj = (subject || '').trim().substring(0, 200);
    let normBody = (body || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    const prefix = 'mailto:' + encodeURIComponent(cleanTo) + '?subject=' + encodeURIComponent(cleanSubj) + '&body=';
    let full = prefix + encodeURIComponent(normBody);
    while (full.length > 1800 && normBody.length > 0) {
        normBody = normBody.substring(0, Math.max(0, normBody.length - 25));
        full = prefix + encodeURIComponent(normBody.trimEnd() + '...');
    }
    return full.substring(0, 1800);
}

function updateEmailModalMailtoLink() {
    const mailtoBtn = document.getElementById('emailModalMailtoBtn');
    if (!mailtoBtn) return;
    const toVal   = document.getElementById('emailModalTo').value;
    const subjVal = document.getElementById('emailModalSubject').value;
    const bodyVal = document.getElementById('emailModalBody').value;
    mailtoBtn.setAttribute('href', buildSafeMailtoHref(toVal, subjVal, bodyVal));
}

function openEmailCandidateModal(appId, templateKey, interviewId) {
    document.getElementById('emailModalAppId').value = appId;
    document.getElementById('emailModalTemplate').value = templateKey;
    if (interviewId) {
        document.getElementById('emailModalInterviewId').value = interviewId;
    }

    const badge = document.getElementById('emailModalTemplateBadge');
    if (badge) {
        badge.textContent = templateKey.replace(/_/g, ' ');
    }

    const errBox = document.getElementById('emailModalErrorAlert');
    const loading = document.getElementById('emailModalLoading');
    const fields = document.getElementById('emailModalFields');
    errBox.classList.add('d-none');
    errBox.textContent = '';
    loading.classList.remove('d-none');
    fields.classList.add('opacity-50');

    const modalEl = document.getElementById('emailCandidateModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const formData = new URLSearchParams();
    formData.set('csrf_token', CSRF_TOKEN_VAL);
    formData.set('action', 'preview');
    formData.set('application_id', String(appId));
    formData.set('template', templateKey);
    if (interviewId) {
        formData.set('interview_id', String(interviewId));
    }

    fetch(EMAIL_PREVIEW_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(r => r.json())
    .then(data => {
        loading.classList.add('d-none');
        fields.classList.remove('opacity-50');
        if (!data || !data.ok) {
            errBox.textContent = (data && data.error) ? data.error : 'Failed to load email preview.';
            errBox.classList.remove('d-none');
            return;
        }
        document.getElementById('emailModalTo').value = data.to || '';
        document.getElementById('emailModalSubject').value = data.subject || '';
        document.getElementById('emailModalBody').value = data.body || data.text || '';
        const mailtoBtn = document.getElementById('emailModalMailtoBtn');
        if (mailtoBtn) {
            if (data.mailto && data.mailto.length <= 1800) {
                mailtoBtn.setAttribute('href', data.mailto);
            } else {
                updateEmailModalMailtoLink();
            }
        }
    })
    .catch(() => {
        loading.classList.add('d-none');
        fields.classList.remove('opacity-50');
        errBox.textContent = 'Network error while loading email preview.';
        errBox.classList.remove('d-none');
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
