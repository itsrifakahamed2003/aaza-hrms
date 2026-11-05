<?php
declare(strict_types=1);

/**
 * AaZa Group HR - Attendance & Leave Operations Helpers (Phase 8).
 * Handles working days calculation, leave balances, attendance auto-sync,
 * status badges, and dashboard summary counters.
 */

/**
 * Return human-readable label list for leave request statuses.
 */
function leave_status_list(): array
{
    return [
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * Return Bootstrap badge HTML for leave request status.
 */
function leave_status_badge(string $status): string
{
    return status_badge('leave', $status);
}

/**
 * Return human-readable label list for attendance statuses.
 */
function attendance_status_list(): array
{
    return [
        'present'  => 'Present',
        'late'     => 'Late',
        'half_day' => 'Half Day',
        'absent'   => 'Absent',
        'leave'    => 'On Leave',
    ];
}

/**
 * Return Bootstrap badge HTML for attendance status.
 */
function attendance_status_badge(string $status): string
{
    return status_badge('attendance', $status);
}

/**
 * Retrieve the active employee record linked to a given user ID, or null if none.
 */
function get_linked_employee_for_user(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT e.*, c.name AS company_name, c.code AS company_code,
                d.name AS department_name, des.title AS designation_title
           FROM employees e
           JOIN companies c ON c.id = e.company_id
      LEFT JOIN departments d ON d.id = e.department_id
      LEFT JOIN designations des ON des.id = e.designation_id
          WHERE e.user_id = ? AND e.employment_status = 'active'
          LIMIT 1"
    );
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Validate that a user ID can be linked to an employee in a given company.
 *
 * Rules:
 *   (a) user must exist and be active (is_active = 1)
 *   (b) user must have an assignment to the SAME company as the employee
 *   (c) user must not already be linked to another employee
 *
 * @param PDO $pdo
 * @param int|null $userId User ID to link, or null/0 to unlink
 * @param int $companyId Target employee's company ID
 * @param int|null $currentEmployeeId Current employee ID if updating, to allow re-selecting same user
 * @return string|null Null on success, error message string on validation failure
 */
function validate_employee_user_link(PDO $pdo, ?int $userId, int $companyId, ?int $currentEmployeeId = null): ?string
{
    if ($userId === null || $userId <= 0) {
        return null;
    }

    // (a) User must be active
    $stUser = $pdo->prepare('SELECT id, username, full_name, is_active FROM users WHERE id = ? LIMIT 1');
    $stUser->execute([$userId]);
    $user = $stUser->fetch();
    if (!$user) {
        return 'The selected login account does not exist.';
    }
    if ((int)$user['is_active'] !== 1) {
        return 'The selected login account is inactive and cannot be linked.';
    }

    // (b) User must have an assignment to the SAME company as the employee
    $stAssign = $pdo->prepare('SELECT COUNT(*) FROM company_user_assignments WHERE user_id = ? AND company_id = ?');
    $stAssign->execute([$userId, $companyId]);
    if ((int)$stAssign->fetchColumn() === 0) {
        return 'The selected login account is not assigned to this company.';
    }

    // (c) User must not already be linked to another employee
    $sqlDup = 'SELECT id, employee_code, first_name, last_name FROM employees WHERE user_id = ?';
    $params = [$userId];
    if ($currentEmployeeId !== null && $currentEmployeeId > 0) {
        $sqlDup .= ' AND id != ?';
        $params[] = $currentEmployeeId;
    }
    $sqlDup .= ' LIMIT 1';
    $stDup = $pdo->prepare($sqlDup);
    $stDup->execute($params);
    $dup = $stDup->fetch();
    if ($dup) {
        return "The selected login account is already linked to another employee ({$dup['first_name']} {$dup['last_name']} - {$dup['employee_code']}).";
    }

    return null;
}

/**
 * Fetch active users assigned to a company who are not already linked to another employee.
 * If $currentEmployeeId is given, any user currently linked to that employee is also included.
 */
function get_available_users_for_company(PDO $pdo, int $companyId, ?int $currentEmployeeId = null): array
{
    $sql = "
        SELECT DISTINCT u.id, u.username, u.full_name, u.email, cua.company_id
          FROM users u
          JOIN company_user_assignments cua ON cua.user_id = u.id AND cua.company_id = ?
     LEFT JOIN employees e ON e.user_id = u.id " . ($currentEmployeeId ? "AND e.id != ?" : "") . "
         WHERE u.is_active = 1
           AND e.id IS NULL
         ORDER BY u.full_name, u.username
    ";
    $params = [$companyId];
    if ($currentEmployeeId) {
        $params[] = $currentEmployeeId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Retrieve active public holidays within a date range for a specific company or group-wide.
 *
 * @param string $startDate Y-m-d
 * @param string $endDate Y-m-d
 * @param int|null $companyId
 * @param PDO|null $pdo
 * @return array<string, string> Map of 'YYYY-MM-DD' => holiday name
 */
function get_holidays_in_range(string $startDate, string $endDate, ?int $companyId = null, ?PDO $pdo = null, bool $clearCache = false): array
{
    $pdo = $pdo ?? db();
    static $cache = [];
    if ($clearCache) {
        $cache = [];
    }
    if ($startDate === '' && $endDate === '') {
        return [];
    }
    $cacheKey = "{$startDate}:{$endDate}:" . (int)$companyId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        if ($companyId !== null && $companyId > 0) {
            $st = $pdo->prepare(
                "SELECT holiday_date, name
                   FROM company_holidays
                  WHERE is_active = 1
                    AND holiday_date >= ?
                    AND holiday_date <= ?
                    AND (company_id IS NULL OR company_id = ?)
                  ORDER BY holiday_date ASC"
            );
            $st->execute([$startDate, $endDate, $companyId]);
        } else {
            $st = $pdo->prepare(
                "SELECT holiday_date, name
                   FROM company_holidays
                  WHERE is_active = 1
                    AND holiday_date >= ?
                    AND holiday_date <= ?
                    AND company_id IS NULL
                  ORDER BY holiday_date ASC"
            );
            $st->execute([$startDate, $endDate]);
        }

        $holidays = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $holidays[(string)$row['holiday_date']] = (string)$row['name'];
        }
        return $cache[$cacheKey] = $holidays;
    } catch (Throwable) {
        // Table may not exist yet during migration bootstrap
        return $cache[$cacheKey] = [];
    }
}

/**
 * Return list of working day dates (Y-m-d) between startDate and endDate inclusive.
 * Respects company weekly-off setting (or default WEEKLY_OFF_DAYS) and excludes active public holidays.
 *
 * @param string $startDate Y-m-d
 * @param string $endDate Y-m-d
 * @param array|null $offDays e.g. [6, 7] (Saturday, Sunday)
 * @param int|null $companyId
 * @return string[]
 */
function get_working_days_in_range(string $startDate, string $endDate, ?array $offDays = null, ?int $companyId = null): array
{
    if ($offDays === null && $companyId !== null && $companyId > 0 && function_exists('get_company_setting')) {
        $jsonOff = get_company_setting(db(), $companyId, 'attendance.weekly_off_days', null);
        if ($jsonOff !== null) {
            $decoded = json_decode((string)$jsonOff, true);
            if (is_array($decoded) && !empty($decoded)) {
                $offDays = array_map('intval', $decoded);
            }
        }
    }
    $offDays = $offDays ?? (defined('WEEKLY_OFF_DAYS') ? WEEKLY_OFF_DAYS : [6, 7]);
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);
    if ($start > $end) {
        return [];
    }

    $diffDays = (int)$start->diff($end)->format('%a');
    if ($diffDays > 366) {
        // Safety guard against massive unbounded loops
        return [];
    }

    // Exclude active public holidays for this company & group-wide
    $holidays = get_holidays_in_range($startDate, $endDate, $companyId);

    $dates = [];
    $curr = clone $start;
    while ($curr <= $end) {
        $isoDay = (int)$curr->format('N'); // 1 = Monday ... 7 = Sunday
        $ymd = $curr->format('Y-m-d');
        if (!in_array($isoDay, $offDays, true) && !isset($holidays[$ymd])) {
            $dates[] = $ymd;
        }
        $curr->modify('+1 day');
    }
    return $dates;
}

/**
 * Calculate total working days for a leave request on the server.
 * Count Monday to Friday only (skips WEEKLY_OFF_DAYS or company off-days). Half day = 0.5.
 */
function calculate_working_days(string $startDate, string $endDate, bool $isHalfDay = false, ?array $offDays = null, ?int $companyId = null): float
{
    $workingDays = get_working_days_in_range($startDate, $endDate, $offDays, $companyId);
    $count = count($workingDays);

    if ($count === 0) {
        return 0.0;
    }

    if ($isHalfDay) {
        // Half day is permitted only when start_date === end_date and falls on a working day
        return 0.5;
    }

    return (float)$count;
}

/**
 * Compute leave balance for an employee for a specific leave type and calendar year.
 * Splitting requests by year if a request crosses Dec 31st.
 *
 * @return array{allowance: float, is_unlimited: bool, taken: float, pending: float, remaining: float}
 */
function leave_balance(PDO $pdo, int $employeeId, int $leaveTypeId, int $year): array
{
    // Fetch employee company_id for company weekly off & public holiday calculation
    $stEmp = $pdo->prepare("SELECT company_id FROM employees WHERE id = ? LIMIT 1");
    $stEmp->execute([$employeeId]);
    $empCompanyId = (int)($stEmp->fetchColumn() ?: 0);

    // Fetch leave type allowance
    $stType = $pdo->prepare("SELECT days_per_year FROM leave_types WHERE id = ?");
    $stType->execute([$leaveTypeId]);
    $typeRow = $stType->fetch();
    $allowance = $typeRow ? (float)$typeRow['days_per_year'] : 0.0;
    $isUnlimited = ($allowance <= 0.0);

    // Year range boundaries
    $yearStart = "$year-01-01";
    $yearEnd   = "$year-12-31";

    // Query all approved or pending requests that overlap this calendar year
    $stReq = $pdo->prepare(
        "SELECT id, start_date, end_date, total_days, half_day, status
           FROM leave_requests
          WHERE employee_id = ?
            AND leave_type_id = ?
            AND status IN ('approved', 'pending')
            AND start_date <= ?
            AND end_date >= ?"
    );
    $stReq->execute([$employeeId, $leaveTypeId, $yearEnd, $yearStart]);
    $requests = $stReq->fetchAll();

    $taken = 0.0;
    $pending = 0.0;

    foreach ($requests as $req) {
        $rStart = (string)$req['start_date'];
        $rEnd   = (string)$req['end_date'];
        $isHalf = (bool)$req['half_day'];
        $status = (string)$req['status'];

        // Overlap boundaries with the target calendar year
        $overlapStart = max($rStart, $yearStart);
        $overlapEnd   = min($rEnd, $yearEnd);

        if ($isHalf && $rStart === $rEnd) {
            $daysInYear = 0.5;
        } else {
            $daysInYear = (float)count(get_working_days_in_range($overlapStart, $overlapEnd, null, $empCompanyId));
        }

        if ($status === 'approved') {
            $taken += $daysInYear;
        } elseif ($status === 'pending') {
            $pending += $daysInYear;
        }
    }

    $remaining = $isUnlimited ? INF : max(0.0, $allowance - $taken);

    return [
        'allowance'    => $allowance,
        'is_unlimited' => $isUnlimited,
        'taken'        => $taken,
        'pending'      => $pending,
        'remaining'    => $remaining,
    ];
}

/**
 * Check if the employee already has an active (pending or approved) leave request
 * overlapping the given date range.
 */
function has_overlapping_leave(PDO $pdo, int $employeeId, string $startDate, string $endDate, ?int $excludeRequestId = null): bool
{
    $sql = "SELECT 1 FROM leave_requests
             WHERE employee_id = ?
               AND status IN ('pending', 'approved')
               AND start_date <= ?
               AND end_date >= ?";
    $params = [$employeeId, $endDate, $startDate];

    if ($excludeRequestId !== null && $excludeRequestId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeRequestId;
    }
    $sql .= " LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

/**
 * Automatically create attendance rows with status 'leave' for each working day
 * of an approved leave request (skip weekly-off days).
 * Only inserts when no manual record exists or the existing record is 'absent'.
 *
 * @return int Number of attendance records inserted or updated to 'leave'
 */
function sync_approved_leave_attendance(PDO $pdo, int $leaveRequestId): int
{
    $stReq = $pdo->prepare(
        "SELECT lr.*, lt.name AS leave_type_name
           FROM leave_requests lr
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.id = ? AND lr.status = 'approved'"
    );
    $stReq->execute([$leaveRequestId]);
    $req = $stReq->fetch();
    if (!$req) {
        return 0;
    }

    $empId     = (int)$req['employee_id'];
    $companyId = (int)$req['company_id'];
    $isHalf    = (bool)$req['half_day'];
    $typeName  = (string)$req['leave_type_name'];
    $workingDays = get_working_days_in_range((string)$req['start_date'], (string)$req['end_date'], null, $companyId);

    $synced = 0;
    $targetStatus = $isHalf ? 'half_day' : 'leave';
    $remarks = "Approved Leave #{$leaveRequestId} ($typeName)";

    foreach ($workingDays as $dateStr) {
        // Check existing record
        $chk = $pdo->prepare("SELECT id, status FROM attendance WHERE employee_id = ? AND att_date = ?");
        $chk->execute([$empId, $dateStr]);
        $existing = $chk->fetch();

        if (!$existing) {
            $ins = $pdo->prepare(
                "INSERT INTO attendance (employee_id, company_id, att_date, status, remarks, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $ins->execute([$empId, $companyId, $dateStr, $targetStatus, $remarks, current_user_id() ?: null]);
            $synced++;
        } elseif ((string)$existing['status'] === 'absent') {
            $upd = $pdo->prepare(
                "UPDATE attendance
                    SET status = ?, remarks = ?, recorded_by = ?
                  WHERE id = ?"
            );
            $upd->execute([$targetStatus, $remarks, current_user_id() ?: null, (int)$existing['id']]);
            $synced++;
        }
    }

    return $synced;
}

/**
 * Remove auto-created attendance rows matching an approved leave request when it is cancelled.
 *
 * @return int Number of attendance records deleted
 */
function remove_approved_leave_attendance(PDO $pdo, int $leaveRequestId): int
{
    $stReq = $pdo->prepare("SELECT employee_id, start_date, end_date FROM leave_requests WHERE id = ?");
    $stReq->execute([$leaveRequestId]);
    $req = $stReq->fetch();
    if (!$req) {
        return 0;
    }

    $empId = (int)$req['employee_id'];
    $sDate = (string)$req['start_date'];
    $eDate = (string)$req['end_date'];
    $pattern = "Approved Leave #{$leaveRequestId} (%";

    $del = $pdo->prepare(
        "DELETE FROM attendance
          WHERE employee_id = ?
            AND att_date BETWEEN ? AND ?
            AND status IN ('leave', 'half_day')
            AND remarks LIKE ?"
    );
    $del->execute([$empId, $sDate, $eDate, $pattern]);
    return $del->rowCount();
}

/**
 * Count total pending leave requests, optionally scoped by company.
 */
function count_pending_leaves(PDO $pdo, ?int $companyId = null): int
{
    $sql = "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'";
    $params = [];
    if ($companyId !== null && $companyId > 0) {
        $sql .= " AND company_id = ?";
        $params[] = $companyId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * Escape a CSV cell value to prevent CSV Formula Injection (CWE-1236).
 * Prepends a single quote or tab if the value starts with =, +, -, @, or tab/CR.
 */
function escape_csv_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $str = (string)$value;
    if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $str;
    }
    return $str;
}

/**
 * Checks if a leave type can be safely deleted.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, leave_type: ?array, counts: array<string, int>}
 */
function check_leave_type_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Invalid leave type ID.',
            'leave_type' => null,
            'counts'     => [],
        ];
    }

    $st = $pdo->prepare('SELECT * FROM leave_types WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $lt = $st->fetch();

    if (!$lt) {
        return [
            'can_delete' => false,
            'reason'     => 'Leave type not found.',
            'leave_type' => null,
            'counts'     => [],
        ];
    }

    // 1. Must be inactive (retired)
    if ((int)$lt['is_active'] !== 0) {
        return [
            'can_delete' => false,
            'reason'     => "Active leave types cannot be deleted. You must deactivate '{$lt['name']}' first.",
            'leave_type' => $lt,
            'counts'     => [],
        ];
    }

    // 2. Count dependent data (leave requests)
    $chkReq = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE leave_type_id = ?');
    $chkReq->execute([$id]);
    $reqCount = (int)$chkReq->fetchColumn();

    if ($reqCount > 0) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete leave type '{$lt['name']}': Used by {$reqCount} leave request" . ($reqCount === 1 ? '' : 's') . '. Keep it deactivated instead.',
            'leave_type' => $lt,
            'counts'     => ['leave_requests' => $reqCount],
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'leave_type' => $lt,
        'counts'     => ['leave_requests' => 0],
    ];
}

/**
 * Permanently deletes an inactive leave type with no dependent data inside a transaction.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{success: bool, message: string}
 */
function delete_leave_type(int $id, PDO $pdo): array
{
    $chk = check_leave_type_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $lt   = $chk['leave_type'];
    $name = (string)$lt['name'];
    $cid  = $lt['company_id'] !== null ? (int)$lt['company_id'] : null;

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $del = $pdo->prepare('DELETE FROM leave_types WHERE id = ?');
        $del->execute([$id]);

        log_activity('leave_type.delete', 'leave_type', $id, [
            'name'       => $name,
            'company_id' => $cid,
        ], $cid);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => "Leave type '{$name}' was permanently deleted.",
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete leave type {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Cannot delete leave type '{$name}' due to database integrity constraints. Keep it deactivated instead.",
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting leave type {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the leave type: ' . $e->getMessage(),
        ];
    }
}

