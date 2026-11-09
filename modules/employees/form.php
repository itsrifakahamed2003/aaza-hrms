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

// Helper to find all subordinate employee IDs recursively to prevent circular reporting loops
if (!function_exists('get_employee_subordinate_ids')) {
    function get_employee_subordinate_ids(PDO $pdo, int $employeeId): array
    {
        if ($employeeId <= 0) {
            return [];
        }
        $subordinateIds = [];
        $queue = [$employeeId];
        $visited = [$employeeId => true];

        $st = $pdo->prepare('SELECT id FROM employees WHERE reporting_manager_id = ?');
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $st->execute([$currentId]);
            $children = $st->fetchAll(PDO::FETCH_COLUMN);
            foreach ($children as $childId) {
                $cid = (int)$childId;
                if (!isset($visited[$cid])) {
                    $visited[$cid] = true;
                    $subordinateIds[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
        return $subordinateIds;
    }
}

// Fetch reporting managers for allowed companies:
// Exclude the employee being edited AND their own subordinates (cycle/loop prevention)
$excludedManagerIds = $editing ? array_merge([$id], get_employee_subordinate_ids($pdo, $id)) : [];
$excludeClause = '';
if (!empty($excludedManagerIds)) {
    $exPh = implode(',', array_fill(0, count($excludedManagerIds), '?'));
    $excludeClause = " AND e.id NOT IN ($exPh)";
}

$st = $pdo->prepare(
    "SELECT e.id, e.company_id, e.employee_code, e.first_name, e.last_name, d.title AS designation_title
       FROM employees e
  LEFT JOIN designations d ON d.id = e.designation_id
      WHERE e.company_id IN ($ph)
        AND e.employment_status = 'active'"
      . $excludeClause . "
      ORDER BY e.first_name, e.last_name"
);
$mgrParams = array_merge($allowedCompanyIds, $excludedManagerIds);
$st->execute($mgrParams);
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

// Detect initial document type
$existingDoc = (string)($employee['nic_passport'] ?? '');
if ($existingDoc !== '' && !preg_match('/^([0-9]{9}[VvXx]|[0-9]{12})$/', $existingDoc)) {
    $docType = 'passport';
} else {
    $docType = 'nic';
}

$errors = [];
$fieldErrors = [];

// Form submission handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $companyId = $editing ? (int)$employee['company_id'] : (int)($_POST['company_id'] ?? 0);
    if (!$editing) {
        if (!in_array($companyId, $allowedCompanyIds, true)) {
            $errors[] = 'Please select a valid company.';
            $fieldErrors['company_id'] = 'Please select a valid company.';
        }
        require_company_access($companyId, 'employee.create');
    }

    $firstName      = trim((string)($_POST['first_name'] ?? ''));
    $lastName       = trim((string)($_POST['last_name'] ?? ''));
    $email          = trim((string)($_POST['email'] ?? ''));
    $phoneRaw       = trim((string)($_POST['phone'] ?? ''));
    $docType        = (string)($_POST['doc_type'] ?? 'nic');
    $nicPassport    = trim((string)($_POST['nic_passport'] ?? ''));
    $dob            = trim((string)($_POST['dob'] ?? ''));
    $gender         = trim((string)($_POST['gender'] ?? ''));
    $address        = trim((string)($_POST['address'] ?? ''));
    $departmentId   = (int)($_POST['department_id'] ?? 0);
    $designationId  = (int)($_POST['designation_id'] ?? 0);
    $managerId      = (int)($_POST['reporting_manager_id'] ?? 0);
    $joiningDate    = trim((string)($_POST['joining_date'] ?? ''));
    $employmentType = trim((string)($_POST['employment_type'] ?? 'full_time'));

    // Normalize phone: strip spaces/hyphens/parentheses, normalize +94 or 94 to 0
    $phone = preg_replace('/[\s\-()]+/', '', $phoneRaw);
    if (str_starts_with($phone, '+94')) {
        $phone = '0' . substr($phone, 3);
    } elseif (str_starts_with($phone, '94') && strlen($phone) === 11) {
        $phone = '0' . substr($phone, 2);
    }

    // Validation
    if ($firstName === '' || mb_strlen($firstName) > 80) {
        $fieldErrors['first_name'] = 'First name is required (maximum 80 characters).';
        $errors[] = 'First name is required (maximum 80 characters).';
    }
    if ($lastName === '' || mb_strlen($lastName) > 80) {
        $fieldErrors['last_name'] = 'Last name is required (maximum 80 characters).';
        $errors[] = 'Last name is required (maximum 80 characters).';
    }

    // Work email: REQUIRED, valid, unique within the company
    if ($email === '') {
        $fieldErrors['email'] = 'Work email is required.';
        $errors[] = 'Work email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $fieldErrors['email'] = 'Enter a valid work email address (maximum 150 characters).';
        $errors[] = 'Enter a valid work email address (maximum 150 characters).';
    } else {
        $stDup = $pdo->prepare(
            'SELECT id, employee_code, first_name, last_name FROM employees WHERE company_id = ? AND LOWER(email) = LOWER(?)'
            . ($editing ? ' AND id != ?' : '') . ' LIMIT 1'
        );
        $dupParams = $editing ? [$companyId, $email, $id] : [$companyId, $email];
        $stDup->execute($dupParams);
        if ($dup = $stDup->fetch()) {
            $msg = "An employee with email '{$email}' already exists in this company ({$dup['first_name']} {$dup['last_name']} - {$dup['employee_code']}).";
            $fieldErrors['email'] = $msg;
            $errors[] = $msg;
        }
    }

    // Phone: REQUIRED, exactly 10 digits Sri Lankan style ^0[0-9]{9}$
    if ($phone === '') {
        $fieldErrors['phone'] = 'Phone number is required.';
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^0[0-9]{9}$/', $phone)) {
        $fieldErrors['phone'] = 'Phone number must be exactly 10 digits starting with 0 (e.g. 0771234567 or +94771234567).';
        $errors[] = 'Phone number must be exactly 10 digits starting with 0 (e.g. 0771234567 or +94771234567).';
    }

    // NIC / Passport
    if ($docType === 'passport') {
        if ($nicPassport === '') {
            $fieldErrors['nic_passport'] = 'Passport number is required.';
            $errors[] = 'Passport number is required.';
        } elseif (!preg_match('/^[A-Za-z0-9]{6,20}$/', $nicPassport)) {
            $fieldErrors['nic_passport'] = 'Passport number must be 6 to 20 alphanumeric characters.';
            $errors[] = 'Passport number must be 6 to 20 alphanumeric characters.';
        } else {
            $nicPassport = strtoupper($nicPassport);
        }
    } else {
        $docType = 'nic';
        if ($nicPassport === '') {
            $fieldErrors['nic_passport'] = 'NIC number is required.';
            $errors[] = 'NIC number is required.';
        } elseif (!preg_match('/^([0-9]{9}[VvXx]|[0-9]{12})$/', $nicPassport)) {
            $fieldErrors['nic_passport'] = 'Enter a valid Sri Lankan NIC (old 9 digits with V/X, or new 12 digits).';
            $errors[] = 'Enter a valid Sri Lankan NIC (old 9 digits with V/X, or new 12 digits).';
        } else {
            $nicPassport = strtoupper($nicPassport);
        }
    }

    // Duplicate checks for NIC / Passport within the same company
    if ($nicPassport !== '' && empty($fieldErrors['nic_passport'])) {
        $stDup = $pdo->prepare(
            'SELECT id, employee_code, first_name, last_name FROM employees WHERE company_id = ? AND UPPER(nic_passport) = UPPER(?)'
            . ($editing ? ' AND id != ?' : '') . ' LIMIT 1'
        );
        $dupParams = $editing ? [$companyId, $nicPassport, $id] : [$companyId, $nicPassport];
        $stDup->execute($dupParams);
        if ($dup = $stDup->fetch()) {
            $label = $docType === 'passport' ? 'Passport' : 'NIC';
            $msg = "An employee with {$label} '{$nicPassport}' already exists in this company ({$dup['first_name']} {$dup['last_name']} - {$dup['employee_code']}).";
            $fieldErrors['nic_passport'] = $msg;
            $errors[] = $msg;
        }
    }

    // Date of Birth: REQUIRED, valid date, not in the future, at least 16 years old
    if ($dob === '') {
        $fieldErrors['dob'] = 'Date of birth is required.';
        $errors[] = 'Date of birth is required.';
    } else {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
        $today = new DateTimeImmutable('today');
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $fieldErrors['dob'] = 'Date of birth must be a valid date (YYYY-MM-DD).';
            $errors[] = 'Date of birth must be a valid date (YYYY-MM-DD).';
        } elseif ($d > $today) {
            $fieldErrors['dob'] = 'Date of birth cannot be in the future.';
            $errors[] = 'Date of birth cannot be in the future.';
        } else {
            $minAgeDate = $today->modify('-16 years');
            if ($d > $minAgeDate) {
                $fieldErrors['dob'] = 'Employee must be at least 16 years old.';
                $errors[] = 'Employee must be at least 16 years old.';
            }
        }
    }

    // Gender: REQUIRED
    if ($gender === '' || !in_array($gender, ['male', 'female'], true)) {
        $fieldErrors['gender'] = 'Gender is required. Please select male or female.';
        $errors[] = 'Gender is required. Please select male or female.';
    }

    // Joining Date: REQUIRED, valid date
    if ($joiningDate === '') {
        $fieldErrors['joining_date'] = 'Joining date is required.';
        $errors[] = 'Joining date is required.';
    } else {
        $jd = DateTimeImmutable::createFromFormat('Y-m-d', $joiningDate);
        if (!$jd || $jd->format('Y-m-d') !== $joiningDate) {
            $fieldErrors['joining_date'] = 'Joining date must be a valid date (YYYY-MM-DD).';
            $errors[] = 'Joining date must be a valid date (YYYY-MM-DD).';
        }
    }

    // Employment Type: REQUIRED
    if ($employmentType === '' || !array_key_exists($employmentType, employment_type_list())) {
        $fieldErrors['employment_type'] = 'Please select a valid employment type.';
        $errors[] = 'Please select a valid employment type.';
    }

    // SERVER-SIDE VERIFICATION: Department and Designation are REQUIRED, must belong to selected company and be active
    if ($departmentId <= 0) {
        $fieldErrors['department_id'] = 'Department is required. Please select a department.';
        $errors[] = 'Department is required. Please select a department.';
    } else {
        $isCurrentDept = ($editing && $departmentId === (int)($employee['department_id'] ?? 0));
        $st = $pdo->prepare(
            'SELECT id FROM departments WHERE id = ? AND company_id = ?' . ($isCurrentDept ? '' : ' AND is_active = 1') . ' LIMIT 1'
        );
        $st->execute([$departmentId, $companyId]);
        if (!$st->fetch()) {
            $fieldErrors['department_id'] = 'The selected department is invalid or does not belong to the selected company.';
            $errors[] = 'The selected department is invalid or does not belong to the selected company.';
        }
    }

    if ($designationId <= 0) {
        $fieldErrors['designation_id'] = 'Designation is required. Please select a designation.';
        $errors[] = 'Designation is required. Please select a designation.';
    } else {
        $isCurrentDesig = ($editing && $designationId === (int)($employee['designation_id'] ?? 0));
        $st = $pdo->prepare(
            'SELECT id FROM designations WHERE id = ? AND company_id = ?' . ($isCurrentDesig ? '' : ' AND is_active = 1') . ' LIMIT 1'
        );
        $st->execute([$designationId, $companyId]);
        if (!$st->fetch()) {
            $fieldErrors['designation_id'] = 'The selected designation is invalid or does not belong to the selected company.';
            $errors[] = 'The selected designation is invalid or does not belong to the selected company.';
        }
    }

    // Server-side verification: Reporting manager must belong to same company, be active, not self, and not subordinate
    if ($managerId > 0) {
        $subordinateIds = $editing ? get_employee_subordinate_ids($pdo, $id) : [];
        if ($editing && $managerId === $id) {
            $fieldErrors['reporting_manager_id'] = 'An employee cannot report to themselves.';
            $errors[] = 'An employee cannot report to themselves.';
        } elseif ($editing && in_array($managerId, $subordinateIds, true)) {
            $fieldErrors['reporting_manager_id'] = 'An employee cannot report to one of their subordinates (avoids hierarchy loop).';
            $errors[] = 'An employee cannot report to one of their subordinates (avoids hierarchy loop).';
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM employees WHERE id = ? AND company_id = ? AND employment_status = "active" LIMIT 1'
            );
            $st->execute([$managerId, $companyId]);
            if (!$st->fetch()) {
                $fieldErrors['reporting_manager_id'] = 'Selected reporting manager is invalid or does not belong to the same company.';
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
                $fieldErrors['basic_salary'] = 'Basic salary must be a valid non-negative number up to 9,999,999,999.99.';
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
            $msg = 'You do not have permission to link login accounts (requires employee.update).';
            $fieldErrors['user_id'] = $msg;
            $errors[] = $msg;
            flash_set('danger', $msg);
        } elseif ($targetUserId !== null) {
            $linkError = validate_employee_user_link($pdo, $targetUserId, $companyId, $editing ? $id : null);
            if ($linkError !== null) {
                $fieldErrors['user_id'] = $linkError;
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
<div class="page-header mb-3">
    <div class="page-header-title-block">
        <h1 class="page-header-title h4 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0 small">
            <?= $editing ? 'Update personal, organizational, and employment details. <span class="text-muted ms-1">Code: ' . e($employee['employee_code']) . '</span>' : 'Register a new employee. Employee code is auto-generated per company.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= e($editing ? url('modules/employees/view.php?id=' . $id) : url('modules/employees/index.php')) ?>">
            &larr; Back
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Please fix the highlighted fields:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" id="employeeForm" autocomplete="off">
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- Organizational Context -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">Company &amp; Employment</div>
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
                            <select class="form-select <?= isset($fieldErrors['company_id']) ? 'is-invalid' : '' ?>" id="companyId" name="company_id" required onchange="onCompanyChange()">
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= (int)$employee['company_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                                        <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($fieldErrors['company_id'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['company_id']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="joiningDate">Joining Date <span class="text-danger">*</span></label>
                        <input class="form-control <?= isset($fieldErrors['joining_date']) ? 'is-invalid' : '' ?>" type="date" id="joiningDate" name="joining_date" required
                               value="<?= e($employee['joining_date'] ?? date('Y-m-d')) ?>">
                        <?php if (isset($fieldErrors['joining_date'])): ?>
                            <div class="invalid-feedback"><?= e($fieldErrors['joining_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="employmentType">Employment Type <span class="text-danger">*</span></label>
                        <select class="form-select <?= isset($fieldErrors['employment_type']) ? 'is-invalid' : '' ?>" id="employmentType" name="employment_type" required>
                            <?php foreach (employment_type_list() as $val => $lbl): ?>
                                <option value="<?= e($val) ?>"<?= ($employee['employment_type'] ?? 'full_time') === $val ? ' selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($fieldErrors['employment_type'])): ?>
                            <div class="invalid-feedback"><?= e($fieldErrors['employment_type']) ?></div>
                        <?php endif; ?>
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
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="departmentId">Department <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($fieldErrors['department_id']) ? 'is-invalid' : '' ?>" id="departmentId" name="department_id" required>
                                <option value="">-- Select Department --</option>
                            </select>
                            <?php if (isset($fieldErrors['department_id'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['department_id']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Required. Only shows active departments of the selected company.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="designationId">Designation <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($fieldErrors['designation_id']) ? 'is-invalid' : '' ?>" id="designationId" name="designation_id" required>
                                <option value="">-- Select Designation --</option>
                            </select>
                            <?php if (isset($fieldErrors['designation_id'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['designation_id']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Required. Only shows active designations of the selected company.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="reportingManagerId">Reporting Manager</label>
                            <select class="form-select <?= isset($fieldErrors['reporting_manager_id']) ? 'is-invalid' : '' ?>" id="reportingManagerId" name="reporting_manager_id">
                                <option value="0">-- No Reporting Manager --</option>
                            </select>
                            <?php if (isset($fieldErrors['reporting_manager_id'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['reporting_manager_id']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Optional. Active staff from same company (subordinates excluded to avoid loops).</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="userId">Login account</label>
                            <select class="form-select <?= isset($fieldErrors['user_id']) ? 'is-invalid' : '' ?>" id="userId" name="user_id">
                                <option value="0">-- None (Unlinked) --</option>
                            </select>
                            <?php if (isset($fieldErrors['user_id'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['user_id']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Optional. Lists active users assigned to this company not linked elsewhere.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-body fw-semibold">Personal Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="firstName">First Name <span class="text-danger">*</span></label>
                            <input class="form-control <?= isset($fieldErrors['first_name']) ? 'is-invalid' : '' ?>"
                                   type="text" id="firstName" name="first_name" maxlength="80" required
                                   value="<?= e($employee['first_name']) ?>">
                            <?php if (isset($fieldErrors['first_name'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['first_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="lastName">Last Name <span class="text-danger">*</span></label>
                            <input class="form-control <?= isset($fieldErrors['last_name']) ? 'is-invalid' : '' ?>"
                                   type="text" id="lastName" name="last_name" maxlength="80" required
                                   value="<?= e($employee['last_name']) ?>">
                            <?php if (isset($fieldErrors['last_name'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['last_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="email">Work Email <span class="text-danger">*</span></label>
                            <input class="form-control <?= isset($fieldErrors['email']) ? 'is-invalid' : '' ?>"
                                   type="email" id="email" name="email" maxlength="150" required
                                   placeholder="name@company.com"
                                   value="<?= e($employee['email'] ?? '') ?>">
                            <?php if (isset($fieldErrors['email'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['email']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Required. Must be unique within the selected company.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="phone">Phone <span class="text-danger">*</span></label>
                            <input class="form-control font-monospace <?= isset($fieldErrors['phone']) ? 'is-invalid' : '' ?>"
                                   type="tel" id="phone" name="phone" inputmode="numeric" maxlength="10" required
                                   pattern="^0[0-9]{9}$" placeholder="0771234567"
                                   value="<?= e($employee['phone'] ?? '') ?>">
                            <?php if (isset($fieldErrors['phone'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['phone']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Required. Exactly 10 digits Sri Lankan style (e.g. 0771234567 or +94771234567).</div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label" for="docType">Document Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="docType" name="doc_type" onchange="onDocTypeChange()">
                                <option value="nic"<?= $docType === 'nic' ? ' selected' : '' ?>>NIC</option>
                                <option value="passport"<?= $docType === 'passport' ? ' selected' : '' ?>>Passport</option>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label" id="docNumberLabel" for="nicPassport">
                                <?= $docType === 'passport' ? 'Passport No.' : 'NIC Number' ?> <span class="text-danger">*</span>
                            </label>
                            <input class="form-control font-monospace <?= isset($fieldErrors['nic_passport']) ? 'is-invalid' : '' ?>"
                                   type="text" id="nicPassport" name="nic_passport" required
                                   maxlength="<?= $docType === 'passport' ? '20' : '12' ?>"
                                   placeholder="<?= $docType === 'passport' ? 'e.g. N1234567' : 'e.g. 199512345678 or 951234567V' ?>"
                                   value="<?= e($employee['nic_passport'] ?? '') ?>">
                            <?php if (isset($fieldErrors['nic_passport'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['nic_passport']) ?></div>
                            <?php endif; ?>
                            <div class="form-text" id="docNumberHelp">
                                <?= $docType === 'passport' ? '6-20 alphanumeric characters.' : '9 digits + V/X or 12 digits.' ?>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label" for="dob">Date of Birth <span class="text-danger">*</span></label>
                            <input class="form-control <?= isset($fieldErrors['dob']) ? 'is-invalid' : '' ?>"
                                   type="date" id="dob" name="dob" required
                                   max="<?= date('Y-m-d', strtotime('-16 years')) ?>"
                                   value="<?= e($employee['dob'] ?? '') ?>">
                            <?php if (isset($fieldErrors['dob'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['dob']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Required. Minimum 16 years old.</div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($fieldErrors['gender']) ? 'is-invalid' : '' ?>" id="gender" name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <option value="male"<?= ($employee['gender'] ?? '') === 'male' ? ' selected' : '' ?>>Male</option>
                                <option value="female"<?= ($employee['gender'] ?? '') === 'female' ? ' selected' : '' ?>>Female</option>
                            </select>
                            <?php if (isset($fieldErrors['gender'])): ?>
                                <div class="invalid-feedback"><?= e($fieldErrors['gender']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="address">Residential Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"><?= e($employee['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a class="btn btn-outline-secondary" href="<?= e($editing ? url('modules/employees/view.php?id=' . $id) : url('modules/employees/index.php')) ?>">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary px-4" id="saveEmployeeBtn">
                    <i class="bi bi-check-lg me-1"></i> <?= $editing ? 'Save Changes' : 'Save Employee' ?>
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

function onDocTypeChange() {
    const docTypeEl = document.getElementById('docType');
    const inputEl = document.getElementById('nicPassport');
    const labelEl = document.getElementById('docNumberLabel');
    const helpEl = document.getElementById('docNumberHelp');
    if (!docTypeEl || !inputEl || !labelEl) return;

    if (docTypeEl.value === 'passport') {
        labelEl.innerHTML = 'Passport No. <span class="text-danger">*</span>';
        inputEl.placeholder = 'e.g. N1234567';
        inputEl.maxLength = 20;
        inputEl.pattern = '^[A-Za-z0-9]{6,20}$';
        if (helpEl) helpEl.textContent = '6-20 alphanumeric characters.';
    } else {
        labelEl.innerHTML = 'NIC Number <span class="text-danger">*</span>';
        inputEl.placeholder = 'e.g. 199512345678 or 951234567V';
        inputEl.maxLength = 12;
        inputEl.pattern = '^([0-9]{9}[vVxX]|[0-9]{12})$';
        if (helpEl) helpEl.textContent = '9 digits + V/X or 12 digits.';
    }
}

// Auto-clean phone input when typed or pasted (convert +94 to 0, strip non-digits, max 10 digits)
const phoneInput = document.getElementById('phone');
if (phoneInput) {
    phoneInput.addEventListener('input', function() {
        let val = this.value.trim();
        if (val.startsWith('+94')) {
            val = '0' + val.substring(3);
        } else if (val.startsWith('94') && val.length > 10) {
            val = '0' + val.substring(2);
        }
        val = val.replace(/[^\d]/g, '').slice(0, 10);
        this.value = val;
    });
}

function onCompanyChange() {
    const cid = getSelectedCompanyId();

    // 1. Departments
    const deptSelect = document.getElementById('departmentId');
    deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
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
    desigSelect.innerHTML = '<option value="">-- Select Designation --</option>';
    allDesigs.filter(d => parseInt(d.company_id, 10) === cid).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.title;
        if (parseInt(d.id, 10) === selectedDesigId) {
            opt.selected = true;
        }
        desigSelect.appendChild(opt);
    });

    // 3. Reporting Managers: formatted as "Name (CODE) - Designation"
    const mgrSelect = document.getElementById('reportingManagerId');
    mgrSelect.innerHTML = '<option value="0">-- No Reporting Manager --</option>';
    allManagers.filter(m => parseInt(m.company_id, 10) === cid).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        const desig = m.designation_title ? ' - ' + m.designation_title : '';
        opt.textContent = m.first_name + ' ' + m.last_name + ' (' + m.employee_code + ')' + desig;
        if (parseInt(m.id, 10) === selectedManagerId) {
            opt.selected = true;
        }
        mgrSelect.appendChild(opt);
    });

    // 4. Login Account: deduplicated active users
    const userSelect = document.getElementById('userId');
    if (userSelect) {
        userSelect.innerHTML = '<option value="0">-- None (Unlinked) --</option>';
        const seenUserIds = new Set();
        allUsers.filter(u => parseInt(u.company_id, 10) === cid).forEach(u => {
            const uid = parseInt(u.id, 10);
            if (seenUserIds.has(uid)) return;
            seenUserIds.add(uid);
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

document.addEventListener('DOMContentLoaded', function() {
    onCompanyChange();
    onDocTypeChange();
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
