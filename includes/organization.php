<?php
declare(strict_types=1);

/**
 * Organizational Data Helpers: Departments & Designations.
 * Provides safe validation and permanent deletion logic.
 */

/**
 * Checks if a department can be safely deleted.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, department: ?array, counts: array<string, int>}
 */
function check_department_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Invalid department ID.',
            'department' => null,
            'counts'     => [],
        ];
    }

    $st = $pdo->prepare('SELECT id, company_id, name, description, is_active FROM departments WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $dept = $st->fetch();

    if (!$dept) {
        return [
            'can_delete' => false,
            'reason'     => 'Department not found.',
            'department' => null,
            'counts'     => [],
        ];
    }

    // 1. Must be inactive (retired)
    if ((int)$dept['is_active'] !== 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Active departments cannot be deleted. You must deactivate the department first.',
            'department' => $dept,
            'counts'     => [],
        ];
    }

    // 2. Count dependent data
    $queries = [
        'employees'          => 'SELECT COUNT(*) FROM employees WHERE department_id = ?',
        'job vacancies'      => 'SELECT COUNT(*) FROM job_vacancies WHERE department_id = ?',
        'job offers'         => 'SELECT COUNT(*) FROM job_offers WHERE department_id = ?',
        'employment history' => 'SELECT COUNT(*) FROM employment_history WHERE department_id = ?',
    ];

    $counts = [];
    $blocking = [];

    foreach ($queries as $label => $sql) {
        try {
            $chk = $pdo->prepare($sql);
            $chk->execute([$id]);
            $num = (int)$chk->fetchColumn();
            $counts[$label] = $num;
            if ($num > 0) {
                $blocking[] = $num . ' ' . ($num === 1 ? rtrim($label, 's') : $label);
            }
        } catch (Throwable) {}
    }

    try {
        $chkTr = $pdo->prepare('SELECT COUNT(*) FROM employee_transfers WHERE from_department_id = ? OR to_department_id = ?');
        $chkTr->execute([$id, $id]);
        $numTr = (int)$chkTr->fetchColumn();
        if ($numTr > 0) {
            $counts['transfers'] = $numTr;
            $blocking[] = $numTr . ' transfer record' . ($numTr === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    if (!empty($blocking)) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete department '{$dept['name']}': Used by " . implode(', ', $blocking) . '. Keep it deactivated instead.',
            'department' => $dept,
            'counts'     => $counts,
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'department' => $dept,
        'counts'     => $counts,
    ];
}

/**
 * Permanently deletes an inactive department with no dependent data inside a transaction.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{success: bool, message: string}
 */
function delete_department(int $id, PDO $pdo): array
{
    $chk = check_department_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $dept = $chk['department'];
    $name = (string)$dept['name'];
    $cid  = (int)$dept['company_id'];

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $del = $pdo->prepare('DELETE FROM departments WHERE id = ?');
        $del->execute([$id]);

        log_activity('department.delete', 'department', $id, [
            'name' => $name,
        ], $cid);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => "Department '{$name}' was permanently deleted.",
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete department {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Cannot delete department '{$name}' due to database integrity constraints. Keep it deactivated instead.",
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting department {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the department: ' . $e->getMessage(),
        ];
    }
}

/**
 * Checks if a designation can be safely deleted.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, designation: ?array, counts: array<string, int>}
 */
function check_designation_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Invalid designation ID.',
            'designation'=> null,
            'counts'     => [],
        ];
    }

    $st = $pdo->prepare('SELECT id, company_id, title, description, is_active FROM designations WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $desig = $st->fetch();

    if (!$desig) {
        return [
            'can_delete' => false,
            'reason'     => 'Designation not found.',
            'designation'=> null,
            'counts'     => [],
        ];
    }

    // 1. Must be inactive (retired)
    if ((int)$desig['is_active'] !== 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Active designations cannot be deleted. You must deactivate the designation first.',
            'designation'=> $desig,
            'counts'     => [],
        ];
    }

    // 2. Count dependent data
    $queries = [
        'employees'          => 'SELECT COUNT(*) FROM employees WHERE designation_id = ?',
        'job vacancies'      => 'SELECT COUNT(*) FROM job_vacancies WHERE designation_id = ?',
        'job offers'         => 'SELECT COUNT(*) FROM job_offers WHERE designation_id = ?',
        'employment history' => 'SELECT COUNT(*) FROM employment_history WHERE designation_id = ?',
    ];

    $counts = [];
    $blocking = [];

    foreach ($queries as $label => $sql) {
        try {
            $chk = $pdo->prepare($sql);
            $chk->execute([$id]);
            $num = (int)$chk->fetchColumn();
            $counts[$label] = $num;
            if ($num > 0) {
                $blocking[] = $num . ' ' . ($num === 1 ? rtrim($label, 's') : $label);
            }
        } catch (Throwable) {}
    }

    try {
        $chkTr = $pdo->prepare('SELECT COUNT(*) FROM employee_transfers WHERE from_designation_id = ? OR to_designation_id = ?');
        $chkTr->execute([$id, $id]);
        $numTr = (int)$chkTr->fetchColumn();
        if ($numTr > 0) {
            $counts['transfers'] = $numTr;
            $blocking[] = $numTr . ' transfer record' . ($numTr === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    if (!empty($blocking)) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete designation '{$desig['title']}': Used by " . implode(', ', $blocking) . '. Keep it deactivated instead.',
            'designation'=> $desig,
            'counts'     => $counts,
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'designation'=> $desig,
        'counts'     => $counts,
    ];
}

/**
 * Permanently deletes an inactive designation with no dependent data inside a transaction.
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{success: bool, message: string}
 */
function delete_designation(int $id, PDO $pdo): array
{
    $chk = check_designation_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $desig = $chk['designation'];
    $title = (string)$desig['title'];
    $cid   = (int)$desig['company_id'];

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $del = $pdo->prepare('DELETE FROM designations WHERE id = ?');
        $del->execute([$id]);

        log_activity('designation.delete', 'designation', $id, [
            'name' => $title,
        ], $cid);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => "Designation '{$title}' was permanently deleted.",
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete designation {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "Cannot delete designation '{$title}' due to database integrity constraints. Keep it deactivated instead.",
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting designation {$id}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the designation: ' . $e->getMessage(),
        ];
    }
}
