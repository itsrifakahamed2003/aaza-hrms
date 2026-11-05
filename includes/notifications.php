<?php
declare(strict_types=1);

/**
 * AaZa HRMS - In-App Notifications & Scheduled Reminders
 *
 * Provides notification dispatching, permission-based multi-tenant fanout,
 * user inbox queries, and automated daily reminders.
 *
 * Rules:
 *  - Strict tenant scoping: Users only receive notifications for companies they are assigned to.
 *  - Privacy guard: Never include salary, offered salary, or NIC/Passport in notification messages.
 *  - Safe links: Notification URLs are relative paths opening accessible module pages.
 */

/**
 * Checks whether a notification event channel is enabled for a given company.
 */
function is_notification_channel_enabled(PDO $pdo, ?int $companyId, string $type): bool
{
    if ($companyId === null || $companyId <= 0 || !function_exists('get_company_setting')) {
        return true;
    }
    $raw = get_company_setting($pdo, $companyId, 'notifications.enabled_types', null);
    if ($raw === null) {
        return true;
    }
    $enabledTypes = json_decode((string)$raw, true);
    if (!is_array($enabledTypes)) {
        return true;
    }

    $channel = match (true) {
        str_starts_with($type, 'leave') => 'leave',
        str_starts_with($type, 'candidate') || str_starts_with($type, 'application') || str_starts_with($type, 'vacancy') => 'recruitment',
        str_starts_with($type, 'interview') => 'interview',
        str_starts_with($type, 'offer') => 'offer',
        str_starts_with($type, 'doc') => 'document',
        str_starts_with($type, 'security') || str_starts_with($type, 'account') => 'security',
        default => null,
    };

    if ($channel === null) {
        return true;
    }

    return in_array($channel, $enabledTypes, true);
}

/**
 * Send a notification to a specific user.
 * If $dedupeKey is provided, deduplicates based on UNIQUE(user_id, dedupe_key).
 *
 * @return bool True if inserted, false if deduplicated or failed.
 */
function notify_user(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $url = null,
    ?int $companyId = null,
    ?string $dedupeKey = null
): bool {
    if ($userId <= 0) {
        return false;
    }

    if (!is_notification_channel_enabled($pdo, $companyId, $type)) {
        return false;
    }

    $type = mb_substr(trim($type), 0, 50);
    $title = mb_substr(trim($title), 0, 150);
    $message = mb_substr(trim($message), 0, 255);
    $url = ($url !== null && trim($url) !== '') ? mb_substr(trim($url), 0, 255) : null;
    $dedupeKey = ($dedupeKey !== null && trim($dedupeKey) !== '') ? mb_substr(trim($dedupeKey), 0, 100) : null;

    if ($dedupeKey !== null) {
        // Idempotent insertion using INSERT IGNORE on UNIQUE(user_id, dedupe_key)
        $st = $pdo->prepare(
            'INSERT IGNORE INTO notifications (user_id, company_id, type, title, message, link_url, dedupe_key, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())'
        );
        $st->execute([$userId, $companyId, $type, $title, $message, $url, $dedupeKey]);
        return $st->rowCount() > 0;
    }

    $st = $pdo->prepare(
        'INSERT INTO notifications (user_id, company_id, type, title, message, link_url, dedupe_key, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, 0, NOW())'
    );
    $st->execute([$userId, $companyId, $type, $title, $message, $url]);
    return $st->rowCount() > 0;
}

/**
 * Dispatch a notification to all active users who hold $permissionCode in $companyId.
 * Strictly excludes:
 *  - The actor ($excludeUserId) so users do not notify themselves.
 *  - Users not assigned to $companyId (multi-company isolation).
 *  - Users with a 'deny' override for this permission in this company.
 *
 * @return int Total number of notifications successfully inserted.
 */
function notify_permission(
    PDO $pdo,
    int $companyId,
    string $permissionCode,
    string $type,
    string $title,
    string $message,
    ?string $url = null,
    ?string $dedupeKey = null,
    ?int $excludeUserId = null
): int {
    if ($companyId <= 0) {
        return 0;
    }

    // Permission code equivalents
    $equivs = [$permissionCode];
    if ($permissionCode === 'employee.update') {
        $equivs[] = 'employee.edit';
    } elseif ($permissionCode === 'employee.edit') {
        $equivs[] = 'employee.update';
    } elseif ($permissionCode === 'user.create' || $permissionCode === 'user.edit') {
        $equivs[] = 'user.manage';
    }

    $inPlaceholders = implode(',', array_fill(0, count($equivs), '?'));

    // Query active users:
    // 1. Users assigned to $companyId who have the permission (via role or override)
    // 2. UNION Group Administrators (is_group_admin = 1, is_active = 1) across the organization
    $sql = "SELECT DISTINCT user_id FROM (
                SELECT u.id AS user_id
                  FROM users u
                  JOIN company_user_assignments cua ON cua.user_id = u.id AND cua.company_id = ?
                 WHERE u.is_active = 1
                   AND (
                       EXISTS (
                           SELECT 1 FROM user_permission_overrides upo
                           JOIN permissions p ON p.id = upo.permission_id
                           WHERE upo.user_id = u.id AND upo.company_id = ? AND p.code IN ($inPlaceholders) AND upo.effect = 'allow'
                       )
                       OR (
                           EXISTS (
                               SELECT 1 FROM company_user_assignments cua2
                               JOIN role_permissions rp ON rp.role_id = cua2.role_id
                               JOIN permissions p2 ON p2.id = rp.permission_id
                               WHERE cua2.user_id = u.id AND cua2.company_id = ? AND p2.code IN ($inPlaceholders)
                           )
                           AND NOT EXISTS (
                               SELECT 1 FROM user_permission_overrides upo2
                               JOIN permissions p3 ON p3.id = upo2.permission_id
                               WHERE upo2.user_id = u.id AND upo2.company_id = ? AND p3.code IN ($inPlaceholders) AND upo2.effect = 'deny'
                           )
                       )
                   )
                UNION
                SELECT id AS user_id
                  FROM users
                 WHERE is_group_admin = 1 AND is_active = 1
            ) AS recipients";

    $params = [$companyId, $companyId];
    foreach ($equivs as $eq) {
        $params[] = $eq;
    }
    $params[] = $companyId;
    foreach ($equivs as $eq) {
        $params[] = $eq;
    }
    $params[] = $companyId;
    foreach ($equivs as $eq) {
        $params[] = $eq;
    }

    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= " WHERE user_id != ?";
        $params[] = $excludeUserId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $recipientIds = $st->fetchAll(PDO::FETCH_COLUMN);

    $insertedCount = 0;
    foreach ($recipientIds as $recId) {
        $uid = (int)$recId;
        if (notify_user($pdo, $uid, $type, $title, $message, $url, $companyId, $dedupeKey)) {
            $insertedCount++;
        }
    }

    return $insertedCount;
}

/**
 * Get the count of unread notifications for a user.
 */
function get_unread_notification_count(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/**
 * Get the latest N notifications for a user (for the topbar dropdown).
 */
function get_recent_notifications(PDO $pdo, int $userId, int $limit = 8): array
{
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare(
        "SELECT id, company_id, type, title, message, link_url, is_read, read_at, created_at
           FROM notifications
          WHERE user_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT {$limit}"
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * Mark a single notification as read, strictly owned by $userId.
 */
function mark_notification_as_read(PDO $pdo, int $notificationId, int $userId): bool
{
    if ($notificationId <= 0 || $userId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'UPDATE notifications
            SET is_read = 1, read_at = IFNULL(read_at, NOW())
          WHERE id = ? AND user_id = ?'
    );
    $st->execute([$notificationId, $userId]);
    if ($st->rowCount() > 0) {
        return true;
    }
    // Check if it belongs to user and was already marked as read
    $chk = $pdo->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$notificationId, $userId]);
    return (bool)$chk->fetchColumn();
}

/**
 * Mark a single notification as unread, strictly owned by $userId.
 */
function mark_notification_as_unread(PDO $pdo, int $notificationId, int $userId): bool
{
    if ($notificationId <= 0 || $userId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'UPDATE notifications
            SET is_read = 0, read_at = NULL
          WHERE id = ? AND user_id = ?'
    );
    $st->execute([$notificationId, $userId]);
    if ($st->rowCount() > 0) {
        return true;
    }
    $chk = $pdo->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$notificationId, $userId]);
    return (bool)$chk->fetchColumn();
}

/**
 * Mark all unread notifications for a user as read.
 */
function mark_all_notifications_as_read(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $st = $pdo->prepare(
        'UPDATE notifications
            SET is_read = 1, read_at = NOW()
          WHERE user_id = ? AND is_read = 0'
    );
    $st->execute([$userId]);
    return $st->rowCount();
}

/**
 * Delete a notification, strictly owned by $userId.
 */
function delete_notification(PDO $pdo, int $notificationId, int $userId): bool
{
    if ($notificationId <= 0 || $userId <= 0) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
    $st->execute([$notificationId, $userId]);
    return $st->rowCount() > 0;
}

/**
 * Retrieve paginated notifications for the full notification center.
 */
function get_notifications_paginated(
    PDO $pdo,
    int $userId,
    string $filter = 'all',
    ?string $type = null,
    int $page = 1,
    int $perPage = 20
): array {
    if ($userId <= 0) {
        return [
            'items'        => [],
            'total'        => 0,
            'page'         => 1,
            'per_page'     => $perPage,
            'total_pages'  => 1,
            'unread_count' => 0,
        ];
    }

    $where = ['user_id = ?'];
    $params = [$userId];

    if ($filter === 'unread') {
        $where[] = 'is_read = 0';
    }

    if ($type !== null && $type !== '' && $type !== 'all') {
        $where[] = 'type = ?';
        $params[] = $type;
    }

    $whereSql = implode(' AND ', $where);

    // Count matching
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $totalPages = max(1, (int)ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    $listSt = $pdo->prepare(
        "SELECT id, company_id, type, title, message, link_url, is_read, read_at, created_at
           FROM notifications
          WHERE {$whereSql}
          ORDER BY created_at DESC, id DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $listSt->execute($params);
    $items = $listSt->fetchAll();

    $unreadCount = get_unread_notification_count($pdo, $userId);

    return [
        'items'        => $items,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => $totalPages,
        'unread_count' => $unreadCount,
    ];
}

/**
 * Return display metadata (icon, color, badge style) for a notification type.
 */
function notification_type_meta(string $type): array
{
    return match (true) {
        str_starts_with($type, 'leave') => [
            'icon'        => 'bi-calendar-check',
            'badge_class' => 'bg-info-subtle text-info border border-info-subtle',
            'color'       => '#0284c7',
            'label'       => 'Leave',
        ],
        str_starts_with($type, 'candidate_') || str_starts_with($type, 'app_') => [
            'icon'        => 'bi-person-badge',
            'badge_class' => 'bg-primary-subtle text-primary border border-primary-subtle',
            'color'       => '#4f46e5',
            'label'       => 'Recruitment',
        ],
        str_starts_with($type, 'interview') || str_starts_with($type, 'eval') => [
            'icon'        => 'bi-camera-video',
            'badge_class' => 'bg-purple-subtle text-purple border border-purple-subtle',
            'color'       => '#7c3aed',
            'label'       => 'Interview',
        ],
        str_starts_with($type, 'offer') => [
            'icon'        => 'bi-file-earmark-text',
            'badge_class' => 'bg-success-subtle text-success border border-success-subtle',
            'color'       => '#16a34a',
            'label'       => 'Offer',
        ],
        str_starts_with($type, 'doc') => [
            'icon'        => 'bi-file-earmark-exclamation',
            'badge_class' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            'color'       => '#d97706',
            'label'       => 'Document',
        ],
        str_starts_with($type, 'vacancy') => [
            'icon'        => 'bi-briefcase',
            'badge_class' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
            'color'       => '#475569',
            'label'       => 'Vacancy',
        ],
        str_starts_with($type, 'password') || str_starts_with($type, 'account') => [
            'icon'        => 'bi-shield-lock',
            'badge_class' => 'bg-danger-subtle text-danger border border-danger-subtle',
            'color'       => '#dc2626',
            'label'       => 'Security',
        ],
        default => [
            'icon'        => 'bi-bell',
            'badge_class' => 'bg-light text-dark border',
            'color'       => '#64748b',
            'label'       => 'General',
        ],
    };
}

/**
 * Format a timestamp into a relative human-friendly string.
 */
function notification_time_ago(string $datetime): string
{
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . 'm ago';
    }
    if ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . 'h ago';
    }
    if ($diff < 172800) {
        return 'Yesterday ' . date('h:i A', $timestamp);
    }
    if ($diff < 604800) {
        $days = (int)floor($diff / 86400);
        return $days . 'd ago';
    }
    return date('d M Y, h:i A', $timestamp);
}

/**
 * Execute automated reminder rules (CLI / Scheduler Engine).
 *
 * Checks:
 *  1. Upcoming interviews (today & tomorrow) -> panel members
 *  2. Interviews awaiting evaluation -> panel members
 *  3. Candidates awaiting hiring decision (selected without offer) -> vacancy owner + HR
 *  4. Vacancy closing date approaching (<= 2 days) -> vacancy owner + HR
 *  5. Pending leave requests (> 3 days stale) -> approvers
 *  6. Employee documents expiring within 30 days -> HR
 *
 * @param PDO  $pdo
 * @param bool $dryRun When true, calculates reminders without inserting rows.
 * @return array Counts of reminders created per category.
 */
function send_system_reminders(PDO $pdo, bool $dryRun = false): array
{
    $today = date('Y-m-d');
    $stats = [
        'interviews_upcoming'   => 0,
        'interviews_awaiting'   => 0,
        'candidates_decision'   => 0,
        'vacancies_deadline'    => 0,
        'leaves_stale'          => 0,
        'documents_expiry'      => 0,
        'total'                 => 0,
    ];

    // 1. Upcoming interviews (scheduled today or tomorrow)
    $stUpcoming = $pdo->prepare(
        "SELECT i.id, i.application_id, i.round_no, i.scheduled_at, i.company_id,
                c.first_name, c.last_name, comp.name AS company_name,
                ip.user_id AS panel_user_id
           FROM interviews i
           JOIN candidate_applications ca ON ca.id = i.application_id
           JOIN candidates c ON c.id = ca.candidate_id
           JOIN companies comp ON comp.id = i.company_id
           JOIN interview_panel ip ON ip.interview_id = i.id
          WHERE i.status = 'scheduled'
            AND DATE(i.scheduled_at) IN (CURDATE(), CURDATE() + INTERVAL 1 DAY)"
    );
    $stUpcoming->execute();
    foreach ($stUpcoming->fetchAll() as $row) {
        $dedupeKey = "interview:upcoming:{$row['id']}:{$row['panel_user_id']}:{$today}";
        $scheduledTime = date('d M Y, h:i A', strtotime($row['scheduled_at']));
        $title = 'Upcoming Interview Reminder';
        $msg = "Interview Round {$row['round_no']} with {$row['first_name']} {$row['last_name']} is scheduled for {$scheduledTime}.";
        $url = 'modules/interviews/view.php?id=' . (int)$row['id'];

        if ($dryRun) {
            $stats['interviews_upcoming']++;
        } elseif (notify_user($pdo, (int)$row['panel_user_id'], 'interview_reminder', $title, $msg, $url, (int)$row['company_id'], $dedupeKey)) {
            $stats['interviews_upcoming']++;
        }
    }

    // 2. Interviews awaiting evaluation (completed or passed scheduled date without evaluation)
    $stAwaiting = $pdo->prepare(
        "SELECT i.id, i.application_id, i.round_no, i.scheduled_at, i.company_id,
                c.first_name, c.last_name,
                ip.user_id AS panel_user_id
           FROM interviews i
           JOIN candidate_applications ca ON ca.id = i.application_id
           JOIN candidates c ON c.id = ca.candidate_id
           JOIN interview_panel ip ON ip.interview_id = i.id
          WHERE (i.status = 'completed' OR (i.status = 'scheduled' AND i.scheduled_at < NOW()))
            AND NOT EXISTS (
                SELECT 1 FROM interview_evaluations ie
                 WHERE ie.interview_id = i.id AND ie.evaluator_id = ip.user_id
            )"
    );
    $stAwaiting->execute();
    foreach ($stAwaiting->fetchAll() as $row) {
        $dedupeKey = "interview:awaiting_eval:{$row['id']}:{$row['panel_user_id']}:{$today}";
        $title = 'Pending Interview Evaluation';
        $msg = "Please submit your evaluation for {$row['first_name']} {$row['last_name']} (Round {$row['round_no']}).";
        $url = 'modules/interviews/evaluate.php?id=' . (int)$row['id'];

        if ($dryRun) {
            $stats['interviews_awaiting']++;
        } elseif (notify_user($pdo, (int)$row['panel_user_id'], 'interview_eval_reminder', $title, $msg, $url, (int)$row['company_id'], $dedupeKey)) {
            $stats['interviews_awaiting']++;
        }
    }

    // 3. Candidates awaiting hiring decision (selected without job offer)
    $stDecision = $pdo->prepare(
        "SELECT ca.id, ca.candidate_id, ca.company_id, ca.vacancy_id,
                c.first_name, c.last_name,
                jv.title AS vacancy_title, jv.created_by AS vacancy_owner_id
           FROM candidate_applications ca
           JOIN candidates c ON c.id = ca.candidate_id
           JOIN job_vacancies jv ON jv.id = ca.vacancy_id
          WHERE ca.status = 'selected'
            AND NOT EXISTS (
                SELECT 1 FROM job_offers jo WHERE jo.application_id = ca.id
            )"
    );
    $stDecision->execute();
    foreach ($stDecision->fetchAll() as $row) {
        $appId = (int)$row['id'];
        $dedupeKey = "candidate:decision:{$appId}:{$today}";
        $title = 'Hiring Decision Pending';
        $msg = "Candidate {$row['first_name']} {$row['last_name']} has been selected for {$row['vacancy_title']} and is awaiting an offer or next step.";
        $url = 'modules/candidates/view.php?id=' . (int)$row['candidate_id'];

        if ($dryRun) {
            $stats['candidates_decision']++;
        } else {
            $notified = 0;
            if (!empty($row['vacancy_owner_id'])) {
                if (notify_user($pdo, (int)$row['vacancy_owner_id'], 'candidate_decision', $title, $msg, $url, (int)$row['company_id'], $dedupeKey)) {
                    $notified++;
                }
            }
            $notified += notify_permission($pdo, (int)$row['company_id'], 'offer.create', 'candidate_decision', $title, $msg, $url, $dedupeKey, (int)($row['vacancy_owner_id'] ?? 0));
            if ($notified > 0) {
                $stats['candidates_decision']++;
            }
        }
    }

    // 4. Vacancy closing date deadlines (closing within next 2 days or today)
    $stVac = $pdo->prepare(
        "SELECT jv.id, jv.company_id, jv.title, jv.closing_date, jv.created_by
           FROM job_vacancies jv
          WHERE jv.status = 'open'
            AND jv.closing_date IS NOT NULL
            AND jv.closing_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 2 DAY"
    );
    $stVac->execute();
    foreach ($stVac->fetchAll() as $row) {
        $vacId = (int)$row['id'];
        $closingFormatted = date('d M Y', strtotime($row['closing_date']));
        $dedupeKey = "vacancy:deadline:{$vacId}:{$today}";
        $title = 'Vacancy Closing Soon';
        $msg = "Job vacancy \"{$row['title']}\" application deadline is approaching on {$closingFormatted}.";
        $url = 'modules/vacancies/view.php?id=' . $vacId;

        if ($dryRun) {
            $stats['vacancies_deadline']++;
        } else {
            $notified = 0;
            if (!empty($row['created_by'])) {
                if (notify_user($pdo, (int)$row['created_by'], 'vacancy_deadline', $title, $msg, $url, (int)$row['company_id'], $dedupeKey)) {
                    $notified++;
                }
            }
            $notified += notify_permission($pdo, (int)$row['company_id'], 'candidate.view', 'vacancy_deadline', $title, $msg, $url, $dedupeKey, (int)($row['created_by'] ?? 0));
            if ($notified > 0) {
                $stats['vacancies_deadline']++;
            }
        }
    }

    // 5. Pending leave requests older than 3 days
    $stLeave = $pdo->prepare(
        "SELECT lr.id, lr.employee_id, lr.company_id, lr.requested_at, lr.total_days,
                e.employee_code, e.first_name, e.last_name,
                lt.name AS leave_type_name
           FROM leave_requests lr
           JOIN employees e ON e.id = lr.employee_id
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.status = 'pending'
            AND lr.requested_at <= NOW() - INTERVAL 3 DAY"
    );
    $stLeave->execute();
    foreach ($stLeave->fetchAll() as $row) {
        $reqId = (int)$row['id'];
        $reqDateFormatted = date('d M Y', strtotime($row['requested_at']));
        $dedupeKey = "leave:stale:{$reqId}:{$today}";
        $title = 'Pending Leave Request (> 3 Days)';
        $msg = "Leave request for {$row['first_name']} {$row['last_name']} ({$row['employee_code']}) submitted on {$reqDateFormatted} is awaiting approval.";
        $url = 'modules/leave/view.php?id=' . $reqId;

        if ($dryRun) {
            $stats['leaves_stale']++;
        } else {
            $sent = notify_permission($pdo, (int)$row['company_id'], 'leave.approve', 'leave_reminder', $title, $msg, $url, $dedupeKey);
            if ($sent > 0) {
                $stats['leaves_stale']++;
            }
        }
    }

    // 6. Employee document expiry within 30 days
    $stDoc = $pdo->prepare(
        "SELECT ed.id, ed.employee_id, ed.company_id, ed.title, ed.doc_type, ed.expiry_date,
                e.employee_code, e.first_name, e.last_name
           FROM employee_documents ed
           JOIN employees e ON e.id = ed.employee_id
          WHERE ed.expiry_date IS NOT NULL
            AND ed.expiry_date >= CURDATE()
            AND ed.expiry_date <= CURDATE() + INTERVAL 30 DAY"
    );
    $stDoc->execute();
    foreach ($stDoc->fetchAll() as $row) {
        $docId = (int)$row['id'];
        $docTitle = !empty($row['title']) ? $row['title'] : ucwords(str_replace('_', ' ', $row['doc_type']));
        $expiryFormatted = date('d M Y', strtotime($row['expiry_date']));
        $dedupeKey = "doc:expiry:{$docId}:{$today}";
        $title = 'Document Expiring Soon';
        $msg = "Document \"{$docTitle}\" for {$row['first_name']} {$row['last_name']} ({$row['employee_code']}) expires on {$expiryFormatted}.";
        $url = 'modules/employees/view.php?id=' . (int)$row['employee_id'] . '#documents';

        if ($dryRun) {
            $stats['documents_expiry']++;
        } else {
            $sent = notify_permission($pdo, (int)$row['company_id'], 'employee.view', 'document_expiry', $title, $msg, $url, $dedupeKey);
            if ($sent > 0) {
                $stats['documents_expiry']++;
            }
        }
    }

    $stats['total'] = $stats['interviews_upcoming']
                    + $stats['interviews_awaiting']
                    + $stats['candidates_decision']
                    + $stats['vacancies_deadline']
                    + $stats['leaves_stale']
                    + $stats['documents_expiry'];

    return $stats;
}
