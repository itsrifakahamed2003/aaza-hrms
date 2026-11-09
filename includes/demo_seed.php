<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Optional Fake Demo Data Seeder & Cleaner
 * 
 * Generates 100% fake realistic demonstration data for hosting, testing, and reviews.
 * Features:
 * - 100% fake Sri Lankan names, @example.com emails, and 077000000x phone numbers.
 * - Idempotent execution (safe to run repeatedly without creating duplicates).
 * - Full atomic transaction safety (rolls back on any error).
 * - Comprehensive logging in demo_seed_log for 100% clean, safe removal.
 */

if (!defined('APP_NAME') && is_file(__DIR__ . '/../config/config.php')) {
    require_once __DIR__ . '/bootstrap.php';
}

/**
 * Checks whether demo data is currently seeded for a given company.
 */
function is_demo_seeded(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM demo_seed_log WHERE company_id = ?");
        $st->execute([$companyId]);
        if ((int)$st->fetchColumn() > 0) {
            return true;
        }
    } catch (Throwable) {
        // Table might not exist yet
    }

    $stEmp = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%'");
    $stEmp->execute([$companyId]);
    return (int)$stEmp->fetchColumn() > 0;
}

/**
 * Helper to log a seeded row ID into demo_seed_log.
 */
function log_demo_seed_row(PDO $pdo, int $companyId, string $tableName, int $rowId): void
{
    if ($rowId <= 0) {
        return;
    }
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM demo_seed_log WHERE company_id = ? AND table_name = ? AND row_id = ?");
        $chk->execute([$companyId, $tableName, $rowId]);
        if ((int)$chk->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO demo_seed_log (company_id, table_name, row_id, created_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$companyId, $tableName, $rowId]);
        }
    } catch (Throwable) {
        // demo_seed_log might not be created in rare test setups
    }
}

/**
 * Seeds comprehensive fake demo data for the specified company.
 * 
 * @param PDO $pdo Active database connection
 * @param int $companyId Target company ID
 * @param string $demoPassword Plaintext password for demo users
 * @return array<string, int> Counts of created/seeded entities
 * @throws Throwable On any fatal database error (triggers atomic rollback)
 */
function demo_seed(PDO $pdo, int $companyId, string $demoPassword): array
{
    if ($companyId <= 0) {
        throw new InvalidArgumentException("A valid positive Company ID is required for demo seeding.");
    }
    if (strlen($demoPassword) < 8) {
        throw new InvalidArgumentException("Demo user password must be at least 8 characters.");
    }

    // Verify company exists
    $stComp = $pdo->prepare("SELECT id, name, code FROM companies WHERE id = ?");
    $stComp->execute([$companyId]);
    $companyRow = $stComp->fetch();
    if (!$companyRow) {
        throw new InvalidArgumentException("Company with ID {$companyId} not found.");
    }

    // Ensure demo_seed_log table exists (only execute DDL if missing, avoiding MySQL implicit commit)
    $hasLogTable = false;
    try {
        $chkLog = $pdo->query("SHOW TABLES LIKE 'demo_seed_log'");
        $hasLogTable = (bool)$chkLog->fetchColumn();
    } catch (Throwable) {
        $hasLogTable = false;
    }
    if (!$hasLogTable) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `demo_seed_log` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `company_id` int(10) unsigned NOT NULL,
          `table_name` varchar(64) NOT NULL,
          `row_id` int(10) unsigned NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_demo_seed_company_table` (`company_id`, `table_name`),
          KEY `idx_demo_seed_row` (`table_name`, `row_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    $counts = [
        'departments'       => 0,
        'designations'      => 0,
        'users'             => 0,
        'employees'         => 0,
        'leave_requests'    => 0,
        'attendance'        => 0,
        'job_vacancies'     => 0,
        'candidates'        => 0,
        'applications'      => 0,
        'interviews'        => 0,
        'job_offers'        => 0,
        'reviews'           => 0,
        'notifications'     => 0,
        'company_holidays'  => 0,
    ];

    try {
        // =====================================================================
        // 1. DEPARTMENTS
        // =====================================================================
        $deptNames = ['Human Resources', 'Finance', 'IT', 'Operations', 'Sales'];
        $deptIds = [];
        foreach ($deptNames as $dName) {
            $stD = $pdo->prepare("SELECT id FROM departments WHERE company_id = ? AND name = ?");
            $stD->execute([$companyId, $dName]);
            $existingId = $stD->fetchColumn();
            if ($existingId) {
                $deptIds[$dName] = (int)$existingId;
            } else {
                $insD = $pdo->prepare("INSERT INTO departments (company_id, name, description, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
                $insD->execute([$companyId, $dName, "{$dName} Department (Demo)"]);
                $newId = (int)$pdo->lastInsertId();
                $deptIds[$dName] = $newId;
                log_demo_seed_row($pdo, $companyId, 'departments', $newId);
                $counts['departments']++;
            }
        }

        // =====================================================================
        // 2. DESIGNATIONS
        // =====================================================================
        $desigTitles = [
            'HR Manager'          => 'Human Resources',
            'Accountant'          => 'Finance',
            'Software Engineer'   => 'IT',
            'Operations Officer'  => 'Operations',
            'Sales Executive'     => 'Sales',
        ];
        $desigIds = [];
        foreach ($desigTitles as $title => $deptName) {
            $stDes = $pdo->prepare("SELECT id FROM designations WHERE company_id = ? AND title = ?");
            $stDes->execute([$companyId, $title]);
            $existingDesId = $stDes->fetchColumn();
            if ($existingDesId) {
                $desigIds[$title] = (int)$existingDesId;
            } else {
                $insDes = $pdo->prepare("INSERT INTO designations (company_id, title, description, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
                $insDes->execute([$companyId, $title, "{$title} Role (Demo)"]);
                $newDesId = (int)$pdo->lastInsertId();
                $desigIds[$title] = $newDesId;
                log_demo_seed_row($pdo, $companyId, 'designations', $newDesId);
                $counts['designations']++;
            }
        }

        // =====================================================================
        // 3. DEMO LOGIN USERS (demo_hr, demo_manager, demo_employee)
        // =====================================================================
        // Resolve role IDs
        $roleMap = [];
        $stRoles = $pdo->query("SELECT id, name FROM roles WHERE name IN ('hr_manager', 'manager', 'employee')");
        while ($r = $stRoles->fetch()) {
            $roleMap[$r['name']] = (int)$r['id'];
        }

        $pwHash = password_hash($demoPassword, PASSWORD_DEFAULT);
        $demoUsersDef = [
            'demo_hr' => [
                'username'  => 'demo_hr',
                'email'     => 'demo_hr@example.com',
                'full_name' => 'Demo HR Manager',
                'role'      => 'hr_manager',
            ],
            'demo_manager' => [
                'username'  => 'demo_manager',
                'email'     => 'demo_manager@example.com',
                'full_name' => 'Demo IT Department Manager',
                'role'      => 'manager',
            ],
            'demo_employee' => [
                'username'  => 'demo_employee',
                'email'     => 'demo_employee@example.com',
                'full_name' => 'Demo Staff Employee',
                'role'      => 'employee',
            ],
        ];

        $userIds = [];
        foreach ($demoUsersDef as $uKey => $uData) {
            $stU = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stU->execute([$uData['username'], $uData['email']]);
            $existingUid = $stU->fetchColumn();
            if ($existingUid) {
                $uid = (int)$existingUid;
                // Update password to matching demoPassword and ensure active
                $updU = $pdo->prepare("UPDATE users SET password_hash = ?, is_active = 1, full_name = ? WHERE id = ?");
                $updU->execute([$pwHash, $uData['full_name'], $uid]);
            } else {
                $insU = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active, must_change_password, created_at) VALUES (?, ?, ?, ?, 0, 1, 0, NOW())");
                $insU->execute([$uData['username'], $uData['email'], $pwHash, $uData['full_name']]);
                $uid = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'users', $uid);
                $counts['users']++;
            }
            $userIds[$uKey] = $uid;

            // Ensure company assignment
            $roleId = $roleMap[$uData['role']] ?? null;
            if ($roleId) {
                $chkAssign = $pdo->prepare("SELECT id FROM company_user_assignments WHERE user_id = ? AND company_id = ? AND role_id = ?");
                $chkAssign->execute([$uid, $companyId, $roleId]);
                $assignId = $chkAssign->fetchColumn();
                if (!$assignId) {
                    $insAssign = $pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id, assigned_at) VALUES (?, ?, ?, NOW())");
                    $insAssign->execute([$uid, $companyId, $roleId]);
                    $newAssignId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'company_user_assignments', $newAssignId);
                }
            }
        }

        // =====================================================================
        // 4. 12 EMPLOYEES (Sri Lankan Fake Names)
        // =====================================================================
        $employeeDefs = [
            [
                'code'          => 'DEMO-0001',
                'first_name'    => 'Kusal',
                'last_name'     => 'Perera',
                'email'         => 'kusal.demo@example.com',
                'phone'         => '0770000001',
                'nic'           => '199012345678',
                'dob'           => '1990-08-17',
                'gender'        => 'male',
                'dept'          => 'Human Resources',
                'desig'         => 'HR Manager',
                'manager_code'  => null,
                'user_key'      => 'demo_hr',
                'joining'       => '2024-01-15',
                'status'        => 'active',
                'salary'        => 220000.00,
            ],
            [
                'code'          => 'DEMO-0002',
                'first_name'    => 'Dinesh',
                'last_name'     => 'Chandimal',
                'email'         => 'dinesh.demo@example.com',
                'phone'         => '0770000002',
                'nic'           => '198923456789',
                'dob'           => '1989-11-18',
                'gender'        => 'male',
                'dept'          => 'IT',
                'desig'         => 'Software Engineer',
                'manager_code'  => null,
                'user_key'      => 'demo_manager',
                'joining'       => '2024-03-01',
                'status'        => 'active',
                'salary'        => 280000.00,
            ],
            [
                'code'          => 'DEMO-0003',
                'first_name'    => 'Pathum',
                'last_name'     => 'Nissanka',
                'email'         => 'pathum.demo@example.com',
                'phone'         => '0770000003',
                'nic'           => '199834567890',
                'dob'           => '1998-05-18',
                'gender'        => 'male',
                'dept'          => 'IT',
                'desig'         => 'Software Engineer',
                'manager_code'  => 'DEMO-0002',
                'user_key'      => 'demo_employee',
                'joining'       => '2024-06-15',
                'status'        => 'active',
                'salary'        => 140000.00,
            ],
            [
                'code'          => 'DEMO-0004',
                'first_name'    => 'Charith',
                'last_name'     => 'Asalanka',
                'email'         => 'charith.demo@example.com',
                'phone'         => '0770000004',
                'nic'           => '199745678901',
                'dob'           => '1997-06-29',
                'gender'        => 'male',
                'dept'          => 'IT',
                'desig'         => 'Software Engineer',
                'manager_code'  => 'DEMO-0002',
                'user_key'      => null,
                'joining'       => '2024-09-01',
                'status'        => 'active',
                'salary'        => 135000.00,
            ],
            [
                'code'          => 'DEMO-0005',
                'first_name'    => 'Wanindu',
                'last_name'     => 'Hasaranga',
                'email'         => 'wanindu.demo@example.com',
                'phone'         => '0770000005',
                'nic'           => '199756789012',
                'dob'           => '1997-07-29',
                'gender'        => 'male',
                'dept'          => 'Finance',
                'desig'         => 'Accountant',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-01-10',
                'status'        => 'active',
                'salary'        => 160000.00,
            ],
            [
                'code'          => 'DEMO-0006',
                'first_name'    => 'Chamika',
                'last_name'     => 'Karunaratne',
                'email'         => 'chamika.demo@example.com',
                'phone'         => '0770000006',
                'nic'           => '199667890123',
                'dob'           => '1996-05-29',
                'gender'        => 'male',
                'dept'          => 'Operations',
                'desig'         => 'Operations Officer',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-02-15',
                'status'        => 'active',
                'salary'        => 115000.00,
            ],
            [
                'code'          => 'DEMO-0007',
                'first_name'    => 'Dasun',
                'last_name'     => 'Shanaka',
                'email'         => 'dasun.demo@example.com',
                'phone'         => '0770000007',
                'nic'           => '199178901234',
                'dob'           => '1991-09-09',
                'gender'        => 'male',
                'dept'          => 'Sales',
                'desig'         => 'Sales Executive',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-04-01',
                'status'        => 'active',
                'salary'        => 125000.00,
            ],
            [
                'code'          => 'DEMO-0008',
                'first_name'    => 'Sadeera',
                'last_name'     => 'Samarawickrama',
                'email'         => 'sadeera.demo@example.com',
                'phone'         => '0770000008',
                'nic'           => '199589012345',
                'dob'           => '1995-08-30',
                'gender'        => 'male',
                'dept'          => 'IT',
                'desig'         => 'Software Engineer',
                'manager_code'  => 'DEMO-0002',
                'user_key'      => null,
                'joining'       => '2025-06-20',
                'status'        => 'active',
                'salary'        => 130000.00,
            ],
            [
                'code'          => 'DEMO-0009',
                'first_name'    => 'Dunith',
                'last_name'     => 'Wellalage',
                'email'         => 'dunith.demo@example.com',
                'phone'         => '0770000009',
                'nic'           => '200390123456',
                'dob'           => '2003-01-09',
                'gender'        => 'male',
                'dept'          => 'Operations',
                'desig'         => 'Operations Officer',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-08-10',
                'status'        => 'active',
                'salary'        => 110000.00,
            ],
            [
                'code'          => 'DEMO-0010',
                'first_name'    => 'Maheesh',
                'last_name'     => 'Theekshana',
                'email'         => 'maheesh.demo@example.com',
                'phone'         => '0770000010',
                'nic'           => '200001234567',
                'dob'           => '2000-08-01',
                'gender'        => 'male',
                'dept'          => 'Sales',
                'desig'         => 'Sales Executive',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-10-01',
                'status'        => 'active',
                'salary'        => 120000.00,
            ],
            [
                'code'          => 'DEMO-0011',
                'first_name'    => 'Dilshan',
                'last_name'     => 'Madushanka',
                'email'         => 'dilshan.demo@example.com',
                'phone'         => '0770000011',
                'nic'           => '200012345678',
                'dob'           => '2000-09-18',
                'gender'        => 'male',
                'dept'          => 'Finance',
                'desig'         => 'Accountant',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2025-11-15',
                'status'        => 'on_leave',
                'salary'        => 145000.00,
            ],
            [
                'code'          => 'DEMO-0012',
                'first_name'    => 'Kasun',
                'last_name'     => 'Rajitha',
                'email'         => 'kasun.demo@example.com',
                'phone'         => '0770000012',
                'nic'           => '199323456789',
                'dob'           => '1993-06-01',
                'gender'        => 'male',
                'dept'          => 'Operations',
                'desig'         => 'Operations Officer',
                'manager_code'  => null,
                'user_key'      => null,
                'joining'       => '2024-02-01',
                'status'        => 'resigned',
                'salary'        => 112000.00,
            ],
        ];

        $empIdByCode = [];
        // First pass: insert or find employees without manager link to resolve IDs
        foreach ($employeeDefs as $eDef) {
            $stE = $pdo->prepare("SELECT id FROM employees WHERE company_id = ? AND employee_code = ?");
            $stE->execute([$companyId, $eDef['code']]);
            $existingEmpId = $stE->fetchColumn();
            $dId = $deptIds[$eDef['dept']] ?? null;
            $desId = $desigIds[$eDef['desig']] ?? null;
            $linkedUid = $eDef['user_key'] ? ($userIds[$eDef['user_key']] ?? null) : null;

            if ($existingEmpId) {
                $eId = (int)$existingEmpId;
                // Keep linked user updated
                if ($linkedUid) {
                    $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$linkedUid, $eId]);
                }
            } else {
                $insEmp = $pdo->prepare(
                    "INSERT INTO employees (
                        employee_code, company_id, user_id, first_name, last_name, email, phone,
                        nic_passport, dob, gender, address, department_id, designation_id,
                        joining_date, employment_type, employment_status, basic_salary, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '123 Fake Street, Colombo, Sri Lanka', ?, ?, ?, 'full_time', ?, ?, NOW())"
                );
                $insEmp->execute([
                    $eDef['code'],
                    $companyId,
                    $linkedUid,
                    $eDef['first_name'],
                    $eDef['last_name'],
                    $eDef['email'],
                    $eDef['phone'],
                    $eDef['nic'],
                    $eDef['dob'],
                    $eDef['gender'],
                    $dId,
                    $desId,
                    $eDef['joining'],
                    $eDef['status'],
                    $eDef['salary'],
                ]);
                $eId = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'employees', $eId);
                $counts['employees']++;

                // Record initial employment history
                try {
                    $insHist = $pdo->prepare(
                        "INSERT INTO employment_history (
                            employee_id, company_id, department_id, designation_id,
                            employment_type, start_date, change_type, remarks, created_at
                        ) VALUES (?, ?, ?, ?, 'full_time', ?, 'joined', 'Initial demo onboarding', NOW())"
                    );
                    $insHist->execute([$eId, $companyId, $dId, $desId, $eDef['joining']]);
                    $histId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'employment_history', $histId);
                } catch (Throwable) {}
            }
            $empIdByCode[$eDef['code']] = $eId;
        }

        // Second pass: set reporting_manager_id
        foreach ($employeeDefs as $eDef) {
            if ($eDef['manager_code'] && isset($empIdByCode[$eDef['manager_code']])) {
                $mgrId = $empIdByCode[$eDef['manager_code']];
                $thisEmpId = $empIdByCode[$eDef['code']];
                $pdo->prepare("UPDATE employees SET reporting_manager_id = ? WHERE id = ?")->execute([$mgrId, $thisEmpId]);
            }
        }

        // =====================================================================
        // 5. LEAVE REQUESTS (8 Requests: 3 approved, 3 pending, 1 rejected, 1 cancelled)
        // =====================================================================
        // Resolve leave types
        $stLt = $pdo->prepare("SELECT id, name FROM leave_types WHERE company_id = ? OR company_id IS NULL ORDER BY id ASC");
        $stLt->execute([$companyId]);
        $leaveTypeRows = $stLt->fetchAll();
        $leaveTypeMap = [];
        foreach ($leaveTypeRows as $lt) {
            $leaveTypeMap[strtolower(trim($lt['name']))] = (int)$lt['id'];
        }
        $annualLtId  = $leaveTypeMap['annual leave'] ?? (array_values($leaveTypeMap)[0] ?? 1);
        $casualLtId  = $leaveTypeMap['casual leave'] ?? $annualLtId;
        $medicalLtId = $leaveTypeMap['medical leave'] ?? $annualLtId;

        $approverAdminUserId = $userIds['demo_manager'] ?? ($userIds['demo_hr'] ?? 1);

        $leaveRequestsDef = [
            // 3 Approved
            [
                'emp_code'    => 'DEMO-0003', // Pathum Nissanka
                'type_id'     => $annualLtId,
                'start'       => '2026-10-12', // Monday
                'end'         => '2026-10-13', // Tuesday
                'days'        => 2.0,
                'reason'      => 'Family vacation (Demo)',
                'status'      => 'approved',
                'decided_by'  => $approverAdminUserId,
                'decided_at'  => '2026-10-10 11:30:00',
                'comment'     => 'Approved. Enjoy your leave.',
            ],
            [
                'emp_code'    => 'DEMO-0004', // Charith Asalanka
                'type_id'     => $casualLtId,
                'start'       => '2026-10-21', // Wednesday
                'end'         => '2026-10-21',
                'days'        => 1.0,
                'reason'      => 'Personal appointment (Demo)',
                'status'      => 'approved',
                'decided_by'  => $approverAdminUserId,
                'decided_at'  => '2026-10-18 09:00:00',
                'comment'     => 'Approved.',
            ],
            [
                'emp_code'    => 'DEMO-0005', // Wanindu Hasaranga
                'type_id'     => $medicalLtId,
                'start'       => '2026-10-29', // Thursday
                'end'         => '2026-10-30', // Friday
                'days'        => 2.0,
                'reason'      => 'Dental procedure recovery (Demo)',
                'status'      => 'approved',
                'decided_by'  => $userIds['demo_hr'] ?? $approverAdminUserId,
                'decided_at'  => '2026-10-28 16:45:00',
                'comment'     => 'Approved. Get well soon.',
            ],
            // 3 Pending (Pathum reports to demo_manager!)
            [
                'emp_code'    => 'DEMO-0003', // Pathum Nissanka
                'type_id'     => $annualLtId,
                'start'       => '2026-11-16', // Monday
                'end'         => '2026-11-17', // Tuesday
                'days'        => 2.0,
                'reason'      => 'Annual leave for personal errands (Demo)',
                'status'      => 'pending',
                'decided_by'  => null,
                'decided_at'  => null,
                'comment'     => null,
            ],
            [
                'emp_code'    => 'DEMO-0006', // Chamika Karunaratne
                'type_id'     => $casualLtId,
                'start'       => '2026-11-18', // Wednesday
                'end'         => '2026-11-18',
                'days'        => 1.0,
                'reason'      => 'Attending family wedding (Demo)',
                'status'      => 'pending',
                'decided_by'  => null,
                'decided_at'  => null,
                'comment'     => null,
            ],
            [
                'emp_code'    => 'DEMO-0008', // Sadeera Samarawickrama
                'type_id'     => $medicalLtId,
                'start'       => '2026-11-20', // Friday
                'end'         => '2026-11-20',
                'days'        => 1.0,
                'reason'      => 'Medical checkup (Demo)',
                'status'      => 'pending',
                'decided_by'  => null,
                'decided_at'  => null,
                'comment'     => null,
            ],
            // 1 Rejected
            [
                'emp_code'    => 'DEMO-0007', // Dasun Shanaka
                'type_id'     => $casualLtId,
                'start'       => '2026-10-26',
                'end'         => '2026-10-26',
                'days'        => 1.0,
                'reason'      => 'Casual leave request (Demo)',
                'status'      => 'rejected',
                'decided_by'  => $approverAdminUserId,
                'decided_at'  => '2026-10-24 10:15:00',
                'comment'     => 'Staff shortage on requested date. Please reschedule.',
            ],
            // 1 Cancelled
            [
                'emp_code'    => 'DEMO-0010', // Maheesh Theekshana
                'type_id'     => $annualLtId,
                'start'       => '2026-10-27',
                'end'         => '2026-10-28',
                'days'        => 2.0,
                'reason'      => 'Personal travel plans (Demo)',
                'status'      => 'cancelled',
                'decided_by'  => null,
                'decided_at'  => null,
                'comment'     => null,
            ],
        ];

        $seededLeaveReqIds = [];
        foreach ($leaveRequestsDef as $lr) {
            $eId = $empIdByCode[$lr['emp_code']] ?? null;
            if (!$eId) continue;

            $chkLr = $pdo->prepare("SELECT id FROM leave_requests WHERE employee_id = ? AND start_date = ? AND end_date = ?");
            $chkLr->execute([$eId, $lr['start'], $lr['end']]);
            $existingLrId = $chkLr->fetchColumn();

            if (!$existingLrId) {
                $insLr = $pdo->prepare(
                    "INSERT INTO leave_requests (
                        employee_id, company_id, leave_type_id, start_date, end_date,
                        total_days, half_day, reason, status, decided_by, decided_at, decision_comment, requested_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())"
                );
                $insLr->execute([
                    $eId,
                    $companyId,
                    $lr['type_id'],
                    $lr['start'],
                    $lr['end'],
                    $lr['days'],
                    $lr['reason'],
                    $lr['status'],
                    $lr['decided_by'],
                    $lr['decided_at'],
                    $lr['comment'],
                ]);
                $lrId = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'leave_requests', $lrId);
                $seededLeaveReqIds[] = $lrId;
                $counts['leave_requests']++;

                // Record audit row in leave_approvals if decided
                if (in_array($lr['status'], ['approved', 'rejected'], true) && $lr['decided_by']) {
                    $insAppr = $pdo->prepare(
                        "INSERT INTO leave_approvals (leave_request_id, approver_id, decision, comments, decided_at)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $insAppr->execute([$lrId, $lr['decided_by'], $lr['status'], $lr['comment'], $lr['decided_at'] ?: date('Y-m-d H:i:s')]);
                    $apprId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'leave_approvals', $apprId);
                }

                // Call application leave attendance sync for approved leave
                if ($lr['status'] === 'approved') {
                    if (function_exists('sync_approved_leave_attendance')) {
                        sync_approved_leave_attendance($pdo, $lrId);
                    }
                }
            }
        }

        // =====================================================================
        // 6. ATTENDANCE FOR THE LAST 14 DAYS (For Active Employees)
        // =====================================================================
        // Range: past 14 days up to today
        $today = new DateTimeImmutable('today');
        $activeEmpCodes = ['DEMO-0001', 'DEMO-0002', 'DEMO-0003', 'DEMO-0004', 'DEMO-0005', 'DEMO-0006', 'DEMO-0007', 'DEMO-0008', 'DEMO-0009', 'DEMO-0010'];

        for ($i = 14; $i >= 0; $i--) {
            $curDate = $today->sub(new DateInterval("P{$i}D"));
            $dayOfWeek = (int)$curDate->format('N'); // 1 = Mon, 7 = Sun
            if ($dayOfWeek >= 6) {
                // Skip weekend
                continue;
            }
            $dateStr = $curDate->format('Y-m-d');

            foreach ($activeEmpCodes as $idx => $code) {
                $eId = $empIdByCode[$code] ?? null;
                if (!$eId) continue;

                // Check if attendance already recorded (e.g. from leave sync)
                $chkAtt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND att_date = ?");
                $chkAtt->execute([$eId, $dateStr]);
                if ($chkAtt->fetchColumn()) {
                    continue;
                }

                // Realistic distribution: Mostly present, occasional late or absent
                $rand = ($i * 7 + $idx) % 20;
                if ($rand === 3) {
                    $attStatus = 'late';
                    $cIn  = '09:35:00';
                    $cOut = '17:30:00';
                    $rem  = 'Traffic delay on baseline road (Demo)';
                } elseif ($rand === 11 && $i > 2) {
                    $attStatus = 'absent';
                    $cIn  = null;
                    $cOut = null;
                    $rem  = 'Unexcused absence (Demo)';
                } else {
                    $attStatus = 'present';
                    $cIn  = sprintf('08:%02d:00', 40 + (($idx * 3) % 18));
                    $cOut = sprintf('17:%02d:00', 10 + (($idx * 2) % 20));
                    $rem  = null;
                }

                $insAtt = $pdo->prepare(
                    "INSERT INTO attendance (employee_id, company_id, att_date, check_in, check_out, status, remarks, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $insAtt->execute([$eId, $companyId, $dateStr, $cIn, $cOut, $attStatus, $rem]);
                $attId = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'attendance', $attId);
                $counts['attendance']++;
            }
        }

        // =====================================================================
        // 7. 3 JOB VACANCIES (2 Open, 1 Closed)
        // =====================================================================
        $vacanciesDef = [
            [
                'code'        => 'DEMO-VAC-01',
                'title'       => 'Senior Full Stack Engineer (Demo)',
                'dept'        => 'IT',
                'desig'       => 'Software Engineer',
                'positions'   => 2,
                'type'        => 'full_time',
                'status'      => 'open',
                'closing'     => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
                'description' => "We are looking for a Senior Full Stack Engineer experienced with PHP 8, MySQL, and modern JavaScript architectures.",
                'requirements'=> "- 4+ years of full stack web engineering experience.\n- Strong SQL schema design and query optimization.\n- Experience with RESTful APIs, security hardening, and git workflows.",
            ],
            [
                'code'        => 'DEMO-VAC-02',
                'title'       => 'HR Operations Specialist (Demo)',
                'dept'        => 'Human Resources',
                'desig'       => 'HR Manager',
                'positions'   => 1,
                'type'        => 'full_time',
                'status'      => 'open',
                'closing'     => (new DateTimeImmutable('+45 days'))->format('Y-m-d'),
                'description' => "Seeking a dynamic HR Operations Specialist to oversee talent acquisition, attendance management, and employee onboarding.",
                'requirements'=> "- Bachelor's degree in HRM or related field.\n- Excellent English and Sinhala/Tamil communication.\n- Prior experience handling enterprise HRIS software.",
            ],
            [
                'code'        => 'DEMO-VAC-03',
                'title'       => 'Finance & Accounts Associate (Demo)',
                'dept'        => 'Finance',
                'desig'       => 'Accountant',
                'positions'   => 1,
                'type'        => 'full_time',
                'status'      => 'closed',
                'closing'     => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
                'description' => "Responsible for managing bookkeeping, payroll tax calculations, invoicing, and financial reporting.",
                'requirements'=> "- Part qualified in CA, ACCA, or CIMA.\n- 2+ years of general accounting experience.\n- High proficiency in spreadsheet modelling.",
            ],
        ];

        $vacIdByCode = [];
        foreach ($vacanciesDef as $vDef) {
            $stV = $pdo->prepare("SELECT id FROM job_vacancies WHERE company_id = ? AND vacancy_code = ?");
            $stV->execute([$companyId, $vDef['code']]);
            $existingVacId = $stV->fetchColumn();

            if ($existingVacId) {
                $vacIdByCode[$vDef['code']] = (int)$existingVacId;
            } else {
                $insV = $pdo->prepare(
                    "INSERT INTO job_vacancies (
                        company_id, vacancy_code, title, department_id, designation_id,
                        positions_count, employment_type, description, requirements, closing_date, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $insV->execute([
                    $companyId,
                    $vDef['code'],
                    $vDef['title'],
                    $deptIds[$vDef['dept']] ?? null,
                    $desigIds[$vDef['desig']] ?? null,
                    $vDef['positions'],
                    $vDef['type'],
                    $vDef['description'],
                    $vDef['requirements'],
                    $vDef['closing'],
                    $vDef['status'],
                ]);
                $vId = (int)$pdo->lastInsertId();
                $vacIdByCode[$vDef['code']] = $vId;
                log_demo_seed_row($pdo, $companyId, 'job_vacancies', $vId);
                $counts['job_vacancies']++;
            }
        }

        // =====================================================================
        // 8. 10 CANDIDATES & APPLICATIONS (Spread across pipeline stages)
        // =====================================================================
        $candidatesDef = [
            ['code' => 'DEMO-CAND-01', 'first' => 'Niroshan',  'last' => 'Dickwella',  'vac' => 'DEMO-VAC-01', 'stage' => 'applied',             'score' => 3.5],
            ['code' => 'DEMO-CAND-02', 'first' => 'Jeffrey',   'last' => 'Vandersay',   'vac' => 'DEMO-VAC-01', 'stage' => 'under_review',        'score' => 3.8],
            ['code' => 'DEMO-CAND-03', 'first' => 'Avishka',   'last' => 'Fernando',    'vac' => 'DEMO-VAC-01', 'stage' => 'shortlisted',         'score' => 4.0],
            ['code' => 'DEMO-CAND-04', 'first' => 'Dhananjaya','last' => 'de Silva',    'vac' => 'DEMO-VAC-01', 'stage' => 'interview_scheduled', 'score' => 4.2],
            ['code' => 'DEMO-CAND-05', 'first' => 'Nuwan',     'last' => 'Thushara',    'vac' => 'DEMO-VAC-01', 'stage' => 'interviewed',           'score' => 4.4],
            ['code' => 'DEMO-CAND-06', 'first' => 'Binura',    'last' => 'Fernando',    'vac' => 'DEMO-VAC-01', 'stage' => 'selected',            'score' => 4.6],
            ['code' => 'DEMO-CAND-07', 'first' => 'Lahiru',    'last' => 'Kumara',      'vac' => 'DEMO-VAC-01', 'stage' => 'offered',             'score' => 4.5],
            ['code' => 'DEMO-CAND-08', 'first' => 'Ramesh',    'last' => 'Mendis',      'vac' => 'DEMO-VAC-02', 'stage' => 'offer_accepted',      'score' => 4.7],
            ['code' => 'DEMO-CAND-09', 'first' => 'Asitha',    'last' => 'Fernando',    'vac' => 'DEMO-VAC-02', 'stage' => 'rejected',            'score' => 2.5],
            ['code' => 'DEMO-CAND-10', 'first' => 'Pramod',    'last' => 'Madushan',    'vac' => 'DEMO-VAC-03', 'stage' => 'interviewed',           'score' => 3.9],
        ];

        $appIdByCandCode = [];
        foreach ($candidatesDef as $idx => $cDef) {
            $candEmail = strtolower("{$cDef['first']}.{$cDef['last']}.demo@example.com");
            $candPhone = sprintf('07700001%02d', $idx + 1);

            $stC = $pdo->prepare("SELECT id FROM candidates WHERE candidate_code = ? OR email = ?");
            $stC->execute([$cDef['code'], $candEmail]);
            $existingCandId = $stC->fetchColumn();

            if ($existingCandId) {
                $candId = (int)$existingCandId;
            } else {
                $insC = $pdo->prepare(
                    "INSERT INTO candidates (
                        candidate_code, first_name, last_name, email, phone, nic_passport,
                        qualifications, experience_years, skills, source, consent_given, created_at
                    ) VALUES (?, ?, ?, ?, ?, '199511122233', 'B.Sc. in Computer Science (Demo)', 4.5, 'PHP, MySQL, TypeScript, Git', 'LinkedIn (Demo)', 1, NOW())"
                );
                $insC->execute([$cDef['code'], $cDef['first'], $cDef['last'], $candEmail, $candPhone]);
                $candId = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'candidates', $candId);
                $counts['candidates']++;
            }

            // Create application
            $vacId = $vacIdByCode[$cDef['vac']] ?? null;
            if ($vacId) {
                $chkApp = $pdo->prepare("SELECT id FROM candidate_applications WHERE candidate_id = ? AND vacancy_id = ?");
                $chkApp->execute([$candId, $vacId]);
                $existingAppId = $chkApp->fetchColumn();

                if ($existingAppId) {
                    $appId = (int)$existingAppId;
                } else {
                    $insApp = $pdo->prepare(
                        "INSERT INTO candidate_applications (
                            candidate_id, company_id, vacancy_id, application_date, status, notes, created_at
                        ) VALUES (?, ?, ?, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, NOW())"
                    );
                    $daysAgo = 18 - $idx;
                    $insApp->execute([
                        $candId,
                        $companyId,
                        $vacId,
                        $daysAgo,
                        $cDef['stage'],
                        "Demo evaluation notes for {$cDef['first']} {$cDef['last']}.",
                    ]);
                    $appId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'candidate_applications', $appId);
                    $counts['applications']++;
                }
                $appIdByCandCode[$cDef['code']] = $appId;
            }
        }

        // =====================================================================
        // 9. 3 INTERVIEWS & EVALUATIONS
        // =====================================================================
        // Interview 1: Upcoming scheduled for Dhananjaya (DEMO-CAND-04)
        $cand4AppId = $appIdByCandCode['DEMO-CAND-04'] ?? null;
        if ($cand4AppId) {
            $chkI1 = $pdo->prepare("SELECT id FROM interviews WHERE application_id = ?");
            $chkI1->execute([$cand4AppId]);
            if (!$chkI1->fetchColumn()) {
                $insI1 = $pdo->prepare(
                    "INSERT INTO interviews (
                        application_id, company_id, round_no, interview_type, scheduled_at,
                        duration_min, mode, status, notes, created_at
                    ) VALUES (?, ?, 1, 'technical', DATE_ADD(NOW(), INTERVAL 3 DAY), 45, 'online', 'scheduled', 'Initial technical assessment (Demo)', NOW())"
                );
                $insI1->execute([$cand4AppId, $companyId]);
                $i1Id = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'interviews', $i1Id);
                $counts['interviews']++;
            }
        }

        // Interview 2: Completed for Nuwan (DEMO-CAND-05) with evaluation
        $cand5AppId = $appIdByCandCode['DEMO-CAND-05'] ?? null;
        if ($cand5AppId) {
            $chkI2 = $pdo->prepare("SELECT id FROM interviews WHERE application_id = ?");
            $chkI2->execute([$cand5AppId]);
            $i2Id = $chkI2->fetchColumn();
            if (!$i2Id) {
                $insI2 = $pdo->prepare(
                    "INSERT INTO interviews (
                        application_id, company_id, round_no, interview_type, scheduled_at,
                        duration_min, mode, status, notes, created_at
                    ) VALUES (?, ?, 1, 'technical', DATE_SUB(NOW(), INTERVAL 4 DAY), 60, 'in_person', 'completed', 'Completed technical round (Demo)', NOW())"
                );
                $insI2->execute([$cand5AppId, $companyId]);
                $i2Id = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'interviews', $i2Id);
                $counts['interviews']++;
            }
            if ($i2Id) {
                $chkEval = $pdo->prepare("SELECT id FROM interview_evaluations WHERE interview_id = ?");
                $chkEval->execute([$i2Id]);
                if (!$chkEval->fetchColumn()) {
                    $insEval = $pdo->prepare(
                        "INSERT INTO interview_evaluations (
                            interview_id, evaluator_id, technical_score, communication_score,
                            attitude_score, overall_score, strengths, weaknesses, comments, recommendation, evaluated_at
                        ) VALUES (?, ?, 4, 4, 5, 4.33, 'Strong understanding of backend architecture and databases.', 'Needs slight refinement in frontend styling.', 'Recommended for next offer round.', 'hire', NOW())"
                    );
                    $evaluatorUid = $userIds['demo_manager'] ?? 1;
                    $insEval->execute([$i2Id, $evaluatorUid]);
                    $evalId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'interview_evaluations', $evalId);
                }
            }
        }

        // Interview 3: Completed for Pramod (DEMO-CAND-10) with evaluation
        $cand10AppId = $appIdByCandCode['DEMO-CAND-10'] ?? null;
        if ($cand10AppId) {
            $chkI3 = $pdo->prepare("SELECT id FROM interviews WHERE application_id = ?");
            $chkI3->execute([$cand10AppId]);
            $i3Id = $chkI3->fetchColumn();
            if (!$i3Id) {
                $insI3 = $pdo->prepare(
                    "INSERT INTO interviews (
                        application_id, company_id, round_no, interview_type, scheduled_at,
                        duration_min, mode, status, notes, created_at
                    ) VALUES (?, ?, 1, 'hr', DATE_SUB(NOW(), INTERVAL 6 DAY), 45, 'online', 'completed', 'HR and background round (Demo)', NOW())"
                );
                $insI3->execute([$cand10AppId, $companyId]);
                $i3Id = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'interviews', $i3Id);
                $counts['interviews']++;
            }
            if ($i3Id) {
                $chkEval2 = $pdo->prepare("SELECT id FROM interview_evaluations WHERE interview_id = ?");
                $chkEval2->execute([$i3Id]);
                if (!$chkEval2->fetchColumn()) {
                    $insEval2 = $pdo->prepare(
                        "INSERT INTO interview_evaluations (
                            interview_id, evaluator_id, technical_score, communication_score,
                            attitude_score, overall_score, strengths, weaknesses, comments, recommendation, evaluated_at
                        ) VALUES (?, ?, 4, 5, 4, 4.33, 'Polite, clear communicator with strong bookkeeping pedigree.', 'None observed.', 'Strong culture fit.', 'hire', NOW())"
                    );
                    $evaluatorHrUid = $userIds['demo_hr'] ?? 1;
                    $insEval2->execute([$i3Id, $evaluatorHrUid]);
                    $eval2Id = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'interview_evaluations', $eval2Id);
                }
            }
        }

        // =====================================================================
        // 10. 2 JOB OFFERS (1 Sent, 1 Accepted)
        // =====================================================================
        // Offer 1: Sent to Lahiru Kumara (DEMO-CAND-07)
        $cand7AppId = $appIdByCandCode['DEMO-CAND-07'] ?? null;
        if ($cand7AppId) {
            $chkOff1 = $pdo->prepare("SELECT id FROM job_offers WHERE application_id = ?");
            $chkOff1->execute([$cand7AppId]);
            if (!$chkOff1->fetchColumn()) {
                $insOff1 = $pdo->prepare(
                    "INSERT INTO job_offers (
                        application_id, company_id, designation_id, department_id, offered_salary,
                        employment_type, proposed_join_date, offer_date, expiry_date, status, created_at
                    ) VALUES (?, ?, ?, ?, 165000.00, 'full_time', DATE_ADD(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 11 DAY), 'sent', NOW())"
                );
                $insOff1->execute([
                    $cand7AppId,
                    $companyId,
                    $desigIds['Software Engineer'] ?? null,
                    $deptIds['IT'] ?? null,
                ]);
                $off1Id = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'job_offers', $off1Id);
                $counts['job_offers']++;
            }
        }

        // Offer 2: Accepted by Ramesh Mendis (DEMO-CAND-08)
        $cand8AppId = $appIdByCandCode['DEMO-CAND-08'] ?? null;
        if ($cand8AppId) {
            $chkOff2 = $pdo->prepare("SELECT id FROM job_offers WHERE application_id = ?");
            $chkOff2->execute([$cand8AppId]);
            if (!$chkOff2->fetchColumn()) {
                $insOff2 = $pdo->prepare(
                    "INSERT INTO job_offers (
                        application_id, company_id, designation_id, department_id, offered_salary,
                        employment_type, proposed_join_date, offer_date, expiry_date, status, responded_at, created_at
                    ) VALUES (?, ?, ?, ?, 125000.00, 'full_time', DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'accepted', DATE_SUB(NOW(), INTERVAL 2 DAY), NOW())"
                );
                $insOff2->execute([
                    $cand8AppId,
                    $companyId,
                    $desigIds['HR Manager'] ?? null,
                    $deptIds['Human Resources'] ?? null,
                ]);
                $off2Id = (int)$pdo->lastInsertId();
                log_demo_seed_row($pdo, $companyId, 'job_offers', $off2Id);
                $counts['job_offers']++;
            }
        }

        // =====================================================================
        // 11. 2 PERFORMANCE REVIEWS & GOALS (If tables exist)
        // =====================================================================
        try {
            $hasPerf = (bool)$pdo->query("SHOW TABLES LIKE 'performance_reviews'")->fetchColumn();
            if ($hasPerf) {
                $pathumEmpId = $empIdByCode['DEMO-0003'] ?? null;
                $charithEmpId = $empIdByCode['DEMO-0004'] ?? null;
                $mgrUid = $userIds['demo_manager'] ?? 1;

                if ($pathumEmpId) {
                    $chkRev1 = $pdo->prepare("SELECT id FROM performance_reviews WHERE employee_id = ? AND period_label = 'Q3 2026 Review'");
                    $chkRev1->execute([$pathumEmpId]);
                    $rev1Id = $chkRev1->fetchColumn();
                    if (!$rev1Id) {
                        $insRev1 = $pdo->prepare(
                            "INSERT INTO performance_reviews (
                                company_id, employee_id, reviewer_user_id, period_label, period_start,
                                period_end, review_date, overall_rating, manager_feedback, improvement_notes,
                                training_requirements, status, acknowledged_at, created_at
                            ) VALUES (?, ?, ?, 'Q3 2026 Review', '2026-07-01', '2026-09-30', '2026-10-05', 4,
                            'Consistently delivered sprint milestones and took initiative on code reviews.',
                            'Continue improving documentation for API services.',
                            'Advanced Cloud Architecture Training', 'acknowledged', '2026-10-06 14:00:00', NOW())"
                        );
                        $insRev1->execute([$companyId, $pathumEmpId, $mgrUid]);
                        $rev1Id = (int)$pdo->lastInsertId();
                        log_demo_seed_row($pdo, $companyId, 'performance_reviews', $rev1Id);
                        $counts['reviews']++;

                        // Seed a goal
                        try {
                            $insG1 = $pdo->prepare(
                                "INSERT INTO performance_goals (review_id, employee_id, company_id, title, description, target_date, progress, status, created_at)
                                 VALUES (?, ?, ?, 'Lead Microservice Migration', 'Migrate legacy modules to decoupled services.', '2026-12-31', 100, 'achieved', NOW())"
                            );
                            $insG1->execute([$rev1Id, $pathumEmpId, $companyId]);
                            $g1Id = (int)$pdo->lastInsertId();
                            log_demo_seed_row($pdo, $companyId, 'performance_goals', $g1Id);
                        } catch (Throwable) {}
                    }
                }

                if ($charithEmpId) {
                    $chkRev2 = $pdo->prepare("SELECT id FROM performance_reviews WHERE employee_id = ? AND period_label = 'Annual 2026 Review'");
                    $chkRev2->execute([$charithEmpId]);
                    if (!$chkRev2->fetchColumn()) {
                        $insRev2 = $pdo->prepare(
                            "INSERT INTO performance_reviews (
                                company_id, employee_id, reviewer_user_id, period_label, period_start,
                                period_end, review_date, overall_rating, manager_feedback, improvement_notes,
                                training_requirements, status, created_at
                            ) VALUES (?, ?, ?, 'Annual 2026 Review', '2026-01-01', '2026-12-31', '2026-10-15', 5,
                            'Exceptional ownership in database indexing and production readiness.',
                            'Mentor junior engineers in best practices.',
                            'System Design & Micro-frontends', 'submitted', NOW())"
                        );
                        $insRev2->execute([$companyId, $charithEmpId, $mgrUid]);
                        $rev2Id = (int)$pdo->lastInsertId();
                        log_demo_seed_row($pdo, $companyId, 'performance_reviews', $rev2Id);
                        $counts['reviews']++;

                        try {
                            $insG2 = $pdo->prepare(
                                "INSERT INTO performance_goals (review_id, employee_id, company_id, title, description, target_date, progress, status, created_at)
                                 VALUES (?, ?, ?, 'Kubernetes Certification', 'Pass the Certified Kubernetes Application Developer exam.', '2026-11-30', 65, 'open', NOW())"
                            );
                            $insG2->execute([$rev2Id, $charithEmpId, $companyId]);
                            $g2Id = (int)$pdo->lastInsertId();
                            log_demo_seed_row($pdo, $companyId, 'performance_goals', $g2Id);
                        } catch (Throwable) {}
                    }
                }
            }
        } catch (Throwable) {
            // Optional table - ignore error
        }

        // =====================================================================
        // 12. 5 NOTIFICATIONS FOR demo_hr USER
        // =====================================================================
        $demoHrUid = $userIds['demo_hr'] ?? null;
        if ($demoHrUid) {
            $demoNotificationsDef = [
                [
                    'type'    => 'leave_submitted',
                    'title'   => 'New Leave Request Submitted',
                    'message' => 'Pathum Nissanka (DEMO-0003) submitted an Annual Leave request for 2 day(s).',
                    'link'    => 'modules/leave/index.php',
                    'dedupe'  => 'demo_notif_leave_0003',
                ],
                [
                    'type'    => 'candidate_applied',
                    'title'   => 'New Candidate Application',
                    'message' => 'Niroshan Dickwella applied for Senior Full Stack Engineer (Demo).',
                    'link'    => 'modules/candidates/index.php',
                    'dedupe'  => 'demo_notif_cand_01',
                ],
                [
                    'type'    => 'interview_scheduled',
                    'title'   => 'Technical Interview Scheduled',
                    'message' => 'Technical interview scheduled for Dhananjaya de Silva on vacancy Senior Full Stack Engineer (Demo).',
                    'link'    => 'modules/interviews/index.php',
                    'dedupe'  => 'demo_notif_int_04',
                ],
                [
                    'type'    => 'offer_accepted',
                    'title'   => 'Job Offer Accepted',
                    'message' => 'Candidate Ramesh Mendis has accepted the job offer for HR Operations Specialist (Demo).',
                    'link'    => 'modules/offers/index.php',
                    'dedupe'  => 'demo_notif_off_08',
                ],
                [
                    'type'    => 'performance_acknowledged',
                    'title'   => 'Performance Review Acknowledged',
                    'message' => 'Pathum Nissanka acknowledged the Q3 2026 Review.',
                    'link'    => 'modules/performance/index.php',
                    'dedupe'  => 'demo_notif_perf_03',
                ],
            ];

            foreach ($demoNotificationsDef as $nDef) {
                $chkN = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND dedupe_key = ?");
                $chkN->execute([$demoHrUid, $nDef['dedupe']]);
                if (!$chkN->fetchColumn()) {
                    $insN = $pdo->prepare(
                        "INSERT INTO notifications (user_id, company_id, type, title, message, link_url, is_read, dedupe_key, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())"
                    );
                    $insN->execute([
                        $demoHrUid,
                        $companyId,
                        $nDef['type'],
                        $nDef['title'],
                        $nDef['message'],
                        $nDef['link'],
                        $nDef['dedupe'],
                    ]);
                    $nId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'notifications', $nId);
                    $counts['notifications']++;
                }
            }
        }

        // =====================================================================
        // 13. 1 UPCOMING COMPANY HOLIDAY
        // =====================================================================
        try {
            $hasHolidays = (bool)$pdo->query("SHOW TABLES LIKE 'company_holidays'")->fetchColumn();
            if ($hasHolidays) {
                $holidayDate = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
                $chkHol = $pdo->prepare("SELECT id FROM company_holidays WHERE company_id = ? AND holiday_date = ?");
                $chkHol->execute([$companyId, $holidayDate]);
                if (!$chkHol->fetchColumn()) {
                    $insHol = $pdo->prepare(
                        "INSERT INTO company_holidays (company_id, holiday_date, name, is_active, created_at)
                         VALUES (?, ?, 'Demo Mercantile Holiday', 1, NOW())"
                    );
                    $insHol->execute([$companyId, $holidayDate]);
                    $holId = (int)$pdo->lastInsertId();
                    log_demo_seed_row($pdo, $companyId, 'company_holidays', $holId);
                    $counts['company_holidays']++;
                }
            }
        } catch (Throwable) {}

        if ($startedTransaction) {
            $pdo->commit();
        }

        return $counts;

    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("[AaZa Demo Seeder Error] " . $e->getMessage());
        throw $e;
    }
}

/**
 * Cleanly and safely removes all seeded demo data for the specified company.
 * Only touches rows tagged with 'DEMO-' / 'demo_' or recorded in demo_seed_log.
 * Real admin accounts, production employees, and genuine company records remain untouched.
 * 
 * @param PDO $pdo Active database connection
 * @param int $companyId Target company ID
 * @return array<string, int> Counts of removed entities
 */
function demo_unseed(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        throw new InvalidArgumentException("A valid positive Company ID is required for demo removal.");
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    $removed = [
        'notifications'        => 0,
        'performance_goals'    => 0,
        'performance_reviews'  => 0,
        'job_offers'           => 0,
        'evaluations'          => 0,
        'interviews'           => 0,
        'applications'         => 0,
        'candidates'           => 0,
        'vacancies'            => 0,
        'attendance'           => 0,
        'leave_approvals'      => 0,
        'leave_requests'       => 0,
        'company_holidays'     => 0,
        'employment_history'   => 0,
        'employees'            => 0,
        'user_assignments'     => 0,
        'users'                => 0,
        'designations'         => 0,
        'departments'          => 0,
    ];

    try {
        // 1. Delete notifications for demo users or seeded rows
        $demoUsernames = ['demo_hr', 'demo_manager', 'demo_employee'];
        $inUsers = "'" . implode("','", $demoUsernames) . "'";
        $delNotif = $pdo->prepare("DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE username IN ({$inUsers}))");
        $delNotif->execute();
        $removed['notifications'] += $delNotif->rowCount();

        // 2. Delete Performance Goals & Reviews
        try {
            $delGoals = $pdo->prepare("DELETE FROM performance_goals WHERE company_id = ? AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%')");
            $delGoals->execute([$companyId, $companyId]);
            $removed['performance_goals'] += $delGoals->rowCount();

            $delRevs = $pdo->prepare("DELETE FROM performance_reviews WHERE company_id = ? AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%')");
            $delRevs->execute([$companyId, $companyId]);
            $removed['performance_reviews'] += $delRevs->rowCount();
        } catch (Throwable) {}

        // 3. Delete Job Offers
        $delOffers = $pdo->prepare(
            "DELETE FROM job_offers WHERE company_id = ? AND application_id IN (
                SELECT id FROM candidate_applications WHERE company_id = ? AND candidate_id IN (
                    SELECT id FROM candidates WHERE candidate_code LIKE 'DEMO-%' OR email LIKE '%@example.com'
                )
            )"
        );
        $delOffers->execute([$companyId, $companyId]);
        $removed['job_offers'] += $delOffers->rowCount();

        // 4. Delete Interview Evaluations & Interviews
        try {
            $delEvals = $pdo->prepare(
                "DELETE FROM interview_evaluations WHERE interview_id IN (
                    SELECT id FROM interviews WHERE company_id = ?
                )"
            );
            $delEvals->execute([$companyId]);
            $removed['evaluations'] += $delEvals->rowCount();
        } catch (Throwable) {}

        $delInt = $pdo->prepare(
            "DELETE FROM interviews WHERE company_id = ? AND application_id IN (
                SELECT id FROM candidate_applications WHERE company_id = ? AND candidate_id IN (
                    SELECT id FROM candidates WHERE candidate_code LIKE 'DEMO-%' OR email LIKE '%@example.com'
                )
            )"
        );
        $delInt->execute([$companyId, $companyId]);
        $removed['interviews'] += $delInt->rowCount();

        // 5. Delete Candidate Applications
        $delApps = $pdo->prepare(
            "DELETE FROM candidate_applications WHERE company_id = ? AND (
                candidate_id IN (SELECT id FROM candidates WHERE candidate_code LIKE 'DEMO-%' OR email LIKE '%@example.com')
                OR vacancy_id IN (SELECT id FROM job_vacancies WHERE company_id = ? AND (vacancy_code LIKE 'DEMO-%' OR title LIKE '%(Demo)%'))
            )"
        );
        $delApps->execute([$companyId, $companyId]);
        $removed['applications'] += $delApps->rowCount();

        // 6. Delete Candidates
        $delCands = $pdo->prepare("DELETE FROM candidates WHERE candidate_code LIKE 'DEMO-%' OR email LIKE '%@example.com'");
        $delCands->execute();
        $removed['candidates'] += $delCands->rowCount();

        // 7. Delete Job Vacancies
        $delVacs = $pdo->prepare("DELETE FROM job_vacancies WHERE company_id = ? AND (vacancy_code LIKE 'DEMO-%' OR title LIKE '%(Demo)%')");
        $delVacs->execute([$companyId]);
        $removed['vacancies'] += $delVacs->rowCount();

        // 8. Delete Attendance
        $delAtt = $pdo->prepare("DELETE FROM attendance WHERE company_id = ? AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%')");
        $delAtt->execute([$companyId, $companyId]);
        $removed['attendance'] += $delAtt->rowCount();

        // 9. Delete Leave Approvals & Leave Requests
        $delAppr = $pdo->prepare(
            "DELETE FROM leave_approvals WHERE leave_request_id IN (
                SELECT id FROM leave_requests WHERE company_id = ? AND employee_id IN (
                    SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%'
                )
            )"
        );
        $delAppr->execute([$companyId, $companyId]);
        $removed['leave_approvals'] += $delAppr->rowCount();

        $delLeaves = $pdo->prepare("DELETE FROM leave_requests WHERE company_id = ? AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%')");
        $delLeaves->execute([$companyId, $companyId]);
        $removed['leave_requests'] += $delLeaves->rowCount();

        // 10. Delete Company Holidays
        try {
            $delHol = $pdo->prepare("DELETE FROM company_holidays WHERE company_id = ? AND (name LIKE '%Demo%' OR id IN (SELECT row_id FROM demo_seed_log WHERE company_id = ? AND table_name = 'company_holidays'))");
            $delHol->execute([$companyId, $companyId]);
            $removed['company_holidays'] += $delHol->rowCount();
        } catch (Throwable) {}

        // 11. Delete Employment History
        try {
            $delHist = $pdo->prepare("DELETE FROM employment_history WHERE company_id = ? AND employee_id IN (SELECT id FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%')");
            $delHist->execute([$companyId, $companyId]);
            $removed['employment_history'] += $delHist->rowCount();
        } catch (Throwable) {}

        // 12. Delete Employees
        $delEmps = $pdo->prepare("DELETE FROM employees WHERE company_id = ? AND employee_code LIKE 'DEMO-%'");
        $delEmps->execute([$companyId]);
        $removed['employees'] += $delEmps->rowCount();

        // 13. Delete User Assignments & Users
        $delAssign = $pdo->prepare("DELETE FROM company_user_assignments WHERE user_id IN (SELECT id FROM users WHERE username IN ({$inUsers}))");
        $delAssign->execute();
        $removed['user_assignments'] += $delAssign->rowCount();

        $delUsers = $pdo->prepare("DELETE FROM users WHERE username IN ({$inUsers})");
        $delUsers->execute();
        $removed['users'] += $delUsers->rowCount();

        // 14. Delete seeded Designations and Departments ONLY if logged in demo_seed_log
        try {
            $delDes = $pdo->prepare("DELETE FROM designations WHERE company_id = ? AND id IN (SELECT row_id FROM demo_seed_log WHERE company_id = ? AND table_name = 'designations')");
            $delDes->execute([$companyId, $companyId]);
            $removed['designations'] += $delDes->rowCount();

            $delDept = $pdo->prepare("DELETE FROM departments WHERE company_id = ? AND id IN (SELECT row_id FROM demo_seed_log WHERE company_id = ? AND table_name = 'departments')");
            $delDept->execute([$companyId, $companyId]);
            $removed['departments'] += $delDept->rowCount();
        } catch (Throwable) {}

        // 15. Clean demo_seed_log for this company
        try {
            $pdo->prepare("DELETE FROM demo_seed_log WHERE company_id = ?")->execute([$companyId]);
        } catch (Throwable) {}

        if ($startedTransaction) {
            $pdo->commit();
        }

        return $removed;

    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("[AaZa Demo Unseed Error] " . $e->getMessage());
        throw $e;
    }
}

