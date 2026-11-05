<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed.');
}

csrf_require();

$offerId  = (int)($_POST['offer_id'] ?? 0);
$action   = trim((string)($_POST['action'] ?? ''));
$returnTo = trim((string)($_POST['return_to'] ?? ''));

if ($offerId <= 0) {
    abort(404, 'Job offer not found.');
}

$pdo = db();
$defaultReturn = url('modules/offers/view.php?id=' . $offerId);
$target = safe_return_path($returnTo, $defaultReturn);

// 1. Authorize company access BEFORE starting transaction & row lock
$initSt = $pdo->prepare('SELECT id, company_id FROM job_offers WHERE id = ? LIMIT 1');
$initSt->execute([$offerId]);
$initOffer = $initSt->fetch();

if (!$initOffer) {
    abort(404, 'Job offer not found.');
}

// Company ID is ALWAYS taken from the database row, NEVER from POST
$companyId = (int)$initOffer['company_id'];
require_company_access($companyId, 'offer.manage');

try {
    $pdo->beginTransaction();

    // Lock offer row FOR UPDATE
    $st = $pdo->prepare(
        'SELECT jo.*,
                ca.id AS app_id, ca.status AS app_status, ca.candidate_id, ca.vacancy_id
           FROM job_offers jo
           JOIN candidate_applications ca ON ca.id = jo.application_id
          WHERE jo.id = ?
          FOR UPDATE'
    );
    $st->execute([$offerId]);
    $offer = $st->fetch();

    if (!$offer) {
        $pdo->rollBack();
        abort(404, 'Job offer not found.');
    }

    $currentStatus = (string)$offer['status'];
    $applicationId = (int)$offer['application_id'];
    $currentUserId = current_user_id();

    // Auto-expire check: If expiry date has passed while in draft or sent, transition to expired
    if (!empty($offer['expiry_date']) && $offer['expiry_date'] < date('Y-m-d') && in_array($currentStatus, ['draft', 'sent'], true)) {
        $pdo->prepare("UPDATE job_offers SET status = 'expired' WHERE id = ?")->execute([$offerId]);
        if ((string)$offer['app_status'] === 'offered') {
            update_candidate_application_status(
                $pdo,
                $applicationId,
                'selected',
                "Offer #{$offerId} expired",
                $currentUserId
            );
        }
        $pdo->commit();
        log_activity('offer.expire', 'job_offers', $offerId, ['reason' => 'expiry_date_passed'], $companyId);
        flash_set('danger', 'This job offer has expired because the expiry date has passed. Candidate application returned to Selected.');
        redirect_path($target);
    }

    switch ($action) {
        case 'send':
            if ($currentStatus !== 'draft') {
                $pdo->rollBack();
                flash_set('danger', "Only draft offers can be marked as Sent (current status: " . (offer_status_list()[$currentStatus] ?? $currentStatus) . ").");
                redirect_path($target);
            }

            $pdo->prepare("UPDATE job_offers SET status = 'sent' WHERE id = ?")->execute([$offerId]);

            // Set application status to 'offered'
            update_candidate_application_status(
                $pdo,
                $applicationId,
                'offered',
                'Job offer #' . $offerId . ' marked as sent to candidate',
                $currentUserId
            );

            log_activity('offer.send', 'job_offer', $offerId, [
                'old_status'     => 'draft',
                'new_status'     => 'sent',
                'application_id' => $applicationId,
            ], $companyId);

            $pdo->commit();

            // Candidate name lookup for safe notification
            $candNameSt = $pdo->prepare('SELECT first_name, last_name FROM candidates WHERE id = ?');
            $candNameSt->execute([(int)$offer['candidate_id']]);
            $candRow = $candNameSt->fetch();
            $candName = $candRow ? trim($candRow['first_name'] . ' ' . $candRow['last_name']) : 'Candidate';

            if (!empty($offer['created_by']) && (int)$offer['created_by'] !== $currentUserId) {
                notify_user(
                    $pdo,
                    (int)$offer['created_by'],
                    'offer_sent',
                    'Job Offer Sent',
                    "Job Offer #{$offerId} for {$candName} was sent.",
                    'modules/offers/view.php?id=' . $offerId,
                    $companyId,
                    'offer:status:' . $offerId . ':sent'
                );
            }
            notify_permission(
                $pdo,
                $companyId,
                'offer.manage',
                'offer_sent',
                'Job Offer Sent',
                "Job Offer #{$offerId} for {$candName} was sent.",
                'modules/offers/view.php?id=' . $offerId,
                'offer:status:' . $offerId . ':sent',
                $currentUserId
            );

            flash_set('success', 'Offer marked as Sent. Candidate application status updated to Offered.');
            break;

        case 'accept':
            if ($currentStatus !== 'sent') {
                $pdo->rollBack();
                flash_set('danger', "Only sent offers can be marked as Accepted (current status: " . (offer_status_list()[$currentStatus] ?? $currentStatus) . ").");
                redirect_path($target);
            }

            $pdo->prepare("UPDATE job_offers SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$offerId]);

            // Set application status to 'offer_accepted'
            update_candidate_application_status(
                $pdo,
                $applicationId,
                'offer_accepted',
                'Candidate accepted job offer #' . $offerId,
                $currentUserId
            );

            log_activity('offer.accept', 'job_offer', $offerId, [
                'old_status'     => 'sent',
                'new_status'     => 'accepted',
                'application_id' => $applicationId,
            ], $companyId);

            $pdo->commit();

            // Candidate name lookup for safe notification
            $candNameSt = $pdo->prepare('SELECT first_name, last_name FROM candidates WHERE id = ?');
            $candNameSt->execute([(int)$offer['candidate_id']]);
            $candRow = $candNameSt->fetch();
            $candName = $candRow ? trim($candRow['first_name'] . ' ' . $candRow['last_name']) : 'Candidate';

            if (!empty($offer['created_by']) && (int)$offer['created_by'] !== $currentUserId) {
                notify_user(
                    $pdo,
                    (int)$offer['created_by'],
                    'offer_accepted',
                    'Job Offer Accepted',
                    "Candidate {$candName} accepted Job Offer #{$offerId}.",
                    'modules/offers/view.php?id=' . $offerId,
                    $companyId,
                    'offer:status:' . $offerId . ':accepted'
                );
            }
            notify_permission(
                $pdo,
                $companyId,
                'offer.manage',
                'offer_accepted',
                'Job Offer Accepted',
                "Candidate {$candName} accepted Job Offer #{$offerId}.",
                'modules/offers/view.php?id=' . $offerId,
                'offer:status:' . $offerId . ':accepted',
                $currentUserId
            );

            flash_set('success', 'Candidate acceptance recorded. Application updated to Offer Accepted.');
            break;

        case 'decline':
            if ($currentStatus !== 'sent') {
                $pdo->rollBack();
                flash_set('danger', "Only sent offers can be recorded as Declined (current status: " . (offer_status_list()[$currentStatus] ?? $currentStatus) . ").");
                redirect_path($target);
            }

            $remarks = mb_substr(trim((string)($_POST['remarks'] ?? 'Candidate declined offer')), 0, 255);
            if ($remarks === '') {
                $remarks = 'Candidate declined offer';
            }

            $pdo->prepare("UPDATE job_offers SET status = 'declined', responded_at = NOW() WHERE id = ?")->execute([$offerId]);

            // Set application status to 'offer_declined'
            update_candidate_application_status(
                $pdo,
                $applicationId,
                'offer_declined',
                $remarks,
                $currentUserId
            );

            log_activity('offer.decline', 'job_offer', $offerId, [
                'old_status'     => 'sent',
                'new_status'     => 'declined',
                'remarks'        => $remarks,
                'application_id' => $applicationId,
            ], $companyId);

            $pdo->commit();

            // Candidate name lookup for safe notification
            $candNameSt = $pdo->prepare('SELECT first_name, last_name FROM candidates WHERE id = ?');
            $candNameSt->execute([(int)$offer['candidate_id']]);
            $candRow = $candNameSt->fetch();
            $candName = $candRow ? trim($candRow['first_name'] . ' ' . $candRow['last_name']) : 'Candidate';

            if (!empty($offer['created_by']) && (int)$offer['created_by'] !== $currentUserId) {
                notify_user(
                    $pdo,
                    (int)$offer['created_by'],
                    'offer_declined',
                    'Job Offer Declined',
                    "Candidate {$candName} declined Job Offer #{$offerId}.",
                    'modules/offers/view.php?id=' . $offerId,
                    $companyId,
                    'offer:status:' . $offerId . ':declined'
                );
            }
            notify_permission(
                $pdo,
                $companyId,
                'offer.manage',
                'offer_declined',
                'Job Offer Declined',
                "Candidate {$candName} declined Job Offer #{$offerId}.",
                'modules/offers/view.php?id=' . $offerId,
                'offer:status:' . $offerId . ':declined',
                $currentUserId
            );

            flash_set('success', 'Candidate decline recorded. Application updated to Offer Declined.');
            break;

        case 'withdraw':
            if (!in_array($currentStatus, ['draft', 'sent'], true)) {
                $pdo->rollBack();
                flash_set('danger', "Only draft or sent offers can be withdrawn (current status: " . (offer_status_list()[$currentStatus] ?? $currentStatus) . ").");
                redirect_path($target);
            }

            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
            if ($reason === '') {
                $pdo->rollBack();
                flash_set('danger', 'A reason is required when withdrawing a job offer.');
                redirect_path($target);
            }

            $pdo->prepare("UPDATE job_offers SET status = 'withdrawn' WHERE id = ?")->execute([$offerId]);

            // If application is 'offered', transition it back to 'selected'
            if ((string)$offer['app_status'] === 'offered') {
                update_candidate_application_status(
                    $pdo,
                    $applicationId,
                    'selected',
                    'Offer #' . $offerId . ' withdrawn: ' . $reason,
                    $currentUserId
                );
            }

            log_activity('offer.withdraw', 'job_offer', $offerId, [
                'old_status'     => $currentStatus,
                'new_status'     => 'withdrawn',
                'reason'         => $reason,
                'application_id' => $applicationId,
            ], $companyId);

            $pdo->commit();
            flash_set('success', 'Job offer withdrawn successfully.');
            break;

        default:
            $pdo->rollBack();
            flash_set('danger', 'Invalid or unsupported action requested.');
            break;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Offer status change error: ' . $e->getMessage());
    flash_set('danger', 'Failed to update offer status due to a system error.');
}

redirect_path($target);
