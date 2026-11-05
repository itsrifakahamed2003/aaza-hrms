<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('audit.view');

$pdo = db();
$isGroupPrivileged = is_group_admin() || user_can('dashboard.group');
$accessibleCompanies = accessible_company_ids('audit.view');

if (!$isGroupPrivileged && empty($accessibleCompanies)) {
    abort(403, 'You do not have permission to view audit logs in any company.');
}

// -------------------------------------------------------------
// Filter Sanitization & Validation
// -------------------------------------------------------------
$rawCompany = (string)($_GET['company_id'] ?? ($_POST['company_id'] ?? ''));
$companyFilter = null; // null = all accessible
if ($rawCompany === 'system' || $rawCompany === '-1') {
    $companyFilter = -1; // Company IS NULL
} elseif (is_numeric($rawCompany) && (int)$rawCompany > 0) {
    $companyFilter = (int)$rawCompany;
}

$userFilter = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
$actionFilter = trim((string)($_GET['action'] ?? ($_POST['action'] ?? '')));
$entityFilter = trim((string)($_GET['entity_type'] ?? ($_POST['entity_type'] ?? '')));
$qFilter = trim((string)($_GET['q'] ?? ($_POST['q'] ?? '')));

// Date Range (default: last 7 days, max 1 year span)
$today = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-7 days'));
$rawStart = (string)($_GET['start_date'] ?? ($_POST['start_date'] ?? ''));
$rawEnd   = (string)($_GET['end_date'] ?? ($_POST['end_date'] ?? ''));

$startDate = parse_ymd($rawStart) ?? $defaultStart;
$endDate   = parse_ymd($rawEnd) ?? $today;

// Enforce chronological order and 366-day span cap
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
$d1 = new DateTime($startDate);
$d2 = new DateTime($endDate);
if ($d1->diff($d2)->days > 366) {
    $d1 = (clone $d2)->modify('-365 days');
    $startDate = $d1->format('Y-m-d');
    flash_set('warning', 'Date range was automatically constrained to the maximum span of 1 year.');
}

// -------------------------------------------------------------
// Build Scoped SQL WHERE Clause
// -------------------------------------------------------------
$where = [];
$params = [];

// Company Scoping & Filtering
if ($isGroupPrivileged) {
    if ($companyFilter === -1) {
        $where[] = 'a.company_id IS NULL';
    } elseif ($companyFilter !== null && $companyFilter > 0) {
        $where[] = 'a.company_id = ?';
        $params[] = $companyFilter;
    }
} else {
    // Company admin: strictly limited to accessible companies (never NULL / never other companies)
    if ($companyFilter !== null && $companyFilter > 0 && in_array($companyFilter, $accessibleCompanies, true)) {
        $where[] = 'a.company_id = ?';
        $params[] = $companyFilter;
    } else {
        $ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
        $where[] = "a.company_id IN ($ph)";
        $params = array_merge($params, $accessibleCompanies);
    }
}

// User Filter
if ($userFilter > 0) {
    if ($isGroupPrivileged) {
        $where[] = 'a.user_id = ?';
        $params[] = $userFilter;
    } else {
        // Enforce user belongs to accessible companies
        $uCheck = $pdo->prepare('SELECT COUNT(*) FROM company_user_assignments WHERE user_id = ? AND company_id IN (' . implode(',', $accessibleCompanies) . ')');
        $uCheck->execute([$userFilter]);
        if ((int)$uCheck->fetchColumn() > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $userFilter;
        }
    }
}

// Action Filter (exact or prefix like "interview.")
if ($actionFilter !== '') {
    if (str_ends_with($actionFilter, '.') || str_ends_with($actionFilter, '*')) {
        $where[] = 'a.action LIKE ?';
        $params[] = rtrim($actionFilter, '*') . '%';
    } else {
        $where[] = '(a.action = ? OR a.action LIKE ?)';
        $params[] = $actionFilter;
        $params[] = $actionFilter . '.%';
    }
}

// Entity Type Filter
if ($entityFilter !== '') {
    $where[] = 'a.entity_type = ?';
    $params[] = $entityFilter;
}

// Free Text Search on Action Only
if ($qFilter !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = '%' . $qFilter . '%';
}

// Date Range Filter (inclusive)
$where[] = 'a.created_at >= ?';
$params[] = $startDate . ' 00:00:00';
$where[] = 'a.created_at <= ?';
$params[] = $endDate . ' 23:59:59';

$whereSql = implode(' AND ', $where);

// Filters logged for audit trail
$filtersLogged = [
    'company_id'  => $companyFilter,
    'user_id'     => $userFilter ?: null,
    'action'      => $actionFilter ?: null,
    'entity_type' => $entityFilter ?: null,
    'q'           => $qFilter ?: null,
    'start_date'  => $startDate,
    'end_date'    => $endDate,
];

// -------------------------------------------------------------
// Action: CSV Export (POST + CSRF)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    csrf_require();
    if (!user_can('audit.export')) {
        abort(403, 'You do not have permission to export audit logs to CSV.');
    }

    $exportSql = "SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.ip_address,
                         u.full_name AS user_full_name, u.username AS user_username,
                         c.name AS company_name, c.code AS company_code
                    FROM activity_logs a
               LEFT JOIN users u ON a.user_id = u.id
               LEFT JOIN companies c ON a.company_id = c.id
                   WHERE $whereSql
                ORDER BY a.id DESC
                   LIMIT 50000";

    $st = $pdo->prepare($exportSql);
    $st->execute($params);

    $filename = 'audit_log_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    $headers = [
        'Log ID',
        'Timestamp',
        'Action',
        'User Name',
        'Username',
        'Company',
        'Company Code',
        'Entity Type',
        'Entity ID',
        'IP Address',
    ];
    fputcsv($out, array_map('escape_csv_cell', $headers));

    $exportedCount = 0;
    while ($row = $st->fetch()) {
        $exportedCount++;
        fputcsv($out, [
            escape_csv_cell((string)$row['id']),
            escape_csv_cell((string)$row['created_at']),
            escape_csv_cell((string)$row['action']),
            escape_csv_cell((string)($row['user_full_name'] ?? 'System')),
            escape_csv_cell((string)($row['user_username'] ?? '—')),
            escape_csv_cell((string)($row['company_name'] ?? 'Global / System')),
            escape_csv_cell((string)($row['company_code'] ?? '—')),
            escape_csv_cell((string)($row['entity_type'] ?? '—')),
            escape_csv_cell((string)($row['entity_id'] ?? '—')),
            escape_csv_cell((string)($row['ip_address'] ?? '—')),
        ]);
    }
    fclose($out);

    // Audit log the export itself
    log_activity('audit.export', 'activity_log', null, [
        'count'   => $exportedCount,
        'filters' => $filtersLogged,
    ]);
    exit;
}

// -------------------------------------------------------------
// Action: Render Log Viewer (GET)
// -------------------------------------------------------------
// 1. Total Count for Pagination
$countSql = "SELECT COUNT(*) FROM activity_logs a WHERE $whereSql";
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRecords = (int)$countSt->fetchColumn();

// 2. Pagination Math
$page = max(1, (int)($_GET['page'] ?? 1));
$pg = paginate($totalRecords, $page, PER_PAGE);

// 3. Fetch Page Rows
$listSql = "SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.ip_address, a.company_id, a.user_id,
                   u.full_name AS user_full_name, u.username AS user_username,
                   c.name AS company_name, c.code AS company_code
              FROM activity_logs a
         LEFT JOIN users u ON a.user_id = u.id
         LEFT JOIN companies c ON a.company_id = c.id
             WHERE $whereSql
          ORDER BY a.id DESC
             LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];

$listSt = $pdo->prepare($listSql);
$listSt->execute($params);
$logs = $listSt->fetchAll();

// Log viewing the audit log (once per page load)
log_activity('audit.view', 'activity_log', null, ['filters' => $filtersLogged]);

// -------------------------------------------------------------
// Load Filter Dropdown Data (Strictly Scoped)
// -------------------------------------------------------------
// Companies dropdown
if ($isGroupPrivileged) {
    $companiesList = $pdo->query('SELECT id, name, code FROM companies ORDER BY name ASC')->fetchAll();
} else {
    $ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
    $cSt = $pdo->prepare("SELECT id, name, code FROM companies WHERE id IN ($ph) ORDER BY name ASC");
    $cSt->execute($accessibleCompanies);
    $companiesList = $cSt->fetchAll();
}

// Users dropdown
if ($isGroupPrivileged) {
    $usersList = $pdo->query('SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
} else {
    $ph = implode(',', array_fill(0, count($accessibleCompanies), '?'));
    $uSt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.full_name, u.username
           FROM users u
           JOIN company_user_assignments cua ON cua.user_id = u.id
          WHERE cua.company_id IN ($ph)
            AND u.is_active = 1
       ORDER BY u.full_name ASC"
    );
    $uSt->execute($accessibleCompanies);
    $usersList = $uSt->fetchAll();
}

// Distinct Entity Types in current scope
$entSql = "SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL AND entity_type != '' ORDER BY entity_type ASC";
$entitiesList = $pdo->query($entSql)->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Activity Audit Log';
require __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Audit Logs</li>
            </ol>
        </nav>
        <h2 class="h4 mb-0 fw-bold text-body font-sans">
            <i class="bi bi-shield-check text-primary me-2"></i>Activity Audit Trail
        </h2>
        <p class="text-muted small mb-0 font-sans">Immutable, append-only security and operational audit trail across company operations.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (user_can('audit.export')): ?>
            <form method="post" action="<?= e(url('modules/audit/index.php')) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="export_csv" value="1">
                <input type="hidden" name="company_id" value="<?= e((string)$rawCompany) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$userFilter ?>">
                <input type="hidden" name="action" value="<?= e($actionFilter) ?>">
                <input type="hidden" name="entity_type" value="<?= e($entityFilter) ?>">
                <input type="hidden" name="q" value="<?= e($qFilter) ?>">
                <input type="hidden" name="start_date" value="<?= e($startDate) ?>">
                <input type="hidden" name="end_date" value="<?= e($endDate) ?>">
                <button type="submit" class="btn btn-outline-success font-sans">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-body border-bottom py-3">
        <h5 class="card-title mb-0 fw-semibold text-body font-sans d-flex align-items-center">
            <i class="bi bi-funnel text-primary me-2"></i>Filter Audit Events
        </h5>
    </div>
    <div class="card-body p-3">
        <form method="get" action="<?= e(url('modules/audit/index.php')) ?>" class="row g-3 font-sans">
            <!-- Company -->
            <div class="col-md-3">
                <label for="company_id" class="form-label small fw-bold text-muted">Company Scope</label>
                <select name="company_id" id="company_id" class="form-select form-select-sm">
                    <?php if ($isGroupPrivileged): ?>
                        <option value="">All Companies (Group View)</option>
                        <option value="system"<?= $rawCompany === 'system' || $rawCompany === '-1' ? ' selected' : '' ?>>Global / System Only (No Company)</option>
                    <?php else: ?>
                        <option value="">All Accessible Companies</option>
                    <?php endif; ?>
                    <?php foreach ($companiesList as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"<?= (string)$companyFilter === (string)$c['id'] ? ' selected' : '' ?>>
                            <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- User -->
            <div class="col-md-3">
                <label for="user_id" class="form-label small fw-bold text-muted">Actor / User</label>
                <select name="user_id" id="user_id" class="form-select form-select-sm">
                    <option value="0">All Users</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"<?= $userFilter === (int)$u['id'] ? ' selected' : '' ?>>
                            <?= e($u['full_name']) ?> (@<?= e($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Action Code / Prefix -->
            <div class="col-md-2">
                <label for="action" class="form-label small fw-bold text-muted">Action Code / Prefix</label>
                <input type="text" name="action" id="action" class="form-control form-control-sm font-monospace"
                       placeholder="e.g. interview." value="<?= e($actionFilter) ?>">
            </div>

            <!-- Entity Type -->
            <div class="col-md-2">
                <label for="entity_type" class="form-label small fw-bold text-muted">Entity Type</label>
                <select name="entity_type" id="entity_type" class="form-select form-select-sm font-monospace">
                    <option value="">All Entities</option>
                    <?php foreach ($entitiesList as $ent): ?>
                        <option value="<?= e($ent) ?>"<?= $entityFilter === $ent ? ' selected' : '' ?>><?= e($ent) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Free Text on Action -->
            <div class="col-md-2">
                <label for="q" class="form-label small fw-bold text-muted">Action Free Search</label>
                <input type="text" name="q" id="q" class="form-control form-control-sm"
                       placeholder="Search action text..." value="<?= e($qFilter) ?>">
            </div>

            <!-- Date From -->
            <div class="col-md-3">
                <label for="start_date" class="form-label small fw-bold text-muted">From Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control form-control-sm"
                       value="<?= e($startDate) ?>" required>
            </div>

            <!-- Date To -->
            <div class="col-md-3">
                <label for="end_date" class="form-label small fw-bold text-muted">To Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control form-control-sm"
                       value="<?= e($endDate) ?>" required>
            </div>

            <!-- Submit / Reset Buttons -->
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-search me-1"></i>Apply Filters
                </button>
                <a href="<?= e(url('modules/audit/index.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i>Reset
                </a>
                <span class="text-muted small ms-auto align-self-center">
                    Showing <?= number_format($totalRecords) ?> total event<?= $totalRecords === 1 ? '' : 's' ?>
                </span>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 font-sans">
                <thead class="table-active border-bottom text-uppercase small text-muted">
                    <tr>
                        <th class="ps-3" style="width: 70px;">ID</th>
                        <th style="width: 170px;">Timestamp</th>
                        <th>User</th>
                        <th>Company</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>IP Address</th>
                        <th class="text-end pe-3" style="width: 90px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No audit events found matching the specified filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $row): ?>
                            <tr>
                                <td class="ps-3 font-monospace text-muted small">#<?= (int)$row['id'] ?></td>
                                <td class="font-monospace small">
                                    <div class="text-body fw-medium"><?= e(format_date($row['created_at'], 'd M Y, H:i')) ?></div>
                                    <div class="text-muted small"><?= e(format_date($row['created_at'], 's \s\e\c')) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($row['user_id'])): ?>
                                        <div class="fw-semibold text-body"><?= e($row['user_full_name'] ?? 'User #' . $row['user_id']) ?></div>
                                        <div class="text-muted small font-monospace">@<?= e($row['user_username'] ?? '—') ?></div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">System / Anonymous</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['company_id'])): ?>
                                        <div class="fw-medium text-body"><?= e($row['company_name'] ?? 'Company #' . $row['company_id']) ?></div>
                                        <span class="badge text-bg-secondary font-monospace"><?= e($row['company_code'] ?? '') ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-body-secondary text-body border text-secondary">Global / System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= e(url('modules/audit/view.php?id=' . (int)$row['id'])) ?>" class="text-decoration-none">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1">
                                            <?= e($row['action']) ?>
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <?php if (!empty($row['entity_type'])): ?>
                                        <span class="badge bg-body-secondary text-body border font-monospace text-body"><?= e($row['entity_type']) ?></span>
                                        <?php if (!empty($row['entity_id'])): ?>
                                            <span class="text-muted font-monospace small">#<?= (int)$row['entity_id'] ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace small text-muted">
                                    <?= e($row['ip_address'] ?? '—') ?>
                                </td>
                                <td class="text-end pe-3">
                                    <a href="<?= e(url('modules/audit/view.php?id=' . (int)$row['id'])) ?>" class="btn btn-sm btn-outline-primary" title="View details payload">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer bg-body border-top py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted font-sans">
                Page <?= (int)$pg['page'] ?> of <?= (int)$pg['pages'] ?> (<?= number_format($totalRecords) ?> total records)
            </div>
            <div>
                <?= render_pagination($pg) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
