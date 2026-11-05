<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// POST-only validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method Not Allowed');
}

require_login();
csrf_require();

$pdo = db();
$interviewId = (int)($_POST['interview_id'] ?? 0);

if ($interviewId <= 0) {
    abort(404, 'Interview ID is required.');
}

// Fetch interview details - looking up company_id strictly from DB record, never from POST body
$intSt = $pdo->prepare('SELECT * FROM interviews WHERE id = ? LIMIT 1');
$intSt->execute([$interviewId]);
$interview = $intSt->fetch();

if (!$interview) {
    abort(404, 'Interview record not found.');
}

$companyId = (int)$interview['company_id'];

// Verify company access with 'interview.manage'
require_company_access($companyId, 'interview.manage');

// Safe redirect target
$defaultReturn = url('modules/interviews/view.php?id=' . $interviewId);
$returnTo = safe_return_path((string)($_POST['return_to'] ?? ''), $defaultReturn);

// RULE: Terminal status check upfront - only 'scheduled' can be modified
if ($interview['status'] !== 'scheduled') {
    flash_set('danger', 'This interview is in a terminal status (' . $interview['status'] . ') and cannot be modified.');
    redirect_path($returnTo);
}

$action = trim((string)($_POST['action'] ?? ''));

switch ($action) {
    case 'completed':
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare('SELECT * FROM interviews WHERE id = ? FOR UPDATE');
            $st->execute([$interviewId]);
            $currentInt = $st->fetch();

            if (!$currentInt || $currentInt['status'] !== 'scheduled') {
                $pdo->rollBack();
                flash_set('danger', 'This interview is in a terminal status (' . ($currentInt ? $currentInt['status'] : 'unknown') . ') and cannot be modified.');
                redirect_path($returnTo);
            }

            if (strtotime((string)$currentInt['scheduled_at']) > time()) {
                $pdo->rollBack();
                flash_set('danger', 'An interview can only be marked as completed after its scheduled time.');
                redirect_path($returnTo);
            }

            $upSt = $pdo->prepare("UPDATE interviews SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $upSt->execute([$interviewId]);

            // Update candidate application status to 'interviewed' if eligible
            $appId = (int)$currentInt['application_id'];
            $appSt = $pdo->prepare('SELECT status FROM candidate_applications WHERE id = ? FOR UPDATE');
            $appSt->execute([$appId]);
            $currentAppStatus = (string)$appSt->fetchColumn();

            if ($currentAppStatus === 'interview_scheduled') {
                $otherSt = $pdo->prepare("SELECT COUNT(*) FROM interviews WHERE application_id = ? AND status = 'scheduled' AND id != ?");
                $otherSt->execute([$appId, $interviewId]);
                if ((int)$otherSt->fetchColumn() === 0) {
                    update_candidate_application_status(
                        $pdo,
                        $appId,
                        'interviewed',
                        'Interview session #' . $interviewId . ' marked completed',
                        current_user_id()
                    );
                }
            }

            log_activity('interview.complete', 'interview', $interviewId, [
                'previous_status' => $currentInt['status'],
            ], $companyId);

            $pdo->commit();
            flash_set('success', 'Interview session has been marked as completed.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error completing interview: ' . $e->getMessage());
            flash_set('danger', 'A database error occurred while updating the interview status.');
        }
        redirect_path($returnTo);
        break;

    case 'no_show':
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare('SELECT * FROM interviews WHERE id = ? FOR UPDATE');
            $st->execute([$interviewId]);
            $currentInt = $st->fetch();

            if (!$currentInt || $currentInt['status'] !== 'scheduled') {
                $pdo->rollBack();
                flash_set('danger', 'This interview is in a terminal status (' . ($currentInt ? $currentInt['status'] : 'unknown') . ') and cannot be modified.');
                redirect_path($returnTo);
            }

            if (strtotime((string)$currentInt['scheduled_at']) > time()) {
                $pdo->rollBack();
                flash_set('danger', 'An interview can only be marked as no-show after its scheduled time.');
                redirect_path($returnTo);
            }

            $upSt = $pdo->prepare("UPDATE interviews SET status = 'no_show', updated_at = NOW() WHERE id = ?");
            $upSt->execute([$interviewId]);

            log_activity('interview.no_show', 'interview', $interviewId, [
                'previous_status' => $currentInt['status'],
            ], $companyId);

            $pdo->commit();
            flash_set('success', 'Interview session marked as No Show.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error updating interview no-show: ' . $e->getMessage());
            flash_set('danger', 'A database error occurred while updating the interview status.');
        }
        redirect_path($returnTo);
        break;

    case 'cancelled':
        $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
        if ($cancelReason === '') {
            flash_set('danger', 'Please provide a reason for cancelling the interview.');
            redirect_path($returnTo);
        }

        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare('SELECT * FROM interviews WHERE id = ? FOR UPDATE');
            $st->execute([$interviewId]);
            $currentInt = $st->fetch();

            if (!$currentInt || $currentInt['status'] !== 'scheduled') {
                $pdo->rollBack();
                flash_set('danger', 'This interview is in a terminal status (' . ($currentInt ? $currentInt['status'] : 'unknown') . ') and cannot be modified.');
                redirect_path($returnTo);
            }

            $noteAppend = "\n[Cancelled by " . current_user()['full_name'] . " on " . date('Y-m-d H:i') . ']: ' . $cancelReason;
            $upSt = $pdo->prepare("UPDATE interviews SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), ?), updated_at = NOW() WHERE id = ?");
            $upSt->execute([$noteAppend, $interviewId]);

            log_activity('interview.cancel', 'interview', $interviewId, [
                'reason' => $cancelReason,
            ], $companyId);

            $pdo->commit();

            // Notify panel users about cancellation
            $panelSt = $pdo->prepare('SELECT user_id FROM interview_panel WHERE interview_id = ?');
            $panelSt->execute([$interviewId]);
            $panelUserIds = $panelSt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($panelUserIds as $pUid) {
                notify_user(
                    $pdo,
                    (int)$pUid,
                    'interview_cancelled',
                    'Interview Cancelled',
                    "The interview (Round {$interview['round_no']}) scheduled on {$interview['scheduled_at']} has been cancelled.",
                    'modules/interviews/view.php?id=' . $interviewId,
                    $companyId,
                    'interview:cancel:' . $interviewId . ':' . (int)$pUid
                );
            }

            flash_set('success', 'Interview session has been cancelled.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error cancelling interview: ' . $e->getMessage());
            flash_set('danger', 'A database error occurred while cancelling the interview.');
        }
        redirect_path($returnTo);
        break;

    case 'rescheduled':
        $newScheduledAtRaw = trim((string)($_POST['new_scheduled_at'] ?? ''));
        $newDurationMin    = (int)($_POST['new_duration_min'] ?? $interview['duration_min']);
        $newLocation       = trim((string)($_POST['new_location_or_link'] ?? $interview['location_or_link']));
        $reason            = trim((string)($_POST['reschedule_reason'] ?? ''));

        if ($reason === '') {
            flash_set('danger', 'Please provide a reason for rescheduling.');
            redirect_path($returnTo);
        }

        $newTs = strtotime($newScheduledAtRaw);
        if (!$newTs || $newTs < time()) {
            flash_set('danger', 'New interview date and time must be in the future.');
            redirect_path($returnTo);
        }

        if ($newDurationMin < 10 || $newDurationMin > 480) {
            flash_set('danger', 'Interview duration must be between 10 and 480 minutes.');
            redirect_path($returnTo);
        }

        // Panel members: from POST or copied from existing interview
        if (isset($_POST['new_panel'])) {
            $panel = array_map('intval', (array)$_POST['new_panel']);
            $panel = array_values(array_filter($panel, fn($uid) => $uid > 0));
        } else {
            $panSt = $pdo->prepare('SELECT user_id FROM interview_panel WHERE interview_id = ?');
            $panSt->execute([$interviewId]);
            $panel = array_map('intval', $panSt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (empty($panel)) {
            flash_set('danger', 'Please select at least one panel interviewer for the rescheduled session.');
            redirect_path($returnTo);
        }

        // Check active users in this company
        $allowedSt = $pdo->prepare(
            'SELECT DISTINCT u.id
               FROM users u
               LEFT JOIN company_user_assignments cua ON cua.user_id = u.id
              WHERE u.is_active = 1
                AND (u.is_group_admin = 1 OR cua.company_id = ?)'
        );
        $allowedSt->execute([$companyId]);
        $allowedIds = array_map('intval', $allowedSt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($panel as $uid) {
            if (!in_array($uid, $allowedIds, true)) {
                flash_set('danger', 'One or more selected panel interviewers do not have access to this company.');
                redirect_path($returnTo);
            }
        }

        try {
            $pdo->beginTransaction();

            // 1. Lock existing interview row
            $st = $pdo->prepare('SELECT * FROM interviews WHERE id = ? FOR UPDATE');
            $st->execute([$interviewId]);
            $currentInt = $st->fetch();

            if (!$currentInt || $currentInt['status'] !== 'scheduled') {
                $pdo->rollBack();
                flash_set('danger', 'This interview is in a terminal status (' . ($currentInt ? $currentInt['status'] : 'unknown') . ') and cannot be modified.');
                redirect_path($returnTo);
            }

            $appId = (int)$currentInt['application_id'];

            // 2. Lock candidate application row
            $appSt = $pdo->prepare('SELECT id, company_id, status FROM candidate_applications WHERE id = ? FOR UPDATE');
            $appSt->execute([$appId]);
            $lockedApp = $appSt->fetch();
            if (!$lockedApp) {
                throw new RuntimeException('Candidate application record not found.');
            }
            if (in_array((string)$lockedApp['status'], ['hired', 'rejected', 'withdrawn', 'offer_declined'], true)) {
                throw new RuntimeException('Cannot reschedule an interview for an application in closed status (' . $lockedApp['status'] . ').');
            }

            // 3. Lock selected panel users
            $sortedPanel = $panel;
            sort($sortedPanel);
            $pPlaceholders = implode(',', array_fill(0, count($sortedPanel), '?'));
            $userLockSt = $pdo->prepare("SELECT id FROM users WHERE id IN ($pPlaceholders) FOR UPDATE");
            $userLockSt->execute($sortedPanel);

            // 4. Mark old interview as 'rescheduled'
            $oldNoteAppend = "\n[Rescheduled to new session on " . date('Y-m-d H:i') . ']: ' . $reason;
            $upOld = $pdo->prepare("UPDATE interviews SET status = 'rescheduled', notes = CONCAT(COALESCE(notes, ''), ?), updated_at = NOW() WHERE id = ?");
            $upOld->execute([$oldNoteAppend, $interviewId]);

            // 5. Re-run overlap query under lock (excluding old interview)
            $newStartSql = date('Y-m-d H:i:s', $newTs);
            $newEndSql   = date('Y-m-d H:i:s', $newTs + ($newDurationMin * 60));

            $overlapQuery = "SELECT i.id, i.scheduled_at, i.duration_min, u.full_name
                               FROM interviews i
                               JOIN interview_panel ip ON ip.interview_id = i.id
                               JOIN users u ON u.id = ip.user_id
                              WHERE ip.user_id IN ($pPlaceholders)
                                AND i.status = 'scheduled'
                                AND i.id != ?
                                AND i.scheduled_at < ?
                                AND DATE_ADD(i.scheduled_at, INTERVAL i.duration_min MINUTE) > ?
                              LIMIT 1";

            $overlapParams = array_merge($sortedPanel, [$interviewId, $newEndSql, $newStartSql]);
            $overlapSt = $pdo->prepare($overlapQuery);
            $overlapSt->execute($overlapParams);
            $overlap = $overlapSt->fetch();

            if ($overlap) {
                throw new RuntimeException('Interviewer "' . $overlap['full_name'] . '" is already scheduled for an interview at an overlapping time ('
                    . format_datetime((string)$overlap['scheduled_at']) . ').');
            }

            // 6. Round number computation / duplicate active round check
            $roundNo = (int)$currentInt['round_no'];
            $activeRoundsSt = $pdo->prepare("SELECT round_no FROM interviews WHERE application_id = ? AND status NOT IN ('cancelled', 'rescheduled') AND id != ?");
            $activeRoundsSt->execute([$appId, $interviewId]);
            $existingActiveRounds = array_map('intval', $activeRoundsSt->fetchAll(PDO::FETCH_COLUMN));

            if (in_array($roundNo, $existingActiveRounds, true)) {
                $maxRoundSt = $pdo->prepare("SELECT COALESCE(MAX(round_no), 0) + 1 FROM interviews WHERE application_id = ? AND status NOT IN ('cancelled', 'rescheduled') AND id != ?");
                $maxRoundSt->execute([$appId, $interviewId]);
                $roundNo = (int)$maxRoundSt->fetchColumn();

                if (in_array($roundNo, $existingActiveRounds, true)) {
                    throw new RuntimeException("An active interview for round {$roundNo} already exists for this application.");
                }
            }

            // 7. Determine mode
            $mode = match ($currentInt['interview_type']) {
                'phone' => 'phone',
                'video' => 'online',
                default => 'in_person',
            };

            // 8. Create new interview row
            $insSt = $pdo->prepare(
                'INSERT INTO interviews (
                    application_id, company_id, round_no, interview_type,
                    scheduled_at, duration_min, location_or_link, location,
                    mode, status, notes, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $newNotes = 'Rescheduled from session #' . $interviewId . '. Reason: ' . $reason;
            $insSt->execute([
                $appId,
                $companyId,
                $roundNo,
                $currentInt['interview_type'],
                $newStartSql,
                $newDurationMin,
                $newLocation,
                $newLocation,
                $mode,
                'scheduled',
                $newNotes,
                current_user_id(),
            ]);

            $newInterviewId = (int)$pdo->lastInsertId();

            // 9. Insert panel members for new session into interview_panel ONLY
            $ipSt = $pdo->prepare('INSERT INTO interview_panel (interview_id, user_id) VALUES (?, ?)');
            foreach ($panel as $userId) {
                $ipSt->execute([$newInterviewId, $userId]);
            }

            // 10. Update old interview note to reference the specific new ID
            $upOldRef = $pdo->prepare("UPDATE interviews SET notes = REPLACE(notes, 'new session on', CONCAT('session #', ?, ' on')) WHERE id = ?");
            $upOldRef->execute([$newInterviewId, $interviewId]);

            // 11. Ensure application status is 'interview_scheduled'
            if ((string)$lockedApp['status'] !== 'interview_scheduled') {
                update_candidate_application_status(
                    $pdo,
                    $appId,
                    'interview_scheduled',
                    'Interview rescheduled to ' . format_datetime($newStartSql),
                    current_user_id()
                );
            }

            // 12. Log activity
            log_activity('interview.reschedule', 'interview', $interviewId, [
                'new_interview_id' => $newInterviewId,
                'new_scheduled_at' => $newStartSql,
                'reason'           => $reason,
            ], $companyId);

            log_activity('interview.schedule', 'interview', $newInterviewId, [
                'rescheduled_from' => $interviewId,
                'scheduled_at'     => $newStartSql,
            ], $companyId);

            $pdo->commit();

            // Notify panel users about rescheduled interview
            foreach ($panel as $pUid) {
                notify_user(
                    $pdo,
                    (int)$pUid,
                    'interview_rescheduled',
                    'Interview Rescheduled',
                    "Interview (Round {$interview['round_no']}) has been rescheduled to " . format_datetime($newStartSql) . ".",
                    'modules/interviews/view.php?id=' . $newInterviewId,
                    $companyId,
                    'interview:resched:' . $newInterviewId . ':' . (int)$pUid
                );
            }

            flash_set('success', 'Interview successfully rescheduled to ' . format_datetime($newStartSql) . '.');
            redirect_path(url('modules/interviews/view.php?id=' . $newInterviewId));
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('danger', $e->getMessage());
            redirect_path($returnTo);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error rescheduling interview: ' . $e->getMessage());
            flash_set('danger', 'A database error occurred while rescheduling the interview.');
            redirect_path($returnTo);
        }
        break;

    default:
        flash_set('danger', 'Invalid action specified.');
        redirect_path($returnTo);
        break;
}
