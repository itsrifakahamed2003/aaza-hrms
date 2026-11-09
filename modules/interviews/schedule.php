<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$appId = (int)($_GET['application_id'] ?? $_POST['application_id'] ?? 0);

if ($appId <= 0) {
    abort(404, 'Candidate application ID is required.');
}

// Fetch application and verify existence
$appSt = $pdo->prepare(
    'SELECT ca.*,
            c.first_name, c.last_name, c.candidate_code, c.email AS candidate_email, c.phone AS candidate_phone,
            v.title AS vacancy_title, v.vacancy_code, v.company_id AS vacancy_company_id,
            comp.name AS company_name, comp.code AS company_code
       FROM candidate_applications ca
       JOIN candidates c ON c.id = ca.candidate_id
       JOIN job_vacancies v ON v.id = ca.vacancy_id
       JOIN companies comp ON comp.id = ca.company_id
      WHERE ca.id = ?
      LIMIT 1'
);
$appSt->execute([$appId]);
$application = $appSt->fetch();

if (!$application) {
    abort(404, 'Candidate application not found.');
}

$companyId = (int)$application['company_id'];

// Verify company access with 'interview.manage'
require_company_access($companyId, 'interview.manage');

// Verify open pipeline state (not hired/rejected/withdrawn/offer_declined)
$terminalStatuses = ['hired', 'rejected', 'withdrawn', 'offer_declined'];
$isClosed = in_array((string)$application['status'], $terminalStatuses, true);

// Next default round number
$roundSt = $pdo->prepare('SELECT COALESCE(MAX(round_no), 0) + 1 FROM interviews WHERE application_id = ?');
$roundSt->execute([$appId]);
$defaultRoundNo = (int)$roundSt->fetchColumn();

// Fetch active users who have access to this company for panel assignment
$panelUsersSt = $pdo->prepare(
    'SELECT DISTINCT u.id, u.full_name, u.email, u.username
       FROM users u
       LEFT JOIN company_user_assignments cua ON cua.user_id = u.id
      WHERE u.is_active = 1
        AND (u.is_group_admin = 1 OR cua.company_id = ?)
      ORDER BY u.full_name'
);
$panelUsersSt->execute([$companyId]);
$eligibleInterviewers = $panelUsersSt->fetchAll();
$eligibleUserIds = array_map('intval', array_column($eligibleInterviewers, 'id'));

$errors = [];
$formData = [
    'round_no'         => (string)$defaultRoundNo,
    'interview_type'   => 'video',
    'scheduled_at'     => '',
    'duration_min'     => '45',
    'location_or_link' => '',
    'panel'            => [current_user_id()],
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($isClosed) {
        $errors[] = 'Cannot schedule an interview for an application in closed status (' . $application['status'] . ').';
    }

    $roundNo = max(1, (int)($_POST['round_no'] ?? $defaultRoundNo));
    $interviewType = trim((string)($_POST['interview_type'] ?? 'video'));
    $scheduledAtRaw = trim((string)($_POST['scheduled_at'] ?? ''));
    $durationMin = (int)($_POST['duration_min'] ?? 45);
    $locationOrLink = trim((string)($_POST['location_or_link'] ?? ''));
    $panel = array_map('intval', (array)($_POST['panel'] ?? []));
    $panel = array_values(array_filter($panel, fn($uid) => $uid > 0));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $formData = [
        'round_no'         => (string)$roundNo,
        'interview_type'   => $interviewType,
        'scheduled_at'     => $scheduledAtRaw,
        'duration_min'     => (string)$durationMin,
        'location_or_link' => $locationOrLink,
        'panel'            => $panel,
        'notes'            => $notes,
    ];

    $validTypes = interview_type_list();
    if (!isset($validTypes[$interviewType])) {
        $errors[] = 'Please select a valid interview type.';
    }

    // Validate scheduled_at
    $scheduledTs = strtotime($scheduledAtRaw);
    if (!$scheduledTs) {
        $errors[] = 'Please provide a valid scheduled date and time.';
    } elseif ($scheduledTs < time()) {
        $errors[] = 'Interview scheduled date and time cannot be in the past.';
    }

    // Validate duration
    if ($durationMin < 10 || $durationMin > 480) {
        $errors[] = 'Interview duration must be between 10 and 480 minutes.';
    }

    // Validate panel members
    if (empty($panel)) {
        $errors[] = 'Please select at least one interviewer for the panel.';
    } else {
        foreach ($panel as $pId) {
            if (!in_array($pId, $eligibleUserIds, true)) {
                $errors[] = 'One or more selected panel interviewers do not have active access to this company.';
                break;
            }
        }
    }

    // Prevent double booking of the same interviewer at an overlapping time
    if ($scheduledTs && $scheduledTs >= time() && !empty($panel)) {
        $startSql = date('Y-m-d H:i:s', $scheduledTs);
        $endSql   = date('Y-m-d H:i:s', $scheduledTs + ($durationMin * 60));

        $pPlaceholders = implode(',', array_fill(0, count($panel), '?'));
        $overlapQuery = "SELECT i.id, i.scheduled_at, i.duration_min, u.full_name
                           FROM interviews i
                           JOIN interview_panel ip ON ip.interview_id = i.id
                           JOIN users u ON u.id = ip.user_id
                          WHERE ip.user_id IN ($pPlaceholders)
                            AND i.status = 'scheduled'
                            AND i.scheduled_at < ?
                            AND DATE_ADD(i.scheduled_at, INTERVAL i.duration_min MINUTE) > ?
                          LIMIT 1";

        $overlapParams = array_merge($panel, [$endSql, $startSql]);
        $overlapSt = $pdo->prepare($overlapQuery);
        $overlapSt->execute($overlapParams);
        $overlap = $overlapSt->fetch();

        if ($overlap) {
            $errors[] = 'Interviewer "' . $overlap['full_name'] . '" is already scheduled for an interview at an overlapping time ('
                . format_datetime((string)$overlap['scheduled_at']) . ', ' . (int)$overlap['duration_min'] . ' mins).';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1. Lock application row
            $appLockSt = $pdo->prepare('SELECT id, company_id, status FROM candidate_applications WHERE id = ? FOR UPDATE');
            $appLockSt->execute([$appId]);
            $lockedApp = $appLockSt->fetch();
            if (!$lockedApp) {
                throw new RuntimeException('Candidate application not found.');
            }
            if (in_array((string)$lockedApp['status'], $terminalStatuses, true)) {
                throw new RuntimeException('Cannot schedule an interview for an application in closed status (' . $lockedApp['status'] . ').');
            }

            // 2. Lock selected panel users in consistent ID order to avoid deadlocks
            $sortedPanel = $panel;
            sort($sortedPanel);
            $pPlaceholders = implode(',', array_fill(0, count($sortedPanel), '?'));
            $userLockSt = $pdo->prepare("SELECT id FROM users WHERE id IN ($pPlaceholders) FOR UPDATE");
            $userLockSt->execute($sortedPanel);

            // 3. Re-run overlap query under lock
            $scheduledAtFormatted = date('Y-m-d H:i:s', $scheduledTs);
            $endSql = date('Y-m-d H:i:s', $scheduledTs + ($durationMin * 60));

            $overlapQuery = "SELECT i.id, i.scheduled_at, i.duration_min, u.full_name
                               FROM interviews i
                               JOIN interview_panel ip ON ip.interview_id = i.id
                               JOIN users u ON u.id = ip.user_id
                              WHERE ip.user_id IN ($pPlaceholders)
                                AND i.status = 'scheduled'
                                AND i.scheduled_at < ?
                                AND DATE_ADD(i.scheduled_at, INTERVAL i.duration_min MINUTE) > ?
                              LIMIT 1";
            $overlapParams = array_merge($sortedPanel, [$endSql, $scheduledAtFormatted]);
            $overlapSt = $pdo->prepare($overlapQuery);
            $overlapSt->execute($overlapParams);
            $overlap = $overlapSt->fetch();

            if ($overlap) {
                throw new RuntimeException('Interviewer "' . $overlap['full_name'] . '" is already scheduled for an interview at an overlapping time ('
                    . format_datetime((string)$overlap['scheduled_at']) . ', ' . (int)$overlap['duration_min'] . ' mins).');
            }

            // 4. Check active rounds and compute round number if conflicting
            $activeRoundsSt = $pdo->prepare("SELECT round_no FROM interviews WHERE application_id = ? AND status NOT IN ('cancelled', 'rescheduled')");
            $activeRoundsSt->execute([$appId]);
            $activeRounds = array_map('intval', $activeRoundsSt->fetchAll(PDO::FETCH_COLUMN));

            if (in_array($roundNo, $activeRounds, true)) {
                // Ignore conflicting round_no from form and compute on server
                $maxRoundSt = $pdo->prepare("SELECT COALESCE(MAX(round_no), 0) + 1 FROM interviews WHERE application_id = ? AND status NOT IN ('cancelled', 'rescheduled')");
                $maxRoundSt->execute([$appId]);
                $roundNo = (int)$maxRoundSt->fetchColumn();

                if (in_array($roundNo, $activeRounds, true)) {
                    throw new RuntimeException("An active interview for round {$roundNo} already exists for this application.");
                }
            }

            // 5. Determine mode based on interview type
            $mode = match ($interviewType) {
                'phone' => 'phone',
                'video' => 'online',
                default => 'in_person',
            };

            // 6. Insert interview record
            $insSt = $pdo->prepare(
                'INSERT INTO interviews (
                    application_id, company_id, round_no, interview_type,
                    scheduled_at, duration_min, location_or_link, location,
                    mode, status, notes, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $insSt->execute([
                $appId,
                $companyId,
                $roundNo,
                $interviewType,
                $scheduledAtFormatted,
                $durationMin,
                $locationOrLink,
                $locationOrLink,
                $mode,
                'scheduled',
                $notes !== '' ? $notes : null,
                current_user_id(),
            ]);

            $interviewId = (int)$pdo->lastInsertId();

            // 7. Insert panel members into interview_panel ONLY
            $ipSt = $pdo->prepare('INSERT INTO interview_panel (interview_id, user_id) VALUES (?, ?)');
            foreach ($panel as $userId) {
                $ipSt->execute([$interviewId, $userId]);
            }

            // 8. Update candidate application status to 'interview_scheduled'
            update_candidate_application_status(
                $pdo,
                $appId,
                'interview_scheduled',
                'Interview round ' . $roundNo . ' scheduled for ' . format_datetime($scheduledAtFormatted),
                current_user_id()
            );

            // 9. Log activity for audit
            log_activity('interview.schedule', 'interview', $interviewId, [
                'round_no'         => $roundNo,
                'interview_type'   => $interviewType,
                'scheduled_at'     => $scheduledAtFormatted,
                'duration_min'     => $durationMin,
                'panel_user_ids'   => $panel,
            ], $companyId);

            $pdo->commit();

            // Notify panel users
            $candFullName = trim($application['first_name'] . ' ' . $application['last_name']);
            foreach ($panel as $panelUid) {
                notify_user(
                    $pdo,
                    (int)$panelUid,
                    'interview_scheduled',
                    'Interview Scheduled',
                    "You have been assigned to interview {$candFullName} (Round {$roundNo}) on {$scheduledAtFormatted}.",
                    'modules/interviews/view.php?id=' . $interviewId,
                    $companyId,
                    'interview:sched:' . $interviewId . ':' . (int)$panelUid
                );
            }

            if (!empty($_POST['send_email'])) {
                try {
                    $mailRes = mail_send_candidate_template($pdo, $appId, 'interview_invite', [
                        'interview_id' => $interviewId,
                    ]);
                    if (!empty($mailRes['ok'])) {
                        flash_set('success', 'Interview round ' . $roundNo . ' has been scheduled and invitation email sent to candidate.');
                    } else {
                        $mailErrText = trim((string)($mailRes['error'] ?? 'Email delivery failed.'));
                        flash_set('warning', 'Saved, but the email could not be sent: ' . $mailErrText);
                    }
                } catch (Throwable) {
                    flash_set('warning', 'Saved, but the email could not be sent: Unexpected mail error.');
                }
            } else {
                flash_set('success', 'Interview round ' . $roundNo . ' has been scheduled successfully.');
            }
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
            error_log('Interview scheduling error: ' . $e->getMessage());
            $errors[] = 'A database error occurred while scheduling the interview. Please try again.';
        }
    }
}

$pageTitle = 'Schedule Interview';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4" style="max-width: 960px;">
    <!-- Page Header -->
    <div class="page-header mb-3">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/interviews/index.php')) ?>">Interviews</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['candidate_id'])) ?>"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Schedule Interview</li>
                </ol>
            </nav>
            <h1 class="page-header-title h4 mb-0">Schedule Interview</h1>
        </div>
        <div class="page-actions">
            <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['candidate_id'])) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Candidate Profile
            </a>
        </div>
    </div>

    <!-- Candidate & Vacancy Header Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h4 class="fw-bold mb-0 text-body"><?= e($application['first_name'] . ' ' . $application['last_name']) ?></h4>
                        <span class="badge bg-body-secondary text-body border"><?= e($application['candidate_code']) ?></span>
                        <?= application_status_badge((string)$application['status']) ?>
                    </div>
                    <div class="text-secondary small">
                        Applying for: <strong><?= e($application['vacancy_title']) ?></strong> (<?= e($application['vacancy_code']) ?>)
                        &bull; Company: <span class="badge bg-body-secondary text-body border"><?= e($application['company_code']) ?> - <?= e($application['company_name']) ?></span>
                    </div>
                </div>
                <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['candidate_id'])) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person me-1"></i>View Profile
                </a>
            </div>
        </div>
    </div>

    <?php if ($isClosed): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Cannot Schedule:</strong> This application is currently in closed status (<strong><?= e(application_status_list()[$application['status']] ?? $application['status']) ?></strong>). Interviews can only be scheduled for open pipeline candidates.
            </div>
        </div>
    <?php endif; ?>

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

    <!-- Schedule Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3">
            <h5 class="card-title mb-0 fw-semibold">
                <i class="bi bi-calendar-plus text-primary me-2"></i>Interview Session Details
            </h5>
        </div>
        <div class="card-body p-4">
            <form method="post" action="<?= e(url('modules/interviews/schedule.php?application_id=' . $appId)) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="application_id" value="<?= $appId ?>">

                <div class="row g-3">
                    <!-- Round No -->
                    <div class="col-md-4">
                        <label for="round_no" class="form-label fw-semibold small text-secondary">
                            Round Number <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control" id="round_no" name="round_no"
                               min="1" max="20" required value="<?= e($formData['round_no']) ?>">
                        <div class="form-text">e.g. 1 for initial interview, 2 for technical, etc.</div>
                    </div>

                    <!-- Interview Type -->
                    <div class="col-md-4">
                        <label for="interview_type" class="form-label fw-semibold small text-secondary">
                            Interview Type <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="interview_type" name="interview_type" required>
                            <?php foreach (interview_type_list() as $tKey => $tLabel): ?>
                                <option value="<?= e($tKey) ?>" <?= $formData['interview_type'] === $tKey ? 'selected' : '' ?>>
                                    <?= e($tLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Duration -->
                    <div class="col-md-4">
                        <label for="duration_min" class="form-label fw-semibold small text-secondary">
                            Duration (Minutes) <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="duration_min" name="duration_min" required>
                            <?php foreach ([15, 30, 45, 60, 90, 120] as $mins): ?>
                                <option value="<?= $mins ?>" <?= (int)$formData['duration_min'] === $mins ? 'selected' : '' ?>>
                                    <?= $mins ?> minutes
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Scheduled At -->
                    <div class="col-md-6">
                        <label for="scheduled_at" class="form-label fw-semibold small text-secondary">
                            Date &amp; Time <span class="text-danger">*</span>
                        </label>
                        <input type="datetime-local" class="form-control" id="scheduled_at" name="scheduled_at"
                               min="<?= date('Y-m-d\TH:i') ?>" required
                               value="<?= e($formData['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($formData['scheduled_at'])) : '') ?>">
                        <div class="form-text">Must be a date and time in the future.</div>
                    </div>

                    <!-- Location / Link -->
                    <div class="col-md-6">
                        <label for="location_or_link" class="form-label fw-semibold small text-secondary">
                            Location or Video Meeting Link
                        </label>
                        <input type="text" class="form-control" id="location_or_link" name="location_or_link"
                               placeholder="e.g. Google Meet link, Zoom URL, or Boardroom 3"
                               value="<?= e($formData['location_or_link']) ?>">
                    </div>

                    <!-- Interviewers Panel -->
                    <div class="col-12">
                        <label class="form-label fw-semibold small text-secondary d-flex justify-content-between align-items-center">
                            <span>Interview Panel Members <span class="text-danger">*</span></span>
                            <span class="text-muted fw-normal small">Active users with access to <?= e($application['company_code']) ?></span>
                        </label>
                        <div class="border rounded p-3 bg-body-tertiary" style="max-height: 220px; overflow-y: auto;">
                            <?php if (empty($eligibleInterviewers)): ?>
                                <p class="text-muted small mb-0">No active eligible interviewers found for this company.</p>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($eligibleInterviewers as $interviewer): ?>
                                        <?php $isSelected = in_array((int)$interviewer['id'], $formData['panel'], true); ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="panel[]" value="<?= (int)$interviewer['id'] ?>"
                                                       id="panel_user_<?= (int)$interviewer['id'] ?>"
                                                       <?= $isSelected ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="panel_user_<?= (int)$interviewer['id'] ?>">
                                                    <strong><?= e($interviewer['full_name']) ?></strong>
                                                    <span class="text-muted">(<?= e($interviewer['username']) ?>)</span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-text">Double-booking detection will prevent scheduling if any selected interviewer is already booked.</div>
                    </div>

                    <!-- Notes -->
                    <div class="col-12">
                        <label for="notes" class="form-label fw-semibold small text-secondary">Preparation Notes / Focus Areas</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"
                                  placeholder="Internal preparation notes for the interview panel..."><?= e($formData['notes']) ?></textarea>
                    </div>

                    <!-- Optional Email the Candidate Checkbox -->
                    <div class="col-12">
                        <?php
                        $candEmailAvailable = trim((string)($application['candidate_email'] ?? '')) !== '';
                        $defaultCheckEmail  = $_SERVER['REQUEST_METHOD'] === 'POST'
                            ? !empty($_POST['send_email'])
                            : (mail_is_configured() && $candEmailAvailable);
                        ?>
                        <div class="form-check p-3 ps-5 rounded bg-body-tertiary border">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="send_email"
                                   id="scheduleSendEmailCheckbox"
                                   value="1"
                                   <?= $defaultCheckEmail ? 'checked' : '' ?>
                                   <?= !$candEmailAvailable ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-semibold small" for="scheduleSendEmailCheckbox">
                                <i class="bi bi-envelope-paper text-primary me-1"></i>Email the candidate
                            </label>
                            <div class="form-text small mt-1">
                                <?php if (!$candEmailAvailable): ?>
                                    Candidate has no email address on file.
                                <?php elseif (mail_is_configured()): ?>
                                    Automatically sends an interview invitation email to <strong><?= e((string)$application['candidate_email']) ?></strong> once saved.
                                <?php else: ?>
                                    Outgoing email is not configured in <code>config/config.php</code> (leave unchecked or use "Email candidate" on the interview page to open your mail app).
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$application['candidate_id'])) ?>" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary px-4" <?= $isClosed ? 'disabled' : '' ?>>
                        <i class="bi bi-calendar-check me-1"></i>Confirm &amp; Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
