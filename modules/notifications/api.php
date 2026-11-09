<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 1. Authentication Check
if (!session_is_valid() || current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// 2. Rate Limiting (120 requests per minute per user)
if (!check_rate_limit('notifications_api', 120, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'poll'));

// For POST actions, verify CSRF token
if ($method === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($submittedToken === null) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && isset($parsed['csrf_token'])) {
                $submittedToken = (string)$parsed['csrf_token'];
                if (isset($parsed['action'])) {
                    $action = (string)$parsed['action'];
                }
                if (isset($parsed['id'])) {
                    $_POST['id'] = $parsed['id'];
                }
            }
        }
    }

    if (!is_string($submittedToken) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $submittedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }
}

switch ($action) {
    case 'poll':
    case 'list':
        $limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 8;
        $unreadCount = get_unread_notification_count($pdo, $currentUserId);
        $recentRows = get_recent_notifications($pdo, $currentUserId, $limit);

        $items = [];
        foreach ($recentRows as $row) {
            $meta = notification_type_meta((string)$row['type']);
            $items[] = [
                'id'         => (int)$row['id'],
                'type'       => (string)$row['type'],
                'type_label' => $meta['label'],
                'title'      => (string)$row['title'],
                'message'    => (string)$row['message'],
                'link_url'   => !empty($row['link_url']) ? url((string)$row['link_url']) : null,
                'is_read'    => (int)$row['is_read'] === 1,
                'read_at'    => $row['read_at'],
                'created_at' => (string)$row['created_at'],
                'time_ago'   => notification_time_ago((string)$row['created_at']),
                'icon'       => $meta['icon'],
                'color'      => $meta['color'],
            ];
        }

        echo json_encode([
            'success'       => true,
            'unread_count'  => $unreadCount,
            'notifications' => $items,
        ]);
        exit;

    case 'mark_read':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $idParam = $_POST['id'] ?? ($_GET['id'] ?? null);

        if ($idParam === 'all' || (isset($_POST['mark_all']) && $_POST['mark_all'] == 1)) {
            $updated = mark_all_notifications_as_read($pdo, $currentUserId);
            echo json_encode([
                'success'      => true,
                'marked_count' => $updated,
                'unread_count' => 0,
            ]);
            exit;
        }

        $notifId = (int)$idParam;
        if ($notifId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid notification ID']);
            exit;
        }

        // Must strictly belong to the authenticated user
        $ok = mark_notification_as_read($pdo, $notifId, $currentUserId);
        if (!$ok) {
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found or access denied']);
            exit;
        }

        $unreadCount = get_unread_notification_count($pdo, $currentUserId);
        echo json_encode([
            'success'      => true,
            'id'           => $notifId,
            'unread_count' => $unreadCount,
        ]);
        exit;

    case 'mark_unread':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid notification ID']);
            exit;
        }

        // Must strictly belong to the authenticated user
        $ok = mark_notification_as_unread($pdo, $notifId, $currentUserId);
        if (!$ok) {
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found or access denied']);
            exit;
        }

        $unreadCount = get_unread_notification_count($pdo, $currentUserId);
        echo json_encode([
            'success'      => true,
            'id'           => $notifId,
            'unread_count' => $unreadCount,
        ]);
        exit;

    case 'delete':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid notification ID']);
            exit;
        }

        // Must strictly belong to the authenticated user
        $deleted = delete_notification($pdo, $notifId, $currentUserId);
        if (!$deleted) {
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found or access denied']);
            exit;
        }

        $unreadCount = get_unread_notification_count($pdo, $currentUserId);
        echo json_encode([
            'success'      => true,
            'id'           => $notifId,
            'unread_count' => $unreadCount,
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action requested']);
        exit;
}

