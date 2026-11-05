<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 1. Authentication check
if (!session_is_valid() || current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 2. Rate limiting check (60 searches per minute per user)
if (!check_rate_limit('global_search', 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please wait a moment before searching again.']);
    exit;
}

// 3. Query extraction & sanitization
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);

if (mb_strlen($q) < 2) {
    echo json_encode([
        'success'     => true,
        'q'           => $q,
        'total_count' => 0,
        'groups'      => [],
    ]);
    exit;
}

// 4. Perform search (limit 5 per group for dropdown preview)
$searchData = search_global($q, ['limit_per_group' => 5]);

// Format items for dropdown consumption
$formattedGroups = [];
foreach ($searchData['groups'] as $key => $group) {
    if ($group['total'] <= 0) {
        continue;
    }

    $formattedItems = [];
    foreach ($group['items'] as $item) {
        $formattedItems[] = [
            'id'                => $item['id'],
            'title'             => $item['title'],
            'highlighted_title' => search_highlight($item['title'], $q),
            'subtitle'          => $item['subtitle'],
            'url'               => $item['url'],
            'icon'              => $item['icon'],
            'type'              => $item['type'],
        ];
    }

    $formattedGroups[] = [
        'key'   => $group['key'],
        'label' => $group['label'],
        'icon'  => $group['icon'],
        'total' => $group['total'],
        'items' => $formattedItems,
    ];
}

echo json_encode([
    'success'     => true,
    'q'           => $q,
    'total_count' => $searchData['total_count'],
    'groups'      => $formattedGroups,
]);
exit;

