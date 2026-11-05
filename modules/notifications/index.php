<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// Handle POST actions (mark all read, mark single read/unread, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $postAction = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['id'] ?? 0);

    if ($postAction === 'mark_all_read') {
        $count = mark_all_notifications_as_read($pdo, $currentUserId);
        flash_set('success', $count > 0 ? "Marked {$count} notification(s) as read." : "All notifications are already read.");
    } elseif ($postAction === 'mark_read' && $targetId > 0) {
        if (mark_notification_as_read($pdo, $targetId, $currentUserId)) {
            flash_set('success', 'Notification marked as read.');
        } else {
            flash_set('danger', 'Unable to mark notification as read.');
        }
    } elseif ($postAction === 'mark_unread' && $targetId > 0) {
        if (mark_notification_as_unread($pdo, $targetId, $currentUserId)) {
            flash_set('success', 'Notification marked as unread.');
        } else {
            flash_set('danger', 'Unable to mark notification as unread.');
        }
    } elseif ($postAction === 'delete' && $targetId > 0) {
        if (delete_notification($pdo, $targetId, $currentUserId)) {
            flash_set('success', 'Notification deleted successfully.');
        } else {
            flash_set('danger', 'Unable to delete notification.');
        }
    }

    $retTab = (string)($_POST['tab'] ?? 'all');
    $retType = (string)($_POST['type'] ?? '');
    $retPage = (int)($_POST['page'] ?? 1);
    $redirectUrl = 'modules/notifications/index.php?tab=' . urlencode($retTab) . '&type=' . urlencode($retType) . '&page=' . $retPage;
    redirect($redirectUrl);
}

// Query parameters
$tab = (string)($_GET['tab'] ?? 'all');
if (!in_array($tab, ['all', 'unread'], true)) {
    $tab = 'all';
}
$typeFilter = trim((string)($_GET['type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Fetch paginated notifications
$data = get_notifications_paginated($pdo, $currentUserId, $tab, $typeFilter !== '' ? $typeFilter : null, $page, $perPage);
$items = $data['items'];
$totalCount = $data['total'];
$totalPages = $data['total_pages'];
$unreadCount = $data['unread_count'];

// Total count across all notifications
$stAllTotal = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$stAllTotal->execute([$currentUserId]);
$allTotal = (int)$stAllTotal->fetchColumn();

// Distinct types present for this user for filter dropdown
$stTypes = $pdo->prepare('SELECT DISTINCT type FROM notifications WHERE user_id = ? ORDER BY type');
$stTypes->execute([$currentUserId]);
$userTypes = $stTypes->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Notifications';
include_once ROOT_PATH . '/includes/header.php';
?>

<style>
.notif-feed-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.notif-feed-item {
    position: relative;
    border: 1px solid var(--border) !important;
    background-color: var(--surface) !important;
    border-radius: var(--radius-card, 10px);
    margin-bottom: 0;
    transition: transform var(--transition-hover, 0.15s ease), box-shadow var(--transition-hover, 0.15s ease), border-color var(--transition-hover, 0.15s ease);
}
.notif-feed-item:hover {
    border-color: rgba(37, 99, 235, 0.3) !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
    transform: translateY(-1px);
}
.notif-avatar {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background-color: rgba(37, 99, 235, 0.08);
    color: var(--accent, #2563EB);
    border: 1px solid rgba(37, 99, 235, 0.16);
    transition: transform 0.15s ease;
}
.notif-feed-item:hover .notif-avatar {
    transform: scale(1.04);
}
.notif-filter-pills .nav-link {
    color: var(--text-secondary);
    font-weight: 500;
    border: 1px solid transparent;
    padding: 6px 14px;
    border-radius: 9999px;
    font-size: 0.85rem;
    transition: all 0.15s ease;
}
.notif-filter-pills .nav-link:hover {
    color: var(--text-primary);
    background-color: var(--surface-hover, #F1F5F9);
}
.notif-filter-pills .nav-link.active {
    background-color: var(--accent, #2563EB);
    color: #FFFFFF !important;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
}
.notif-filter-pills .nav-link.active .badge {
    background-color: rgba(255, 255, 255, 0.25) !important;
    color: #FFFFFF !important;
}
.btn-icon-subtle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: transparent;
    border: 1px solid transparent;
    color: var(--text-muted);
    transition: all 0.15s ease;
    cursor: pointer;
}
.btn-ghost-secondary:hover {
    background-color: var(--surface-hover, #F1F5F9);
    color: var(--text-primary);
    border-color: var(--border);
}
.btn-ghost-primary {
    color: var(--accent, #2563EB);
}
.btn-ghost-primary:hover {
    background-color: var(--accent-tint, rgba(37, 99, 235, 0.1));
    color: var(--accent-hover, #1D4ED8);
    border-color: rgba(37, 99, 235, 0.25);
}
.btn-ghost-danger {
    color: var(--text-muted);
}
.btn-ghost-danger:hover {
    background-color: var(--danger-tint, rgba(239, 68, 68, 0.1));
    color: var(--danger, #EF4444);
    border-color: rgba(239, 68, 68, 0.25);
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">
    <!-- Breadcrumb & Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1.5 small">
                    <li class="breadcrumb-item"><a href="<?= url('index.php') ?>" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Notifications</li>
                </ol>
            </nav>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h1 class="h3 fw-bold mb-0 text-body">
                    <i class="bi bi-bell-fill me-2 text-primary"></i>Notifications Center
                </h1>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 small fw-semibold">
                        <?= $unreadCount ?> unread
                    </span>
                <?php else: ?>
                    <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2.5 py-1 small fw-medium">
                        <i class="bi bi-check2 me-1"></i>All caught up
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-secondary small mb-0 mt-1">Review workflow updates, system alerts, and notifications.</p>
        </div>
        <div>
            <?php if ($unreadCount > 0): ?>
                <form method="POST" action="<?= url('modules/notifications/index.php') ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1.5 d-inline-flex align-items-center gap-1.5 shadow-none">
                        <i class="bi bi-check2-all"></i>
                        <span>Mark All as Read</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-2.5">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <!-- Filter Tabs: All vs Unread -->
                <ul class="nav nav-pills notif-filter-pills gap-1" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="<?= url('modules/notifications/index.php?tab=all' . ($typeFilter !== '' ? '&type=' . urlencode($typeFilter) : '')) ?>">
                            <i class="bi bi-inbox me-1"></i> All
                            <span class="badge rounded-pill ms-1"><?= $allTotal ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'unread' ? 'active' : '' ?>" href="<?= url('modules/notifications/index.php?tab=unread' . ($typeFilter !== '' ? '&type=' . urlencode($typeFilter) : '')) ?>">
                            <i class="bi bi-envelope me-1"></i> Unread
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge rounded-pill bg-danger text-white ms-1"><?= $unreadCount ?></span>
                            <?php else: ?>
                                <span class="badge rounded-pill ms-1">0</span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <!-- Category Filter Dropdown -->
                <form method="GET" action="<?= url('modules/notifications/index.php') ?>" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <div class="input-group input-group-sm" style="min-width: 220px;">
                        <span class="input-group-text bg-body-tertiary border-end-0 text-muted rounded-start-pill ps-3 pe-2">
                            <i class="bi bi-funnel"></i>
                        </span>
                        <select id="typeSelect" name="type" class="form-select form-select-sm border-start-0 rounded-end-pill pe-4" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($userTypes as $ut): ?>
                                <?php $meta = notification_type_meta((string)$ut); ?>
                                <option value="<?= e($ut) ?>" <?= $typeFilter === $ut ? 'selected' : '' ?>>
                                    <?= e($meta['label'] . ' (' . $ut . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($typeFilter !== ''): ?>
                        <a href="<?= url('modules/notifications/index.php?tab=' . urlencode($tab)) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-2.5" title="Clear category filter">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Notifications List -->
    <?php if (empty($items)): ?>
        <div class="card border-0 shadow-sm rounded-4 text-center py-5 px-3 my-3">
            <div class="card-body py-4">
                <div class="notif-empty-illustration mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 68px; height: 68px; background: rgba(37, 99, 235, 0.08); color: var(--accent, #2563EB);">
                    <i class="bi <?= $tab === 'unread' ? 'bi-check2-circle' : 'bi-bell-slash' ?>" style="font-size: 2.2rem;"></i>
                </div>
                <h5 class="fw-semibold text-body mb-2">
                    <?= $tab === 'unread' ? "You're all caught up!" : "No notifications found" ?>
                </h5>
                <p class="text-secondary small mb-3 mx-auto" style="max-width: 440px;">
                    <?= $tab === 'unread' ? 'There are no unread notifications waiting for your review. Check the "All" tab to view past alerts and updates.' : 'There are no notifications matching your selected filter criteria.' ?>
                </p>
                <?php if ($tab === 'unread'): ?>
                    <a href="<?= url('modules/notifications/index.php?tab=all') ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5">
                        <i class="bi bi-inbox me-1"></i> View All Notifications
                    </a>
                <?php elseif ($typeFilter !== ''): ?>
                    <a href="<?= url('modules/notifications/index.php?tab=' . urlencode($tab)) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Category Filter
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="notif-feed-container">
            <?php foreach ($items as $item): ?>
                <?php
                    $isRead = (int)$item['is_read'] === 1;
                    $meta = notification_type_meta((string)$item['type']);
                    $timeAgo = notification_time_ago((string)$item['created_at']);
                ?>
                <div class="card notif-feed-item shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-start gap-3">
                            <!-- Icon Avatar -->
                            <div class="notif-avatar">
                                <i class="bi <?= e($meta['icon']) ?> fs-5"></i>
                            </div>

                            <!-- Content Body -->
                            <div class="flex-grow-1 min-w-0">
                                <!-- Header Row: Category, New Badge, Time -->
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1.5">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge rounded-pill bg-body-secondary text-secondary border border-secondary-subtle px-2.5 py-1 fw-medium" style="font-size: 0.75rem;">
                                            <?= e($meta['label']) ?>
                                        </span>
                                        <?php if (!$isRead): ?>
                                            <span class="badge rounded-pill bg-primary text-white px-2 py-0.5 fw-semibold" style="font-size: 0.7rem;">
                                                New
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 text-muted small" title="<?= e($item['created_at']) ?>">
                                        <i class="bi bi-clock text-secondary opacity-75" style="font-size: 0.78rem;"></i>
                                        <span><?= e($timeAgo) ?></span>
                                    </div>
                                </div>

                                <!-- Notification Title -->
                                <h6 class="mb-1 text-body fw-semibold" style="font-size: 0.95rem;">
                                    <?= e($item['title']) ?>
                                </h6>

                                <!-- Notification Message -->
                                <p class="text-secondary small mb-2 text-break" style="line-height: 1.5; font-size: 0.865rem;">
                                    <?= e($item['message']) ?>
                                </p>

                                <!-- Action Toolbar -->
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 border-top border-light-subtle">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($item['link_url'])): ?>
                                            <a href="<?= e(url($item['link_url'])) ?>" class="btn btn-primary btn-sm rounded-pill px-3 py-1 d-inline-flex align-items-center gap-1.5 fw-medium shadow-none notif-link-btn" data-id="<?= (int)$item['id'] ?>" style="font-size: 0.8rem; height: 30px;">
                                                <span>View Details</span>
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex align-items-center gap-1.5 ms-auto">
                                        <!-- Toggle Read / Unread -->
                                        <form method="POST" action="<?= url('modules/notifications/index.php') ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="<?= $isRead ? 'mark_unread' : 'mark_read' ?>">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                            <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                            <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$page ?>">
                                            <button type="submit" class="btn btn-sm btn-icon-subtle <?= $isRead ? 'btn-ghost-secondary' : 'btn-ghost-primary' ?> rounded-pill px-2.5 py-1 d-inline-flex align-items-center gap-1" title="<?= $isRead ? 'Mark as unread' : 'Mark as read' ?>" style="font-size: 0.78rem; height: 30px;">
                                                <i class="bi bi-<?= $isRead ? 'envelope' : 'check2' ?>"></i>
                                                <span class="d-none d-sm-inline"><?= $isRead ? 'Mark as unread' : 'Mark as read' ?></span>
                                            </button>
                                        </form>

                                        <!-- Delete Notification -->
                                        <form method="POST" action="<?= url('modules/notifications/index.php') ?>" class="d-inline" onsubmit="return confirm('Delete this notification permanently?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                            <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                            <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$page ?>">
                                            <button type="submit" class="btn btn-sm btn-icon-subtle btn-ghost-danger rounded-circle p-0 d-inline-flex align-items-center justify-content-center" title="Delete notification" style="width: 30px; height: 30px;">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
        <div class="card border-0 shadow-sm rounded-3 py-2 px-3 mt-4">
            <nav aria-label="Notifications pagination">
                <ul class="pagination pagination-sm justify-content-center mb-0 gap-1">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link rounded-pill px-3" href="<?= url('modules/notifications/index.php?tab=' . urlencode($tab) . '&type=' . urlencode($typeFilter) . '&page=' . ($page - 1)) ?>">
                            <i class="bi bi-chevron-left me-1"></i> Previous
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link rounded-pill" href="<?= url('modules/notifications/index.php?tab=' . urlencode($tab) . '&type=' . urlencode($typeFilter) . '&page=' . $i) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php elseif ($i === $page - 3 || $i === $page + 3): ?>
                            <li class="page-item disabled"><span class="page-link rounded-pill">&hellip;</span></li>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link rounded-pill px-3" href="<?= url('modules/notifications/index.php?tab=' . urlencode($tab) . '&type=' . urlencode($typeFilter) . '&page=' . ($page + 1)) ?>">
                            Next <i class="bi bi-chevron-right ms-1"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const apiUrl = <?= json_encode(url('modules/notifications/api.php')) ?>;
    
    document.querySelectorAll('.notif-link-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const card = this.closest('.notif-feed-item');
            if (id && card && card.classList.contains('is-unread') && csrfToken) {
                try {
                    navigator.sendBeacon(apiUrl, new URLSearchParams({
                        action: 'mark_read',
                        id: id,
                        csrf_token: csrfToken
                    }));
                } catch (e) {
                    fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(csrfToken),
                        keepalive: true
                    }).catch(function() {});
                }
            }
        });
    });
});
</script>

<?php include_once ROOT_PATH . '/includes/footer.php'; ?>


