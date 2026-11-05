<?php
declare(strict_types=1);

/**
 * AaZa Group - Multi-Company HR & Recruitment Management System
 * User Management & Deletion Safety Helpers
 */

/**
 * Checks whether a user can be safely and permanently deleted.
 *
 * Rules:
 * - The user must exist.
 * - Nobody can delete their own account.
 * - The user must be deactivated (is_active = 0).
 * - The last remaining active group administrator can never be deleted.
 * - Block if referenced by important operational records (employees.user_id,
 *   created_by, approved_by, decided_by, interviewers, evaluators, etc.).
 *
 * @param int $userId Target user ID to delete
 * @param PDO $pdo Database connection
 * @param int $currentUserId ID of currently logged in user
 * @return array{can_delete: bool, reason: string, user: ?array, blocking: array<string>}
 */
function check_user_deletable(int $userId, PDO $pdo, int $currentUserId = 0): array
{
    if ($userId <= 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Invalid user ID.',
            'user'       => null,
            'blocking'   => ['Invalid user ID'],
        ];
    }

    $st = $pdo->prepare('SELECT id, username, email, full_name, is_group_admin, is_active FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $user = $st->fetch();

    if (!$user) {
        return [
            'can_delete' => false,
            'reason'     => 'User not found.',
            'user'       => null,
            'blocking'   => ['User not found'],
        ];
    }

    // 1. Nobody can delete their own account
    if ($currentUserId > 0 && $userId === $currentUserId) {
        return [
            'can_delete' => false,
            'reason'     => 'You cannot delete your own account.',
            'user'       => $user,
            'blocking'   => ['Self-deletion is strictly prohibited'],
        ];
    }

    // 2. User must be inactive (is_active = 0)
    if ((int)$user['is_active'] !== 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Cannot delete an active user. Please deactivate the user first.',
            'user'       => $user,
            'blocking'   => ['User account is currently active'],
        ];
    }

    // 3. Last remaining active group admin can never be deleted
    if ((int)$user['is_group_admin'] === 1) {
        $stAdmin = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_group_admin = 1 AND is_active = 1 AND id != ?');
        $stAdmin->execute([$userId]);
        $remainingAdmins = (int)$stAdmin->fetchColumn();
        if ($remainingAdmins === 0) {
            return [
                'can_delete' => false,
                'reason'     => 'Cannot delete the last remaining active Group Administrator.',
                'user'       => $user,
                'blocking'   => ['Last remaining Group Administrator'],
            ];
        }
    }

    // 4. Dependency checks: Important historical and relational records
    $blocking = [];

    // Check 4a: Linked employee profile (employees.user_id)
    try {
        $stEmp = $pdo->prepare('SELECT employee_code, first_name, last_name FROM employees WHERE user_id = ? LIMIT 1');
        $stEmp->execute([$userId]);
        $linkedEmp = $stEmp->fetch();
        if ($linkedEmp) {
            $blocking[] = 'Linked to employee ' . ($linkedEmp['employee_code'] ?? 'record');
        }
    } catch (Throwable) {}

    // Check 4b: Created employees (employees.created_by)
    try {
        $stEmpC = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE created_by = ?');
        $stEmpC->execute([$userId]);
        $cnt = (int)$stEmpC->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' employee record' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4c: Uploaded employee documents (employee_documents.uploaded_by)
    try {
        $stDoc = $pdo->prepare('SELECT COUNT(*) FROM employee_documents WHERE uploaded_by = ?');
        $stDoc->execute([$userId]);
        $cnt = (int)$stDoc->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' employee document' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4d: Recorded employment history (employment_history.recorded_by)
    try {
        $stEh = $pdo->prepare('SELECT COUNT(*) FROM employment_history WHERE recorded_by = ?');
        $stEh->execute([$userId]);
        $cnt = (int)$stEh->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' employment history record' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4e: Employee transfers (requested_by or approved_by)
    try {
        $stTr = $pdo->prepare('SELECT COUNT(*) FROM employee_transfers WHERE requested_by = ? OR approved_by = ?');
        $stTr->execute([$userId, $userId]);
        $cnt = (int)$stTr->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' employee transfer record' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4f: Created job vacancies (job_vacancies.created_by)
    try {
        $stVac = $pdo->prepare('SELECT COUNT(*) FROM job_vacancies WHERE created_by = ?');
        $stVac->execute([$userId]);
        $cnt = (int)$stVac->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' job ' . ($cnt === 1 ? 'vacancy' : 'vacancies');
        }
    } catch (Throwable) {}

    // Check 4g: Created candidate profiles (candidates.created_by)
    try {
        $stCand = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE created_by = ?');
        $stCand->execute([$userId]);
        $cnt = (int)$stCand->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' candidate profile' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4h: Candidate applications (candidate_applications.created_by)
    try {
        $stApp = $pdo->prepare('SELECT COUNT(*) FROM candidate_applications WHERE created_by = ?');
        $stApp->execute([$userId]);
        $cnt = (int)$stApp->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' job application' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4i: Uploaded candidate documents (candidate_documents.uploaded_by)
    try {
        $stCd = $pdo->prepare('SELECT COUNT(*) FROM candidate_documents WHERE uploaded_by = ?');
        $stCd->execute([$userId]);
        $cnt = (int)$stCd->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' candidate document' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4j: Candidate status history (candidate_status_history.changed_by)
    try {
        $stCsh = $pdo->prepare('SELECT COUNT(*) FROM candidate_status_history WHERE changed_by = ?');
        $stCsh->execute([$userId]);
        $cnt = (int)$stCsh->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' candidate status change' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4k: Scheduled interviews (interviews.created_by)
    try {
        $stInt = $pdo->prepare('SELECT COUNT(*) FROM interviews WHERE created_by = ?');
        $stInt->execute([$userId]);
        $cnt = (int)$stInt->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' interview session' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4l: Interview panel assignments (interview_panel.user_id or interviewers.user_id)
    try {
        $stPnl = $pdo->prepare('SELECT COUNT(*) FROM interview_panel WHERE user_id = ?');
        $stPnl->execute([$userId]);
        $cnt = (int)$stPnl->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' interview panel assignment' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {
        try {
            $stIv = $pdo->prepare('SELECT COUNT(*) FROM interviewers WHERE user_id = ?');
            $stIv->execute([$userId]);
            $cnt = (int)$stIv->fetchColumn();
            if ($cnt > 0) {
                $blocking[] = $cnt . ' interviewer assignment' . ($cnt === 1 ? '' : 's');
            }
        } catch (Throwable) {}
    }

    // Check 4m: Submitted interview evaluations (interview_evaluations.evaluator_id)
    try {
        $stIe = $pdo->prepare('SELECT COUNT(*) FROM interview_evaluations WHERE evaluator_id = ?');
        $stIe->execute([$userId]);
        $cnt = (int)$stIe->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' interview evaluation' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4n: Job offers created (job_offers.created_by)
    try {
        $stJo = $pdo->prepare('SELECT COUNT(*) FROM job_offers WHERE created_by = ?');
        $stJo->execute([$userId]);
        $cnt = (int)$stJo->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' job offer' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4o: Leave decisions (leave_requests.decided_by)
    try {
        $stLd = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE decided_by = ?');
        $stLd->execute([$userId]);
        $cnt = (int)$stLd->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' leave decision' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4p: Leave approvals (leave_approvals.approver_id)
    try {
        $stLa = $pdo->prepare('SELECT COUNT(*) FROM leave_approvals WHERE approver_id = ?');
        $stLa->execute([$userId]);
        $cnt = (int)$stLa->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' leave approval' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    // Check 4q: Recorded attendance logs (attendance.recorded_by)
    try {
        $stAtt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE recorded_by = ?');
        $stAtt->execute([$userId]);
        $cnt = (int)$stAtt->fetchColumn();
        if ($cnt > 0) {
            $blocking[] = $cnt . ' attendance record' . ($cnt === 1 ? '' : 's');
        }
    } catch (Throwable) {}

    if (!empty($blocking)) {
        $reason = implode('; ', $blocking) . '. Keep this user deactivated instead.';
        return [
            'can_delete' => false,
            'reason'     => $reason,
            'user'       => $user,
            'blocking'   => $blocking,
        ];
    }

    return [
        'can_delete' => true,
        'reason'     => '',
        'user'       => $user,
        'blocking'   => [],
    ];
}

/**
 * Permanently deletes an inactive user with no dependent records inside a transaction.
 * Cleans up user assignments, overrides, and notifications first, then deletes the user.
 * Logs the deletion to activity_logs with the username and email preserved.
 *
 * @param int $userId Target user ID
 * @param PDO $pdo Database connection
 * @param int $currentUserId ID of currently logged in user
 * @return array{success: bool, message: string}
 */
function delete_user(int $userId, PDO $pdo, int $currentUserId = 0): array
{
    $check = check_user_deletable($userId, $pdo, $currentUserId);
    if (!$check['can_delete']) {
        return [
            'success' => false,
            'message' => $check['reason'],
        ];
    }

    $user = $check['user'];
    $username = (string)$user['username'];
    $email = (string)$user['email'];
    $fullName = (string)$user['full_name'];

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // 1. Clean up company user assignments
        $pdo->prepare('DELETE FROM company_user_assignments WHERE user_id = ?')->execute([$userId]);
        try {
            $pdo->prepare('UPDATE company_user_assignments SET assigned_by = NULL WHERE assigned_by = ?')->execute([$userId]);
        } catch (Throwable) {}

        // 2. Clean up user permission overrides
        try {
            $pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id = ?')->execute([$userId]);
        } catch (Throwable) {}

        // 3. Clean up notifications
        try {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
        } catch (Throwable) {}

        // 4. Log the deletion to activity_logs before hard deletion so the audit log persists
        log_activity('user.delete', 'user', $userId, [
            'username'  => $username,
            'email'     => $email,
            'full_name' => $fullName,
        ], null);

        // 5. Delete the user record
        $del = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$userId]);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'message' => 'User "@' . $username . '" (' . $fullName . ') was permanently deleted.',
        ];
    } catch (PDOException $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete user {$userId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Cannot delete user due to existing database relationships. Please keep the user deactivated instead.',
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Unexpected error deleting user {$userId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while deleting the user: ' . $e->getMessage(),
        ];
    }
}
