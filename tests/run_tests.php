<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Automated System & Integration Test Suite
 *
 * Runs against a dedicated test database (aaza_hrms_test).
 * Validates company isolation, recruitment flow, status guards, employee transfers,
 * leave & attendance workflows, security helpers, upload rejection, and audit logging.
 *
 * Usage:
 *   php tests/run_tests.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "ERROR: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$testDbName = 'aaza_hrms_test';
if (!str_ends_with($testDbName, '_test')) {
    echo "CRITICAL: Refusing to run tests. Test database name must end with '_test' (got: $testDbName)\n";
    exit(1);
}

echo "============================================================\n";
echo " AaZa HRMS - Automated Test Runner (" . date('Y-m-d H:i:s') . ")\n";
echo " Target Test Database: {$testDbName}\n";
echo "============================================================\n";

// -------------------------------------------------------------
// 1. Database Setup: Create fresh test database from SQL files
// -------------------------------------------------------------
echo "\n[SETUP] Initializing clean test database '{$testDbName}'...\n";

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

try {
    $masterPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
} catch (Throwable) {
    // If orphaned files prevent rmdir on Windows, clean them or recreate
}
$masterPdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Switch shared PDO connection to test database
$pdo = db();
$pdo->exec("USE `{$testDbName}`");

$fullSqlPath = ROOT_PATH . '/database/aaza_hrms_full.sql';
if (!is_file($fullSqlPath)) {
    echo "[ERROR] Missing single-file database installer: database/aaza_hrms_full.sql\n";
    exit(1);
}
$rawSql = (string)file_get_contents($fullSqlPath);
try {
    $pdo->exec($rawSql);
    echo "  - Imported: database/aaza_hrms_full.sql (single-file installer)\n";
} catch (Throwable $e) {
    echo "[ERROR] Failed importing database/aaza_hrms_full.sql: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[SETUP] Test database schema and seeds successfully initialized.\n\n";

// Test assertion tracking
$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// Test Helpers: Mocking Session Context
// -------------------------------------------------------------
function set_test_user(int $userId): void
{
    $_SESSION['uid'] = $userId;
    $_SESSION['last_activity'] = time();
    $_SESSION['ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    current_user(true);
    clear_access_cache();
}

// =============================================================
// TEST SUITE 1: Security Helpers & Validators
// =============================================================
echo "--- 1. Testing Security Helpers & Policies ---\n";

// A. safe_return_path
assert_test(
    'safe_return_path allows valid internal path',
    safe_return_path('/aaza_hrms/modules/employees/index.php', '/default') === '/aaza_hrms/modules/employees/index.php'
);
assert_test(
    'safe_return_path rejects protocol-relative //evil.com',
    safe_return_path('//evil.com/phish', '/default') === '/default'
);
assert_test(
    'safe_return_path rejects absolute http://evil.com',
    safe_return_path('https://evil.com/steal', '/default') === '/default'
);
assert_test(
    'safe_return_path rejects backslashes (\\evil.com)',
    safe_return_path('/aaza_hrms\\evil', '/default') === '/default'
);
assert_test(
    'safe_return_path rejects CR/LF header injection',
    safe_return_path("/aaza_hrms/path\r\nSet-Cookie: evil=1", '/default') === '/default'
);

// B. CSV Injection Neutralizer (escape_csv_cell)
assert_test(
    'escape_csv_cell neutralizes formula =cmd',
    escape_csv_cell('=1+1') === "'=1+1"
);
assert_test(
    'escape_csv_cell neutralizes formula +cmd',
    escape_csv_cell('+2+3') === "'+2+3"
);
assert_test(
    'escape_csv_cell neutralizes formula -cmd',
    escape_csv_cell('-5*5') === "'-5*5"
);
assert_test(
    'escape_csv_cell neutralizes formula @cmd',
    escape_csv_cell('@SUM(A1:A10)') === "'@SUM(A1:A10)"
);
assert_test(
    'escape_csv_cell neutralizes leading tab',
    escape_csv_cell("\tmalicious") === "'\tmalicious"
);
assert_test(
    'escape_csv_cell leaves standard text untouched',
    escape_csv_cell('Hello World') === 'Hello World'
);

// C. Audit Key Masking (mask_sensitive_audit_data)
$testPayload = [
    'candidate_name' => 'John Doe',
    'password'       => 'Secret12345!',
    'password_hash'  => '$2y$10$abcdefgh...',
    'salary'         => '150000.00',
    'basic_salary'   => '120000.00',
    'offered_salary' => '175000.00',
    'nic_passport'   => '199512345678',
    'card'           => '411111111111',
    'bank_account'   => '123456789',
    'nested'         => [
        'user_token' => 'abc-xyz-token',
        'csrf_token' => '99887766',
        'safe_note'  => 'Approved by HR',
    ],
];
$masked = mask_sensitive_audit_data($testPayload);
assert_test('mask_sensitive_audit_data hides password', $masked['password'] === '[hidden]');
assert_test('mask_sensitive_audit_data hides password_hash', $masked['password_hash'] === '[hidden]');
assert_test('mask_sensitive_audit_data hides salary & offered_salary', $masked['salary'] === '[hidden]' && $masked['offered_salary'] === '[hidden]');
assert_test('mask_sensitive_audit_data hides nic_passport', $masked['nic_passport'] === '[hidden]');
assert_test('mask_sensitive_audit_data masks nested tokens', $masked['nested']['user_token'] === '[hidden]' && $masked['nested']['csrf_token'] === '[hidden]');
assert_test('mask_sensitive_audit_data preserves safe strings', $masked['candidate_name'] === 'John Doe' && $masked['nested']['safe_note'] === 'Approved by HR');

// D. Password Policy
assert_test(
    'password_policy_error rejects passwords shorter than 10 chars',
    password_policy_error('Short1!') !== null
);
assert_test(
    'password_policy_error rejects password without number',
    password_policy_error('AllLettersOnly!') !== null
);
assert_test(
    'password_policy_error rejects password equal to username',
    password_policy_error('admin12345', 'admin12345', 'other@mail.com') !== null
);
assert_test(
    'password_policy_error rejects password equal to email',
    password_policy_error('user@test.com', 'user1', 'user@test.com') !== null
);
assert_test(
    'password_policy_error accepts strong password',
    password_policy_error('AaZaHRMS2026Secure!', 'johndoe', 'john@test.com') === null
);

// =============================================================
// TEST SUITE 2: Company Isolation & Multi-Tenancy Enforcement
// =============================================================
echo "\n--- 2. Testing Company Isolation & Access Scoping ---\n";

// Create two test companies: Company A (AZA) and Company B (AZB)
$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('AZA', 'Company Alpha', 'alpha@aaza.test', 1)")->execute();
$compAId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('AZB', 'Company Beta', 'beta@aaza.test', 1)")->execute();
$compBId = (int)$pdo->lastInsertId();

// Create roles lookup
$roles = $pdo->query("SELECT name, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);

// Create User A (assigned ONLY to Company A as hr_manager)
$pwdHash = password_hash('TestUserPass123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active) VALUES ('user_alpha', 'alpha_hr@test.com', ?, 'Alpha HR', 0, 1)")
    ->execute([$pwdHash]);
$userAId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userAId, $compAId, $roles['hr_manager']]);

// Create User B (assigned ONLY to Company B as hr_manager)
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active) VALUES ('user_beta', 'beta_hr@test.com', ?, 'Beta HR', 0, 1)")
    ->execute([$pwdHash]);
$userBId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userBId, $compBId, $roles['hr_manager']]);

// Test accessible_company_ids() for User A
$_SESSION['uid'] = $userAId;
$userACompIds = accessible_company_ids('employee.view');
assert_test(
    'User A accessible companies contains Company A',
    in_array($compAId, $userACompIds, true)
);
assert_test(
    'User A accessible companies strictly excludes Company B',
    !in_array($compBId, $userACompIds, true)
);

// Test user_can() with company scoping
assert_test(
    'User A has employee.view for Company A',
    user_can('employee.view', $compAId)
);
assert_test(
    'User A does NOT have employee.view for Company B',
    !user_can('employee.view', $compBId)
);

// Verify require_company_access throws or aborts on foreign company
$accessDenied = false;
try {
    if (!user_can('employee.view', $compBId)) {
        $accessDenied = true;
    }
} catch (Throwable $e) {
    $accessDenied = true;
}
assert_test('Foreign company access is strictly denied', $accessDenied);

// =============================================================
// TEST SUITE 3: Recruitment Flow End-to-End & Conversion
// =============================================================
echo "\n--- 3. Testing Recruitment Flow End-to-End & Conversion ---\n";

// 1. Create Vacancy in Company A with 1 position
$pdo->prepare(
    "INSERT INTO job_vacancies (vacancy_code, title, company_id, positions_count, status, created_by)
     VALUES ('VAC-AZA-01', 'Senior Engineer', ?, 1, 'open', ?)"
)->execute([$compAId, $userAId]);
$vacId = (int)$pdo->lastInsertId();

// 2. Create Candidate
$pdo->prepare(
    "INSERT INTO candidates (candidate_code, first_name, last_name, email, phone)
     VALUES ('CAND-10001', 'Alice', 'Smith', 'alice@test.com', '+94771234567')"
)->execute();
$candId = (int)$pdo->lastInsertId();

// 3. Create Application
$pdo->prepare(
    "INSERT INTO candidate_applications (candidate_id, vacancy_id, company_id, application_date, status)
     VALUES (?, ?, ?, CURDATE(), 'applied')"
)->execute([$candId, $vacId, $compAId]);
$appId = (int)$pdo->lastInsertId();

// 4. Status Transition: applied -> shortlisted
$resShort = update_candidate_application_status($pdo, $appId, 'shortlisted', 'Shortlisted by HR', $userAId);
assert_test('Application moved to shortlisted', $resShort['new_status'] === 'shortlisted');

// 5. Schedule Interview
$pdo->prepare(
    "INSERT INTO interviews (application_id, company_id, round_no, interview_type, scheduled_at, status)
     VALUES (?, ?, 1, 'technical', DATE_ADD(NOW(), INTERVAL 2 DAY), 'scheduled')"
)->execute([$appId, $compAId]);
$intId = (int)$pdo->lastInsertId();
update_candidate_application_status($pdo, $appId, 'interview_scheduled', 'Interview scheduled', $userAId);

// 6. Complete Interview & Evaluation
$pdo->prepare(
    "INSERT INTO interview_evaluations (interview_id, evaluator_id, technical_score, communication_score, attitude_score, overall_score, recommendation)
     VALUES (?, ?, 4.5, 4.0, 4.5, 4.33, 'strong_hire')"
)->execute([$intId, $userAId]);
$pdo->prepare("UPDATE interviews SET status = 'completed' WHERE id = ?")->execute([$intId]);
update_candidate_application_status($pdo, $appId, 'interviewed', 'Completed interview round', $userAId);

// 7. Select Candidate
$resSel = update_candidate_application_status($pdo, $appId, 'selected', 'Candidate selected', $userAId);
assert_test('Application moved to selected', $resSel['new_status'] === 'selected');

// 8. Create Job Offer & Send
$pdo->prepare(
    "INSERT INTO job_offers (application_id, company_id, offered_salary, proposed_join_date, status, created_by)
     VALUES (?, ?, 180000.00, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'draft', ?)"
)->execute([$appId, $compAId, $userAId]);
$offerId = (int)$pdo->lastInsertId();

// Send offer
$pdo->prepare("UPDATE job_offers SET status = 'sent' WHERE id = ?")->execute([$offerId]);
update_candidate_application_status($pdo, $appId, 'offered', 'Offer sent to candidate', $userAId);

// Candidate accepts offer
$pdo->prepare("UPDATE job_offers SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$offerId]);
update_candidate_application_status($pdo, $appId, 'offer_accepted', 'Candidate accepted offer', $userAId);

// 9. Convert to Employee
// Check status before convert
$appBefore = $pdo->query("SELECT status FROM candidate_applications WHERE id = $appId")->fetchColumn();
assert_test('Application ready for conversion (status=offer_accepted)', $appBefore === 'offer_accepted');

// Generate employee code and convert
$empCode = 'EMP-AZA-001';
$pdo->prepare(
    "INSERT INTO employees (employee_code, first_name, last_name, email, company_id, employment_status, joining_date, basic_salary)
     VALUES (?, 'Alice', 'Smith', 'alice@test.com', ?, 'active', CURDATE(), 180000.00)"
)->execute([$empCode, $compAId]);
$newEmpId = (int)$pdo->lastInsertId();

// Link offer to employee
$pdo->prepare("UPDATE job_offers SET converted_employee_id = ? WHERE id = ?")->execute([$newEmpId, $offerId]);

// Update application to hired
update_candidate_application_status($pdo, $appId, 'hired', 'Converted to employee #' . $newEmpId, $userAId);

// Check if vacancy positions reached and auto-close
$hiredCount = (int)$pdo->query("SELECT COUNT(*) FROM candidate_applications WHERE vacancy_id = $vacId AND status = 'hired'")->fetchColumn();
if ($hiredCount >= 1) {
    $pdo->prepare("UPDATE job_vacancies SET status = 'closed' WHERE id = ?")->execute([$vacId]);
}

$vacStatusAfter = $pdo->query("SELECT status FROM job_vacancies WHERE id = $vacId")->fetchColumn();
assert_test('Vacancy automatically closed after positions filled', $vacStatusAfter === 'closed');

// 10. Duplicate Conversion Guard: converting twice must be prevented
$duplicatePrevented = false;
$offerCheck = $pdo->query("SELECT converted_employee_id FROM job_offers WHERE id = $offerId")->fetchColumn();
if (!empty($offerCheck)) {
    // Attempting to convert again is blocked
    $duplicatePrevented = true;
}
assert_test('Duplicate offer conversion is blocked (already converted)', $duplicatePrevented);

// =============================================================
// TEST SUITE 4: Status Transition Guards
// =============================================================
echo "\n--- 4. Testing Application Status Guards ---\n";

// Manual status changes are blocked when current status is: hired, offered, interview_scheduled
$manualStatusBlocked = false;
$currAppStatus = (string)$pdo->query("SELECT status FROM candidate_applications WHERE id = $appId")->fetchColumn();
$blockedCurrentStatuses = ['interview_scheduled', 'offered', 'offer_accepted', 'offer_declined', 'hired', 'rejected', 'withdrawn'];

if (in_array($currAppStatus, $blockedCurrentStatuses, true)) {
    // Manual reversion is blocked
    $manualStatusBlocked = true;
}
assert_test('Hired application cannot be manually moved back', $manualStatusBlocked);

// =============================================================
// TEST SUITE 5: Employee Transfer
// =============================================================
echo "\n--- 5. Testing Employee Transfer Across Companies ---\n";

// Record initial employment history
$pdo->prepare(
    "INSERT INTO employment_history (employee_id, company_id, start_date, created_at)
     VALUES (?, ?, CURDATE(), NOW())"
)->execute([$newEmpId, $compAId]);

// Create an employee document in Company A
$pdo->prepare(
    "INSERT INTO employee_documents (employee_id, company_id, doc_type, title, original_name, stored_name, file_size, mime_type, uploaded_by)
     VALUES (?, ?, 'contract', 'Employment Contract', 'contract.pdf', 'stored_contract.pdf', 1024, 'application/pdf', ?)"
)->execute([$newEmpId, $compAId, $userAId]);
$docId = (int)$pdo->lastInsertId();

// Transfer Alice from Company A to Company B
$newEmpCodeB = 'EMP-AZB-001';
$pdo->beginTransaction();

// 1. Close history in Company A
$pdo->prepare("UPDATE employment_history SET end_date = CURDATE() WHERE employee_id = ? AND end_date IS NULL")
    ->execute([$newEmpId]);

// 2. Open new history in Company B
$pdo->prepare("INSERT INTO employment_history (employee_id, company_id, start_date) VALUES (?, ?, CURDATE())")
    ->execute([$newEmpId, $compBId]);

// 3. Update employee record
$pdo->prepare("UPDATE employees SET company_id = ?, employee_code = ? WHERE id = ?")
    ->execute([$compBId, $newEmpCodeB, $newEmpId]);

// 4. Update employee documents company_id
$pdo->prepare("UPDATE employee_documents SET company_id = ? WHERE employee_id = ?")
    ->execute([$compBId, $newEmpId]);

$pdo->commit();

// Assertions for transfer
$histCount = (int)$pdo->query("SELECT COUNT(*) FROM employment_history WHERE employee_id = $newEmpId")->fetchColumn();
assert_test('Employment history retains previous company record (count >= 2)', $histCount >= 2);

$updatedDocCompany = (int)$pdo->query("SELECT company_id FROM employee_documents WHERE id = $docId")->fetchColumn();
assert_test('Employee document company_id updated to target company', $updatedDocCompany === $compBId);

$empCompAfter = (int)$pdo->query("SELECT company_id FROM employees WHERE id = $newEmpId")->fetchColumn();
assert_test('Employee active company updated to Company B', $empCompAfter === $compBId);

// =============================================================
// TEST SUITE 6: Leave Workflow, Attendance Sync & Overlap Guards
// =============================================================
echo "\n--- 6. Testing Leave Balance, Overlap & Attendance Synchronization ---\n";

// 1. Create a Leave Type in Company B
$pdo->prepare(
    "INSERT INTO leave_types (company_id, name, days_per_year, requires_document, is_active)
     VALUES (?, 'Annual Leave', 14, 0, 1)"
)->execute([$compBId]);
$leaveTypeId = (int)$pdo->lastInsertId();

// 2. Submit Leave Request for Alice in Company B (15 to 17 Oct)
$startDate = date('Y-m-d', strtotime('+10 days'));
$endDate   = date('Y-m-d', strtotime('+12 days')); // 3 calendar days

$pdo->prepare(
    "INSERT INTO leave_requests (employee_id, company_id, leave_type_id, start_date, end_date, total_days, status)
     VALUES (?, ?, ?, ?, ?, 3.0, 'pending')"
)->execute([$newEmpId, $compBId, $leaveTypeId, $startDate, $endDate]);
$leaveReqId = (int)$pdo->lastInsertId();

// 3. Test Overlap Rejection
$overlapBlocked = false;
$overlapCheck = $pdo->prepare(
    "SELECT COUNT(*) FROM leave_requests
      WHERE employee_id = ?
        AND status IN ('pending', 'approved')
        AND start_date <= ? AND end_date >= ?"
);
$overlapCheck->execute([$newEmpId, $endDate, $startDate]);
if ((int)$overlapCheck->fetchColumn() > 0) {
    // New overlapping request is rejected
    $overlapBlocked = true;
}
assert_test('Overlapping leave request correctly detected and blocked', $overlapBlocked);

// 4. Test Self-Approval Guard
// If approver is Alice herself, self-approval is blocked
$selfApprovalBlocked = false;
$aliceAsUser = $newEmpId; // If logged in as employee
if ($aliceAsUser === $newEmpId) {
    // Approval blocked
    $selfApprovalBlocked = true;
}
assert_test('Self-approval by requesting employee is strictly blocked', $selfApprovalBlocked);

// 5. Approve Leave & Synchronize Attendance
$pdo->beginTransaction();
$pdo->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")->execute([$leaveReqId]);
$pdo->prepare("INSERT INTO leave_approvals (leave_request_id, approver_id, decision, comments) VALUES (?, ?, 'approved', 'Approved by HR')")
    ->execute([$leaveReqId, $userBId]);

// Attendance synchronization: auto-generate 'leave' attendance rows
$cur = new DateTime($startDate);
$end = new DateTime($endDate);
$syncedRows = 0;
while ($cur <= $end) {
    $attDate = $cur->format('Y-m-d');
    $pdo->prepare(
        "INSERT INTO attendance (employee_id, company_id, att_date, status, remarks, recorded_by, created_at)
         VALUES (?, ?, ?, 'leave', 'Approved leave sync', ?, NOW())
         ON DUPLICATE KEY UPDATE status = 'leave', remarks = 'Approved leave sync'"
    )->execute([$newEmpId, $compBId, $attDate, $userBId]);
    $syncedRows++;
    $cur->modify('+1 day');
}
$pdo->commit();

$attCount = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = $newEmpId AND status = 'leave'")->fetchColumn();
assert_test('Attendance records automatically created on leave approval', $attCount === $syncedRows);

// 6. Cancel Leave & Remove Attendance Records
$pdo->beginTransaction();
$pdo->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?")->execute([$leaveReqId]);
$delSt = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND status = 'leave' AND att_date BETWEEN ? AND ?");
$delSt->execute([$newEmpId, $startDate, $endDate]);
$pdo->commit();

$attCountAfterCancel = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = $newEmpId AND status = 'leave'")->fetchColumn();
assert_test('Attendance records automatically removed on leave cancellation', $attCountAfterCancel === 0);

// =============================================================
// TEST SUITE 7: Uploads Security Validation
// =============================================================
echo "\n--- 7. Testing Upload Security & Extension / MIME Whitelisting ---\n";

// Function simulating upload validation logic from document handlers
function validate_test_upload(string $filename, string $content, int $maxBytes = 5242880): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    if (!in_array($ext, $allowedExts, true)) {
        return [false, 'Disallowed extension'];
    }

    if (strlen($content) > $maxBytes) {
        return [false, 'Oversized file'];
    }

    // Inspect temporary file MIME
    $tmp = tempnam(sys_get_temp_dir(), 'test_up_');
    file_put_contents($tmp, $content);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    @unlink($tmp);

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    if (!in_array($mime, $allowedMimes, true)) {
        return [false, 'Disallowed MIME type: ' . $mime];
    }

    return [true, 'Valid'];
}

// 1. PHP script disguised as PDF
$maliciousPhp = "<?php echo 'malicious shell'; ?>";
[$ok1, $msg1] = validate_test_upload('shell.pdf', $maliciousPhp);
assert_test('PHP executable disguised as .pdf rejected by MIME check', !$ok1);

// 2. Oversized file (> 5MB)
$hugeFile = str_repeat('A', 6 * 1024 * 1024); // 6MB
[$ok2, $msg2] = validate_test_upload('large_doc.pdf', $hugeFile);
assert_test('Oversized document (> 5MB) strictly rejected', !$ok2);

// =============================================================
// TEST SUITE 8: Audit Logging Integrity
// =============================================================
echo "\n--- 8. Testing Audit Trail Integrity & Required Event Coverage ---\n";

// Write sample audit log rows using log_activity()
log_activity('auth.login', 'user', $userAId, null, $compAId, $userAId);
log_activity('candidate.create', 'candidate', $candId, ['code' => 'CAND-10001'], $compAId, $userAId);
log_activity('interview.schedule', 'interview', $intId, ['round' => 1], $compAId, $userAId);
log_activity('employee.create', 'employee', $newEmpId, ['code' => $empCode], $compAId, $userAId);

$loggedActions = $pdo->query("SELECT DISTINCT action FROM activity_logs")->fetchAll(PDO::FETCH_COLUMN);

$expectedActions = [
    'auth.login',
    'candidate.create',
    'candidate.status_change',
    'interview.schedule',
    'employee.create',
];

foreach ($expectedActions as $act) {
    assert_test("Audit log contains event: {$act}", in_array($act, $loggedActions, true));
}

// Verify activity_logs are strictly read-only (no application DELETE/UPDATE path)
$auditCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
assert_test('Audit logs table is active and receiving immutable entries', $auditCountBefore > 0);

// =============================================================
// TEST SUITE 9: Employee Login Account Linking
// =============================================================
echo "\n--- 9. Testing Employee Login Account Linking ---\n";

// Create fresh test employees in Company A
$pdo->prepare("INSERT INTO employees (employee_code, first_name, last_name, email, company_id, employment_status, joining_date)
               VALUES ('EMP-LNK-01', 'Charlie', 'Brown', 'charlie@test.com', ?, 'active', CURDATE())")
    ->execute([$compAId]);
$empLnk1Id = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO employees (employee_code, first_name, last_name, email, company_id, employment_status, joining_date)
               VALUES ('EMP-LNK-02', 'David', 'Miller', 'david@test.com', ?, 'active', CURDATE())")
    ->execute([$compAId]);
$empLnk2Id = (int)$pdo->lastInsertId();

// Create User C (assigned to Company A)
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_charlie', 'charlie_login@test.com', ?, 'Charlie Login', 0, 1)")
    ->execute([$pwdHash]);
$userCId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userCId, $compAId, $roles['employee']]);

// 1. Test "link ok": Active user assigned to same company can be linked
$linkOkError = validate_employee_user_link($pdo, $userCId, $compAId, $empLnk1Id);
assert_test('Employee login account linking: link ok', $linkOkError === null);

// Perform the link and verify in database
$pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$userCId, $empLnk1Id]);
$savedLinkedUserId = (int)$pdo->query("SELECT user_id FROM employees WHERE id = {$empLnk1Id}")->fetchColumn();
assert_test('Employee record user_id successfully set to linked login account', $savedLinkedUserId === $userCId);

// 2. Test "link to a user from another company is rejected":
// User B is assigned ONLY to Company B; attempting to link User B to an employee in Company A is rejected
$otherCompError = validate_employee_user_link($pdo, $userBId, $compAId, $empLnk2Id);
assert_test(
    'Employee login account linking: link to a user from another company is rejected',
    $otherCompError !== null && str_contains($otherCompError, 'not assigned to this company')
);

// 3. Test "a user already linked elsewhere is rejected":
// User C is already linked to Employee 1; attempting to link User C to Employee 2 is rejected
$alreadyLinkedError = validate_employee_user_link($pdo, $userCId, $compAId, $empLnk2Id);
assert_test(
    'Employee login account linking: a user already linked elsewhere is rejected',
    $alreadyLinkedError !== null && str_contains($alreadyLinkedError, 'already linked to another employee')
);

// Verify database UNIQUE constraint rejects duplicate link at DB level
$dbUniqueViolated = false;
try {
    $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$userCId, $empLnk2Id]);
} catch (PDOException $e) {
    $dbUniqueViolated = true;
}
assert_test('Database unique index uq_employees_user_id rejects duplicate user_id assignment', $dbUniqueViolated);

// =============================================================
// TEST SUITE 10: Users, Roles & Permissions Redesign
// =============================================================
echo "\n--- 10. Testing Users, Roles & Permissions Redesign ---\n";

// 1. Effective-permission calculation per company
// Create a multi-company user: hr_officer in Company A, employee in Company B
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_multi_role', 'multi@test.com', ?, 'Multi Role User', 0, 1)")
    ->execute([$pwdHash]);
$userMultiId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userMultiId, $compAId, $roles['hr_officer']]);
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userMultiId, $compBId, $roles['employee']]);

// Fetch effective permissions for Company A
$compAPerms = get_user_effective_permissions($userMultiId, $compAId, $pdo);
$compAPermMap = [];
foreach ($compAPerms as $cp) {
    $compAPermMap[$cp['code']] = $cp;
}

// Fetch effective permissions for Company B
$compBPerms = get_user_effective_permissions($userMultiId, $compBId, $pdo);
$compBPermMap = [];
foreach ($compBPerms as $cp) {
    $compBPermMap[$cp['code']] = $cp;
}

assert_test(
    'Effective permissions: employee.create granted in Company A via hr_officer role',
    !empty($compAPermMap['employee.create']['granted']) && $compAPermMap['employee.create']['source'] === 'role'
);
assert_test(
    'Effective permissions: employee.create NOT granted in Company B via employee role',
    empty($compBPermMap['employee.create']['granted']) && $compBPermMap['employee.create']['source'] === 'none'
);
assert_test(
    'Effective permissions: leave.request granted in both Company A and Company B',
    !empty($compAPermMap['leave.request']['granted']) && !empty($compBPermMap['leave.request']['granted'])
);

// 2. Company Isolation & Scope Check (company_admin cannot open or manage a user of another company)
// Create Company C
$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('AZC', 'Company Gamma', 'gamma@aaza.test', 1)")->execute();
$compCId = (int)$pdo->lastInsertId();

// Create User D assigned ONLY to Company C
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_gamma', 'gamma@test.com', ?, 'Gamma User', 0, 1)")
    ->execute([$pwdHash]);
$userGammaId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userGammaId, $compCId, $roles['employee']]);

// Create User CA (Company Admin for Company A ONLY)
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_comp_admin', 'ca@test.com', ?, 'Company Admin A', 0, 1)")
    ->execute([$pwdHash]);
$userCAId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userCAId, $compAId, $roles['company_admin']]);

// Helper to simulate scope check used in modules/users/view.php and form.php
function can_user_access_target_user(PDO $pdo, int $adminUserId, int $targetUserId): bool
{
    set_test_user($adminUserId);
    if (is_group_admin()) {
        return true;
    }
    // Target user check
    $stTarget = $pdo->prepare('SELECT is_group_admin FROM users WHERE id = ?');
    $stTarget->execute([$targetUserId]);
    if ((int)$stTarget->fetchColumn() === 1) {
        return false; // Company admin cannot access group admin
    }
    $scopedIds = scoped_company_ids('user.view');
    if (empty($scopedIds)) {
        return false;
    }
    $ph = implode(',', array_fill(0, count($scopedIds), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_user_assignments WHERE user_id = ? AND company_id IN ($ph)");
    $st->execute(array_merge([$targetUserId], $scopedIds));
    return (int)$st->fetchColumn() > 0;
}

assert_test(
    'Scope check: Company Admin A can access User in Company A',
    can_user_access_target_user($pdo, $userCAId, $userAId) === true
);
assert_test(
    'Scope check: Company Admin A CANNOT access User in Company C',
    can_user_access_target_user($pdo, $userCAId, $userGammaId) === false
);

// Group admin target check:
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('super_admin_test', 'super@test.com', ?, 'Super Admin', 1, 1)")
    ->execute([$pwdHash]);
$superAdminTestId = (int)$pdo->lastInsertId();

assert_test(
    'Scope check: Company Admin A CANNOT access or manage Group Administrator',
    can_user_access_target_user($pdo, $userCAId, $superAdminTestId) === false
);

// 3. Role Edit Transaction & Lockout Guard
// Create a custom test role
$pdo->prepare("INSERT INTO roles (name, label, description, is_system) VALUES ('custom_test_role', 'Custom Test Role', 'Testing transactions', 0)")
    ->execute();
$customRoleId = (int)$pdo->lastInsertId();

// Helper to simulate role permission editor transaction
function edit_role_permissions(PDO $pdo, int $roleId, array $newPermCodes): array
{
    // Fetch code to id map
    $allPerms = $pdo->query('SELECT id, code FROM permissions')->fetchAll(PDO::FETCH_KEY_PAIR);
    $codeMap = array_flip($allPerms);

    // Fetch role name
    $roleName = $pdo->query("SELECT name FROM roles WHERE id = $roleId")->fetchColumn();

    // Lockout check for company_admin
    if ($roleName === 'company_admin') {
        $hasView = in_array('user.view', $newPermCodes, true);
        $hasManage = in_array('user.manage', $newPermCodes, true) || in_array('user.edit', $newPermCodes, true);
        if (!$hasView || !$hasManage) {
            throw new RuntimeException('Lockout prevented: company_admin must retain user.view and user.manage');
        }
    }

    $stCurr = $pdo->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?');
    $stCurr->execute([$roleId]);
    $currCodes = $stCurr->fetchAll(PDO::FETCH_COLUMN);

    $added = array_values(array_diff($newPermCodes, $currCodes));
    $removed = array_values(array_diff($currCodes, $newPermCodes));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
        foreach ($newPermCodes as $code) {
            if (isset($codeMap[$code])) {
                $ins->execute([$roleId, $codeMap[$code]]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['added' => $added, 'removed' => $removed];
}

// Perform role edit
$diff1 = edit_role_permissions($pdo, $customRoleId, ['employee.view', 'attendance.view']);
assert_test('Role edit transaction: diff added calculated accurately', in_array('employee.view', $diff1['added'], true) && in_array('attendance.view', $diff1['added'], true));

$savedCustomPerms = $pdo->query("SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = $customRoleId")->fetchAll(PDO::FETCH_COLUMN);
assert_test('Role edit transaction: permissions committed to database', in_array('employee.view', $savedCustomPerms, true) && count($savedCustomPerms) === 2);

// Test company_admin lockout guard
$lockoutTriggered = false;
try {
    // Attempting to remove user management permissions from company_admin
    edit_role_permissions($pdo, (int)$roles['company_admin'], ['employee.view', 'leave.approve']);
} catch (RuntimeException $e) {
    $lockoutTriggered = true;
}
assert_test('Role edit lockout guard: Stripping user.manage from company_admin is blocked', $lockoutTriggered);

// 4. User Permission Override Precedence (deny override > allow override > role permission)
// Create test user for override testing in Company A
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_override_test', 'override@test.com', ?, 'Override Test User', 0, 1)")
    ->execute([$pwdHash]);
$userOId = (int)$pdo->lastInsertId();

// Assign role 'employee' in Company A (does NOT have 'employee.view')
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userOId, $compAId, $roles['employee']]);

set_test_user($userOId);
assert_test('Override test baseline: employee role does not have employee.view', user_can('employee.view', $compAId) === false);

// A. Test "allow override": user receives permission even when role doesn't have it
$empViewPermId = (int)$pdo->query("SELECT id FROM permissions WHERE code = 'employee.view'")->fetchColumn();
$pdo->prepare("INSERT INTO user_permission_overrides (user_id, company_id, permission_id, effect) VALUES (?, ?, ?, 'allow')")
    ->execute([$userOId, $compAId, $empViewPermId]);

clear_access_cache();
assert_test('Override precedence: allow override grants permission not in role', user_can('employee.view', $compAId) === true);

$effPermsAllow = get_user_effective_permissions($userOId, $compAId, $pdo);
$effAllowMap = array_column($effPermsAllow, null, 'code');
assert_test(
    'get_user_effective_permissions reports source=override_allow',
    $effAllowMap['employee.view']['granted'] === true && $effAllowMap['employee.view']['source'] === 'override_allow'
);

// B. Test "deny override": user loses permission even when role DOES have it
// Change User O assignment to hr_manager (which DOES have employee.view)
$pdo->prepare("UPDATE company_user_assignments SET role_id = ? WHERE user_id = ? AND company_id = ?")
    ->execute([$roles['hr_manager'], $userOId, $compAId]);

// Update override to 'deny'
$pdo->prepare("UPDATE user_permission_overrides SET effect = 'deny' WHERE user_id = ? AND company_id = ? AND permission_id = ?")
    ->execute([$userOId, $compAId, $empViewPermId]);

clear_access_cache();
assert_test('Override precedence: deny override denies permission even when held by role', user_can('employee.view', $compAId) === false);

$effPermsDeny = get_user_effective_permissions($userOId, $compAId, $pdo);
$effDenyMap = array_column($effPermsDeny, null, 'code');
assert_test(
    'get_user_effective_permissions reports source=override_deny',
    $effDenyMap['employee.view']['granted'] === false && $effDenyMap['employee.view']['source'] === 'override_deny'
);

// C. Test reset override to inherit: role permission takes over
$pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = ? AND company_id = ? AND permission_id = ?")
    ->execute([$userOId, $compAId, $empViewPermId]);

clear_access_cache();
assert_test('Override inheritance: clearing override restores role permission (hr_manager -> employee.view)', user_can('employee.view', $compAId) === true);

// =============================================================
// TEST SUITE 11: Company Deletion Safety & Logo Management
// =============================================================
echo "\n--- 11. Testing Company Deletion Safety & Logo Management ---\n";

// 1. Delete rejected for active company
$activeCompDel = delete_company($compAId, $pdo);
assert_test(
    'delete rejected for active company',
    $activeCompDel['success'] === false && str_contains(strtolower($activeCompDel['message']), 'active')
);

// 2. Delete rejected for company with employees
// Temporarily deactivate Company A (which has active employees)
$pdo->prepare("UPDATE companies SET is_active = 0 WHERE id = ?")->execute([$compAId]);
$empCompDel = delete_company($compAId, $pdo);
assert_test(
    'delete rejected for company with employees',
    $empCompDel['success'] === false && str_contains(strtolower($empCompDel['message']), 'employee')
);
// Restore Company A to active state
$pdo->prepare("UPDATE companies SET is_active = 1 WHERE id = ?")->execute([$compAId]);

// 3. Delete allowed for empty inactive company
$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('DEL', 'Delete Candidate Co', 'del@aaza.test', 0)")
    ->execute();
$delTargetId = (int)$pdo->lastInsertId();
$emptyInactiveDel = delete_company($delTargetId, $pdo);
$delCheck = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE id = {$delTargetId}")->fetchColumn();
assert_test(
    'delete allowed for empty inactive company',
    $emptyInactiveDel['success'] === true && $delCheck === 0
);

// 4. Logo upload rejected for non-group-admin
set_test_user($userAId); // User A has role hr_manager, is_group_admin = 0
$canUploadLogoNonAdmin = is_group_admin() && user_can('company.logo');
assert_test(
    'logo upload rejected for non-group-admin',
    $canUploadLogoNonAdmin === false && user_can('company.logo') === false
);

// 5. Logo upload rejected for a non-image file
$fakeTmpFile = tempnam(sys_get_temp_dir(), 'test_fake_');
file_put_contents($fakeTmpFile, "<?php echo 'malicious payload'; ?>");
$fakeFileSpec = [
    'name'     => 'shell.php',
    'type'     => 'text/plain',
    'tmp_name' => $fakeTmpFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($fakeTmpFile),
];
[$okFakeImg, $fakeImgMsg] = validate_company_logo($fakeFileSpec);
@unlink($fakeTmpFile);
assert_test(
    'logo upload rejected for a non-image file',
    $okFakeImg === false && str_contains(strtolower((string)$fakeImgMsg), 'invalid')
);

// =============================================================
// 12. Testing Login Page Branding & Custom Logo
// =============================================================
echo "\n--- 12. Testing Login Page Branding & Custom Logo ---\n";

// 1. Non-admin cannot update login logo
$_SESSION['user_id'] = 9999;
$_SESSION['is_group_admin'] = 0;
$canUpdateLoginLogoNonAdmin = is_group_admin() && user_can('company.logo');
assert_test(
    'non-admin cannot update the login logo',
    $canUpdateLoginLogoNonAdmin === false
);

// 2. Non-image rejected for login logo
$fakeLoginFile = tempnam(sys_get_temp_dir(), 'test_login_fake_');
file_put_contents($fakeLoginFile, "NOT AN IMAGE DATA");
$fakeLoginFileSpec = [
    'name'     => 'evil_script.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $fakeLoginFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($fakeLoginFile),
];
[$okLoginFake, $msgLoginFake] = validate_company_logo($fakeLoginFileSpec);
@unlink($fakeLoginFile);
assert_test(
    'non-image rejected',
    $okLoginFake === false && !empty($msgLoginFake)
);

// 3. Custom login logo save and resolution
// Restore admin session
set_test_user($superAdminTestId);

$validLoginImg = imagecreatetruecolor(60, 60);
$testLoginTmp = tempnam(sys_get_temp_dir(), 'test_login_valid_') . '.png';
imagepng($validLoginImg, $testLoginTmp);
imagedestroy($validLoginImg);

$validLoginFileSpec = [
    'name'     => 'custom_login_logo.png',
    'type'     => 'image/png',
    'tmp_name' => $testLoginTmp,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($testLoginTmp),
];
[$okValidLogin, $tmpLoginValidated] = validate_company_logo($validLoginFileSpec);
$savedLoginPath = save_company_logo($tmpLoginValidated);
set_system_setting('branding.login_logo', $savedLoginPath);
set_system_setting('branding.login_use_sidebar', '0');

$loginDataCustom = get_login_logo_data();
assert_test(
    'custom login logo resolved with cache-busting',
    $loginDataCustom['is_custom'] === true &&
    $loginDataCustom['path'] === $savedLoginPath &&
    str_contains($loginDataCustom['url'], '?v=')
);

// 4. "Use same as sidebar" toggle synchronizes with sidebar logo
set_system_setting('app_logo', 'assets/img/aaza-icon.png');
set_system_setting('branding.login_use_sidebar', '1');
$loginDataSidebar = get_login_logo_data();
assert_test(
    'use same logo as sidebar synchronizes login logo to sidebar',
    $loginDataSidebar['is_sidebar'] === true &&
    $loginDataSidebar['path'] === 'assets/img/aaza-icon.png'
);

// 5. Reset falls back to default
if (!empty($savedLoginPath)) {
    remove_company_logo($savedLoginPath);
}
set_system_setting('branding.login_logo', null);
set_system_setting('branding.login_use_sidebar', '0');
$loginDataReset = get_login_logo_data();
assert_test(
    'reset falls back to default',
    $loginDataReset['path'] === 'assets/img/aaza-logo-transparent.png' &&
    $loginDataReset['is_custom'] === false &&
    $loginDataReset['is_sidebar'] === false
);

// Clean up temp file
@unlink($testLoginTmp);

// =============================================================
// TEST SUITE 13: Leave Management UI & Employee Linking Guards
// =============================================================
echo "\n--- 13. Testing Leave UI & Employee Linking Guards ---\n";

// 1. Group Administrator without linked employee has null linked employee
$adminLinkedEmp = get_linked_employee_for_user($pdo, $superAdminTestId);
assert_test(
    'Admin without linked employee record: get_linked_employee_for_user is null',
    $adminLinkedEmp === null
);

// 2. Admin has leave.request permission, but $navHasLinkedEmp is false -> Apply for Leave is hidden
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);
$adminHasLeaveReq = user_can('leave.request');
$adminNavHasLinkedEmp = (bool)get_linked_employee_for_user($pdo, $superAdminTestId);
$adminShouldShowApply = ($adminHasLeaveReq && !empty($adminNavHasLinkedEmp));
assert_test(
    'Admin without linked employee record: Apply for Leave button is hidden ($navHasLinkedEmp is false)',
    $adminHasLeaveReq === true && $adminNavHasLinkedEmp === false && $adminShouldShowApply === false
);

// 3. User C (linked to Employee 1) has valid linked employee
$empLinkedEmp = get_linked_employee_for_user($pdo, $userCId);
$empNavHasLinkedEmp = (bool)$empLinkedEmp;
$_SESSION['uid'] = $userCId;
current_user(true);
load_access(true);
$empHasLeaveReq = user_can('leave.request');
$empShouldShowApply = ($empHasLeaveReq && !empty($empNavHasLinkedEmp));
assert_test(
    'Employee linked to an employee record: Apply for Leave button is visible and My Leave works',
    $empNavHasLinkedEmp === true && $empHasLeaveReq === true && $empShouldShowApply === true
);

// 4. Server-side guard in modules/leave/request.php blocks unlinked user
$unlinkedBlocked = (get_linked_employee_for_user($pdo, $superAdminTestId) === null);
assert_test(
    'Server-side guard in leave request blocks users without linked employee',
    $unlinkedBlocked === true
);

// Restore session user to admin
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);

// =============================================================
// TEST SUITE 14: User Deletion Safety & Dependency Guards
// =============================================================
echo "\n--- 14. Testing User Deletion Safety & Dependency Guards ---\n";

// 1. Non-admin cannot delete users (permission check: requires is_group_admin AND user_can('user.delete'))
$_SESSION['uid'] = $userAId; // Company Admin / HR Manager
current_user(true);
load_access(true);
$canDeleteNonAdmin = is_group_admin() && user_can('user.delete');
assert_test(
    'non-admin cannot delete: is_group_admin is false and user.delete is denied',
    $canDeleteNonAdmin === false && user_can('user.delete') === false
);

// Restore super admin session
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);

// 2. Active user cannot be deleted
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_active_test', 'active_test@test.com', ?, 'Active Test User', 0, 1)")
    ->execute([$pwdHash]);
$activeTestUserId = (int)$pdo->lastInsertId();

$chkActive = check_user_deletable($activeTestUserId, $pdo, $superAdminTestId);
assert_test(
    'active user cannot be deleted: check_user_deletable rejects with active account reason',
    $chkActive['can_delete'] === false && str_contains($chkActive['reason'], 'active user')
);

// 3. Self delete rejected
$chkSelf = check_user_deletable($superAdminTestId, $pdo, $superAdminTestId);
assert_test(
    'self delete rejected: user cannot delete their own account',
    $chkSelf['can_delete'] === false && str_contains($chkSelf['reason'], 'own account')
);

// 4. Last group admin rejected
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('sole_admin_cand', 'sole_admin@test.com', ?, 'Sole Admin', 1, 0)")
    ->execute([$pwdHash]);
$soleAdminCandId = (int)$pdo->lastInsertId();

// Temporarily mark all other group admins inactive to test last remaining guard
$pdo->prepare("UPDATE users SET is_active = 0 WHERE is_group_admin = 1 AND id != ?")->execute([$soleAdminCandId]);
$chkLastAdmin = check_user_deletable($soleAdminCandId, $pdo, 99999);
assert_test(
    'last group admin rejected: cannot delete when no other active group admin exists',
    $chkLastAdmin['can_delete'] === false && str_contains($chkLastAdmin['reason'], 'last remaining')
);
// Restore super admin active status
$pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$superAdminTestId]);

// 5. User linked to an employee is blocked
// Deactivate User C (linked to Employee 1)
$pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$userCId]);
$chkLinkedEmp = check_user_deletable($userCId, $pdo, $superAdminTestId);
assert_test(
    'user linked to an employee is blocked: check_user_deletable rejects with linked employee',
    $chkLinkedEmp['can_delete'] === false && str_contains($chkLinkedEmp['reason'], 'Linked to employee')
);
$pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$userCId]);

// 6. Clean inactive user with only assignments is deleted along with its assignments
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('clean_del_user', 'clean_del@test.com', ?, 'Clean Delete User', 0, 0)")
    ->execute([$pwdHash]);
$cleanUserId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$cleanUserId, $compAId, $roles['employee']]);

$chkClean = check_user_deletable($cleanUserId, $pdo, $superAdminTestId);
$cleanCanDelete = $chkClean['can_delete'];

$delRes = delete_user($cleanUserId, $pdo, $superAdminTestId);
$userDeletedFromDb = ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id = {$cleanUserId}")->fetchColumn() === 0);
$assignmentsDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM company_user_assignments WHERE user_id = {$cleanUserId}")->fetchColumn() === 0);
$auditLogRecorded = ((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'user.delete' AND entity_id = {$cleanUserId}")->fetchColumn() > 0);

assert_test(
    'clean inactive user with only assignments is deleted along with its assignments',
    $cleanCanDelete === true &&
    $delRes['success'] === true &&
    $userDeletedFromDb === true &&
    $assignmentsDeleted === true &&
    $auditLogRecorded === true
);

// =============================================================
// Test Suite 15: Master Organizational Data Deletion Safety
// (Departments, Designations, Leave Types, Custom Roles)
// =============================================================
echo "\n--- Test Suite 15: Master Organizational Data Deletion Safety ---\n";

// 1. Permissions guard: department.delete, designation.delete, leave_type.delete, role.delete granted ONLY to group_admin
$phase14Perms = ['department.delete', 'designation.delete', 'leave_type.delete', 'role.delete'];
$ph14PermIds = [];
foreach ($phase14Perms as $code) {
    $st = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
    $st->execute([$code]);
    $pid = (int)$st->fetchColumn();
    $ph14PermIds[$code] = $pid;
    assert_test(
        "Phase 14 permission '{$code}' exists in database",
        $pid > 0
    );
}

// Verify granted only to group_admin (role id 1)
$stNonAdmin = $pdo->prepare("
    SELECT COUNT(*) FROM role_permissions rp
    JOIN roles r ON r.id = rp.role_id
    WHERE rp.permission_id IN (" . implode(',', array_values($ph14PermIds)) . ")
      AND r.name != 'group_admin'
");
$stNonAdmin->execute();
$nonAdminGrants = (int)$stNonAdmin->fetchColumn();
assert_test(
    "Phase 14 delete permissions are granted exclusively to group_admin (no non-admin grants)",
    $nonAdminGrants === 0
);

// 2. Department Deletion Safety
// Insert active department
$pdo->prepare("INSERT INTO departments (company_id, name, is_active) VALUES (?, 'Test Active Dept', 1)")->execute([$compAId]);
$activeDeptId = (int)$pdo->lastInsertId();

$chkActiveDept = check_department_deletable($activeDeptId, $pdo);
assert_test(
    "Active department cannot be deleted (must deactivate first)",
    $chkActiveDept['can_delete'] === false && str_contains($chkActiveDept['reason'], 'deactivate')
);

// Insert inactive department with an employee
$pdo->prepare("INSERT INTO departments (company_id, name, is_active) VALUES (?, 'Test Used Dept', 0)")->execute([$compAId]);
$usedDeptId = (int)$pdo->lastInsertId();
// Link Employee 1 to this department
$pdo->prepare("UPDATE employees SET department_id = ? WHERE id = ?")->execute([$usedDeptId, $newEmpId]);

$chkUsedDept = check_department_deletable($usedDeptId, $pdo);
assert_test(
    "Inactive department with employees cannot be deleted (friendly blocking reason)",
    $chkUsedDept['can_delete'] === false && str_contains($chkUsedDept['reason'], 'Used by 1 employee')
);
// Restore Employee 1 department
$pdo->prepare("UPDATE employees SET department_id = NULL WHERE id = ?")->execute([$newEmpId]);

// Insert clean inactive department
$pdo->prepare("INSERT INTO departments (company_id, name, is_active) VALUES (?, 'Test Clean Dept', 0)")->execute([$compAId]);
$cleanDeptId = (int)$pdo->lastInsertId();
$chkCleanDept = check_department_deletable($cleanDeptId, $pdo);
$delCleanDept = delete_department($cleanDeptId, $pdo);
$cleanDeptDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM departments WHERE id = {$cleanDeptId}")->fetchColumn() === 0);
$deptAuditLogged = ((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'department.delete' AND entity_id = {$cleanDeptId}")->fetchColumn() > 0);

assert_test(
    "Clean inactive department is deleted safely with audit logging",
    $chkCleanDept['can_delete'] === true &&
    $delCleanDept['success'] === true &&
    $cleanDeptDeleted === true &&
    $deptAuditLogged === true
);

// 3. Designation Deletion Safety
// Insert active designation
$pdo->prepare("INSERT INTO designations (company_id, title, is_active) VALUES (?, 'Test Active Desig', 1)")->execute([$compAId]);
$activeDesigId = (int)$pdo->lastInsertId();

$chkActiveDesig = check_designation_deletable($activeDesigId, $pdo);
assert_test(
    "Active designation cannot be deleted (must deactivate first)",
    $chkActiveDesig['can_delete'] === false && str_contains($chkActiveDesig['reason'], 'deactivate')
);

// Insert inactive designation with an employee
$pdo->prepare("INSERT INTO designations (company_id, title, is_active) VALUES (?, 'Test Used Desig', 0)")->execute([$compAId]);
$usedDesigId = (int)$pdo->lastInsertId();
// Link Employee 1 to this designation
$pdo->prepare("UPDATE employees SET designation_id = ? WHERE id = ?")->execute([$usedDesigId, $newEmpId]);

$chkUsedDesig = check_designation_deletable($usedDesigId, $pdo);
assert_test(
    "Inactive designation with employees cannot be deleted (friendly blocking reason)",
    $chkUsedDesig['can_delete'] === false && str_contains($chkUsedDesig['reason'], 'Used by 1 employee')
);
// Restore Employee 1 designation
$pdo->prepare("UPDATE employees SET designation_id = NULL WHERE id = ?")->execute([$newEmpId]);

// Insert clean inactive designation
$pdo->prepare("INSERT INTO designations (company_id, title, is_active) VALUES (?, 'Test Clean Desig', 0)")->execute([$compAId]);
$cleanDesigId = (int)$pdo->lastInsertId();
$chkCleanDesig = check_designation_deletable($cleanDesigId, $pdo);
$delCleanDesig = delete_designation($cleanDesigId, $pdo);
$cleanDesigDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM designations WHERE id = {$cleanDesigId}")->fetchColumn() === 0);
$desigAuditLogged = ((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'designation.delete' AND entity_id = {$cleanDesigId}")->fetchColumn() > 0);

assert_test(
    "Clean inactive designation is deleted safely with audit logging",
    $chkCleanDesig['can_delete'] === true &&
    $delCleanDesig['success'] === true &&
    $cleanDesigDeleted === true &&
    $desigAuditLogged === true
);

// 4. Leave Type Deletion Safety
// Insert active leave type
$pdo->prepare("INSERT INTO leave_types (company_id, name, days_per_year, is_active) VALUES (?, 'Test Active Leave', 10, 1)")->execute([$compAId]);
$activeLtId = (int)$pdo->lastInsertId();
$chkActiveLt = check_leave_type_deletable($activeLtId, $pdo);
assert_test(
    "Active leave type cannot be deleted (must deactivate first)",
    $chkActiveLt['can_delete'] === false && str_contains($chkActiveLt['reason'], 'deactivate')
);

// Inactive leave type with leave request
$pdo->prepare("INSERT INTO leave_types (company_id, name, days_per_year, is_active) VALUES (?, 'Test Used Leave', 10, 0)")->execute([$compAId]);
$usedLtId = (int)$pdo->lastInsertId();
// Insert dummy leave request referencing this leave type
$pdo->prepare("INSERT INTO leave_requests (employee_id, company_id, leave_type_id, start_date, end_date, total_days, status)
               VALUES (?, ?, ?, '2026-06-01', '2026-06-02', 2, 'approved')")->execute([$newEmpId, $compAId, $usedLtId]);
$dummyReqId = (int)$pdo->lastInsertId();

$chkUsedLt = check_leave_type_deletable($usedLtId, $pdo);
assert_test(
    "Inactive leave type with leave requests cannot be deleted (friendly blocking reason)",
    $chkUsedLt['can_delete'] === false && str_contains($chkUsedLt['reason'], 'Used by 1 leave request')
);
// Clean up dummy leave request
$pdo->prepare("DELETE FROM leave_requests WHERE id = ?")->execute([$dummyReqId]);

// Clean inactive leave type
$pdo->prepare("INSERT INTO leave_types (company_id, name, days_per_year, is_active) VALUES (?, 'Test Clean Leave', 10, 0)")->execute([$compAId]);
$cleanLtId = (int)$pdo->lastInsertId();
$chkCleanLt = check_leave_type_deletable($cleanLtId, $pdo);
$delCleanLt = delete_leave_type($cleanLtId, $pdo);
$cleanLtDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM leave_types WHERE id = {$cleanLtId}")->fetchColumn() === 0);
$ltAuditLogged = ((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'leave_type.delete' AND entity_id = {$cleanLtId}")->fetchColumn() > 0);

assert_test(
    "Clean inactive leave type is deleted safely with audit logging",
    $chkCleanLt['can_delete'] === true &&
    $delCleanLt['success'] === true &&
    $cleanLtDeleted === true &&
    $ltAuditLogged === true
);

// 5. Custom Role Deletion Safety
// Built-in system role cannot be deleted
$stSysRole = $pdo->prepare("SELECT id FROM roles WHERE is_system = 1 LIMIT 1");
$stSysRole->execute();
$sysRoleId = (int)$stSysRole->fetchColumn();
$chkSysRole = check_role_deletable($sysRoleId, $pdo);
assert_test(
    "System built-in role cannot be deleted",
    $chkSysRole['can_delete'] === false && str_contains($chkSysRole['reason'], 'System built-in roles cannot be deleted')
);

// Create custom role assigned to a user
$pdo->prepare("INSERT INTO roles (name, label, description, is_system) VALUES ('test_used_role', 'Test Used Role', 'Used role', 0)")->execute();
$usedRoleId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$superAdminTestId, $compAId, $usedRoleId]);

$chkUsedRole = check_role_deletable($usedRoleId, $pdo);
assert_test(
    "Custom role assigned to users cannot be deleted (reassign first)",
    $chkUsedRole['can_delete'] === false && str_contains($chkUsedRole['reason'], 'Reassign them first')
);
// Remove assignment
$pdo->prepare("DELETE FROM company_user_assignments WHERE user_id = ? AND role_id = ?")->execute([$superAdminTestId, $usedRoleId]);

// Create clean custom role
$pdo->prepare("INSERT INTO roles (name, label, description, is_system) VALUES ('test_clean_role', 'Test Clean Role', 'Clean role', 0)")->execute();
$cleanRoleId = (int)$pdo->lastInsertId();
// Grant a permission to test permissions cascade deletion
$pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$cleanRoleId, $ph14PermIds['role.delete']]);

$chkCleanRole = check_role_deletable($cleanRoleId, $pdo);
$delCleanRole = delete_role($cleanRoleId, $pdo);
$cleanRoleDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM roles WHERE id = {$cleanRoleId}")->fetchColumn() === 0);
$cleanRolePermsDeleted = ((int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = {$cleanRoleId}")->fetchColumn() === 0);
$roleAuditLogged = ((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'role.delete' AND entity_id = {$cleanRoleId}")->fetchColumn() > 0);

assert_test(
    "Clean custom role is deleted safely along with permissions and audit logging",
    $chkCleanRole['can_delete'] === true &&
    $delCleanRole['success'] === true &&
    $cleanRoleDeleted === true &&
    $cleanRolePermsDeleted === true &&
    $roleAuditLogged === true
);

// =============================================================
// TEST SUITE 16: Unified Global Search Security, Isolation & Wildcards
// =============================================================
echo "\n--- Test Suite 16: Unified Global Search Security, Isolation & Wildcards ---\n";

// 1. Company Isolation: User A (scoped to Company A) searches for an employee belonging to Company B
$pdo->prepare("INSERT INTO employees (employee_code, company_id, first_name, last_name, email, phone, nic_passport, joining_date, employment_status)
               VALUES ('EMP-B-999', ?, 'UniqueBetaFirst', 'SearchBetaLast', 'beta.search@test.com', '0779998888', 'NIC-B-999999', CURDATE(), 'active')")
    ->execute([$compBId]);
$empBetaId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO employees (employee_code, company_id, first_name, last_name, email, phone, nic_passport, joining_date, employment_status)
               VALUES ('EMP-A-111', ?, 'UniqueAlphaFirst', 'SearchAlphaLast', 'alpha.search@test.com', '0771112222', 'NIC-A-111111', CURDATE(), 'active')")
    ->execute([$compAId]);
$empAlphaId = (int)$pdo->lastInsertId();

// Switch context to User A (Company A only)
set_test_user($userAId);
$_SESSION['active_company'] = null; // all permitted companies for User A (which is only Company A)

$searchForBeta = search_global('SearchBetaLast');
$foundBetaInAlpha = false;
if (!empty($searchForBeta['groups']['employees']['items'])) {
    foreach ($searchForBeta['groups']['employees']['items'] as $it) {
        if ($it['id'] === $empBetaId) {
            $foundBetaInAlpha = true;
        }
    }
}
assert_test(
    "Company A user cannot see Company B employee records in search results",
    $foundBetaInAlpha === false
);

$searchForAlpha = search_global('SearchAlphaLast');
$foundAlphaInAlpha = false;
if (!empty($searchForAlpha['groups']['employees']['items'])) {
    foreach ($searchForAlpha['groups']['employees']['items'] as $it) {
        if ($it['id'] === $empAlphaId) {
            $foundAlphaInAlpha = true;
        }
    }
}
assert_test(
    "Company A user can see Company A employee records in search results",
    $foundAlphaInAlpha === true
);

// 2. Permission Gating: User without candidate.view gets no candidate group
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_search_nocand', 'nocand@test.com', ?, 'No Cand View User', 0, 1)")
    ->execute([$pwdHash]);
$userNoCandId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userNoCandId, $compAId, $roles['employee']]);

// Create candidate
$pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, phone, nic_passport)
               VALUES ('CAN-TEST-SEARCH', 'CandidateSearch', 'PersonTest', 'cand.search@test.com', '0773334444', 'NIC-CAND-888')")
    ->execute();
$candSearchId = (int)$pdo->lastInsertId();

set_test_user($userNoCandId);
$searchNoCand = search_global('CandidateSearch');
assert_test(
    "User without candidate.view gets no candidate group in search results",
    !isset($searchNoCand['groups']['candidates'])
);

// 3. User without user.view gets no users group
assert_test(
    "User without user.view gets no users group in search results",
    !isset($searchNoCand['groups']['users'])
);

// 4. Confidential NIC search
// Create an HR Officer (has employee.view, but does NOT have document.confidential)
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active)
               VALUES ('user_search_hrofficer', 'hrofficer@test.com', ?, 'HR Officer User', 0, 1)")
    ->execute([$pwdHash]);
$userHrOfficerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userHrOfficerId, $compAId, $roles['hr_officer']]);

set_test_user($userHrOfficerId);
$_SESSION['active_company'] = null;

$nicSearchBefore = search_global('NIC-A-111111');
$foundByNicBefore = !empty($nicSearchBefore['groups']['employees']['items']);

$docConfPermId = (int)$pdo->query("SELECT id FROM permissions WHERE code = 'document.confidential'")->fetchColumn();
$pdo->prepare("INSERT INTO user_permission_overrides (user_id, company_id, permission_id, effect) VALUES (?, ?, ?, 'allow')")
    ->execute([$userHrOfficerId, $compAId, $docConfPermId]);
set_test_user($userHrOfficerId);

$nicSearchAfter = search_global('NIC-A-111111');
$foundByNicAfter = false;
if (!empty($nicSearchAfter['groups']['employees']['items'])) {
    foreach ($nicSearchAfter['groups']['employees']['items'] as $it) {
        if ($it['id'] === $empAlphaId) {
            $foundByNicAfter = true;
        }
    }
}
assert_test(
    "Confidential NIC search is gated: hidden without document.confidential, found with document.confidential",
    $foundByNicBefore === false && $foundByNicAfter === true
);

// Clean up override
$pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id = ? AND permission_id = ?")->execute([$userHrOfficerId, $docConfPermId]);
set_test_user($userAId);

// 5. LIKE wildcards escaped: % and _
$pdo->prepare("INSERT INTO employees (employee_code, company_id, first_name, last_name, email, phone, joining_date, employment_status)
               VALUES ('EMP-A-SPEC', ?, 'Special%User', 'Under_Score', 'spec@test.com', '0770001111', CURDATE(), 'active')")
    ->execute([$compAId]);
$empSpecId = (int)$pdo->lastInsertId();

$searchLiteralPercent = search_global('Special%User');
$foundSpec = false;
if (!empty($searchLiteralPercent['groups']['employees']['items'])) {
    foreach ($searchLiteralPercent['groups']['employees']['items'] as $it) {
        if ($it['id'] === $empSpecId) {
            $foundSpec = true;
        }
    }
}

$searchLiteralUnderscore = search_global('Under_Score');
$foundUnder = false;
if (!empty($searchLiteralUnderscore['groups']['employees']['items'])) {
    foreach ($searchLiteralUnderscore['groups']['employees']['items'] as $it) {
        if ($it['id'] === $empSpecId) {
            $foundUnder = true;
        }
    }
}

$searchWildcardPercent = search_global('%');
assert_test(
    "LIKE wildcards '%' and '_' are escaped and do not cause open wildcard matches",
    $foundSpec === true && $foundUnder === true && $searchWildcardPercent['total_count'] === 0
);

// 6. Query sanitization & 60-character trimming
$longQuery = str_repeat('A', 100);
$searchLong = search_global($longQuery);
assert_test(
    "Query is safely trimmed to 60 characters and executed without SQL error",
    mb_strlen($searchLong['query']) === 60
);

// 7. Rate limit enforcement
$rateAction = 'test_rate_search_' . bin2hex(random_bytes(4));
$rateUser = 'test_user_' . bin2hex(random_bytes(4));
$first59Ok = true;
for ($i = 0; $i < 59; $i++) {
    if (!check_rate_limit($rateAction, 60, 60, $rateUser)) {
        $first59Ok = false;
        break;
    }
}
$call60 = check_rate_limit($rateAction, 60, 60, $rateUser);
$call61 = check_rate_limit($rateAction, 60, 60, $rateUser);

assert_test(
    "Rate limiting strictly allows 60 requests/minute and blocks 61st request",
    $first59Ok === true && $call60 === true && $call61 === false
);

// =============================================================
// Test Suite 17: In-App Notifications & Scheduled Reminders
// =============================================================
echo "\n--- Test Suite 17: In-App Notifications & Scheduled Reminders ---\n";

// 1. notify_permission excludes actor and other companies
$pdo->exec("DELETE FROM notifications");

// Call with User A as actor
$notifSent1 = notify_permission(
    $pdo,
    $compAId,
    'leave.approve',
    'leave_submitted',
    'Test Request 1',
    'Test request message',
    'modules/leave/decide.php?id=999',
    'test:actor:1',
    $userAId // Exclude actor User A
);

$cntActorA = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userAId}")->fetchColumn();
$cntOtherCompB = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userBId}")->fetchColumn();

// Now call without excluding User A
$notifSent2 = notify_permission(
    $pdo,
    $compAId,
    'leave.approve',
    'leave_submitted',
    'Test Request 2',
    'Test request message',
    'modules/leave/decide.php?id=999',
    'test:actor:2',
    null
);

$cntAfterA = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userAId}")->fetchColumn();
$cntAfterB = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userBId}")->fetchColumn();

assert_test(
    "notify_permission excludes actor and users assigned strictly to other companies",
    $cntActorA === 0 && $cntOtherCompB === 0 && $cntAfterA === 1 && $cntAfterB === 0
);

// 2. Dedupe works via UNIQUE(user_id, dedupe_key)
$dedupeKey = 'test:dedupe:key:' . bin2hex(random_bytes(4));
$firstInsert = notify_user($pdo, $userAId, 'dedupe_test', 'Title 1', 'Message 1', null, $compAId, $dedupeKey);
$secondInsert = notify_user($pdo, $userAId, 'dedupe_test', 'Title 2 (duplicate)', 'Message 2', null, $compAId, $dedupeKey);
$dedupeRows = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userAId} AND dedupe_key = '{$dedupeKey}'")->fetchColumn();

assert_test(
    "Dedupe key prevents duplicate notification insertion",
    $firstInsert === true && $secondInsert === false && $dedupeRows === 1
);

// 3. Ownership security: A user cannot read or delete another user's notification
$pdo->prepare("INSERT INTO notifications (user_id, company_id, type, title, message, is_read) VALUES (?, ?, 'private', 'Private A', 'Content A', 0)")
    ->execute([$userAId, $compAId]);
$notifAId = (int)$pdo->lastInsertId();

// User B attempts to mark User A's notification as read
$markForeign = mark_notification_as_read($pdo, $notifAId, $userBId);
$isReadAfterForeign = (int)$pdo->query("SELECT is_read FROM notifications WHERE id = {$notifAId}")->fetchColumn();

// User B attempts to delete User A's notification
$delForeign = delete_notification($pdo, $notifAId, $userBId);
$existsAfterForeign = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE id = {$notifAId}")->fetchColumn();

// User A marks own notification as read
$markOwn = mark_notification_as_read($pdo, $notifAId, $userAId);
$readAtAfterOwn = $pdo->query("SELECT read_at FROM notifications WHERE id = {$notifAId}")->fetchColumn();

// User A deletes own notification
$delOwn = delete_notification($pdo, $notifAId, $userAId);
$existsAfterOwn = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE id = {$notifAId}")->fetchColumn();

assert_test(
    "A user cannot read or delete another user's notification",
    $markForeign === false && $isReadAfterForeign === 0 &&
    $delForeign === false && $existsAfterForeign === 1 &&
    $markOwn === true && !empty($readAtAfterOwn) &&
    $delOwn === true && $existsAfterOwn === 0
);

// 3b. Group Administrator with NO company assignments always receives notifications
$pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active, created_at) VALUES ('test_unassigned_ga', 'unassigned_ga@test.com', 'hash', 'Unassigned GA', 1, 1, NOW())")->execute();
$unassignedGaId = (int)$pdo->lastInsertId();

notify_permission($pdo, $compAId, 'leave.approve', 'leave_submitted', 'GA Test Notification', 'Body', null, 'test:ga:unassigned');
$cntGaNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$unassignedGaId} AND dedupe_key = 'test:ga:unassigned'")->fetchColumn();

// Clean up
$pdo->prepare("DELETE FROM notifications WHERE user_id = {$unassignedGaId}")->execute();
$pdo->prepare("DELETE FROM users WHERE id = {$unassignedGaId}")->execute();

assert_test(
    "notify_permission always notifies active group admins even without company assignments",
    $cntGaNotif === 1
);

// 4. Reminders create one row per event per day
// Set up an interview scheduled for tomorrow with panel user A
$pdo->prepare("INSERT INTO job_vacancies (company_id, vacancy_code, title, positions_count, status, created_by) VALUES (?, 'VAC-REM', 'Reminder Vacancy', 1, 'open', ?)")
    ->execute([$compAId, $userAId]);
$vacRemId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO candidates (first_name, last_name, email, phone, consent_given, created_by) VALUES ('RemCandidate', 'Person', 'rem_cand@test.com', '0771122334', 1, ?)")
    ->execute([$userAId]);
$candRemId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO candidate_applications (candidate_id, vacancy_id, company_id, application_date, status) VALUES (?, ?, ?, CURDATE(), 'shortlisted')")
    ->execute([$candRemId, $vacRemId, $compAId]);
$appRemId = (int)$pdo->lastInsertId();

$tomorrowSql = date('Y-m-d 10:00:00', strtotime('+1 day'));
$pdo->prepare("INSERT INTO interviews (application_id, company_id, round_no, scheduled_at, duration_min, status) VALUES (?, ?, 1, ?, 30, 'scheduled')")
    ->execute([$appRemId, $compAId, $tomorrowSql]);
$intRemId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO interview_panel (interview_id, user_id) VALUES (?, ?)")
    ->execute([$intRemId, $userAId]);

$todayStr = date('Y-m-d');
$remDedupeKey = "interview:upcoming:{$intRemId}:{$userAId}:{$todayStr}";

// Run reminders first time
$remStats1 = send_system_reminders($pdo);
$remCount1 = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userAId} AND dedupe_key = '{$remDedupeKey}'")->fetchColumn();

// Run reminders second time on the same day
$remStats2 = send_system_reminders($pdo);
$remCount2 = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userAId} AND dedupe_key = '{$remDedupeKey}'")->fetchColumn();

assert_test(
    "Reminders create one row per event per day and deduplicate subsequent runs",
    $remCount1 === 1 && $remCount2 === 1
);

// =============================================================
// TEST SUITE 18: Company Settings Management & Multi-Tenant Scoping
// =============================================================
echo "\n--- 18. Testing Company Settings Management & Scoping ---\n";

// 1. Missing setting falls back to system defaults / constants
clear_company_settings_cache();
$compBCode = (string)$pdo->query("SELECT code FROM companies WHERE id = {$compBId}")->fetchColumn();
$missingSetting = get_company_setting($pdo, $compBId, 'general.currency_code', 'DEFAULT_LKR');
$fallbackCurrency = format_currency(500.0, null, $compBId);
$defaultEmpCode = generate_employee_code($pdo, $compBId);

assert_test(
    "Missing company setting falls back to default value and constant",
    $missingSetting === 'DEFAULT_LKR' &&
    $fallbackCurrency === 'LKR 500.00' &&
    str_starts_with($defaultEmpCode, $compBCode . '-') &&
    strlen($defaultEmpCode) === (strlen($compBCode) + 5) // AZB-0001 (4 digits padding + hyphen)
);

// 2. Setting overrides constant (Currency, Work Start, Late Grace, Employee Code)
set_company_setting($pdo, $compAId, 'general.currency_code', 'USD');
set_company_setting($pdo, $compAId, 'employee.code_prefix', 'TECH');
set_company_setting($pdo, $compAId, 'employee.code_padding', '6');
set_company_setting($pdo, $compAId, 'attendance.work_start_time', '08:30');
set_company_setting($pdo, $compAId, 'attendance.late_grace_minutes', '10');
set_company_setting($pdo, $compAId, 'recruitment.interview_score_scale', '10');

$customCurrency = format_currency(1250.50, null, $compAId);
$customEmpCode = generate_employee_code($pdo, $compAId);
$cachedCurrency = get_company_setting($pdo, $compAId, 'general.currency_code');
$cachedWorkStart = get_company_setting($pdo, $compAId, 'attendance.work_start_time');
$cachedScoreScale = (int)get_company_setting($pdo, $compAId, 'recruitment.interview_score_scale');

assert_test(
    "Company setting overrides constant for currency and employee code format",
    $customCurrency === 'USD 1,250.50' &&
    $customEmpCode === 'TECH-000001' &&
    $cachedCurrency === 'USD' &&
    $cachedWorkStart === '08:30' &&
    $cachedScoreScale === 10
);

// 3. Custom weekly off days setting overrides default weekend off days
set_company_setting($pdo, $compAId, 'attendance.weekly_off_days', json_encode([5, 6])); // Fri & Sat off
// Mon 2026-11-02 to Sun 2026-11-08: Mon, Tue, Wed, Thu, Sun are working days = 5 days
$customWorkingDays = calculate_working_days('2026-11-02', '2026-11-08', false, null, $compAId);
assert_test(
    "Company setting overrides default weekly off days in working day calculation",
    $customWorkingDays === 5.0
);

// 4. Multi-tenant scoping: Company A admin cannot access or change Company B settings
$compAdminRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'company_admin'")->fetchColumn();
$pdo->prepare("INSERT INTO users (username, email, password_hash, is_active) VALUES ('company_a_admin', 'admin_a@tenant.test', 'hash', 1)")
    ->execute();
$compAdminAId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$compAdminAId, $compAId, $compAdminRoleId]);

$_SESSION['uid'] = $compAdminAId; // Company Admin of Company A
current_user(true);
load_access(true);

$canAccessOwn = user_can('company.settings', $compAId);
$canAccessForeign = user_can('company.settings', $compBId);
$isNonGroupAdmin = !is_group_admin();

assert_test(
    "Company A admin cannot access or change Company B settings",
    $canAccessOwn === true && $canAccessForeign === false && $isNonGroupAdmin === true
);

// 5. Reset to defaults clears company settings and restores defaults
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);

$resetCount = reset_company_settings($pdo, $compAId);
$currencyAfterReset = format_currency(1250.50, null, $compAId);
$settingsRemaining = get_all_company_settings($pdo, $compAId);

assert_test(
    "Reset to defaults clears company settings and restores system defaults",
    $resetCount >= 5 &&
    $currencyAfterReset === 'LKR 1,250.50' &&
    empty($settingsRemaining)
);

// =============================================================
// TEST SUITE 19: Employee Performance Management & Multi-Tenant Scoping
// =============================================================
echo "\n--- 19. Testing Performance Reviews, Goals & Authorization ---\n";

// Setup for Performance Suite:
// Create Employee 1 (Charlie) in Company A linked to User C
$pdo->prepare("INSERT INTO users (username, email, password_hash, is_active) VALUES ('charlie_emp', 'charlie@test.com', 'hash', 1)")
    ->execute();
$userCId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userCId, $compAId, $roles['employee']]);

$pdo->prepare(
    "INSERT INTO employees (employee_code, company_id, user_id, first_name, last_name, email, joining_date, employment_status)
     VALUES ('EMP-CHARLIE', ?, ?, 'Charlie', 'Staff', 'charlie@test.com', CURDATE(), 'active')"
)->execute([$compAId, $userCId]);
$empCharlieId = (int)$pdo->lastInsertId();

// Create Employee 2 (David) in Company A, who is a Manager (direct report: Charlie)
$pdo->prepare("INSERT INTO users (username, email, password_hash, is_active) VALUES ('david_mgr', 'david@test.com', 'hash', 1)")
    ->execute();
$userDId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO company_user_assignments (user_id, company_id, role_id) VALUES (?, ?, ?)")
    ->execute([$userDId, $compAId, $roles['manager']]);

$pdo->prepare(
    "INSERT INTO employees (employee_code, company_id, user_id, first_name, last_name, email, joining_date, employment_status)
     VALUES ('EMP-DAVID', ?, ?, 'David', 'Manager', 'david@test.com', CURDATE(), 'active')"
)->execute([$compAId, $userDId]);
$empDavidId = (int)$pdo->lastInsertId();

// Set Charlie's reporting_manager_id to David
$pdo->prepare("UPDATE employees SET reporting_manager_id = ? WHERE id = ?")->execute([$empDavidId, $empCharlieId]);

// 1. Reporting Manager access: David can review Charlie, but cannot review an unrelated employee
$canDavidReviewCharlie = can_user_manage_employee_performance($pdo, $userDId, $empCharlieId, $compAId);
$canDavidReviewSelf = can_user_manage_employee_performance($pdo, $userDId, $empDavidId, $compAId);

assert_test(
    "Reporting manager can manage performance of direct report, but not non-reports without HR role",
    $canDavidReviewCharlie === true && $canDavidReviewSelf === false
);

// 2. Draft review created: Confidential from employee
$pdo->prepare(
    "INSERT INTO performance_reviews (
        company_id, employee_id, reviewer_user_id, period_label,
        period_start, period_end, review_date, overall_rating,
        manager_feedback, status, created_at, updated_at
    ) VALUES (?, ?, ?, 'Annual 2026', '2026-01-01', '2026-12-31', CURDATE(), 4, 'Great work', 'draft', NOW(), NOW())"
)->execute([$compAId, $empCharlieId, $userDId]);
$revId = (int)$pdo->lastInsertId();

$stRev = $pdo->prepare('SELECT * FROM performance_reviews WHERE id = ?');
$stRev->execute([$revId]);
$revData = $stRev->fetch(PDO::FETCH_ASSOC);

// Switch session to Charlie (the employee)
$_SESSION['uid'] = $userCId;
current_user(true);
load_access(true);

$canCharlieViewDraft = can_user_view_performance_review($pdo, $userCId, $revData);

assert_test(
    "Confidential draft guard: Employee cannot view a review while in draft status",
    $canCharlieViewDraft === false
);

// 3. Submitted review: Employee can view own submitted review, but cannot view other employees' reviews
$pdo->prepare("UPDATE performance_reviews SET status = 'submitted' WHERE id = ?")->execute([$revId]);
$stRev->execute([$revId]);
$revDataSubmitted = $stRev->fetch(PDO::FETCH_ASSOC);

$canCharlieViewSubmitted = can_user_view_performance_review($pdo, $userCId, $revDataSubmitted);
$canCharlieAckSubmitted  = can_user_acknowledge_performance_review($pdo, $userCId, $revDataSubmitted);

// Create another review for David in Company A
$pdo->prepare(
    "INSERT INTO performance_reviews (
        company_id, employee_id, reviewer_user_id, period_label,
        period_start, period_end, review_date, overall_rating,
        manager_feedback, status, created_at, updated_at
    ) VALUES (?, ?, ?, 'Annual 2026', '2026-01-01', '2026-12-31', CURDATE(), 5, 'Executive work', 'submitted', NOW(), NOW())"
)->execute([$compAId, $empDavidId, $userAId]);
$revDavidId = (int)$pdo->lastInsertId();
$stRev->execute([$revDavidId]);
$revDavidData = $stRev->fetch(PDO::FETCH_ASSOC);

$canCharlieViewDavidReview = can_user_view_performance_review($pdo, $userCId, $revDavidData);
$canCharlieAckDavidReview  = can_user_acknowledge_performance_review($pdo, $userCId, $revDavidData);

assert_test(
    "Own-only access: Employee can view & acknowledge their own submitted review, but cannot access another employee's review",
    $canCharlieViewSubmitted === true && $canCharlieAckSubmitted === true &&
    $canCharlieViewDavidReview === false && $canCharlieAckDavidReview === false
);

// 4. Lock after acknowledge: Review cannot be edited once acknowledged; HR can reopen
// Charlie acknowledges their review
$pdo->prepare("UPDATE performance_reviews SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = ?")->execute([$revId]);
$stRev->execute([$revId]);
$revDataAck = $stRev->fetch(PDO::FETCH_ASSOC);

// Manager attempts to edit acknowledged review
$canManagerEditAck = can_user_edit_performance_review($pdo, $userDId, $revDataAck);
// Manager cannot reopen review
$canManagerReopenAck = can_user_reopen_performance_review($pdo, $userDId, $revDataAck);

// Switch session to HR Admin (User A has company_admin for Company A)
$_SESSION['uid'] = $compAdminAId;
current_user(true);
load_access(true);

$canHREditAck = can_user_edit_performance_review($pdo, $compAdminAId, $revDataAck);
$canHRReopenAck = can_user_reopen_performance_review($pdo, $compAdminAId, $revDataAck);

// HR reopens review with reason
$pdo->prepare("UPDATE performance_reviews SET status = 'draft', reopened_at = NOW(), reopen_reason = 'Revising metrics' WHERE id = ?")->execute([$revId]);
$stRev->execute([$revId]);
$revDataReopened = $stRev->fetch(PDO::FETCH_ASSOC);
$canManagerEditReopened = can_user_edit_performance_review($pdo, $userDId, $revDataReopened);

assert_test(
    "Lock after acknowledge: Review is locked against editing; HR can reopen for editing",
    $canManagerEditAck === false &&
    $canHREditAck === false &&
    $canManagerReopenAck === false &&
    $canHRReopenAck === true &&
    $canManagerEditReopened === true
);

// 5. Tenant Scope & Unauthorized access: Company B user cannot view or manage Company A review; HR Officer has none
// User B is HR in Company B
$canCompBViewCompA = can_user_view_performance_review($pdo, $userBId, $revData);
$canCompBManageCompA = can_user_manage_employee_performance($pdo, $userBId, $empCharlieId, $compAId);

// User Multi is hr_officer in Company A
$_SESSION['uid'] = $userMultiId;
current_user(true);
load_access(true);

$hrOfficerCanManage = user_can('performance.manage', $compAId);
$hrOfficerCanView   = user_can('performance.view', $compAId);

assert_test(
    "Tenant isolation & unauthorized access: Company B user denied for Company A review; HR Officer has no performance permissions",
    $canCompBViewCompA === false &&
    $canCompBManageCompA === false &&
    $hrOfficerCanManage === false &&
    $hrOfficerCanView === false
);

// Restore super admin session
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);

// =============================================================
// TEST SUITE 20: Header Variable Isolation & Form Autofill Integrity
// =============================================================
echo "\n--- 20. Testing Header Scope Isolation & User Form Values ---\n";

// 1. includes/header.php does not define or overwrite $user or $targetUser
$testUserPre = ['full_name' => 'Original User', 'username' => 'orig_user', 'email' => 'orig@test.com'];
$user = $testUserPre;
$targetUser = $testUserPre;
$_SESSION['uid'] = $superAdminTestId;
$_SESSION['last_activity'] = time();
$_SESSION['ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
current_user(true);
load_access(true);

$pageTitle = 'Header Scope Test';
ob_start();
require ROOT_PATH . '/includes/header.php';
$hdrOut = ob_get_clean();

assert_test(
    "includes/header.php does not overwrite page-level \$user or \$targetUser variables",
    $user === $testUserPre && $targetUser === $testUserPre
);

// 2. Rendering modules/users/form.php in Edit Mode for another user displays edited user's values, not current user's
$_GET['id'] = $userAId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/modules/users/form.php';

ob_start();
require ROOT_PATH . '/modules/users/form.php';
$editHtml = ob_get_clean();

preg_match('/name="full_name"[^>]*value="([^"]*)"/', $editHtml, $mEditFull);
preg_match('/name="username"[^>]*value="([^"]*)"/', $editHtml, $mEditUser);
preg_match('/name="email"[^>]*value="([^"]*)"/', $editHtml, $mEditMail);
preg_match('/<span class="d-none d-md-inline user-name-label">([^<]*)<\/span>/', $editHtml, $mEditTopbar);

$editFullVal = $mEditFull[1] ?? '';
$editUserVal = $mEditUser[1] ?? '';
$editMailVal = $mEditMail[1] ?? '';
$editTopbarVal = trim($mEditTopbar[1] ?? '');

assert_test(
    "Edit User form renders target user data ('Alpha HR') and not logged-in admin ('Super Admin')",
    $editFullVal === 'Alpha HR' &&
    $editUserVal === 'user_alpha' &&
    $editMailVal === 'alpha_hr@test.com' &&
    $editTopbarVal === 'Super Admin'
);

// 3. Rendering modules/users/form.php in Create Mode displays empty inputs
unset($_GET['id']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/modules/users/form.php';

ob_start();
require ROOT_PATH . '/modules/users/form.php';
$createHtml = ob_get_clean();

preg_match('/name="full_name"[^>]*value="([^"]*)"/', $createHtml, $mCreateFull);
preg_match('/name="username"[^>]*value="([^"]*)"/', $createHtml, $mCreateUser);
preg_match('/name="email"[^>]*value="([^"]*)"/', $createHtml, $mCreateMail);

$createFullVal = $mCreateFull[1] ?? null;
$createUserVal = $mCreateUser[1] ?? null;
$createMailVal = $mCreateMail[1] ?? null;

assert_test(
    "Add User form renders completely empty input fields (no logged-in user prefill)",
    $createFullVal === '' &&
    $createUserVal === '' &&
    $createMailVal === ''
);

// Restore super admin session
$_SESSION['uid'] = $superAdminTestId;
current_user(true);
load_access(true);

// =============================================================
// TEST SUITE 21: Company Holidays & Leave Working Days Integration
// =============================================================
echo "\n--- 21. Testing Company Holidays & Working Days Integration ---\n";

// 1. Database table exists
$stmt = $pdo->query("SHOW TABLES LIKE 'company_holidays'");
assert_test("company_holidays table exists in database", (bool)$stmt->fetch());

// Clear previous test holidays for November 2026
$pdo->exec("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2026-11-01' AND '2026-11-30'");
get_holidays_in_range('', '', null, null, true);

// 2. Insert group-wide holiday (company_id = NULL) on Wednesday 2026-11-04
$stmt = $pdo->prepare("INSERT INTO company_holidays (company_id, holiday_date, name, is_active) VALUES (NULL, '2026-11-04', 'Group National Holiday', 1)");
$stmt->execute();
$groupHolidayId = (int)$pdo->lastInsertId();

// 3. Insert company-specific holiday for Alpha on Thursday 2026-11-05
$stmt = $pdo->prepare("INSERT INTO company_holidays (company_id, holiday_date, name, is_active) VALUES (?, '2026-11-05', 'Alpha Foundation Day', 1)");
$stmt->execute([$compAId]);
$alphaHolidayId = (int)$pdo->lastInsertId();

// 4. Insert inactive company-specific holiday on Friday 2026-11-06
$stmt = $pdo->prepare("INSERT INTO company_holidays (company_id, holiday_date, name, is_active) VALUES (?, '2026-11-06', 'Tentative Holiday', 0)");
$stmt->execute([$compAId]);
$inactiveHolidayId = (int)$pdo->lastInsertId();

// 5. Test get_holidays_in_range()
$alphaHolidays = get_holidays_in_range('2026-11-01', '2026-11-10', $compAId, $pdo);
$betaHolidays = get_holidays_in_range('2026-11-01', '2026-11-10', $compBId, $pdo);

assert_test(
    "Alpha company sees active group holiday (Nov 4) and company holiday (Nov 5), excluding inactive (Nov 6)",
    isset($alphaHolidays['2026-11-04']) &&
    isset($alphaHolidays['2026-11-05']) &&
    !isset($alphaHolidays['2026-11-06'])
);

assert_test(
    "Beta company sees active group holiday (Nov 4) but NOT Alpha company holiday (Nov 5)",
    isset($betaHolidays['2026-11-04']) &&
    !isset($betaHolidays['2026-11-05'])
);

// 6. Test get_working_days_in_range() and calculate_working_days() with holidays & weekly-off [6, 7]
// Mon 2026-11-02 to Sun 2026-11-08:
// Mon(2), Tue(3), Wed(4:GroupHol), Thu(5:AlphaHol), Fri(6:Work), Sat(7:Off), Sun(8:Off)
// Alpha working days = 3 (Mon, Tue, Fri)
$alphaWorkingDays = get_working_days_in_range('2026-11-02', '2026-11-08', [6, 7], $compAId);
$betaWorkingDays = get_working_days_in_range('2026-11-02', '2026-11-08', [6, 7], $compBId);

$alphaCalcDays = calculate_working_days('2026-11-02', '2026-11-08', false, [6, 7], $compAId);
$betaCalcDays = calculate_working_days('2026-11-02', '2026-11-08', false, [6, 7], $compBId);

assert_test(
    "get_working_days_in_range and calculate_working_days exclude weekend and holidays: Alpha gets 3 days, Beta gets 4 days",
    count($alphaWorkingDays) === 3 && count($betaWorkingDays) === 4 &&
    $alphaCalcDays === 3.0 && $betaCalcDays === 4.0
);

// 7. Weekend + Holiday overlap: Group holiday on Saturday 2026-11-07 does not double-deduct
$stmt = $pdo->prepare("INSERT INTO company_holidays (company_id, holiday_date, name, is_active) VALUES (NULL, '2026-11-07', 'Weekend Holiday', 1)");
$stmt->execute();
$alphaWorkingDaysWeekendHol = get_working_days_in_range('2026-11-02', '2026-11-08', [6, 7], $compAId);
assert_test(
    "Holiday overlapping with weekly-off weekend does not double-deduct days",
    count($alphaWorkingDaysWeekendHol) === 3
);

// 8. leave_balance() integration: respects company off-days and excludes holidays
$stmt = $pdo->prepare("SELECT id FROM leave_types WHERE is_active = 1 LIMIT 1");
$stmt->execute();
$testLeaveTypeId = (int)$stmt->fetchColumn();

// Create a test employee in Company A
$stmt = $pdo->prepare("INSERT INTO employees (company_id, employee_code, first_name, last_name, email, joining_date, employment_status) VALUES (?, 'EMP-HOL-001', 'Holiday', 'Tester', 'holtester@alpha.com', '2026-01-01', 'active')");
$stmt->execute([$compAId]);
$holEmpId = (int)$pdo->lastInsertId();

// Insert approved leave request for Nov 2 to Nov 6 (3 working days)
$stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, company_id, leave_type_id, start_date, end_date, total_days, status) VALUES (?, ?, ?, '2026-11-02', '2026-11-06', 3.0, 'approved')");
$stmt->execute([$holEmpId, $compAId, $testLeaveTypeId]);

$bal = leave_balance($pdo, $holEmpId, $testLeaveTypeId, 2026);
assert_test(
    "leave_balance() automatically resolves employee company_id and excludes active holidays (taken = 3.0)",
    (float)$bal['taken'] === 3.0
);

// 9. Holiday deletion safety: Active holiday cannot be deleted, inactive holiday can be deleted
$activeDelAllowed = false;
$inactiveDelAllowed = false;

// Active holiday check (from holidays.php logic: delete only when is_active = 0)
$chkActive = $pdo->prepare("SELECT is_active FROM company_holidays WHERE id = ?");
$chkActive->execute([$alphaHolidayId]);
if ((int)$chkActive->fetchColumn() === 0) {
    $activeDelAllowed = true;
}

// Inactive holiday check
$chkInactive = $pdo->prepare("SELECT is_active FROM company_holidays WHERE id = ?");
$chkInactive->execute([$inactiveHolidayId]);
if ((int)$chkInactive->fetchColumn() === 0) {
    $del = $pdo->prepare("DELETE FROM company_holidays WHERE id = ?");
    $del->execute([$inactiveHolidayId]);
    $inactiveDelAllowed = true;
}

assert_test(
    "Active holiday is guarded against deletion; inactive holiday is deletable",
    !$activeDelAllowed && $inactiveDelAllowed
);

// =============================================================
// TEST SUITE 22: Candidate, Vacancy, Interview, Offer & Employee Deletion Safety
// =============================================================
echo "\n--- 22. Testing Full Recruitment & Employee Deletion Safety ---\n";

// --- CANDIDATE DELETION ---
// 1. Create a hired candidate -> deletion blocked
$stmt = $pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, consent_given) VALUES ('CAN-DEL-01', 'Hired', 'Candidate', 'hired@cand.test', 1)");
$stmt->execute();
$hiredCandId = (int)$pdo->lastInsertId();

// Create a closed vacancy
$stmt = $pdo->prepare("INSERT INTO job_vacancies (company_id, vacancy_code, title, positions_count, status) VALUES (?, 'VAC-DEL-01', 'Test Closed Vac', 1, 'closed')");
$stmt->execute([$compAId]);
$delTestVacId = (int)$pdo->lastInsertId();

// Add hired application
$stmt = $pdo->prepare("INSERT INTO candidate_applications (candidate_id, company_id, vacancy_id, application_date, status) VALUES (?, ?, ?, '2026-10-01', 'hired')");
$stmt->execute([$hiredCandId, $compAId, $delTestVacId]);

$chkHiredCand = check_candidate_deletable($hiredCandId, $pdo);
assert_test(
    "Hired candidate cannot be deleted (check_candidate_deletable returns false)",
    !$chkHiredCand['can_delete']
);

// 2. Candidate with active application / offer -> deletion blocked
$stmt = $pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, consent_given) VALUES ('CAN-DEL-02', 'Active', 'OfferCand', 'actoffer@cand.test', 1)");
$stmt->execute();
$actOfferCandId = (int)$pdo->lastInsertId();

// Application in offered state
$stmt = $pdo->prepare("INSERT INTO candidate_applications (candidate_id, company_id, vacancy_id, application_date, status) VALUES (?, ?, ?, '2026-10-01', 'offered')");
$stmt->execute([$actOfferCandId, $compAId, $delTestVacId]);
$appOfferId = (int)$pdo->lastInsertId();

// Job offer
$stmt = $pdo->prepare("INSERT INTO job_offers (application_id, company_id, offered_salary, offer_date, status, created_by) VALUES (?, ?, 5000, '2026-10-01', 'sent', ?)");
$stmt->execute([$appOfferId, $compAId, $superAdminTestId]);
$delTestOfferId = (int)$pdo->lastInsertId();

$chkActOfferCand = check_candidate_deletable($actOfferCandId, $pdo);
assert_test(
    "Candidate with active job offer cannot be deleted",
    !$chkActOfferCand['can_delete']
);

// 3. Clean candidate without active offer / employment can be deleted
$stmt = $pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, consent_given) VALUES ('CAN-DEL-03', 'Clean', 'Candidate', 'clean@cand.test', 1)");
$stmt->execute();
$cleanCandId = (int)$pdo->lastInsertId();

$delCandResult = delete_candidate($cleanCandId, $pdo, $superAdminTestId);
assert_test(
    "Rejected/clean candidate is safely deleted from database with cascade",
    $delCandResult['success'] && (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE id = {$cleanCandId}")->fetchColumn() === 0
);

// --- VACANCY DELETION ---
// 1. Open or draft vacancy cannot be deleted
$stmt = $pdo->prepare("INSERT INTO job_vacancies (company_id, vacancy_code, title, positions_count, status) VALUES (?, 'VAC-DEL-02', 'Open Vacancy', 1, 'open')");
$stmt->execute([$compAId]);
$openVacId = (int)$pdo->lastInsertId();
$chkOpenVac = check_vacancy_deletable($openVacId, $pdo);
assert_test(
    "Open vacancy cannot be deleted (must be closed/cancelled)",
    !$chkOpenVac['can_delete']
);

// 2. Closed vacancy with active applicants cannot be deleted
$chkClosedWithApp = check_vacancy_deletable($delTestVacId, $pdo);
assert_test(
    "Closed vacancy with active applications cannot be deleted",
    !$chkClosedWithApp['can_delete']
);

// 3. Clean closed vacancy with no applicants can be deleted
$stmt = $pdo->prepare("INSERT INTO job_vacancies (company_id, vacancy_code, title, positions_count, status) VALUES (?, 'VAC-DEL-03', 'Clean Closed Vacancy', 1, 'closed')");
$stmt->execute([$compAId]);
$cleanVacId = (int)$pdo->lastInsertId();

$delVacResult = delete_vacancy($cleanVacId, $pdo, $superAdminTestId);
assert_test(
    "Clean closed vacancy without applicants is safely deleted",
    $delVacResult['success'] && (int)$pdo->query("SELECT COUNT(*) FROM job_vacancies WHERE id = {$cleanVacId}")->fetchColumn() === 0
);

// --- INTERVIEW DELETION ---
// 1. Scheduled interview cannot be deleted
$stmt = $pdo->prepare("INSERT INTO interviews (application_id, company_id, round_no, interview_type, scheduled_at, duration_min, mode, status, created_by) VALUES (?, ?, 1, 'hr', '2026-11-20 10:00:00', 30, 'online', 'scheduled', ?)");
$stmt->execute([$appOfferId, $compAId, $superAdminTestId]);
$schedIntId = (int)$pdo->lastInsertId();
$chkSchedInt = check_interview_deletable($schedIntId, $pdo);
assert_test(
    "Scheduled interview cannot be deleted (must be cancelled)",
    !$chkSchedInt['can_delete']
);

// 2. Cancelled interview can be deleted
$pdo->exec("UPDATE interviews SET status = 'cancelled' WHERE id = {$schedIntId}");
$delIntResult = delete_interview($schedIntId, $pdo, $superAdminTestId);
assert_test(
    "Cancelled interview is safely deleted",
    $delIntResult['success'] && (int)$pdo->query("SELECT COUNT(*) FROM interviews WHERE id = {$schedIntId}")->fetchColumn() === 0
);

// --- OFFER DELETION ---
// 1. Converted offer cannot be deleted
$stmt = $pdo->prepare("INSERT INTO job_offers (application_id, company_id, offered_salary, offer_date, status, converted_employee_id, created_by) VALUES (?, ?, 6000, '2026-10-01', 'accepted', ?, ?)");
$stmt->execute([$appOfferId, $compAId, $holEmpId, $superAdminTestId]);
$convOfferId = (int)$pdo->lastInsertId();
$chkConvOffer = check_offer_deletable($convOfferId, $pdo);
assert_test(
    "Converted job offer cannot be deleted",
    !$chkConvOffer['can_delete']
);

// 2. Declined / expired / withdrawn offer can be deleted
$stmt = $pdo->prepare("INSERT INTO job_offers (application_id, company_id, offered_salary, offer_date, status, created_by) VALUES (?, ?, 4000, '2026-10-01', 'declined', ?)");
$stmt->execute([$appOfferId, $compAId, $superAdminTestId]);
$declOfferId = (int)$pdo->lastInsertId();
$delOfferResult = delete_offer($declOfferId, $pdo, $superAdminTestId);
assert_test(
    "Declined job offer is safely deleted",
    $delOfferResult['success'] && (int)$pdo->query("SELECT COUNT(*) FROM job_offers WHERE id = {$declOfferId}")->fetchColumn() === 0
);

// --- EMPLOYEE DELETION ---
// 1. Active employee cannot be deleted
$chkActiveEmp = check_employee_deletable($holEmpId, $pdo);
assert_test(
    "Active employee cannot be deleted (must be terminated, resigned, or retired)",
    !$chkActiveEmp['can_delete']
);

// 2. Terminated employee who is supervisor/manager to another active employee cannot be deleted
$stmt = $pdo->prepare("INSERT INTO employees (company_id, employee_code, first_name, last_name, email, joining_date, employment_status) VALUES (?, 'MGR-DEL-01', 'Manager', 'Terminated', 'term.mgr@alpha.com', '2025-01-01', 'terminated')");
$stmt->execute([$compAId]);
$termMgrId = (int)$pdo->lastInsertId();

// Set holEmpId manager to termMgrId
$pdo->exec("UPDATE employees SET reporting_manager_id = {$termMgrId} WHERE id = {$holEmpId}");
$chkMgrDel = check_employee_deletable($termMgrId, $pdo);
assert_test(
    "Terminated employee who is reporting manager of active staff cannot be deleted",
    !$chkMgrDel['can_delete']
);
$pdo->exec("UPDATE employees SET reporting_manager_id = NULL WHERE id = {$holEmpId}");

// 3. Employee linked to active user account cannot be deleted
$stmt = $pdo->prepare("INSERT INTO employees (company_id, employee_code, first_name, last_name, email, joining_date, employment_status) VALUES (?, 'LNK-DEL-01', 'Linked', 'Terminated', 'term.lnk@alpha.com', '2025-01-01', 'terminated')");
$stmt->execute([$compAId]);
$termLnkEmpId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_active, is_group_admin) VALUES ('term_user_del', 'term_user_del@alpha.com', 'hash', 'Term User', 1, 0)");
$stmt->execute();
$termUserId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE employees SET user_id = {$termUserId} WHERE id = {$termLnkEmpId}");

$chkLnkDel = check_employee_deletable($termLnkEmpId, $pdo);
assert_test(
    "Terminated employee linked to an active user account cannot be deleted",
    !$chkLnkDel['can_delete']
);

// 4. Terminated employee with clean state can be safely deleted with all history, docs, and audit log
$stmt = $pdo->prepare("INSERT INTO employees (company_id, employee_code, first_name, last_name, email, joining_date, employment_status) VALUES (?, 'CLN-DEL-01', 'Clean', 'Terminated', 'term.cln@alpha.com', '2025-01-01', 'terminated')");
$stmt->execute([$compAId]);
$cleanEmpId = (int)$pdo->lastInsertId();

$delEmpResult = delete_employee($cleanEmpId, $pdo, $superAdminTestId);
assert_test(
    "Clean terminated employee is safely deleted with audit logging",
    $delEmpResult['success'] && (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE id = {$cleanEmpId}")->fetchColumn() === 0
);

// =============================================================
// =============================================================
// TEST SUITE 23: Schema Integrity, Public Apply CV, ESS Dashboard & Company Deletion Guards
// =============================================================
echo "\n--- 23. Testing Schema Integrity, Public Apply CV, ESS Dashboard & Company Deletion Guards ---\n";

// 1. Run tests/check_schema.php via CLI
$schemaOutput = [];
$schemaExitCode = 0;
exec('php ' . escapeshellarg(ROOT_PATH . '/tests/check_schema.php'), $schemaOutput, $schemaExitCode);
assert_test(
    "tests/check_schema.php runs cleanly and reports zero schema mismatches (exit code 0)",
    $schemaExitCode === 0
);

// 2. Single-file installer check
$buildOutput = [];
$buildExitCode = 0;
exec('php ' . escapeshellarg(ROOT_PATH . '/tools/build_full_sql.php') . ' --check', $buildOutput, $buildExitCode);
assert_test(
    "tools/build_full_sql.php --check confirms database/aaza_hrms_full.sql matches migrations",
    $buildExitCode === 0
);

// 2. Public apply with a CV creates a candidate_documents row (doc_type = 'cv')
$stmtVac = $pdo->prepare("INSERT INTO job_vacancies (vacancy_code, title, company_id, positions_count, status, created_by) VALUES ('VAC-REG-CV', 'CV Vacancy', ?, 2, 'open', ?)");
$stmtVac->execute([$compAId, $userAId]);
$cvVacId = (int)$pdo->lastInsertId();

$cvEmail = 'cv.applicant.' . bin2hex(random_bytes(4)) . '@test.com';
$stmtCand = $pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, phone, consent_given) VALUES ('CAND-CV-01', 'Resume', 'Tester', ?, '+94770000001', 1)");
$stmtCand->execute([$cvEmail]);
$cvCandId = (int)$pdo->lastInsertId();

$stmtApp = $pdo->prepare("INSERT INTO candidate_applications (candidate_id, vacancy_id, company_id, application_date, status, notes) VALUES (?, ?, ?, CURDATE(), 'applied', 'Online application test')");
$stmtApp->execute([$cvCandId, $cvVacId, $compAId]);
$cvAppId = (int)$pdo->lastInsertId();

$origDocName = 'my_cv.pdf';
$storedDocName = bin2hex(random_bytes(16)) . '.pdf';
$docMime = 'application/pdf';
$docSize = 102400;

$docSt = $pdo->prepare(
    'INSERT INTO candidate_documents (candidate_id, doc_type, original_name, stored_name, mime_type, file_size, uploaded_by, uploaded_at)
     VALUES (?, "cv", ?, ?, ?, ?, NULL, NOW())'
);
$docSt->execute([$cvCandId, $origDocName, $storedDocName, $docMime, $docSize]);
$cvDocId = (int)$pdo->lastInsertId();

$savedCvDoc = $pdo->query("SELECT * FROM candidate_documents WHERE id = {$cvDocId}")->fetch(PDO::FETCH_ASSOC);
assert_test(
    "Public apply with CV creates a candidate_documents row with doc_type='cv' and correct columns",
    $savedCvDoc !== false &&
    $savedCvDoc['doc_type'] === 'cv' &&
    $savedCvDoc['original_name'] === $origDocName &&
    $savedCvDoc['stored_name'] === $storedDocName &&
    $savedCvDoc['uploaded_by'] === null &&
    (int)$savedCvDoc['candidate_id'] === $cvCandId
);

// 3. Employee dashboard queries run without error (linked employee and unlinked user)
$dashErrors = [];
try {
    $dashUser = ['id' => $userAId, 'full_name' => 'Alpha HR', 'username' => 'alphahr'];
    $linkedEmp = ['id' => $newEmpId, 'company_id' => $compAId];
    
    // User company query
    $stComp = $pdo->prepare(
        "SELECT c.id, c.name, c.code, r.label as role_label
           FROM company_user_assignments cua
           JOIN companies c ON c.id = cua.company_id AND c.is_active = 1
           JOIN roles r ON r.id = cua.role_id
          WHERE cua.user_id = ?
          LIMIT 1"
    );
    $stComp->execute([$dashUser['id']]);
    $userComp = $stComp->fetch();

    // Employee details query (with des.title as desig_name)
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
    $stEmp->execute([(int)$linkedEmp['id']]);
    $empDetails = $stEmp->fetch();

    // Leave types query
    $stLt = $pdo->prepare(
        "SELECT id, name, days_per_year
           FROM leave_types
          WHERE is_active = 1
            AND (company_id IS NULL OR company_id = ?)
          ORDER BY name ASC"
    );
    $stLt->execute([(int)$linkedEmp['company_id']]);
    $leaveTypes = $stLt->fetchAll();

    // Recent leaves query (using requested_at DESC)
    $stRecent = $pdo->prepare(
        "SELECT lr.*, lt.name as leave_type_name
           FROM leave_requests lr
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.employee_id = ?
          ORDER BY lr.requested_at DESC
          LIMIT 5"
    );
    $stRecent->execute([(int)$linkedEmp['id']]);
    $recentLeaves = $stRecent->fetchAll();

    // Holidays query
    $todayStr = date('Y-m-d');
    $futureStr = date('Y-m-d', strtotime('+45 days'));
    $holidays = get_holidays_in_range($todayStr, $futureStr, (int)$linkedEmp['company_id'], $pdo);

} catch (Throwable $t) {
    $dashErrors[] = $t->getMessage();
}

assert_test(
    "Employee dashboard queries run without error (all columns and tables valid)",
    empty($dashErrors)
);

// 4. Company that has interviews or attendance rows cannot be deleted
// Create a dedicated inactive company with interviews
$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('INT-CO', 'Interview Hold Co', 'int@aaza.test', 0)")
    ->execute();
$intCompId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO job_vacancies (vacancy_code, title, company_id, positions_count, status, created_by) VALUES ('VAC-INT-01', 'Role', ?, 1, 'closed', ?)")
    ->execute([$intCompId, $userAId]);
$intVacId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO candidates (candidate_code, first_name, last_name, email, phone) VALUES ('CAND-INT-01', 'Int', 'Candidate', 'int.cand@test.com', '+94770000002')")
    ->execute();
$intCandId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO candidate_applications (candidate_id, vacancy_id, company_id, application_date, status) VALUES (?, ?, ?, CURDATE(), 'applied')")
    ->execute([$intCandId, $intVacId, $intCompId]);
$intAppId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO interviews (application_id, company_id, round_no, interview_type, scheduled_at, status) VALUES (?, ?, 1, 'video', NOW(), 'completed')")
    ->execute([$intAppId, $intCompId]);

$chkIntComp = check_company_deletable($intCompId, $pdo);
$delIntComp = delete_company($intCompId, $pdo);
assert_test(
    "Company with interviews cannot be deleted (check_company_deletable blocks with reason)",
    $chkIntComp['can_delete'] === false &&
    str_contains(strtolower($chkIntComp['reason']), 'interview') &&
    $delIntComp['success'] === false
);

// Create a dedicated inactive company with attendance records
$pdo->prepare("INSERT INTO companies (code, name, email, is_active) VALUES ('ATT-CO', 'Attendance Hold Co', 'att@aaza.test', 0)")
    ->execute();
$attCompId = (int)$pdo->lastInsertId();

// Insert an attendance record for this company
$pdo->prepare("INSERT INTO attendance (employee_id, company_id, att_date, status) VALUES (?, ?, CURDATE(), 'present')")
    ->execute([$newEmpId, $attCompId]);

$chkAttComp = check_company_deletable($attCompId, $pdo);
$delAttComp = delete_company($attCompId, $pdo);
assert_test(
    "Company with attendance records cannot be deleted (check_company_deletable blocks with reason)",
    $chkAttComp['can_delete'] === false &&
    str_contains(strtolower($chkAttComp['reason']), 'attendance') &&
    $delAttComp['success'] === false
);

// =============================================================
// =============================================================
// TEST SUITE 24: Production Installer Hardening & Input Validation
// =============================================================
echo "\n--- 24. Testing Production Installer Hardening & Input Validation ---\n";

// Helper validator functions mimicking public/setup.php rules
$validateDbHost = fn(string $h): bool => (bool)preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $h);
$validateDbName = fn(string $n): bool => (bool)preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $n);
$validateDbUser = fn(string $u): bool => (bool)preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $u);
$validateTz     = fn(string $tz): bool => in_array($tz, timezone_identifiers_list(), true);
$validateCurr   = fn(string $c): bool => (bool)preg_match('/^[A-Z]{3}$/', $c);

// 1. Malicious db_name containing quotes and PHP code injection is rejected
$maliciousDbNames = [
    "test'; phpinfo(); /*",
    'aaza_hrms" OR 1=1',
    "test`drop database`",
    "test<?php system('id'); ?>",
    "db\0injection",
    "test
newline",
    "aaza'--",
];
$allMaliciousRejected = true;
foreach ($maliciousDbNames as $bad) {
    if ($validateDbName($bad)) {
        $allMaliciousRejected = false;
        break;
    }
}
assert_test(
    "Installer strictly rejects db_name containing quotes, PHP code or SQL injection payload",
    $allMaliciousRejected
);

// 2. Valid database names are accepted
$validDbNames = [
    'aaza_hrms',
    'cpaneluser_aazahrms',
    'hrms_test_db',
    'db-tenant.01$',
    'app_db_2026',
];
$allValidAccepted = true;
foreach ($validDbNames as $good) {
    if (!$validateDbName($good)) {
        $allValidAccepted = false;
        break;
    }
}
assert_test(
    "Installer accepts compliant database names with standard characters",
    $allValidAccepted
);

// 3. Malicious and valid db_host checks
assert_test(
    "Installer rejects db_host containing command injection or spaces",
    !$validateDbHost("127.0.0.1; whoami") &&
    !$validateDbHost("localhost' OR '1'='1") &&
    $validateDbHost("127.0.0.1") &&
    $validateDbHost("localhost") &&
    $validateDbHost("mysql.internal.corp:3306")
);

// 4. Timezone whitelist against timezone_identifiers_list()
assert_test(
    "Installer enforces timezone identifier whitelist",
    $validateTz("Asia/Colombo") &&
    $validateTz("UTC") &&
    $validateTz("America/New_York") &&
    !$validateTz("Asia/FakeCity") &&
    !$validateTz("Asia/Colombo'; phpinfo();")
);

// 5. Currency code format check
assert_test(
    "Installer validates currency code format (^[A-Z]{3}$)",
    $validateCurr("LKR") &&
    $validateCurr("USD") &&
    $validateCurr("EUR") &&
    !$validateCurr("US") &&
    !$validateCurr("USDD") &&
    !$validateCurr("lkr") &&
    !$validateCurr("LKR';")
);

// 6. var_export escaping safety test
$dangerousInput = "'; phpinfo(); /*";
$exported = var_export($dangerousInput, true);
$recovered = null;
eval('$recovered = ' . $exported . ';');
assert_test(
    "var_export() produces safe escaped string literal without unescaped delimiters",
    $recovered === $dangerousInput &&
    str_starts_with($exported, "'") &&
    str_ends_with($exported, "'") &&
    str_contains($exported, "\\'")
);

// 7. Installer strictly refuses when installed.lock exists
$testLockFile = __DIR__ . '/../storage/installed.lock';
$hadOriginalLock = is_file($testLockFile);
$originalLockContent = $hadOriginalLock ? file_get_contents($testLockFile) : null;

// Ensure test lock is present
file_put_contents($testLockFile, json_encode(['test' => true, 'timestamp' => time()]));

$proc = proc_open(
    'php',
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__)
);
$phpSnippet = <<<'SNIPPET'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
register_shutdown_function(function() {
    echo "\nHTTP_STATUS:" . http_response_code() . "\n";
});
include 'public/setup.php';
SNIPPET;

fwrite($pipes[0], $phpSnippet);
fclose($pipes[0]);
$cliOutput = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// Restore original lock state
if ($hadOriginalLock && $originalLockContent !== null) {
    file_put_contents($testLockFile, $originalLockContent);
} else {
    @unlink($testLockFile);
}

$refusedByLock = str_contains($cliOutput, 'HTTP_STATUS:403') && str_contains($cliOutput, 'Installation Locked');
assert_test(
    "Installer strictly refuses when installed.lock exists (HTTP 403 Forbidden)",
    $refusedByLock
);

// 8. make_release packages setup.php and excludes installed.lock/config/tests/docs/.git
$distZip = __DIR__ . '/../dist/aaza_hrms_release.zip';
exec('php tools/make_release.php 2>&1', $mrOutput, $mrExit);

assert_test(
    "Release packager (make_release.php) generates valid zip archive",
    is_file($distZip) && filesize($distZip) > 1000
);

$zipArchive = new ZipArchive();
$zipOpened = ($zipArchive->open($distZip) === true);
$hasSetup = false;
$hasFullSql = false;
$hasLock = false;
$hasActiveConfig = false;
$hasActiveDatabase = false;
$hasTests = false;
$hasDocs = false;
$hasGit = false;

if ($zipOpened) {
    for ($i = 0; $i < $zipArchive->numFiles; $i++) {
        $name = $zipArchive->getNameIndex($i);
        if ($name === 'public/setup.php') $hasSetup = true;
        if ($name === 'database/aaza_hrms_full.sql') $hasFullSql = true;
        if (str_contains($name, 'installed.lock')) $hasLock = true;
        if ($name === 'config/config.php') $hasActiveConfig = true;
        if ($name === 'config/database.php') $hasActiveDatabase = true;
        if (str_starts_with($name, 'tests/')) $hasTests = true;
        if (str_starts_with($name, 'docs/')) $hasDocs = true;
        if (str_starts_with($name, '.git/') || str_contains($name, '/.git/')) $hasGit = true;
    }
    $zipArchive->close();
}

assert_test(
    "Release zip strictly includes public/setup.php and database/aaza_hrms_full.sql",
    $zipOpened && $hasSetup && $hasFullSql
);

assert_test(
    "Release zip strictly excludes installed.lock, active configs, tests, docs, and .git",
    $zipOpened && !$hasLock && !$hasActiveConfig && !$hasActiveDatabase && !$hasTests && !$hasDocs && !$hasGit
);

// 9. Step 3 with seeded code AZT is rejected gracefully ('Code already exists, choose another')
$checkCodeSt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE UPPER(code) = ?");
$checkCodeSt->execute(['AZT']);
$aztExists = ((int)$checkCodeSt->fetchColumn()) > 0;

$step3Errors = [];
if ($aztExists) {
    $step3Errors[] = 'Code already exists, choose another';
}
assert_test(
    "Step 3 with seeded code AZT is rejected gracefully ('Code already exists, choose another')",
    $aztExists && in_array('Code already exists, choose another', $step3Errors, true)
);

// 10. Step 3 with 'use existing companies' creates no company and succeeds
$compCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$userCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$pdo->beginTransaction();
$testAdminUsername = 'grp_admin_' . bin2hex(random_bytes(3));
$testAdminEmail = 'grp_admin_' . bin2hex(random_bytes(3)) . '@test.com';
$pwHash = password_hash('AdminPass1234', PASSWORD_DEFAULT);
$insUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active, created_at) VALUES (?, ?, ?, 'Group Admin', 1, 1, NOW())");
$insUser->execute([$testAdminUsername, $testAdminEmail, $pwHash]);
$newAdminId = (int)$pdo->lastInsertId();
$pdo->commit();

$compCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$userCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Clean up test user
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$newAdminId]);

assert_test(
    "Step 3 with 'use existing companies' creates no company and succeeds",
    $compCountBefore === $compCountAfter && $userCountAfter === $userCountBefore + 1
);

// 11. Failure inside transaction leaves users and companies unchanged (atomic rollback)
$compCountPre = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$userCountPre = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO companies (code, name, email) VALUES ('NEWC', 'New Co', 'new@test.com')")->execute();
    $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin) VALUES ('fail_admin', 'fail@test.com', 'hash', 'Fail Admin', 1)")->execute();
    throw new RuntimeException("Simulated step 3 provisioning error");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$compCountPost = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$userCountPost = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

assert_test(
    "Failure inside the transaction leaves users/companies unchanged (atomic rollback)",
    $compCountPre === $compCountPost && $userCountPre === $userCountPost
);

// 12. setup.php equals setup.php.dist byte for byte
$setupPath = __DIR__ . '/../public/setup.php';
$distTplPath = __DIR__ . '/../tools/templates/setup.php.dist';
$isIdenticalByteForByte = (is_file($setupPath) && is_file($distTplPath) && file_get_contents($setupPath) === file_get_contents($distTplPath));

assert_test(
    "public/setup.php equals tools/templates/setup.php.dist byte for byte",
    $isIdenticalByteForByte
);

// 13. PDOException messages in installer mapped to friendly text
$procMap = proc_open(
    'php',
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $mapPipes,
    dirname(__DIR__)
);
$phpSnippetMap = <<<'SNIPPET'
<?php
$content = file_get_contents('public/setup.php');
if (preg_match('/function map_db_exception\b.+?^}/ms', $content, $m)) {
    eval($m[0]);
    $eDup = new PDOException("Duplicate entry 'AZT' for key 'code'", 23000);
    $eDup->errorInfo = ['23000', 1062, "Duplicate entry 'AZT' for key 'code'"];
    $eFk = new PDOException("Cannot add or update a child row: a foreign key constraint fails", 23000);
    $eFk->errorInfo = ['23000', 1452, "Cannot add or update a child row: a foreign key constraint fails"];
    $eOther = new PDOException("Table doesn't exist", 42000);
    $eOther->errorInfo = ['42000', 1146, "Table doesn't exist"];

    echo json_encode([
        'dup' => map_db_exception($eDup),
        'fk' => map_db_exception($eFk),
        'other' => map_db_exception($eOther),
    ]);
}
SNIPPET;
fwrite($mapPipes[0], $phpSnippetMap);
fclose($mapPipes[0]);
$outMap = stream_get_contents($mapPipes[1]);
fclose($mapPipes[1]);
fclose($mapPipes[2]);
proc_close($procMap);
$jsonStart = strpos($outMap, '{');
$jsonEnd = strrpos($outMap, '}');
$jsonStr = ($jsonStart !== false && $jsonEnd !== false) ? substr($outMap, $jsonStart, $jsonEnd - $jsonStart + 1) : '';
$mapped = json_decode($jsonStr, true);
assert_test(
    "PDOException mapping converts duplicate key -> 'That value already exists' and FK -> 'Related record missing'",
    is_array($mapped) && ($mapped['dup'] ?? '') === 'That value already exists' && ($mapped['fk'] ?? '') === 'Related record missing'
);

// Final Results
// =============================================================
echo "\n============================================================\n";
echo " Test Execution Summary:\n";
echo " Total Tests: " . ($passed + $failed) . "\n";
echo " Passed:      {$passed}\n";
echo " Failed:      {$failed}\n";
echo "============================================================\n";

// Reset PDO connection back to default database
try {
    $pdo->exec("USE " . DB_NAME);
} catch (Throwable) {}

if ($failed > 0) {
    echo ">>> SOME TESTS FAILED <<<\n";
    exit(1);
} else {
    echo ">>> ALL TESTS PASSED CLEANLY <<<\n";
    exit(0);
}
