<?php
declare(strict_types=1);

/**
 * Write one row to activity_logs: who, what, when, from where.
 * Never pass passwords or other secrets in $details.
 * A logging failure must never break the user's action.
 */
function log_activity(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array|string|null $details = null,
    ?int $companyId = null,
    ?int $userId = null
): void {
    try {
        $userId ??= isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $st = db()->prepare(
            'INSERT INTO activity_logs (user_id, company_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $companyId, $action, $entityType, $entityId, $details, client_ip()]);
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

/**
 * Recursively masks sensitive keys in audit log details arrays or decoded JSON.
 * Sensitive keys: password, password_hash, token, csrf, session, nic, nic_passport,
 * salary, basic_salary, offered_salary, account, card.
 */
function mask_sensitive_audit_data(mixed $data): mixed
{
    static $sensitiveKeys = [
        'password',
        'password_hash',
        'token',
        'csrf',
        'session',
        'nic',
        'nic_passport',
        'salary',
        'basic_salary',
        'offered_salary',
        'account',
        'card',
    ];

    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return mask_sensitive_audit_data($decoded);
        }
        return $data;
    }

    if (!is_array($data)) {
        return $data;
    }

    $result = [];
    foreach ($data as $key => $value) {
        $keyLower = strtolower((string)$key);
        $isSensitive = false;
        foreach ($sensitiveKeys as $sk) {
            if ($keyLower === $sk || str_contains($keyLower, $sk)) {
                $isSensitive = true;
                break;
            }
        }
        if ($isSensitive) {
            $result[$key] = '[hidden]';
        } elseif (is_array($value)) {
            $result[$key] = mask_sensitive_audit_data($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
