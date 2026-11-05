<?php
declare(strict_types=1);

/**
 * Shared page layout (top). Set $pageTitle before including.
 * Pages must call require_login() before including this file.
 */
$pageTitle = $pageTitle ?? APP_NAME;
$currentUserRow = current_user();

// Grouped navigation menu items
$navCanLeaveApprove = user_can('leave.approve');
$navPendingLeaveCount = $navCanLeaveApprove ? count_pending_leaves(db()) : 0;
$navCanInterviewView = user_can('interview.view');
$navTodayInterviewCount = $navCanInterviewView ? count_today_interviews(db()) : 0;
$navHasLinkedEmp = (bool)get_linked_employee_for_user(db(), current_user_id());
$topbarUnreadNotifs = current_user_id() > 0 ? get_unread_notification_count(db(), current_user_id()) : 0;
$topbarRecentNotifs = current_user_id() > 0 ? get_recent_notifications(db(), current_user_id(), 8) : [];
$navCanPerformance = user_can('performance.view')
    || user_can('performance.manage')
    || user_can('performance.view_own')
    || is_reporting_manager_of_any(db(), current_user_id());
$navSelf = (string)($_SERVER['SCRIPT_NAME'] ?? '');

$navIsItemActive = function(string $path) use ($navSelf): bool {
    return str_ends_with($navSelf, '/' . $path)
        || ($path === 'modules/employees/index.php' && str_contains($navSelf, '/modules/employees/'))
        || ($path === 'modules/vacancies/index.php' && str_contains($navSelf, '/modules/vacancies/'))
        || ($path === 'modules/candidates/index.php' && str_contains($navSelf, '/modules/candidates/'))
        || ($path === 'modules/interviews/calendar.php' && str_contains($navSelf, '/modules/interviews/calendar.php'))
        || ($path === 'modules/interviews/index.php' && str_contains($navSelf, '/modules/interviews/') && !str_contains($navSelf, '/modules/interviews/calendar.php'))
        || ($path === 'modules/offers/index.php' && str_contains($navSelf, '/modules/offers/'))
        || ($path === 'modules/companies/index.php' && str_contains($navSelf, '/modules/companies/'))
        || ($path === 'modules/users/index.php' && (str_contains($navSelf, '/modules/users/') && !str_contains($navSelf, '/modules/users/roles.php')))
        || ($path === 'modules/roles/index.php' && (str_contains($navSelf, '/modules/roles/') || str_contains($navSelf, '/modules/users/roles.php')))
        || ($path === 'modules/attendance/daily.php' && str_contains($navSelf, '/modules/attendance/daily.php'))
        || ($path === 'modules/attendance/employee_attendance.php' && str_contains($navSelf, '/modules/attendance/employee_attendance.php'))
        || ($path === 'modules/leave/index.php' && (str_ends_with($navSelf, '/modules/leave/index.php') || str_ends_with($navSelf, '/modules/leave/view.php')))
        || ($path === 'modules/leave/types.php' && str_contains($navSelf, '/modules/leave/types.php'))
        || ($path === 'modules/leave/holidays.php' && str_contains($navSelf, '/modules/leave/holidays.php'))
        || ($path === 'modules/leave/my_leaves.php' && (str_ends_with($navSelf, '/modules/leave/my_leaves.php') || str_ends_with($navSelf, '/modules/leave/request.php')))
        || ($path === 'modules/performance/index.php' && str_contains($navSelf, '/modules/performance/'))
        || ($path === 'modules/reports/index.php' && str_contains($navSelf, '/modules/reports/'))
        || ($path === 'modules/audit/index.php' && str_contains($navSelf, '/modules/audit/'))
        || ($path === 'modules/system/health.php' && str_contains($navSelf, '/modules/system/'));
};

$navMenuGroups = [
    'Overview' => [
        ['Dashboard', 'public/index.php', 'bi-grid-1x2', true],
    ],
    'Insights' => [
        ['Reports', 'modules/reports/index.php', 'bi-bar-chart-line', user_can('report.view')],
    ],
    'People' => [
        ['Employees',     'modules/employees/index.php',               'bi-people',          user_can('employee.view')],
        ['Attendance',    'modules/attendance/daily.php',              'bi-calendar-check',  user_can('attendance.view')],
        [
            'type'     => 'collapse',
            'id'       => 'sidebarCollapseLeave',
            'label'    => 'Leave',
            'icon'     => 'bi-calendar2-range',
            'badge'    => $navPendingLeaveCount > 0 ? $navPendingLeaveCount : null,
            'children' => [
                ['Leave requests', 'modules/leave/index.php',    'bi-inboxes',        (user_can('leave.view') || $navCanLeaveApprove)],
                ['My Leave',      'modules/leave/my_leaves.php', 'bi-person-walking', (user_can('leave.request') && $navHasLinkedEmp)],
                ['Leave Types',   'modules/leave/types.php',     'bi-tags',           user_can('leave.manage_types')],
                ['Holidays',      'modules/leave/holidays.php',  'bi-calendar-event', (is_group_admin() || user_can('leave.manage') || user_can('leave.manage_types'))],
            ],
        ],
        ['My Attendance', 'modules/attendance/employee_attendance.php','bi-clock-history',   $navHasLinkedEmp],
        ['Performance',   'modules/performance/index.php',             'bi-award',           $navCanPerformance],
    ],
    'Recruitment' => [
        ['Vacancies',  'modules/vacancies/index.php',  'bi-briefcase',          user_can('vacancy.view')],
        ['Candidates', 'modules/candidates/index.php', 'bi-person-badge',       user_can('candidate.view')],
        [
            'type'     => 'collapse',
            'id'       => 'sidebarCollapseInterviews',
            'label'    => 'Interviews',
            'icon'     => 'bi-calendar-event',
            'badge'    => $navTodayInterviewCount > 0 ? $navTodayInterviewCount : null,
            'children' => [
                ['List',     'modules/interviews/index.php',    'bi-list-ul',   $navCanInterviewView],
                ['Calendar', 'modules/interviews/calendar.php', 'bi-calendar3', $navCanInterviewView],
            ],
        ],
        ['Offers',     'modules/offers/index.php',     'bi-file-earmark-check', user_can('offer.view')],
    ],
    'Organization' => [
        ['Companies',    'modules/companies/index.php',           'bi-buildings', is_group_admin()],
        ['Departments',  'modules/organization/departments.php',  'bi-diagram-3', user_can('department.manage')],
        ['Designations', 'modules/organization/designations.php', 'bi-award',     user_can('department.manage')],
    ],
    'Administration' => [
        ['Users',               'modules/users/index.php', 'bi-person-gear', (is_group_admin() || user_can('user.view'))],
        ['Roles & Permissions', 'modules/roles/index.php', 'bi-shield-lock', (is_group_admin() || user_can('role.manage'))],
        ['Audit Logs',           'modules/audit/index.php', 'bi-journal-text', user_can('audit.view')],
        ['System Health',       'modules/system/health.php', 'bi-heart-pulse', is_group_admin()],
    ],
];

$navSwitchCompanies = [];
$navAccessibleIds = accessible_company_ids();
if ($navAccessibleIds) {
    $navPh = implode(',', array_fill(0, count($navAccessibleIds), '?'));
    $navSt = db()->prepare("SELECT id, name FROM companies WHERE id IN ($navPh) ORDER BY name");
    $navSt->execute($navAccessibleIds);
    $navSwitchCompanies = $navSt->fetchAll();
}
$navActiveCompany = active_company_id();
$navSelf = (string)($_SERVER['SCRIPT_NAME'] ?? '');

// User Initials
$navUserInitials = 'U';
if (!empty($currentUserRow['full_name'])) {
    $navParts = explode(' ', trim((string)$currentUserRow['full_name']));
    $navFirst = mb_substr($navParts[0] ?? '', 0, 1);
    $navLast  = count($navParts) > 1 ? mb_substr(end($navParts), 0, 1) : '';
    $navUserInitials = strtoupper($navFirst . $navLast);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <!-- Prevent theme flash -->
    <script>
    (function() {
        try {
            var stored = localStorage.getItem('aaza-theme');
            var theme = stored;
            if (!theme || theme === 'auto') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    window.__AAZA_BASE_URL__ = <?= json_encode(url('')) ?>;
    </script>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/img/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= e(url('assets/img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/components.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/print.css')) ?>" media="print">
</head>
<body>
<div class="app-shell app-wrapper">
    <!-- Collapsible / Off-canvas Sidebar -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Sidebar Brand -->
        <?php
        $navCustomLogo = get_system_setting('app_logo');
        $navLogoPath = (!empty($navCustomLogo) && file_exists(ROOT_PATH . '/' . $navCustomLogo))
            ? $navCustomLogo
            : 'assets/img/aaza-icon.png';
        ?>
        <a class="sidebar-brand" href="<?= e(url('public/index.php')) ?>">
            <div class="sidebar-brand-icon">
                <img src="<?= e(url($navLogoPath)) ?>" alt="<?= e(APP_NAME) ?>">
            </div>
            <span><?= e(APP_NAME) ?></span>
        </a>

        <!-- Sidebar Navigation Groups -->
        <div class="sidebar-nav-container">
            <?php foreach ($navMenuGroups as $navGroupLabel => $navGroupItems): ?>
                <?php
                $navHasVisible = false;
                foreach ($navGroupItems as $navIt) {
                    if (($navIt['type'] ?? '') === 'collapse') {
                        $navVisSubs = array_filter($navIt['children'], fn($c) => !empty($c[3]));
                        if (!empty($navVisSubs)) { $navHasVisible = true; break; }
                    } else {
                        if (!empty($navIt[3])) { $navHasVisible = true; break; }
                    }
                }
                if (!$navHasVisible) continue;
                ?>
                <div class="sidebar-group-title"><?= e($navGroupLabel) ?></div>
                <ul class="sidebar-nav">
                    <?php foreach ($navGroupItems as $navItem): ?>
                        <?php if (($navItem['type'] ?? '') === 'collapse'): ?>
                            <?php
                            $navSecChildren = array_filter($navItem['children'], fn($c) => !empty($c[3]));
                            if (empty($navSecChildren)) continue;
                            $navSecActive = false;
                            foreach ($navSecChildren as $navChild) {
                                if ($navIsItemActive($navChild[1])) { $navSecActive = true; break; }
                            }
                            $navSecBadge = $navItem['badge'] ?? null;
                            $navSecId = $navItem['id'];
                            ?>
                            <li class="sidebar-nav-item has-submenu">
                                <button type="button"
                                        class="sidebar-nav-link sidebar-nav-parent<?= $navSecActive ? ' active' : '' ?><?= $navSecActive ? '' : ' collapsed' ?>"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= e($navSecId) ?>"
                                        aria-expanded="<?= $navSecActive ? 'true' : 'false' ?>"
                                        aria-controls="<?= e($navSecId) ?>"
                                        title="<?= e($navItem['label']) ?>">
                                    <i class="bi <?= e($navItem['icon']) ?>"></i>
                                    <span><?= e($navItem['label']) ?></span>
                                    <?php if ($navSecBadge !== null): ?>
                                        <span class="badge text-bg-warning text-body ms-auto rounded-pill px-2 me-1" style="font-size: 0.72rem;"><?= (int)$navSecBadge ?></span>
                                    <?php endif; ?>
                                    <i class="bi bi-chevron-down sidebar-chevron<?= $navSecBadge !== null ? '' : ' ms-auto' ?>"></i>
                                </button>
                                <div class="collapse<?= $navSecActive ? ' show' : '' ?>" id="<?= e($navSecId) ?>">
                                    <ul class="sidebar-submenu">
                                        <?php foreach ($navSecChildren as $navSub): ?>
                                            <?php
                                            [$navSubLabel, $navSubPath, $navSubIcon, $navSubVisible] = $navSub;
                                            $navSubBadge = $navSub[4] ?? null;
                                            $navSubIsActive = $navIsItemActive($navSubPath);
                                            ?>
                                            <li class="sidebar-submenu-item">
                                                <a class="sidebar-nav-link<?= $navSubIsActive ? ' active' : '' ?>" href="<?= e(url($navSubPath)) ?>" title="<?= e($navSubLabel) ?>">
                                                    <i class="bi <?= e($navSubIcon) ?>"></i>
                                                    <span><?= e($navSubLabel) ?></span>
                                                    <?php if ($navSubBadge !== null): ?>
                                                        <span class="badge text-bg-warning text-body ms-auto rounded-pill px-2" style="font-size: 0.72rem;"><?= (int)$navSubBadge ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </li>
                        <?php else: ?>
                            <?php
                            if (empty($navItem[3])) continue;
                            [$navLabel, $navPath, $navIcon, $navVisible] = $navItem;
                            $navBadge = $navItem[4] ?? null;
                            $navIsActive = $navIsItemActive($navPath);
                            ?>
                            <li class="sidebar-nav-item">
                                <a class="sidebar-nav-link<?= $navIsActive ? ' active' : '' ?>" href="<?= e(url($navPath)) ?>" title="<?= e($navLabel) ?>">
                                    <i class="bi <?= e($navIcon) ?>"></i>
                                    <span><?= e($navLabel) ?></span>
                                    <?php if ($navBadge !== null): ?>
                                        <span class="badge text-bg-warning text-body ms-auto rounded-pill px-2" style="font-size: 0.72rem;"><?= (int)$navBadge ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
        <script>
        try {
            var isMobile = window.innerWidth < 992;
            var isCollapsed = !isMobile && (document.body.classList.contains('sidebar-collapsed') || localStorage.getItem('aaza_sidebar_collapsed') === 'true');
            if (!isMobile && !isCollapsed) {
                var collapses = document.querySelectorAll('.sidebar-nav-container .collapse');
                for (var i = 0; i < collapses.length; i++) {
                    var cEl = collapses[i];
                    var hasActive = cEl.querySelector('.sidebar-nav-link.active') !== null;
                    if (!hasActive && cEl.id) {
                        var savedState = localStorage.getItem('aaza_sidebar_section_' + cEl.id);
                        if (savedState === 'true') {
                            cEl.classList.add('show');
                            var pBtn = document.querySelector('[data-bs-target="#' + cEl.id + '"]');
                            if (pBtn) {
                                pBtn.classList.remove('collapsed');
                                pBtn.setAttribute('aria-expanded', 'true');
                            }
                        } else if (savedState === 'false') {
                            cEl.classList.remove('show');
                            var pBtn = document.querySelector('[data-bs-target="#' + cEl.id + '"]');
                            if (pBtn) {
                                pBtn.classList.add('collapsed');
                                pBtn.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                }
                var el = document.querySelector('.sidebar-nav-container');
                if (el) {
                    el.style.maxHeight = 'calc(100vh - 92px)';
                }
                var y = parseInt(sessionStorage.getItem('aaza_sidebar_scroll') || '0', 10);
                if (el && y > 0) {
                    el.scrollTop = y;
                }
            }
        } catch (e) {}
        </script>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <span>AaZa HRMS &bull; Group HR</span>
            <i class="bi bi-shield-check text-success"></i>
        </div>
    </aside>

    <!-- Main Content Area Wrapper -->
    <div class="app-main-wrapper">
        <!-- Topbar -->
        <header class="app-topbar">
            <div class="topbar-left">
                <!-- Mobile Sidebar Toggle -->
                <button type="button" class="topbar-toggle-btn d-lg-none" id="mobileSidebarToggleBtn" aria-label="Toggle Navigation">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <!-- Desktop Sidebar Toggle -->
                <button type="button" class="topbar-toggle-btn d-none d-lg-flex" id="sidebarToggleBtn" aria-label="Toggle Sidebar" title="Collapse or Expand Sidebar">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <!-- Page Title (< 576px only) -->
                <h2 class="topbar-page-title d-block d-sm-none"><?= e($pageTitle) ?></h2>

                <!-- Unified Global Search Box -->
                <div class="topbar-search-container" id="topbarSearchContainer">
                    <button type="button" class="topbar-icon-btn d-sm-none search-mobile-toggle-btn" id="mobileSearchOpenBtn" aria-label="Open Search" title="Search">
                        <i class="bi bi-search"></i>
                    </button>

                    <form method="get" action="<?= e(url('modules/search/index.php')) ?>" class="topbar-search-box" id="topbarSearchBox" role="search">
                        <button type="button" class="btn btn-link search-mobile-back-btn d-sm-none text-body p-0 me-2" id="mobileSearchCloseBtn" aria-label="Close Search">
                            <i class="bi bi-arrow-left fs-5"></i>
                        </button>
                        <i class="bi bi-search search-box-icon"></i>
                        <input type="search"
                               name="q"
                               class="form-control search-box-input"
                               id="globalSearchInput"
                               placeholder="Search..."
                               autocomplete="off"
                               spellcheck="false"
                               maxlength="60"
                               aria-label="Global Search">
                        <button type="button" class="search-box-clear d-none" id="globalSearchClearBtn" aria-label="Clear input">
                            <i class="bi bi-x fs-6"></i>
                        </button>

                        <!-- Live Dropdown Results Menu -->
                        <div class="search-results-dropdown shadow-lg d-none" id="globalSearchResultsDropdown" role="listbox">
                            <div class="search-dropdown-loading d-none py-3 text-center text-muted" id="globalSearchLoading">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <span class="small">Searching live records...</span>
                            </div>
                            <div class="search-dropdown-body" id="globalSearchDropdownBody"></div>
                            <div class="search-dropdown-footer d-flex justify-content-between align-items-center px-3 py-2 border-top bg-body-tertiary">
                                <span class="small text-muted" id="globalSearchCountHint">Press Enter for all results</span>
                                <a href="#" class="small fw-semibold text-primary text-decoration-none" id="globalSearchFullLink">
                                    Full results &rarr;
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="topbar-right">
                <!-- Company Switcher -->
                <?php if (count($navSwitchCompanies) > 1): ?>
                    <form method="post" action="<?= e(url('public/switch_company.php')) ?>" class="company-switcher-form m-0">
                        <?= csrf_field() ?>
                        <div class="company-switcher-wrap">
                            <div class="company-switcher-mobile-btn d-none" aria-hidden="true" title="Switch Company">
                                <i class="bi bi-buildings"></i>
                            </div>
                            <select name="company_id" class="form-select company-switcher-select" aria-label="Active company" onchange="this.form.submit()">
                                <option value="0">All Companies</option>
                                <?php foreach ($navSwitchCompanies as $navComp): ?>
                                    <option value="<?= (int)$navComp['id'] ?>"<?= $navActiveCompany === (int)$navComp['id'] ? ' selected' : '' ?>>
                                        <?= e($navComp['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- Theme Toggle Button (36px) -->
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch to dark mode" title="Switch to dark mode" aria-pressed="false">
                    <i class="bi bi-moon-stars"></i>
                </button>

                <!-- Notification Bell Dropdown (36px control) -->
                <div class="dropdown notif-dropdown-wrapper">
                    <button type="button" class="topbar-icon-btn position-relative" id="topbarNotificationsBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications" title="Notifications">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $topbarUnreadNotifs > 0 ? '' : 'd-none' ?> notif-badge" id="topbarNotifBadge">
                            <?= $topbarUnreadNotifs > 99 ? '99+' : $topbarUnreadNotifs ?>
                            <span class="visually-hidden">unread notifications</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow border p-0 notif-dropdown-menu" id="topbarNotifMenu" aria-labelledby="topbarNotificationsBtn">
                        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom notif-dropdown-header">
                            <span class="fw-semibold small text-uppercase text-muted">Notifications</span>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-muted notif-mark-all-read-btn" id="topbarNotifMarkAllReadBtn">
                                Mark all as read
                            </button>
                        </div>
                        <div class="notif-dropdown-body" id="topbarNotifList">
                            <?php if (empty($topbarRecentNotifs)): ?>
                                <div class="p-4 text-center text-muted small notif-empty">
                                    <i class="bi bi-bell-slash fs-3 d-block mb-1 text-secondary opacity-50"></i>
                                    No notifications
                                </div>
                            <?php else: ?>
                                <?php foreach ($topbarRecentNotifs as $nItem): ?>
                                    <?php
                                        $nMeta = notification_type_meta((string)$nItem['type']);
                                        $nIsUnread = (int)$nItem['is_read'] === 0;
                                        $nLink = !empty($nItem['link_url']) ? url((string)$nItem['link_url']) : '';
                                    ?>
                                    <a class="notif-dropdown-item <?= $nIsUnread ? 'is-unread' : '' ?>" data-id="<?= (int)$nItem['id'] ?>" data-href="<?= e($nLink) ?>" href="<?= e($nLink ?: '#') ?>">
                                        <div class="notif-icon-circle" style="background-color: <?= e($nMeta['color']) ?>18; color: <?= e($nMeta['color']) ?>;">
                                            <i class="bi <?= e($nMeta['icon']) ?>"></i>
                                        </div>
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex align-items-center justify-content-between gap-1">
                                                <span class="notif-title text-truncate"><?= e((string)$nItem['title']) ?></span>
                                                <span class="notif-time flex-shrink-0"><?= e(notification_time_ago((string)$nItem['created_at'])) ?></span>
                                            </div>
                                            <div class="notif-message"><?= e((string)$nItem['message']) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="px-3 py-2 border-top text-center bg-body-tertiary notif-dropdown-footer">
                            <a href="<?= url('modules/notifications/index.php') ?>" class="small text-decoration-none fw-medium">
                                View all notifications <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <a class="user-avatar-btn" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User account menu">
                        <div class="avatar-initials"><?= e($navUserInitials) ?></div>
                        <span class="d-none d-md-inline user-name-label"><?= e($currentUserRow['full_name'] ?? '') ?></span>
                        <i class="bi bi-chevron-down small text-muted"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-semibold text-body"><?= e($currentUserRow['full_name'] ?? '') ?></div>
                            <div class="small text-muted text-truncate"><?= e($currentUserRow['email'] ?? '') ?></div>
                            <?php if (!empty($currentUserRow['is_group_admin'])): ?>
                                <span class="badge text-bg-secondary mt-1">Group Administrator</span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 d-flex align-items-center gap-2" href="<?= e(url('modules/auth/change_password.php')) ?>">
                                <i class="bi bi-key text-muted"></i> Change Password
                            </a>
                        </li>
                        <li>
                            <form method="post" action="<?= e(url('public/logout.php')) ?>" class="m-0 p-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item text-danger py-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-box-arrow-right"></i> Sign out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Flash Toast Container -->
        <?php $navFlashes = flash_get(); ?>
        <?php if (!empty($navFlashes)): ?>
            <div class="toast-flash-container">
                <?php foreach ($navFlashes as [$navFlashType, $navFlashMessage]): ?>
                    <?php
                    $navFlashIcon = match($navFlashType) {
                        'success' => 'bi-check-circle-fill text-success',
                        'danger', 'error' => 'bi-exclamation-octagon-fill text-danger',
                        'warning' => 'bi-exclamation-triangle-fill text-warning',
                        default   => 'bi-info-circle-fill text-info',
                    };
                    ?>
                    <?php $navRole = in_array($navFlashType, ['danger', 'error', 'warning'], true) ? 'alert' : 'status'; ?>
                    <div class="toast-flash alert alert-<?= e($navFlashType) ?> alert-dismissible fade show" role="<?= $navRole ?>" aria-live="<?= $navRole === 'alert' ? 'assertive' : 'polite' ?>">
                        <i class="bi <?= $navFlashIcon ?> fs-5 flex-shrink-0"></i>
                        <div class="flex-grow-1 small fw-medium"><?= e($navFlashMessage) ?></div>
                        <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Main Content Inner -->
        <main class="app-content">
