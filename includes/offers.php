<?php
declare(strict_types=1);

/**
 * AaZa Group HR - Job Offers module helpers.
 * Status list, status badges, auto-expiration, and offer lifecycle helpers.
 */

/**
 * Valid job offer statuses defined in schema:
 * ENUM('draft','sent','accepted','declined','expired','withdrawn')
 */
function offer_status_list(): array
{
    return [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'accepted'  => 'Accepted',
        'declined'  => 'Declined',
        'expired'   => 'Expired',
        'withdrawn' => 'Withdrawn',
    ];
}

/**
 * Return Bootstrap badge HTML for job offer status.
 */
function offer_status_badge(string $status): string
{
    return status_badge('offer', $status);
}

/**
 * Auto-expire offers whose expiry_date has passed (< CURDATE()) while still in 'draft' or 'sent' status.
 * Processes row by row inside transactions, syncing application status back to 'selected' if 'offered'.
 *
 * @param PDO $pdo
 * @param int|null $companyId Optional company ID filter
 * @return int Number of offers updated to expired
 */
function expire_old_offers(PDO $pdo, ?int $companyId = null): int
{
    $sql = "SELECT id, company_id FROM job_offers
             WHERE status IN ('draft', 'sent')
               AND expiry_date IS NOT NULL
               AND expiry_date < CURDATE()";
    $params = [];
    if ($companyId !== null && $companyId > 0) {
        $sql .= " AND company_id = ?";
        $params[] = $companyId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $candidates = $st->fetchAll();

    $expiredCount = 0;
    $currentUserId = function_exists('current_user_id') && current_user_id() > 0 ? current_user_id() : null;

    foreach ($candidates as $cand) {
        $offerId = (int)$cand['id'];
        $comp = (int)$cand['company_id'];

        try {
            $pdo->beginTransaction();

            $stLock = $pdo->prepare(
                "SELECT jo.id, jo.application_id, jo.company_id, jo.status, jo.expiry_date,
                        ca.status AS app_status
                   FROM job_offers jo
                   LEFT JOIN candidate_applications ca ON ca.id = jo.application_id
                  WHERE jo.id = ?
                  FOR UPDATE"
            );
            $stLock->execute([$offerId]);
            $off = $stLock->fetch();

            if (!$off || !in_array($off['status'], ['draft', 'sent'], true)) {
                $pdo->rollBack();
                continue;
            }

            if (empty($off['expiry_date']) || $off['expiry_date'] >= date('Y-m-d')) {
                $pdo->rollBack();
                continue;
            }

            $pdo->prepare("UPDATE job_offers SET status = 'expired' WHERE id = ?")->execute([$offerId]);

            $appId = (int)($off['application_id'] ?? 0);
            if ($appId > 0 && (string)($off['app_status'] ?? '') === 'offered') {
                update_candidate_application_status(
                    $pdo,
                    $appId,
                    'selected',
                    "Offer #{$offerId} expired",
                    $currentUserId
                );
            }

            if (function_exists('log_activity')) {
                log_activity(
                    'offer.expire',
                    'job_offers',
                    $offerId,
                    ['reason' => 'expiry_date_passed'],
                    $comp,
                    $currentUserId
                );
            }

            $pdo->commit();
            $expiredCount++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error expiring offer #{$offerId}: " . $e->getMessage());
        }
    }

    return $expiredCount;
}

/**
 * Check if an application already has an active offer in 'draft' or 'sent' status.
 */
function has_active_offer(PDO $pdo, int $applicationId, ?int $excludeOfferId = null): bool
{
    if ($applicationId <= 0) {
        return false;
    }
    $sql = "SELECT 1 FROM job_offers WHERE application_id = ? AND status IN ('draft', 'sent')";
    $params = [$applicationId];
    if ($excludeOfferId !== null && $excludeOfferId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeOfferId;
    }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

/**
 * Format currency amount with 2 decimal places.
 * Reads the company's 'general.currency_code' setting or falls back to CURRENCY_CODE ('LKR').
 */
function format_currency(float $amount, ?string $symbol = null, ?int $companyId = null): string
{
    if ($symbol !== null && $symbol !== '') {
        $code = $symbol;
    } elseif ($companyId !== null && $companyId > 0 && function_exists('get_company_setting')) {
        $code = (string)get_company_setting(db(), $companyId, 'general.currency_code', defined('CURRENCY_CODE') ? CURRENCY_CODE : 'LKR');
    } else {
        $code = defined('CURRENCY_CODE') ? (string)CURRENCY_CODE : 'LKR';
    }
    $formatted = number_format($amount, 2);
    return $code !== '' ? $code . ' ' . $formatted : $formatted;
}

/**
 * Check if a job offer can be safely deleted.
 * Rules:
 * - Must not be accepted or converted to an employee record
 * - Status must be 'declined', 'expired', 'withdrawn', or 'draft' (not active 'sent')
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, offer: ?array}
 */
function check_offer_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return ['can_delete' => false, 'reason' => 'Invalid offer ID.', 'offer' => null];
    }

    $st = $pdo->prepare('SELECT * FROM job_offers WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $offer = $st->fetch();

    if (!$offer) {
        return ['can_delete' => false, 'reason' => 'Job offer record not found.', 'offer' => null];
    }

    // 1. Check if converted or accepted
    if (!empty($offer['converted_employee_id']) || $offer['status'] === 'accepted') {
        return [
            'can_delete' => false,
            'reason'     => 'Cannot delete offer: Already accepted and converted into an employee record.',
            'offer'      => $offer,
        ];
    }

    // 2. Active 'sent' check
    if ($offer['status'] === 'sent') {
        return [
            'can_delete' => false,
            'reason'     => 'Cannot delete an active sent offer. Withdraw or expire the offer first.',
            'offer'      => $offer,
        ];
    }

    return ['can_delete' => true, 'reason' => '', 'offer' => $offer];
}

/**
 * Safely delete a job offer.
 *
 * @param int $id
 * @param PDO $pdo
 * @param int $deletedBy
 * @return array{success: bool, message: string}
 */
function delete_offer(int $id, PDO $pdo, int $deletedBy): array
{
    $chk = check_offer_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $offer = $chk['offer'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM job_offers WHERE id = ?")->execute([$id]);

        log_activity('offer.delete', 'job_offers', $id, [
            'offer_code'     => $offer['offer_code'] ?? "#{$id}",
            'application_id' => $offer['application_id'],
            'company_id'     => $offer['company_id'],
        ], (int)$offer['company_id'], $deletedBy);

        $pdo->commit();
        return ['success' => true, 'message' => "Job offer was permanently deleted."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete offer {$id}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete offer: ' . $e->getMessage()];
    }
}
