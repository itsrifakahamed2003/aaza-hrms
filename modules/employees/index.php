<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee.view');

$pdo = db();

// Always filter with scoped_company_ids('employee.view') + company_in_sql()
$scopedCompanyIds = scoped_company_ids('employee.view');
[$scopedSql, $scopedParams] = company_in_sql($scopedCompanyIds, 'e.company_id');

$whereClauses = [$scopedSql];
$params = $scopedParams;

// Search: name, code, email, phone
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $whereClauses[] = '(e.employee_code LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, " ", e.last_name) LIKE ? OR e.email LIKE ? OR e.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}

// Filter: company
$filterCompany = (int)($_GET['company_id'] ?? 0);
if ($filterCompany > 0) {
    if (in_array($filterCompany, $scopedCompanyIds, true)) {
        $whereClauses[] = 'e.company_id = ?';
        $params[] = $filterCompany;
    } else {
        // Requested company is not permitted for this user
        $whereClauses[] = '1 = 0';
    }
}

// Filter: department
$filterDept = (int)($_GET['department_id'] ?? 0);
if ($filterDept > 0) {
    $whereClauses[] = 'e.department_id = ?';
    $params[] = $filterDept;
}

// Filter: status
$filterStatus = trim((string)($_GET['status'] ?? ''));
$statusOptions = employee_status_list();
if ($filterStatus !== '' && isset($statusOptions[$filterStatus])) {
    $whereClauses[] = 'e.employment_status = ?';
    $params[] = $filterStatus;
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Pagination count
$countSt = $pdo->prepare("SELECT COUNT(*) FROM employees e $whereSql");
$countSt->execute($params);
$totalCount = (int)$countSt->fetchColumn();

$pg = paginate($totalCount, (int)($_GET['page'] ?? 1));

// Data query
$sql = "SELECT e.id, e.employee_code, e.company_id, e.first_name, e.last_name, e.email, e.phone,
               e.joining_date, e.employment_type, e.employment_status, e.basic_salary,
               c.code AS company_code, c.name AS company_name,
               d.name AS department_name, des.title AS designation_title
          FROM employees e
          JOIN companies c ON c.id = e.company_id
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN designations des ON des.id = e.designation_id
         $whereSql
         ORDER BY e.created_at DESC, e.id DESC
         LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";

$st = $pdo->prepare($sql);
$st->execute($params);
$employees = $st->fetchAll();

// Load accessible companies for filter dropdown
$filterCompanies = [];
if ($scopedCompanyIds) {
    $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) ORDER BY name");
    $st->execute($scopedCompanyIds);
    $filterCompanies = $st->fetchAll();
}

// Load departments for filter dropdown (if company selected, only that company's depts; otherwise for all scoped companies)
$deptQueryIds = ($filterCompany > 0 && in_array($filterCompany, $scopedCompanyIds, true))
    ? [$filterCompany]
    : $scopedCompanyIds;

$filterDepartments = [];
if ($deptQueryIds) {
    $ph = implode(',', array_fill(0, count($deptQueryIds), '?'));
    $st = $pdo->prepare("SELECT id, company_id, name FROM departments WHERE company_id IN ($ph) ORDER BY name");
    $st->execute($deptQueryIds);
    $filterDepartments = $st->fetchAll();
}

$types = employment_type_list();
$pageTitle = 'Employees';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Employees</h1>
        <p class="text-muted mb-0 small">
            Manage employee directories, records, transfers, and documents across assigned companies.
        </p>
    </div>
    <?php if (user_can('employee.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('modules/employees/form.php')) ?>">
            <span class="me-1">&plus;</span> Add employee
        </a>
    <?php endif; ?>
</div>

<!-- Search & Filters Card -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted mb-1" for="searchQ">Search</label>
                <input type="search" class="form-control form-control-sm" id="searchQ" name="q"
                       placeholder="Name, employee code, email, phone" value="<?= e($q) ?>">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1" for="filterCompany">Company</label>
                <select class="form-select form-select-sm" id="filterCompany" name="company_id" onchange="this.form.submit()">
                    <option value="0">All companies</option>
                    <?php foreach ($filterCompanies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"<?= $filterCompany === (int)$c['id'] ? ' selected' : '' ?>>
                            <?= e($c['code']) ?> - <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1" for="filterDept">Department</label>
                <select class="form-select form-select-sm" id="filterDept" name="department_id">
                    <option value="0">All departments</option>
                    <?php foreach ($filterDepartments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"<?= $filterDept === (int)$d['id'] ? ' selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1" for="filterStatus">Status</label>
                <select class="form-select form-select-sm" id="filterStatus" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statusOptions as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= $filterStatus === $k ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-primary w-100" type="submit">Filter</button>
                <?php if ($q !== '' || $filterCompany > 0 || $filterDept > 0 || $filterStatus !== ''): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/employees/index.php')) ?>" title="Reset filters">&times;</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Employees Table Card -->
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-active">
            <tr>
                <th>Code</th>
                <th>Employee</th>
                <th>Company</th>
                <th>Department &amp; Designation</th>
                <th>Joined</th>
                <th>Status</th>
                <th>Basic Salary</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $emp): ?>
                <?php
                $cid = (int)$emp['company_id'];
                $canEdit = user_can('employee.edit', $cid);
                $canTransfer = user_can('employee.transfer', $cid);
                $canViewSalary = user_can('salary.view', $cid);
                ?>
                <tr>
                    <td>
                        <a class="fw-bold font-monospace text-decoration-none" href="<?= e(url('modules/employees/view.php?id=' . (int)$emp['id'])) ?>">
                            <?= e($emp['employee_code']) ?>
                        </a>
                    </td>
                    <td>
                        <div class="fw-semibold">
                            <a class="text-body text-decoration-none" href="<?= e(url('modules/employees/view.php?id=' . (int)$emp['id'])) ?>">
                                <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
                            </a>
                        </div>
                        <div class="small text-muted">
                            <?php if (!empty($emp['email'])): ?>
                                <span><?= e($emp['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($emp['phone'])): ?>
                                <span class="ms-1">&bull; <?= e($emp['phone']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-body-secondary text-body border"><?= e($emp['company_code']) ?></span>
                        <span class="small d-block text-muted"><?= e($emp['company_name']) ?></span>
                    </td>
                    <td>
                        <div class="fw-medium"><?= e($emp['department_name'] ?? '—') ?></div>
                        <div class="small text-muted"><?= e($emp['designation_title'] ?? '—') ?></div>
                    </td>
                    <td>
                        <div><?= e(date('M d, Y', strtotime((string)$emp['joining_date']))) ?></div>
                        <div class="small text-muted"><?= e($types[$emp['employment_type']] ?? $emp['employment_type']) ?></div>
                    </td>
                    <td>
                        <?= employee_status_badge((string)$emp['employment_status']) ?>
                    </td>
                    <td>
                        <?php if ($canViewSalary): ?>
                            <?php if ($emp['basic_salary'] !== null): ?>
                                <span class="font-monospace fw-semibold">LKR <?= e(number_format((float)$emp['basic_salary'], 2)) ?></span>
                            <?php else: ?>
                                <span class="text-muted small">Not set</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge text-bg-secondary text-opacity-75" title="Requires salary.view permission">Restricted</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/employees/view.php?id=' . (int)$emp['id'])) ?>" title="View profile">
                            View
                        </a>
                        <?php if ($canEdit): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('modules/employees/form.php?id=' . (int)$emp['id'])) ?>" title="Edit details">
                                Edit
                            </a>
                        <?php endif; ?>
                        <?php if ($canTransfer && $emp['employment_status'] === 'active'): ?>
                            <a class="btn btn-sm btn-outline-info" href="<?= e(url('modules/employees/transfer.php?id=' . (int)$emp['id'])) ?>" title="Transfer company">
                                Transfer
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <div class="mb-2 fs-5">No employees found.</div>
                        <div class="small">
                            <?php if ($q !== '' || $filterCompany > 0 || $filterDept > 0 || $filterStatus !== ''): ?>
                                Try clearing search filters to see more results.
                            <?php else: ?>
                                Click "Add employee" above to register your first employee.
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pg['pages'] > 1): ?>
        <div class="card-footer bg-body d-flex justify-content-between align-items-center py-2">
            <span class="small text-muted">
                Showing <?= min($totalCount, $pg['offset'] + 1) ?> to <?= min($totalCount, $pg['offset'] + $pg['per_page']) ?> of <?= $totalCount ?> employees
            </span>
            <?= render_pagination($pg) ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
