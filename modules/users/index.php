<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('user.view');

$pdo = db();
$selfId = (int)current_user()['id'];
$isGroupAdmin = is_group_admin();
$scopedCompanyIds = scoped_company_ids('user.view');

if (!$isGroupAdmin && empty($scopedCompanyIds)) {
    abort(403, 'You do not have access to manage users in any company.');
}

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$filterCompany = (int)($_GET['company_id'] ?? 0);
$filterRole    = (int)($_GET['role_id'] ?? 0);
$filterStatus  = trim((string)($_GET['status'] ?? ''));

// Validate company filter against scoped companies
if ($filterCompany > 0 && !$isGroupAdmin && !in_array($filterCompany, $scopedCompanyIds, true)) {
    $filterCompany = 0;
}

// Available companies for filter dropdown
if ($isGroupAdmin) {
    $filterCompanies = $pdo->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll();
} else {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
    $st->execute($scopedCompanyIds);
    $filterCompanies = $st->fetchAll();
}

// Available roles for filter dropdown
$filterRoles = $pdo->query('SELECT id, name, label FROM roles ORDER BY is_system DESC, label ASC')->fetchAll();

// Build query conditions
$whereClauses = [];
$params = [];

// Company scoping
if (!$isGroupAdmin) {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $whereClauses[] = "u.id IN (SELECT cua.user_id FROM company_user_assignments cua WHERE cua.company_id IN ($ph))";
    foreach ($scopedCompanyIds as $cid) {
        $params[] = $cid;
    }
}

// Search box
if ($q !== '') {
    $whereClauses[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Filter: Company
if ($filterCompany > 0) {
    $whereClauses[] = 'u.id IN (SELECT cua.user_id FROM company_user_assignments cua WHERE cua.company_id = ?)';
    $params[] = $filterCompany;
}

// Filter: Role
if ($filterRole > 0) {
    // Check if selected role is group_admin
    $isGroupAdminRole = false;
    foreach ($filterRoles as $r) {
        if ((int)$r['id'] === $filterRole && $r['name'] === 'group_admin') {
            $isGroupAdminRole = true;
            break;
        }
    }
    if ($isGroupAdminRole) {
        $whereClauses[] = '(u.is_group_admin = 1 OR u.id IN (SELECT cua.user_id FROM company_user_assignments cua WHERE cua.role_id = ?))';
        $params[] = $filterRole;
    } else {
        $whereClauses[] = 'u.id IN (SELECT cua.user_id FROM company_user_assignments cua WHERE cua.role_id = ?)';
        $params[] = $filterRole;
    }
}

// Filter: Status
if ($filterStatus === 'active') {
    $whereClauses[] = 'u.is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $whereClauses[] = 'u.is_active = 0';
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Count total for pagination
$stCount = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$pg = paginate($totalRows, (int)($_GET['page'] ?? 1), 20);

// Fetch paginated users
$st = $pdo->prepare(
    "SELECT u.id, u.username, u.email, u.full_name, u.is_group_admin, u.is_active, u.last_login_at
       FROM users u
       $whereSql
      ORDER BY u.full_name ASC
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$users = $st->fetchAll();

// Company + role assignments for users on this page
$assignments = [];
if ($users) {
    $userIds = array_map('intval', array_column($users, 'id'));
    $phUser = implode(',', array_fill(0, count($userIds), '?'));
    $stAssign = $pdo->prepare(
        "SELECT cua.user_id, c.id AS company_id, c.code AS company_code, c.name AS company_name, r.id AS role_id, r.label AS role_label
           FROM company_user_assignments cua
           JOIN companies c ON c.id = cua.company_id
           JOIN roles r ON r.id = cua.role_id
          WHERE cua.user_id IN ($phUser)
          ORDER BY c.code ASC, r.label ASC"
    );
    $stAssign->execute($userIds);
    foreach ($stAssign->fetchAll() as $row) {
        $uid = (int)$row['user_id'];
        $assignments[$uid][] = [
            'company_id'   => (int)$row['company_id'],
            'company_code' => (string)$row['company_code'],
            'company_name' => (string)$row['company_name'],
            'role_label'   => (string)$row['role_label'],
        ];
    }
}

$canCreateUser = user_can('user.create') || user_can('user.manage');
$hasActiveFilters = ($q !== '' || $filterCompany > 0 || $filterRole > 0 || $filterStatus !== '');

$pageTitle = 'Users';
require __DIR__ . '/../../includes/header.php';
?>
<!-- Page Header -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Users</li>
            </ol>
        </nav>
        <h1 class="page-header-title">System User Accounts</h1>
        <p class="page-header-desc">Manage system users, company role assignments, and security permissions.</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canCreateUser): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('modules/users/form.php')) ?>">
                <i class="bi bi-person-plus me-1"></i> New User
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-center">
            <!-- Search Text -->
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-body-tertiary text-muted border-end-0">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="search" class="form-control border-start-0" name="q"
                           placeholder="Search by name, username, or email..."
                           value="<?= e($q) ?>">
                </div>
            </div>

            <!-- Filter: Company -->
            <div class="col-6 col-md-3">
                <select class="form-select form-select-sm" name="company_id" aria-label="Filter by Company">
                    <option value="">All Companies</option>
                    <?php foreach ($filterCompanies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filterCompany === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter: Role -->
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" name="role_id" aria-label="Filter by Role">
                    <option value="">All Roles</option>
                    <?php foreach ($filterRoles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $filterRole === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= e($r['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter: Status -->
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" name="status" aria-label="Filter by Status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>

            <!-- Submit & Reset Actions -->
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <?php if ($hasActiveFilters): ?>
                    <a href="<?= e(url('modules/users/index.php')) ?>" class="btn btn-outline-secondary btn-sm" title="Clear all filters">
                        Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Users List: Desktop Table & Mobile Cards -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0 fs-6 fw-bold">User Directory</h2>
        <span class="badge bg-body-secondary text-body border text-secondary"><?= (int)$totalRows ?> <?= $totalRows === 1 ? 'user' : 'users' ?></span>
    </div>

    <!-- Desktop Table View -->
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover table-sticky-header align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-4">User</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Company &amp; Role Assignments</th>
                    <th>Last Sign-In</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                $uid = (int)$u['id'];
                $uParts = explode(' ', trim((string)$u['full_name']));
                $uInitials = strtoupper(mb_substr($uParts[0] ?? '', 0, 1) . (count($uParts) > 1 ? mb_substr(end($uParts), 0, 1) : ''));
                $userAssignments = $assignments[$uid] ?? [];
                $viewUrl = url('modules/users/view.php?id=' . $uid);
                ?>
                <tr class="clickable-row" data-href="<?= e($viewUrl) ?>" tabindex="0" role="link" aria-label="View user <?= e($u['full_name']) ?>">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-initials-md flex-shrink-0"><?= e($uInitials) ?></div>
                            <div>
                                <a href="<?= e($viewUrl) ?>" class="fw-semibold text-body text-decoration-none d-block">
                                    <?= e($u['full_name']) ?>
                                    <?php if ($uid === $selfId): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" style="font-size: 0.7rem;">You</span>
                                    <?php endif; ?>
                                </a>
                                <div class="small text-muted"><?= e($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-body-secondary text-body border font-monospace"><?= e($u['username']) ?></span>
                    </td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger"><i class="bi bi-dash-circle me-1"></i>Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <?php if ($u['is_group_admin']): ?>
                                <span class="badge text-bg-dark" title="Full superuser access across all companies">
                                    <i class="bi bi-shield-shaded me-1"></i>Group Admin
                                </span>
                            <?php endif; ?>

                            <?php foreach ($userAssignments as $asgn): ?>
                                <span class="company-role-chip" title="<?= e($asgn['company_name']) ?>">
                                    <strong><?= e($asgn['company_code']) ?></strong> &ndash; <?= e($asgn['role_label']) ?>
                                </span>
                            <?php endforeach; ?>

                            <?php if (!$u['is_group_admin'] && empty($userAssignments)): ?>
                                <span class="text-muted small fst-italic">No company assigned</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="small text-muted">
                        <?= $u['last_login_at'] ? e(date('M j, Y H:i', strtotime((string)$u['last_login_at']))) : '<span class="text-muted">Never</span>' ?>
                    </td>
                    <td class="text-end pe-4 text-nowrap" onclick="event.stopPropagation();">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e($viewUrl) ?>">
                            <i class="bi bi-person-lines-fill me-1"></i> View Profile
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="6" class="p-0">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <h3 class="empty-state-title">No users found</h3>
                            <p class="empty-state-desc">No system accounts match your search filters.</p>
                            <?php if ($hasActiveFilters): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/users/index.php')) ?>">
                                    Clear Filters
                                </a>
                            <?php elseif ($canCreateUser): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e(url('modules/users/form.php')) ?>">
                                    <i class="bi bi-person-plus me-1"></i> Add New User
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Stacked Card View (< 768px) -->
    <div class="d-md-none divide-y">
        <?php foreach ($users as $u): ?>
            <?php
            $uid = (int)$u['id'];
            $uParts = explode(' ', trim((string)$u['full_name']));
            $uInitials = strtoupper(mb_substr($uParts[0] ?? '', 0, 1) . (count($uParts) > 1 ? mb_substr(end($uParts), 0, 1) : ''));
            $userAssignments = $assignments[$uid] ?? [];
            $viewUrl = url('modules/users/view.php?id=' . $uid);
            ?>
            <a href="<?= e($viewUrl) ?>" class="d-block p-3 text-decoration-none text-body border-bottom">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar-initials-md"><?= e($uInitials) ?></div>
                        <div>
                            <div class="fw-semibold text-body">
                                <?= e($u['full_name']) ?>
                                <?php if ($uid === $selfId): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 0.65rem;">You</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted"><?= e($u['email']) ?></div>
                        </div>
                    </div>
                    <div>
                        <?php if ($u['is_active']): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-1 align-items-center mb-2">
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($u['username']) ?></span>
                    <?php if ($u['is_group_admin']): ?>
                        <span class="badge text-bg-dark">Group Admin</span>
                    <?php endif; ?>
                    <?php foreach ($userAssignments as $asgn): ?>
                        <span class="company-role-chip">
                            <strong><?= e($asgn['company_code']) ?></strong> &ndash; <?= e($asgn['role_label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center small text-muted">
                    <span>Last sign-in: <?= $u['last_login_at'] ? e(date('M j, Y', strtotime((string)$u['last_login_at']))) : 'Never' ?></span>
                    <span class="text-primary fw-medium">View &rarr;</span>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if (!$users): ?>
            <div class="empty-state p-4 text-center">
                <div class="empty-state-icon mb-2">
                    <i class="bi bi-people fs-2 text-muted"></i>
                </div>
                <h3 class="empty-state-title fs-6 fw-bold">No users found</h3>
                <p class="empty-state-desc small text-muted">Try adjusting your filters.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination Footer -->
    <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer bg-body d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <span class="small text-muted">Showing <?= $pg['offset'] + 1 ?> to <?= min($pg['offset'] + $pg['per_page'], (int)$pg['total']) ?> of <?= (int)$pg['total'] ?> users</span>
            <?= render_pagination($pg) ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('tr.clickable-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('a, button, form, input, select')) return;
            var href = row.getAttribute('data-href');
            if (href) {
                window.location.href = href;
            }
        });
        row.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                if (e.target.closest('a, button, form, input, select')) return;
                var href = row.getAttribute('data-href');
                if (href) {
                    e.preventDefault();
                    window.location.href = href;
                }
            }
        });
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
