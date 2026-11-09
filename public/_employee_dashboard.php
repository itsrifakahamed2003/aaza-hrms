<?php
declare(strict_types=1);

/**
 * Employee Self-Service (ESS) Dashboard
 * Rendered by public/index.php for standard employees and users without management-level analytics permissions.
 */

// Fetch user's company assignment if available
$userCompStmt = $pdo->prepare(
    "SELECT c.id, c.name, c.code, r.label as role_label
       FROM company_user_assignments cua
       JOIN companies c ON c.id = cua.company_id AND c.is_active = 1
       JOIN roles r ON r.id = cua.role_id
      WHERE cua.user_id = ?
      LIMIT 1"
);
$userCompStmt->execute([$dashUser['id']]);
$userComp = $userCompStmt->fetch();

$compName = $userComp['name'] ?? 'AaZa Group';
$roleLabel = $userComp['role_label'] ?? 'Employee';
$companyId = (int)($userComp['id'] ?? 0);

// If linked to an employee record, fetch details
$empDetails = null;
$leaveBalances = [];
$recentLeaves = [];
$upcomingHolidays = [];

if ($linkedEmp) {
    $empId = (int)$linkedEmp['id'];
    $companyId = (int)$linkedEmp['company_id'];

    // Detailed employee record with department and designation
    $stEmp = $pdo->prepare(
        "SELECT e.*, d.name as dept_name, des.title as desig_name,
                c.name as comp_name,
                CONCAT(m.first_name, ' ', m.last_name) as manager_name
           FROM employees e
           JOIN companies c ON c.id = e.company_id
           LEFT JOIN departments d ON d.id = e.department_id
           LEFT JOIN designations des ON des.id = e.designation_id
           LEFT JOIN employees m ON m.id = e.reporting_manager_id
          WHERE e.id = ? LIMIT 1"
    );
    $stEmp->execute([$empId]);
    $empDetails = $stEmp->fetch();

    // Fetch leave balances for current calendar year
    $currentYear = (int)date('Y');
    $stLt = $pdo->prepare(
        "SELECT id, name, days_per_year
           FROM leave_types
          WHERE is_active = 1
            AND (company_id IS NULL OR company_id = ?)
          ORDER BY name ASC"
    );
    $stLt->execute([$companyId]);
    $leaveTypes = $stLt->fetchAll();

    foreach ($leaveTypes as $lt) {
        $bal = leave_balance($pdo, $empId, (int)$lt['id'], $currentYear);
        $leaveBalances[] = [
            'type_id'   => (int)$lt['id'],
            'name'      => $lt['name'],
            'allowance' => $bal['allowance'],
            'is_unlim'  => $bal['is_unlimited'],
            'taken'     => $bal['taken'],
            'pending'   => $bal['pending'],
            'remaining' => $bal['remaining'],
        ];
    }

    // Recent leave requests (last 5)
    $stRecent = $pdo->prepare(
        "SELECT lr.*, lt.name as leave_type_name
           FROM leave_requests lr
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.employee_id = ?
          ORDER BY lr.requested_at DESC
          LIMIT 5"
    );
    $stRecent->execute([$empId]);
    $recentLeaves = $stRecent->fetchAll();
}

// Fetch upcoming public holidays (next 45 days)
$todayStr = date('Y-m-d');
$futureStr = date('Y-m-d', strtotime('+45 days'));
$rawHolidays = get_holidays_in_range($todayStr, $futureStr, $companyId > 0 ? $companyId : null, $pdo);
foreach ($rawHolidays as $hDate => $hName) {
    $upcomingHolidays[] = [
        'date' => $hDate,
        'name' => $hName,
        'day'  => date('l', strtotime($hDate)),
        'formatted' => date('M j, Y', strtotime($hDate)),
    ];
}
?>

<div class="container-fluid px-3 px-md-4 py-4">
    <!-- Welcome Header -->
    <div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div class="page-header-title-block">
            <h1 class="page-header-title h3 fw-bold mb-1 text-body">
                Welcome, <?= e($dashUser['full_name']) ?>
            </h1>
            <div class="text-muted small d-flex align-items-center gap-2 flex-wrap">
                <span><i class="bi bi-building me-1"></i><?= e($compName) ?></span>
                <span>&bull;</span>
                <span><i class="bi bi-person-badge me-1"></i><?= e($roleLabel) ?></span>
                <span>&bull;</span>
                <span><i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?></span>
            </div>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <?php if ($linkedEmp): ?>
                <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Apply for Leave
                </a>
                <a href="<?= e(url('modules/leave/my_leaves.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar2-range me-1"></i> My Leave Requests
                </a>
            <?php endif; ?>
            <a href="<?= e(url('modules/auth/change_password.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-key me-1"></i> Change Password
            </a>
        </div>
    </div>

    <?php if (!$linkedEmp): ?>
        <!-- State: Account Not Linked to Employee Dossier -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="avatar-lg bg-primary-subtle text-primary rounded-circle mx-auto d-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px;">
                    <i class="bi bi-person-check fs-1"></i>
                </div>
                <h4 class="fw-bold mb-2 text-body">Welcome to the AaZa HRMS Portal</h4>
                <p class="text-muted mx-auto mb-4" style="max-width: 620px;">
                    Your user account (<strong>@<?= e($dashUser['username']) ?></strong>) is active with role 
                    <span class="badge bg-secondary"><?= e($roleLabel) ?></span>. 
                    Your account has not yet been linked to an employee profile. Once your HR Manager links your profile, 
                    your live leave balances, attendance logs, and performance appraisals will appear right here.
                </p>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="<?= e(url('modules/auth/change_password.php')) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-shield-lock me-1"></i> Update Security & Password
                    </a>
                    <a href="<?= e(url('public/login.php?action=logout')) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-box-arrow-right me-1"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- State: Linked Active Employee Dashboard -->
        
        <!-- Employee Profile Overview Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-auto">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold fs-4" style="width: 58px; height: 58px;">
                            <?= strtoupper(substr($dashUser['full_name'], 0, 1)) ?>
                        </div>
                    </div>
                    <div class="col">
                        <h4 class="mb-1 fw-bold text-body"><?= e($empDetails['first_name'] . ' ' . $empDetails['last_name']) ?></h4>
                        <div class="d-flex flex-wrap gap-2 text-muted small">
                            <span><strong>Code:</strong> <?= e($empDetails['employee_code']) ?></span>
                            <span>&bull;</span>
                            <span><strong>Dept:</strong> <?= e($empDetails['dept_name'] ?? 'General') ?></span>
                            <span>&bull;</span>
                            <span><strong>Designation:</strong> <?= e($empDetails['desig_name'] ?? 'Staff') ?></span>
                            <?php if (!empty($empDetails['manager_name'])): ?>
                                <span>&bull;</span>
                                <span><strong>Manager:</strong> <?= e($empDetails['manager_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <span class="badge text-bg-<?= $empDetails['employment_status'] === 'active' ? 'success' : 'secondary' ?> fs-6 px-3 py-2 text-uppercase">
                            <?= e($empDetails['employment_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leave Balances Cards -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0 text-body">
                <i class="bi bi-clock-history me-1 text-primary"></i> Leave Quotas & Balances (<?= date('Y') ?>)
            </h5>
            <a href="<?= e(url('modules/leave/request.php')) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Apply for Leave
            </a>
        </div>

        <div class="row g-3 mb-4">
            <?php if (empty($leaveBalances)): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm text-center py-4 text-muted">
                        No active leave types configured for your company.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($leaveBalances as $bal): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted small text-uppercase fw-semibold mb-1"><?= e($bal['name']) ?></div>
                                <div class="d-flex align-items-baseline justify-content-between mb-2">
                                    <span class="h3 fw-bold mb-0 text-body">
                                        <?= $bal['is_unlim'] ? '&infin;' : number_format((float)$bal['remaining'], 1) ?>
                                    </span>
                                    <span class="small text-muted">
                                        <?= $bal['is_unlim'] ? 'Days Available' : 'days left' ?>
                                    </span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <?php
                                    $pct = 0;
                                    if (!$bal['is_unlim'] && $bal['allowance'] > 0) {
                                        $pct = min(100, round(($bal['taken'] / $bal['allowance']) * 100));
                                    }
                                    ?>
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="d-flex justify-content-between text-muted small mt-2">
                                    <span>Taken: <?= number_format((float)$bal['taken'], 1) ?></span>
                                    <?php if ((float)$bal['pending'] > 0): ?>
                                        <span class="text-warning">Pending: <?= number_format((float)$bal['pending'], 1) ?></span>
                                    <?php endif; ?>
                                    <span>Total: <?= $bal['is_unlim'] ? '&infin;' : number_format((float)$bal['allowance'], 1) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="row g-4">
            <!-- Recent Leave Requests Column -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-0 pt-3 pb-2 d-flex align-items-center justify-content-between">
                        <h6 class="fw-bold mb-0 text-body">
                            <i class="bi bi-inbox me-1 text-primary"></i> Recent Leave Applications
                        </h6>
                        <a href="<?= e(url('modules/leave/my_leaves.php')) ?>" class="small text-decoration-none">
                            View All &rarr;
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentLeaves)): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-calendar-x fs-2 d-block mb-2 text-secondary"></i>
                                You have not submitted any leave requests yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-active small">
                                        <tr>
                                            <th>Type</th>
                                            <th>Dates</th>
                                            <th>Days</th>
                                            <th>Status</th>
                                            <th>Applied On</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentLeaves as $rl): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold text-body"><?= e($rl['leave_type_name']) ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-body"><?= date('M j, Y', strtotime($rl['start_date'])) ?> - <?= date('M j, Y', strtotime($rl['end_date'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-body-secondary text-body border"><?= (float)$rl['total_days'] ?>d</span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stBadge = match($rl['status']) {
                                                        'approved' => 'success',
                                                        'pending'  => 'warning',
                                                        'rejected' => 'danger',
                                                        'cancelled'=> 'secondary',
                                                        default    => 'secondary'
                                                    };
                                                    ?>
                                                    <span class="badge bg-<?= $stBadge ?>-subtle text-<?= $stBadge ?> text-capitalize">
                                                        <?= e($rl['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted small">
                                                    <?= date('M j, Y', strtotime($rl['requested_at'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Holidays Column -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-0 pt-3 pb-2">
                        <h6 class="fw-bold mb-0 text-body">
                            <i class="bi bi-calendar-event me-1 text-primary"></i> Upcoming Public Holidays
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($upcomingHolidays)): ?>
                            <div class="p-4 text-center text-muted small">
                                No public holidays scheduled in the next 45 days.
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($upcomingHolidays as $h): ?>
                                    <li class="list-group-item d-flex align-items-center justify-content-between py-3">
                                        <div>
                                            <div class="fw-semibold text-body"><?= e($h['name']) ?></div>
                                            <div class="text-muted small"><?= e($h['day']) ?></div>
                                        </div>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                            <?= e($h['formatted']) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

