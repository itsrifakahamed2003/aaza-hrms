<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

$employee = [
    'employee_code'        => '',
    'company_id'           => 0,
    'first_name'           => '',
    'last_name'            => '',
    'email'                => '',
    'phone'                => '',
    'nic_passport'         => '',
    'dob'                  => '',
    'gender'               => '',
    'address'              => '',
    'department_id'        => 0,
    'designation_id'       => 0,
    'reporting_manager_id' => 0,
    'user_id'              => null,
    'joining_date'         => date('Y-m-d'),
    'employment_type'      => 'full_time',
    'employment_status'    => 'active',
    'basic_salary'         => null,
];

if ($editing) {
    $st = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        abort(404, 'Employee not found.');
    }
    $employee = $row;
    $companyId = (int)$employee['company_id'];
    require_company_access($companyId, 'employee.edit');
    $allowedCompanyIds = [$companyId];
} else {
    require_permission('employee.create');
    $allowedCompanyIds = accessible_company_ids('employee.create');
    if (!$allowedCompanyIds) {
        abort(403, 'You do not have permission to create employees in any company.');
    }
    // Default company selection
    $active = active_company_id();
    if ($active !== null && in_array($active, $allowedCompanyIds, true)) {
        $employee['company_id'] = $active;
    } else {
        $employee['company_id'] = $allowedCompanyIds[0];
    }
}

// Fetch company details for allowed companies
$ph = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
$st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
$st->execute($allowedCompanyIds);
$companies = $st->fetchAll();
if (!$companies && !$editing) {
    abort(403, 'No active company is available for employee creation.');
}

// Fetch active departments & designations for all allowed companies
$st = $pdo->prepare(
    "SELECT id, company_id, name FROM departments WHERE company_id IN ($ph) AND is_active = 1 ORDER BY name"
);
$st->execute($allowedCompanyIds);
$allDepartments = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT id, company_id, title FROM designations WHERE company_id IN ($ph) AND is_active = 1 ORDER BY title"
);
$st->execute($allowedCompanyIds);
$allDesignations = $st->fetchAll();

// If editing, also ensure current employee's department/designation are included even if deactivated
if ($editing) {
    if (!empty($employee['department_id'])) {
        $found = false;
        foreach ($allDepartments as $d) {
            if ((int)$d['id'] === (int)$employee['department_id']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $st = $pdo->prepare('SELECT id, company_id, name FROM departments WHERE id = ?');
            $st->execute([$employee['department_id']]);
            if ($curDept = $st->fetch()) {
                $curDept['name'] .= ' (Inactive)';
                $allDepartments[] = $curDept;
            }
        }
    }

    if (!empty($employee['designation_id'])) {
        $found = false;
        foreach ($allDesignations as $d) {
            if ((int)$d['id'] === (int)$employee['designation_id']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $st = $pdo->prepare('SELECT id, company_id, title FROM designations WHERE id = ?');
            $st->execute([$employee['designation_id']]);
            if ($curDesig = $st->fetch()) {
                $curDesig['title'] .= ' (Inactive)';
                $allDesignations[] = $curDesig;
            }
        }
    }
}

// Fetch reporting managers for allowed companies
$st = $pdo->prepare(
    "SELECT id, company_id, employee_code, first_name, last_name
       FROM employees
      WHERE company_id IN ($ph) AND employment_status = 'active' " . ($editing ? "AND id != $id " : "") . "
      ORDER BY first_name, last_name"
);
$st->execute($allowedCompanyIds);
$allManagers = $st->fetchAll();

// Fetch active users for allowed companies who are not linked to another employee
$usersSql = "
    SELECT DISTINCT u.id, u.username, u.full_name, u.email, cua.company_id
      FROM users u
      JOIN company_user_assignments cua ON cua.user_id = u.id
 LEFT JOIN employees e ON e.user_id = u.id " . ($editing ? "AND e.id != $id" : "") . "
     WHERE u.is_active = 1
       AND cua.company_id IN ($ph)
       AND e.id IS NULL
     ORDER BY u.full_name, u.username
";
$stUsers = $pdo->prepare($usersSql);
$stUsers->execute($allowedCompanyIds);
$allUsers = $stUsers->fetchAll();

// If editing, ensure current employee's linked user is included in $allUsers
if ($editing && !empty($employee['user_id'])) {
    $foundCurUser = false;
    foreach ($allUsers as $u) {
        if ((int)$u['id'] === (int)$employee['user_id']) {
            $foundCurUser = true;
            break;
        }
    }
    if (!$foundCurUser) {
        $stCurUser = $pdo->prepare('SELECT id, username, full_name, email FROM users WHERE id = ?');
        $stCurUser->execute([$employee['user_id']]);
        if ($curUserRow = $stCurUser->fetch()) {
            $curUserRow['company_id'] = (int)$employee['company_id'];
            $allUsers[] = $curUserRow;
        }
    }
}

$errors = [];

// Form submission handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $companyId = $editing ? (int)$employee['company_id'] : (int)($_POST['company_id'] ?? 0);
    if (!$editing) {
        if (!in_array($companyId, $allowedCompanyIds, true)) {
            $errors[] = 'Please select a valid company.';
        }
        require_company_access($companyId, 'employee.create');
    }

    $firstName      = trim((string)($_POST['first_name'] ?? ''));
    $lastName       = trim((string)($_POST['last_name'] ?? ''));
    $email          = trim((string)($_POST['email'] ?? ''));
    $phone          = trim((string)($_POST['phone'] ?? ''));
    $nicPassport    = trim((string)($_POST['nic_passport'] ?? ''));
    $dob            = trim((string)($_POST['dob'] ?? ''));
    $gender         = trim((string)($_POST['gender'] ?? ''));
    $address        = trim((string)($_POST['address'] ?? ''));
    $departmentId   = (int)($_POST['department_id'] ?? 0);
    $designationId  = (int)($_POST['designation_id'] ?? 0);
    $managerId      = (int)($_POST['reporting_manager_id'] ?? 0);
    $joiningDate    = trim((string)($_POST['joining_date'] ?? ''));
    $employmentType = trim((string)($_POST['employment_type'] ?? 'full_time'));

    // Validation
    if ($firstName === '' || mb_strlen($firstName) > 80) {
        $errors[] = 'First name is required (maximum 80 characters).';
    }
    if ($lastName === '' || mb_strlen($lastName) > 80) {
        $errors[] = 'Last name is required (maximum 80 characters).';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
        $errors[] = 'Enter a valid email address (maximum 150 characters) or leave it empty.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\s-]{0,30}$/', $phone)) {
        $errors[] = 'Phone number may contain only digits, spaces, +, -, and () (max 30 characters).';
    }
    if (mb_strlen($nicPassport) > 30) {
        $errors[] = 'NIC / Passport must be at most 30 characters.';
    }
    if ($dob !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $errors[] = 'Date of birth must be a valid date (YYYY-MM-DD).';
        }
    }
    if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
        $errors[] = 'Please select a valid gender option.';
    }
    if ($joiningDate === '') {
        $errors[] = 'Joining date is required.';
    } else {
        $jd = DateTime::createFromFormat('Y-m-d', $joiningDate);
        if (!$jd || $jd->format('Y-m-d') !== $joiningDate) {
            $errors[] = 'Joining date must be a valid date (YYYY-MM-DD).';
        }
    }
    if (!array_key_exists($employmentType, employment_type_list())) {
        $errors[] = 'Please select a valid employment type.';
    }

    // Duplicate checks for email and NIC/Passport within the same company
    if ($email !== '') {
        $stDup = $pdo->prepare(
            'SELECT id, employee_code, first_name, last_name FROM employees WHERE company_id = ? AND email = ?'
            . ($editing ? ' AND id != ?' : '') . ' LIMIT 1'
        );
        $dupParams = $editing ? [$companyId, $email, $id] : [$companyId, $email];
        $stDup->execute($dupParams);
        if ($dup = $stDup->fetch()) {
            $errors[] = "An employee with email '{$email}' already exists in this company ({$dup['first_name']} {$dup['last_name']} - {$dup['employee_code']}).";
        }
    }

    if ($nicPassport !== '') {
        $stDup = $pdo->prepare(
            'SELECT id, employee_code, first_name, last_name FROM employees WHERE company_id = ? AND nic_passport = ?'
            . ($editing ? ' AND id != ?' : '') . ' LIMIT 1'
        );
        $dupParams = $editing ? [$companyId, $nicPassport, $id] : [$companyId, $nicPassport];
        $stDup->execute($dupParams);
        if ($dup = $stDup->fetch()) {
            $errors[] = "An employee with NIC/Passport '{$nicPassport}' already exists in this company ({$dup['first_name']} {$dup['last_name']} - {$dup['employee_code']}).";
        }
    }

    // SERVER-SIDE VERIFICATION: Department and Designation must belong to the SAME company and be active
    if ($departmentId > 0) {
        $isCurrentDept = ($editing && $departmentId === (int)$employee['department_id']);
        $st = $pdo->prepare(
            'SELECT id FROM departments WHERE id = ? AND company_id = ?' . ($isCurrentDept ? '' : ' AND is_active = 1') . ' LIMIT 1'
        );
        $st->execute([$departmentId, $companyId]);
        if (!$st->fetch()) {
            $errors[] = 'The selected department is invalid or does not belong to the selected company.';
        }
    } else {
        $departmentId = null;
    }

    if ($designationId > 0) {
        $isCurrentDesig = ($editing && $designationId === (int)$employee['designation_id']);
        $st = $pdo->prepare(
            'SELECT id FROM designations WHERE id = ? AND company_id = ?' . ($isCurrentDesig ? '' : ' AND is_active = 1') . ' LIMIT 1'
        );
        $st->execute([$designationId, $companyId]);
        if (!$st->fetch()) {
            $errors[] = 'The selected designation is invalid or does not belong to the selected company.';
        }
    } else {
        $designationId = null;
    }

    // Server-side verification: Reporting manager must belong to same company and be active
    if ($managerId > 0) {
        if ($editing && $managerId === $id) {
            $errors[] = 'An employee cannot report to themselves.';
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM employees WHERE id = ? AND company_id = ? AND employment_status = "active" LIMIT 1'
            );
            $st->execute([$managerId, $companyId]);
            if (!$st->fetch()) {
                $errors[] = 'Selected reporting manager is invalid or does not belong to the same company.';
            }
        }
    } else {
        $managerId = null;
    }

    // Salary: check salary.view permission
    $canSalary = user_can('salary.view', $companyId);
    $basicSalary = $employee['basic_salary']; // default preserve
    if ($canSalary) {
        $salaryInput = trim((string)($_POST['basic_salary'] ?? ''));
        if ($salaryInput !== '') {
            if (!is_numeric($salaryInput) || (float)$salaryInput < 0 || (float)$salaryInput > 9999999999.99) {
                $errors[] = 'Basic salary must be a valid non-negative number up to 9,999,999,999.99.';
            } else {
                $basicSalary = round((float)$salaryInput, 2);
            }
        } else {
            $basicSalary = null;
        }
    } elseif (!$editing) {
        $basicSalary = null;
    }

    // Login account (user_id): optional linking to a user login
    $canUpdateEmployee = user_can('employee.update', $companyId) || user_can('employee.edit', $companyId);
    $targetUserId = null;
    $hasPostedUserId = array_key_exists('user_id', $_POST);
    if ($hasPostedUserId) {
        $rawUserId = trim((string)$_POST['user_id']);
        if ($rawUserId !== '' && (int)$rawUserId > 0) {
            $targetUserId = (int)$rawUserId;
        }

        if (!$canUpdateEmployee) {
            $errors[] = 'You do not have permission to link login accounts (requires employee.update).';
            flash_set('danger', 'You do not have permission to link login accounts (requires employee.update).');
        } elseif ($targetUserId !== null) {
            $linkError = validate_employee_user_link($pdo, $targetUserId, $companyId, $editing ? $id : null);
            if ($linkError !== null) {
                $errors[] = $linkError;
                flash_set('danger', $linkError);
            }
        }
    }

    // Update in-memory state for re-rendering if validation fails
    $employee = array_merge($employee, [
        'company_id'           => $companyId,
        'user_id'              => $hasPostedUserId ? $targetUserId : ($employee['user_id'] ?? null),
        'first_name'           => $firstName,
        'last_name'            => $lastName,
        'email'                => $email,
        'phone'                => $phone,
        'nic_passport'         => $nicPassport,
        'dob'                  => $dob,
        'gender'               => $gender,
        'address'              => $address,
        'department_id'        => $departmentId,
        'designation_id'       => $designationId,
        'reporting_manager_id' => $managerId,
        'joining_date'         => $joiningDate,
        'employment_type'      => $employmentType,
        'basic_salary'         => $basicSalary,
    ]);

    if (!$errors) {
        $currentUserId = (int)current_user()['id'];
        $pdo->beginTransaction();
        try {
            if ($editing) {
                // Check if department or designation changed to track in employment history
                $deptChanged = ((int)($row['department_id'] ?? 0) !== (int)($departmentId ?? 0));
                $desigChanged = ((int)($row['designation_id'] ?? 0) !== (int)($designationId ?? 0));
                $effectiveDate = date('Y-m-d');

                if ($deptChanged || $desigChanged) {
                    // Fetch old and new department names
                    $oldDeptName = 'Unassigned';
                    if (!empty($row['department_id'])) {
                        $stOld = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
                        $stOld->execute([$row['department_id']]);
                        $oldDeptName = (string)($stOld->fetchColumn() ?: 'Unassigned');
                    }
                    $newDeptName = 'Unassigned';
                    if (!empty($departmentId)) {
                        $stNew = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
                        $stNew->execute([$departmentId]);
                        $newDeptName = (string)($stNew->fetchColumn() ?: 'Unassigned');
                    }

                    // Fetch old and new designation titles
                    $oldDesigTitle = 'Unassigned';
                    if (!empty($row['designation_id'])) {
                        $stOldDes = $pdo->prepare('SELECT title FROM designations WHERE id = ?');
                        $stOldDes->execute([$row['designation_id']]);
                        $oldDesigTitle = (string)($stOldDes->fetchColumn() ?: 'Unassigned');
                    }
                    $newDesigTitle = 'Unassigned';
                    if (!empty($designationId)) {
                        $stNewDes = $pdo->prepare('SELECT title FROM designations WHERE id = ?');
                        $stNewDes->execute([$designationId]);
                        $newDesigTitle = (string)($stNewDes->fetchColumn() ?: 'Unassigned');
                    }

                    // Prepare statement to close open employment history rows
                    $stCloseHist = $pdo->prepare(
                        'UPDATE employment_history SET end_date = ? WHERE employee_id = ? AND end_date IS NULL'
                    );

                    $stInsertHist = $pdo->prepare('INSERT INTO employment_history (
                        employee_id, company_id, department_id, designation_id,
                        employment_type, start_date, end_date, change_type, remarks, recorded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

                    if ($deptChanged && !$desigChanged) {
                        // Department change only
                        $stCloseHist->execute([$effectiveDate, $id]);
                        $remarks = "Department changed from '{$oldDeptName}' to '{$newDeptName}'";
                        $stInsertHist->execute([
                            $id, $companyId, $departmentId, $designationId,
                            $employmentType, $effectiveDate, null, 'department_change', $remarks, $currentUserId
                        ]);
                    } elseif ($desigChanged && !$deptChanged) {
                        // Designation change only (promotion)
                        $stCloseHist->execute([$effectiveDate, $id]);
                        $remarks = "Designation changed from '{$oldDesigTitle}' to '{$newDesigTitle}'";
                        $stInsertHist->execute([
                            $id, $companyId, $departmentId, $designationId,
                            $employmentType, $effectiveDate, null, 'promotion', $remarks, $currentUserId
                        ]);
                    } else {
                        // Both changed: record department change then promotion
                        $stCloseHist->execute([$effectiveDate, $id]);
                        $deptRemarks = "Department changed from '{$oldDeptName}' to '{$newDeptName}'";
                        $stInsertHist->execute([
                            $id, $companyId, $departmentId, $row['designation_id'],
                            $employmentType, $effectiveDate, $effectiveDate, 'department_change', $deptRemarks, $currentUserId
                        ]);

                        $desigRemarks = "Designation changed from '{$oldDesigTitle}' to '{$newDesigTitle}'";
                        $stInsertHist->execute([
                            $id, $companyId, $departmentId, $designationId,
                            $employmentType, $effectiveDate, null, 'promotion', $desigRemarks, $currentUserId
                        ]);
                    }
                }

                // Determine user_id to save
                $oldUserId = !empty($row['user_id']) ? (int)$row['user_id'] : null;
                $newUserId = ($canUpdateEmployee && $hasPostedUserId) ? $targetUserId : $oldUserId;

                // Update employee
                if ($canSalary) {
                    $stUp = $pdo->prepare('UPDATE employees SET
                        first_name = ?, last_name = ?, email = ?, phone = ?,
                        nic_passport = ?, dob = ?, gender = ?, address = ?,
                        department_id = ?, designation_id = ?, reporting_manager_id = ?,
                        joining_date = ?, employment_type = ?, basic_salary = ?,
                        user_id = ?, updated_at = NOW()
                        WHERE id = ?');
                    $stUp->execute([
                        $firstName, $lastName, $email ?: null, $phone ?: null,
                        $nicPassport ?: null, $dob ?: null, $gender ?: null, $address ?: null,
                        $departmentId, $designationId, $managerId,
                        $joiningDate, $employmentType, $basicSalary,
                        $newUserId, $id
                    ]);
                } else {
                    $stUp = $pdo->prepare('UPDATE employees SET
                        first_name = ?, last_name = ?, email = ?, phone = ?,
                        nic_passport = ?, dob = ?, gender = ?, address = ?,
                        department_id = ?, designation_id = ?, reporting_manager_id = ?,
                        joining_date = ?, employment_type = ?,
                        user_id = ?, updated_at = NOW()
                        WHERE id = ?');
                    $stUp->execute([
                        $firstName, $lastName, $email ?: null, $phone ?: null,
                        $nicPassport ?: null, $dob ?: null, $gender ?: null, $address ?: null,
                        $departmentId, $designationId, $managerId,
                        $joiningDate, $employmentType,
                        $newUserId, $id
                    ]);
                }

                $pdo->commit();

                log_activity('employee.edit', 'employee', $id, [
                    'name'       => "$firstName $lastName",
                    'code'       => $employee['employee_code'],
                    'company_id' => $companyId,
                ], $companyId);

                if ($oldUserId !== $newUserId) {
                    log_activity('employee.link_user', 'employee', $id, [
                        'old_user_id' => $oldUserId,
                        'new_user_id' => $newUserId,
                    ], $companyId);
                }

                flash_set('success', 'Employee updated successfully.');
                redirect('modules/employees/view.php?id=' . $id);
            } else {
                // Lock company row inside transaction before generating employee code
                $stLockCompany = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
                $stLockCompany->execute([$companyId]);

                // Auto-generate employee code for the selected company
                $empCode = generate_employee_code($pdo, $companyId);
                $empUserId = ($canUpdateEmployee && $hasPostedUserId) ? $targetUserId : null;

                $stIns = $pdo->prepare('INSERT INTO employees (
                    employee_code, company_id, user_id, first_name, last_name, email, phone,
                    nic_passport, dob, gender, address, department_id, designation_id,
                    reporting_manager_id, joining_date, employment_type, employment_status,
                    basic_salary, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?)');

                $stIns->execute([
                    $empCode, $companyId, $empUserId, $firstName, $lastName, $email ?: null, $phone ?: null,
                    $nicPassport ?: null, $dob ?: null, $gender ?: null, $address ?: null,
                    $departmentId, $designationId, $managerId, $joiningDate,
                    $employmentType, $basicSalary, $currentUserId
                ]);

                $newId = (int)$pdo->lastInsertId();

                // Initial appointment history entry
                $stHist = $pdo->prepare('INSERT INTO employment_history (
                    employee_id, company_id, department_id, designation_id,
                    employment_type, start_date, end_date, change_type, remarks, recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?, NULL, "joined", "Initial employment appointment", ?)');
                $stHist->execute([
                    $newId, $companyId, $departmentId, $designationId,
                    $employmentType, $joiningDate, $currentUserId
                ]);

                $pdo->commit();

                log_activity('employee.create', 'employee', $newId, [
                    'code'       => $empCode,
                    'name'       => "$firstName $lastName",
                    'company_id' => $companyId,
                ], $companyId);

                if ($empUserId !== null) {
                    log_activity('employee.link_user', 'employee', $newId, [
                        'old_user_id' => null,
                        'new_user_id' => $empUserId,
                    ], $companyId);
                }

                flash_set('success', "Employee created successfully with code {$empCode}.");
                redirect('modules/employees/view.php?id=' . $newId);
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$pageTitle = $editing ? ('Edit ' . $employee['first_name'] . ' ' . $employee['last_name']) : 'Add employee';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0 small">
            <?= $editing ? 'Update personal, organizational, and employment details.' : 'Register a new employee. Employee code is auto-generated per company.' ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e($editing ? url('modules/employees/view.php?id=' . $id) : url('modules/employees/index.php')) ?>">
        &larr; Back
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
        <div class="fw-semibold mb-1">Please correct the following errors:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate id="employeeForm" autocomplete="off">
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- Organizational Context -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">Company &amp; Code</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="companyId">Company <span class="text-danger">*</span></label>
                        <?php if ($editing): ?>
                            <input type="hidden" name="company_id" value="<?= (int)$employee['company_id'] ?>">
                            <?php
                            $compName = 'Company #' . $employee['company_id'];
                            foreach ($companies as $c) {
                                if ((int)$c['id'] === (int)$employee['company_id']) {
                                    $compName = $c['code'] . ' - ' . $c['name'];
                                    break;
                                }
                            }
                            ?>
                            <input class="form-control" type="text" value="<?= e($compName) ?>" readonly disabled>
                            <div class="form-text">Company transfer must be processed via the Transfer workflow.</div>
                        <?php else: ?>
                            <select class="form-select" id="companyId" name="company_id" required onchange="onCompanyChange()">
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= (int)$employee['company_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                                        <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="employeeCode">Employee Code</label>
                        <input class="form-control font-monospace" id="employeeCode" type="text"
                               value="<?= e($editing ? $employee['employee_code'] : '[Auto-generated per company on submit, e.g. AZT-0001]') ?>"
                               readonly disabled>
                        <div class="form-text">Assigned automatically using company prefix and numeric sequence.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="joiningDate">Joining Date <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="joiningDate" name="joining_date" required
                               value="<?= e($employee['joining_date'] ?? date('Y-m-d')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="employmentType">Employment Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="employmentType" name="employment_type" required>
                            <?php foreach (employment_type_list() as $val => $lbl): ?>
                                <option value="<?= e($val) ?>"<?= ($employee['employment_type'] ?? 'full_time') === $val ? ' selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    $currentCompId = (int)$employee['company_id'];
                    $canSalary = user_can('salary.view', $currentCompId);
                    ?>
                    <?php if ($canSalary): ?>
                        <div class="mb-3">
                            <label class="form-label" for="basicSalary">
                                Basic Salary <span class="badge text-bg-warning ms-1">salary.view</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">LKR</span>
                                <input class="form-control font-monospace" type="number" step="0.01" min="0" max="9999999999.99"
                                       id="basicSalary" name="basic_salary" placeholder="0.00"
                                       value="<?= e($employee['basic_salary'] !== null ? (string)$employee['basic_salary'] : '') ?>">
                            </div>
                            <div class="form-text">Monthly basic rate. Visible only to authorized roles.</div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border small text-muted mb-0">
                            <strong>Salary details restricted:</strong> You do not possess the <code>salary.view</code> permission for this company.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Position & Department Dropdowns -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">Department &amp; Designation</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="departmentId">Department</label>
                            <select class="form-select" id="departmentId" name="department_id">
                                <option value="0">-- Select Department --</option>
                            </select>
                            <div class="form-text">Only shows active departments of the selected company.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="designationId">Designation</label>
                            <select class="form-select" id="designationId" name="designation_id">
                                <option value="0">-- Select Designation --</option>
                            </select>
                            <div class="form-text">Only shows active designations of the selected company.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="reportingManagerId">Reporting Manager</label>
                            <select class="form-select" id="reportingManagerId" name="reporting_manager_id">
                                <option value="0">-- No Reporting Manager --</option>
                            </select>
                            <div class="form-text">Active staff members from the same company.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="userId">Login account</label>
                            <select class="form-select" id="userId" name="user_id">
                                <option value="0">-- None (Unlinked) --</option>
                            </select>
                            <div class="form-text">Optional. Lists active users assigned to this company who are not already linked to another employee.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">Personal Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="firstName">First Name <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" id="firstName" name="first_name" maxlength="80" required
                                   value="<?= e($employee['first_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="lastName">Last Name <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" id="lastName" name="last_name" maxlength="80" required
                                   value="<?= e($employee['last_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" maxlength="150"
                                   value="<?= e($employee['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control" type="text" id="phone" name="phone" maxlength="30"
                                   value="<?= e($employee['phone'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="nicPassport">NIC / Passport No.</label>
                            <input class="form-control" type="text" id="nicPassport" name="nic_passport" maxlength="30"
                                   value="<?= e($employee['nic_passport'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="dob">Date of Birth</label>
                            <input class="form-control" type="date" id="dob" name="dob"
                                   value="<?= e($employee['dob'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="gender">Gender</label>
                            <select class="form-select" id="gender" name="gender">
                                <option value="">-- Select Gender --</option>
                                <option value="male"<?= ($employee['gender'] ?? '') === 'male' ? ' selected' : '' ?>>Male</option>
                                <option value="female"<?= ($employee['gender'] ?? '') === 'female' ? ' selected' : '' ?>>Female</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="address">Residential Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"><?= e($employee['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="<?= e($editing ? url('modules/employees/view.php?id=' . $id) : url('modules/employees/index.php')) ?>">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary px-4">
                    <?= $editing ? 'Save Changes' : 'Create Employee' ?>
                </button>
            </div>
        </div>
    </div>
</form>

<script>
// Dynamic company-scoped dropdown population
const allDepts = <?= json_encode($allDepartments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const allDesigs = <?= json_encode($allDesignations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const allManagers = <?= json_encode($allManagers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const allUsers = <?= json_encode($allUsers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const selectedDeptId = <?= (int)($employee['department_id'] ?? 0) ?>;
const selectedDesigId = <?= (int)($employee['designation_id'] ?? 0) ?>;
const selectedManagerId = <?= (int)($employee['reporting_manager_id'] ?? 0) ?>;
const selectedUserId = <?= (int)($employee['user_id'] ?? 0) ?>;

function getSelectedCompanyId() {
    const compEl = document.getElementById('companyId');
    if (!compEl) {
        return <?= (int)$employee['company_id'] ?>;
    }
    return parseInt(compEl.value, 10) || 0;
}

function onCompanyChange() {
    const cid = getSelectedCompanyId();

    // 1. Departments
    const deptSelect = document.getElementById('departmentId');
    deptSelect.innerHTML = '<option value="0">-- Select Department --</option>';
    allDepts.filter(d => parseInt(d.company_id, 10) === cid).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        if (parseInt(d.id, 10) === selectedDeptId) {
            opt.selected = true;
        }
        deptSelect.appendChild(opt);
    });

    // 2. Designations
    const desigSelect = document.getElementById('designationId');
    desigSelect.innerHTML = '<option value="0">-- Select Designation --</option>';
    allDesigs.filter(d => parseInt(d.company_id, 10) === cid).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.title;
        if (parseInt(d.id, 10) === selectedDesigId) {
            opt.selected = true;
        }
        desigSelect.appendChild(opt);
    });

    // 3. Reporting Managers
    const mgrSelect = document.getElementById('reportingManagerId');
    mgrSelect.innerHTML = '<option value="0">-- No Reporting Manager --</option>';
    allManagers.filter(m => parseInt(m.company_id, 10) === cid).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.employee_code + ' - ' + m.first_name + ' ' + m.last_name;
        if (parseInt(m.id, 10) === selectedManagerId) {
            opt.selected = true;
        }
        mgrSelect.appendChild(opt);
    });

    // 4. Login Account
    const userSelect = document.getElementById('userId');
    if (userSelect) {
        userSelect.innerHTML = '<option value="0">-- None (Unlinked) --</option>';
        allUsers.filter(u => parseInt(u.company_id, 10) === cid).forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.full_name + ' (' + u.username + (u.email ? ' - ' + u.email : '') + ')';
            if (parseInt(u.id, 10) === selectedUserId) {
                opt.selected = true;
            }
            userSelect.appendChild(opt);
        });
    }
}

document.addEventListener('DOMContentLoaded', onCompanyChange);
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
