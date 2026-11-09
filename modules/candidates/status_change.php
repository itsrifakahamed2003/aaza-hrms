<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed.');
}

csrf_require();

$appId = (int)($_POST['application_id'] ?? 0);
$newStatus = trim((string)($_POST['new_status'] ?? ''));
$remarks = mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 255);
$returnTo = trim((string)($_POST['return_to'] ?? ''));

if ($appId <= 0) {
    abort(404, 'Application not found.');
}

$pdo = db();

$st = $pdo->prepare('SELECT id, candidate_id, company_id, vacancy_id, status FROM candidate_applications WHERE id = ? LIMIT 1');
$st->execute([$appId]);
$app = $st->fetch();

if (!$app) {
    abort(404, 'Application not found.');
}

$companyId = (int)$app['company_id'];
require_company_access($companyId, 'candidate.manage');

$manualStatuses = manual_status_list();
$defaultReturn = url('modules/candidates/view.php?id=' . (int)$app['candidate_id']);
$target = safe_return_path($returnTo, $defaultReturn);

if (!isset($manualStatuses[$newStatus])) {
    flash_set('danger', 'Invalid destination status selected.');
    redirect_path($target);
}

try {
    $pdo->beginTransaction();

    // Re-read the application row with SELECT ... FOR UPDATE inside the transaction
    $stLock = $pdo->prepare('SELECT id, candidate_id, company_id, vacancy_id, status FROM candidate_applications WHERE id = ? FOR UPDATE');
    $stLock->execute([$appId]);
    $lockedApp = $stLock->fetch();

    if (!$lockedApp) {
        throw new RuntimeException('Application not found.');
    }

    $curStatus = (string)$lockedApp['status'];
    $hasActiveOffer = has_active_offer($pdo, $appId);

    // Allowed current statuses
    $allowedCurrent = ['applied', 'under_review', 'shortlisted', 'interviewed', 'selected', 'on_hold'];

    if (!in_array($curStatus, $allowedCurrent, true)) {
        // Exception: allow 'rejected' from 'interview_scheduled' (an HR decision) but ONLY IF no active (draft/sent) offer
        if ($curStatus === 'interview_scheduled' && $newStatus === 'rejected') {
            if ($hasActiveOffer) {
                throw new RuntimeException("Cannot reject this application while an active job offer exists. Please withdraw the offer first.");
            }
        } else {
            $curLabel = application_status_list()[$curStatus] ?? ucfirst($curStatus);
            $guidance = match ($curStatus) {
                'interview_scheduled' => 'Please cancel the interview first.',
                'offered'             => 'Please withdraw the offer first.',
                'offer_accepted'      => 'The offer has been accepted. Please withdraw the offer or complete conversion.',
                'offer_declined'      => 'The offer was declined.',
                'hired'               => 'The candidate is already hired.',
                'rejected'            => 'The application is already rejected.',
                'withdrawn'           => 'The application has already been withdrawn.',
                default               => 'Manual status change is not allowed from this status.',
            };
            throw new RuntimeException("Cannot change status manually because the application is currently '{$curLabel}'. {$guidance}");
        }
    }

    // Also block 'on_hold' and 'rejected' while an offer in draft/sent exists for that application
    if (in_array($newStatus, ['on_hold', 'rejected'], true) && $hasActiveOffer) {
        $statusLabel = $manualStatuses[$newStatus] ?? $newStatus;
        throw new RuntimeException("Cannot mark application as {$statusLabel} while an active job offer exists. Please withdraw the offer first.");
    }

    // Selected rule: evaluated on the locked row, must be 'interviewed' with at least 1 evaluation
    if ($newStatus === 'selected') {
        if ($curStatus !== 'interviewed') {
            throw new RuntimeException("An application can only be marked as Selected from the 'Interviewed' stage (current status: '{$curStatus}').");
        }

        $evalCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM interview_evaluations ie
               JOIN interviews i ON ie.interview_id = i.id
              WHERE i.application_id = ?'
        );
        $evalCheck->execute([$appId]);
        if ((int)$evalCheck->fetchColumn() < 1) {
            throw new RuntimeException('At least one interview evaluation is required before selecting an application.');
        }
    }

    // Require a remark for rejected and on_hold
    if (in_array($newStatus, ['rejected', 'on_hold'], true)) {
        if ($remarks === '') {
            $statusLabel = $manualStatuses[$newStatus] ?? $newStatus;
            throw new InvalidArgumentException("A remark is required when marking an application as {$statusLabel}.");
        }
    }

    update_candidate_application_status(
        $pdo,
        $appId,
        $newStatus,
        $remarks !== '' ? $remarks : null,
        current_user_id()
    );
    $pdo->commit();

    if (in_array($newStatus, ['shortlisted', 'selected', 'rejected'], true)) {
        $vacSt = $pdo->prepare(
            'SELECT jv.title, jv.created_by, c.first_name, c.last_name
               FROM candidate_applications ca
               JOIN job_vacancies jv ON jv.id = ca.vacancy_id
               JOIN candidates c ON c.id = ca.candidate_id
              WHERE ca.id = ?'
        );
        $vacSt->execute([$appId]);
        $vacInfo = $vacSt->fetch();
        if ($vacInfo && !empty($vacInfo['created_by'])) {
            $candName = trim($vacInfo['first_name'] . ' ' . $vacInfo['last_name']);
            notify_user(
                $pdo,
                (int)$vacInfo['created_by'],
                'candidate_' . $newStatus,
                'Candidate ' . ucfirst($newStatus),
                "Candidate {$candName} was marked as " . ($manualStatuses[$newStatus] ?? $newStatus) . " for \"{$vacInfo['title']}\".",
                'modules/candidates/view.php?id=' . (int)$app['candidate_id'],
                $companyId,
                'app:status:' . $appId . ':' . $newStatus
            );
        }
    }

    $sendEmailRequested = !empty($_POST['send_email']) && in_array($newStatus, ['shortlisted', 'selected', 'rejected'], true);
    if ($sendEmailRequested) {
        try {
            $mailRes = mail_send_candidate_template($pdo, $appId, $newStatus);
            if (!empty($mailRes['ok'])) {
                flash_set('success', 'Application status updated to ' . ($manualStatuses[$newStatus] ?? $newStatus) . ' and email sent to candidate.');
            } else {
                $mailErrText = trim((string)($mailRes['error'] ?? 'Email delivery failed.'));
                flash_set('warning', 'Saved, but the email could not be sent: ' . $mailErrText);
            }
        } catch (Throwable $mailEx) {
            flash_set('warning', 'Saved, but the email could not be sent: Unexpected mail error.');
        }
    } else {
        flash_set('success', 'Application status updated to ' . ($manualStatuses[$newStatus] ?? $newStatus) . '.');
    }
} catch (RuntimeException|InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('danger', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Status change error: ' . $e->getMessage());
    flash_set('danger', 'Failed to update application status due to a system error.');
}

redirect_path($target);
