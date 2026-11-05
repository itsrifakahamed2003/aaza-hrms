<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Confidential Employee Performance Domain Helpers
 *
 * Implements granular access scoping, reporting manager traversal,
 * rating labels, status badges, and workflow guards.
 */

/**
 * Qualitative label for overall performance rating (1 to 5).
 */
function performance_rating_label(int $rating): string
{
    return match ($rating) {
        5 => 'Outstanding',
        4 => 'Exceeds Expectations',
        3 => 'Meets Expectations',
        2 => 'Needs Improvement',
        1 => 'Unsatisfactory',
        default => 'Not Rated',
    };
}

/**
 * Color badge class for overall performance rating.
 */
function performance_rating_badge_class(int $rating): string
{
    return match ($rating) {
        5 => 'badge-soft-success',
        4 => 'badge-soft-primary',
        3 => 'badge-soft-info',
        2 => 'badge-soft-warning',
        1 => 'badge-soft-danger',
        default => 'badge-soft-secondary',
    };
}

/**
 * HTML star display for ratings (1 to 5) with optional label.
 */
function performance_rating_stars_html(int $rating, bool $showLabel = true): string
{
    $html = '<div class="d-inline-flex align-items-center gap-1">';
    $html .= '<span class="text-warning text-nowrap">';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="bi bi-star-fill"></i>';
        } else {
            $html .= '<i class="bi bi-star text-muted opacity-50"></i>';
        }
    }
    $html .= '</span>';

    if ($showLabel && $rating >= 1 && $rating <= 5) {
        $cls = performance_rating_badge_class($rating);
        $lbl = performance_rating_label($rating);
        $html .= ' <span class="badge ' . e($cls) . ' ms-1">' . e($rating . ' - ' . $lbl) . '</span>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Review workflow status badge.
 */
function performance_status_badge(string $status): string
{
    return match ($status) {
        'draft'        => '<span class="badge badge-soft-secondary"><i class="bi bi-pencil me-1"></i>Draft</span>',
        'submitted'    => '<span class="badge badge-soft-warning"><i class="bi bi-hourglass-split me-1"></i>Submitted</span>',
        'acknowledged' => '<span class="badge badge-soft-success"><i class="bi bi-check-circle me-1"></i>Acknowledged</span>',
        default        => '<span class="badge badge-soft-secondary">' . e(ucfirst($status)) . '</span>',
    };
}

/**
 * Goal progress status badge.
 */
function performance_goal_status_badge(string $status): string
{
    return match ($status) {
        'open'     => '<span class="badge badge-soft-primary"><i class="bi bi-clock me-1"></i>Open</span>',
        'achieved' => '<span class="badge badge-soft-success"><i class="bi bi-check-lg me-1"></i>Achieved</span>',
        'missed'   => '<span class="badge badge-soft-danger"><i class="bi bi-x-lg me-1"></i>Missed</span>',
        default    => '<span class="badge badge-soft-secondary">' . e(ucfirst($status)) . '</span>',
    };
}

/**
 * Checks if a user is the direct reporting manager of any active employee.
 */
function is_reporting_manager_of_any(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $myEmp = get_linked_employee_for_user($pdo, $userId);
    if (!$myEmp) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM employees WHERE reporting_manager_id = ? AND employment_status = 'active'"
    );
    $st->execute([(int)$myEmp['id']]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Returns an array of employee IDs who report directly to this user's linked employee profile.
 *
 * @return int[]
 */
function get_direct_report_employee_ids(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $myEmp = get_linked_employee_for_user($pdo, $userId);
    if (!$myEmp) {
        return [];
    }

    $st = $pdo->prepare(
        "SELECT id FROM employees WHERE reporting_manager_id = ? AND employment_status = 'active' ORDER BY first_name, last_name"
    );
    $st->execute([(int)$myEmp['id']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Checks whether a user can create or manage performance reviews for a specific employee.
 * Granted if:
 *  - User is group_admin
 *  - User holds 'performance.manage' in the employee's company
 *  - User is the direct reporting manager of the employee
 */
function can_user_manage_employee_performance(PDO $pdo, int $userId, int $employeeId, ?int $companyId = null): bool
{
    if ($userId <= 0 || $employeeId <= 0) {
        return false;
    }

    // Check group admin status
    if ($userId === current_user_id()) {
        if (is_group_admin()) {
            return true;
        }
    } else {
        $stAdmin = $pdo->prepare('SELECT is_group_admin FROM users WHERE id = ? LIMIT 1');
        $stAdmin->execute([$userId]);
        if ((bool)$stAdmin->fetchColumn()) {
            return true;
        }
    }

    // Fetch employee details if company or reporting manager not provided
    $st = $pdo->prepare('SELECT id, company_id, reporting_manager_id FROM employees WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return false;
    }

    $empCompanyId = (int)$emp['company_id'];

    // Check if user has performance.manage in the employee's company
    $hasManage = false;
    if ($userId === current_user_id()) {
        $hasManage = user_can('performance.manage', $empCompanyId);
    } else {
        $stPerm = $pdo->prepare(
            "SELECT COUNT(*) FROM company_user_assignments cua
               JOIN role_permissions rp ON rp.role_id = cua.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE cua.user_id = ? AND cua.company_id = ? AND p.code = 'performance.manage'"
        );
        $stPerm->execute([$userId, $empCompanyId]);
        $hasManage = (int)$stPerm->fetchColumn() > 0;
    }

    if ($hasManage) {
        return true;
    }

    // Check if user is the direct reporting manager of this employee
    $myEmp = get_linked_employee_for_user($pdo, $userId);
    if ($myEmp && (int)$emp['reporting_manager_id'] === (int)$myEmp['id']) {
        return true;
    }

    return false;
}

/**
 * Checks whether a user is authorized to view a specific performance review.
 * Granted if:
 *  - User is group_admin
 *  - User holds 'performance.view' or 'performance.manage' in review's company
 *  - User is the reviewer who authored the review
 *  - User is the employee's reporting manager
 *  - User is the reviewed employee AND status is submitted or acknowledged (confidential draft guard)
 */
function can_user_view_performance_review(PDO $pdo, int $userId, array $review): bool
{
    if ($userId <= 0 || empty($review['id'])) {
        return false;
    }

    if ($userId === current_user_id()) {
        if (is_group_admin()) {
            return true;
        }
    } else {
        $stAdmin = $pdo->prepare('SELECT is_group_admin FROM users WHERE id = ? LIMIT 1');
        $stAdmin->execute([$userId]);
        if ((bool)$stAdmin->fetchColumn()) {
            return true;
        }
    }

    $companyId = (int)$review['company_id'];
    $employeeId = (int)$review['employee_id'];
    $status = (string)$review['status'];

    // Check HR / Admin scope
    $hasViewOrManage = false;
    if ($userId === current_user_id()) {
        $hasViewOrManage = user_can('performance.view', $companyId) || user_can('performance.manage', $companyId);
    } else {
        $stPerm = $pdo->prepare(
            "SELECT COUNT(*) FROM company_user_assignments cua
               JOIN role_permissions rp ON rp.role_id = cua.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE cua.user_id = ? AND cua.company_id = ? AND p.code IN ('performance.view', 'performance.manage')"
        );
        $stPerm->execute([$userId, $companyId]);
        $hasViewOrManage = (int)$stPerm->fetchColumn() > 0;
    }

    if ($hasViewOrManage) {
        return true;
    }

    // Direct reviewer who authored the review
    if ((int)($review['reviewer_user_id'] ?? 0) === $userId) {
        return true;
    }

    // Fetch employee data
    $st = $pdo->prepare('SELECT id, user_id, reporting_manager_id FROM employees WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return false;
    }

    // Reporting manager access
    $myEmp = get_linked_employee_for_user($pdo, $userId);
    if ($myEmp && (int)$emp['reporting_manager_id'] === (int)$myEmp['id']) {
        return true;
    }

    // Employee's own review access: allowed ONLY when submitted or acknowledged
    if (!empty($emp['user_id']) && (int)$emp['user_id'] === $userId) {
        if ($status === 'draft') {
            // Confidential guard: Draft reviews are hidden from the employee until submitted by manager
            return false;
        }
        return true;
    }

    return false;
}

/**
 * Checks whether a user can edit an existing performance review.
 * RULE: Reviews CANNOT be edited once acknowledged by the employee. HR can reopen with a reason.
 */
function can_user_edit_performance_review(PDO $pdo, int $userId, array $review): bool
{
    if ($userId <= 0 || empty($review['id'])) {
        return false;
    }

    // Locked after acknowledge
    if (($review['status'] ?? '') === 'acknowledged') {
        return false;
    }

    return can_user_manage_employee_performance($pdo, $userId, (int)$review['employee_id'], (int)$review['company_id']);
}

/**
 * Checks whether the current user is the employee who can acknowledge a submitted review.
 */
function can_user_acknowledge_performance_review(PDO $pdo, int $userId, array $review): bool
{
    if ($userId <= 0 || empty($review['id'])) {
        return false;
    }

    if (($review['status'] ?? '') !== 'submitted') {
        return false;
    }

    // Must be the reviewed employee
    $st = $pdo->prepare('SELECT user_id FROM employees WHERE id = ? LIMIT 1');
    $st->execute([(int)$review['employee_id']]);
    $empUserId = (int)$st->fetchColumn();

    return $empUserId === $userId;
}

/**
 * Checks whether a user has permission to reopen an acknowledged review.
 * Granted strictly to HR / Company Admin / Group Admin.
 */
function can_user_reopen_performance_review(PDO $pdo, int $userId, array $review): bool
{
    if ($userId <= 0 || empty($review['id'])) {
        return false;
    }

    if (($review['status'] ?? '') !== 'acknowledged') {
        return false;
    }

    if ($userId === current_user_id()) {
        if (is_group_admin()) {
            return true;
        }
        return user_can('performance.manage', (int)$review['company_id']);
    } else {
        $stAdmin = $pdo->prepare('SELECT is_group_admin FROM users WHERE id = ? LIMIT 1');
        $stAdmin->execute([$userId]);
        if ((bool)$stAdmin->fetchColumn()) {
            return true;
        }
        $stPerm = $pdo->prepare(
            "SELECT COUNT(*) FROM company_user_assignments cua
               JOIN role_permissions rp ON rp.role_id = cua.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE cua.user_id = ? AND cua.company_id = ? AND p.code = 'performance.manage'"
        );
        $stPerm->execute([$userId, (int)$review['company_id']]);
        return (int)$stPerm->fetchColumn() > 0;
    }
}

/**
 * Builds SQL scoping conditions and parameters for listing performance reviews
 * based on the user's role, accessible companies, reporting relationships, and own reviews.
 *
 * @return array{where_sql: string, params: array}
 */
function build_performance_scope_filter(PDO $pdo, int $userId, ?int $filterCompanyId = null): array
{
    $isAdmin = is_group_admin();
    $manageCompanyIds = accessible_company_ids('performance.manage');
    $viewCompanyIds   = accessible_company_ids('performance.view');
    $hrCompanyIds     = array_unique(array_merge($manageCompanyIds, $viewCompanyIds));

    $myEmp = get_linked_employee_for_user($pdo, $userId);
    $myEmpId = $myEmp ? (int)$myEmp['id'] : 0;
    $directReportIds = $myEmpId > 0 ? get_direct_report_employee_ids($pdo, $userId) : [];

    $orConditions = [];
    $params = [];

    // 1. HR / Company Admin scope
    if ($isAdmin) {
        if ($filterCompanyId && $filterCompanyId > 0) {
            $orConditions[] = "pr.company_id = ?";
            $params[] = $filterCompanyId;
        } else {
            $orConditions[] = "1=1";
        }
    } elseif (!empty($hrCompanyIds)) {
        $allowedCids = $filterCompanyId && in_array($filterCompanyId, $hrCompanyIds, true)
            ? [$filterCompanyId]
            : $hrCompanyIds;
        $ph = implode(',', array_fill(0, count($allowedCids), '?'));
        $orConditions[] = "pr.company_id IN ($ph)";
        foreach ($allowedCids as $cid) {
            $params[] = $cid;
        }
    }

    // 2. Reporting Manager scope (direct reports + authored reviews)
    if (!empty($directReportIds)) {
        $phReports = implode(',', array_fill(0, count($directReportIds), '?'));
        $subCond = "(pr.employee_id IN ($phReports) OR pr.reviewer_user_id = ?)";
        if ($filterCompanyId && $filterCompanyId > 0) {
            $subCond = "(pr.company_id = ? AND " . $subCond . ")";
            $params[] = $filterCompanyId;
        }
        $orConditions[] = $subCond;
        foreach ($directReportIds as $rid) {
            $params[] = $rid;
        }
        $params[] = $userId;
    } elseif ($myEmpId > 0) {
        // Reviewer who authored reviews
        $subCond = "pr.reviewer_user_id = ?";
        if ($filterCompanyId && $filterCompanyId > 0) {
            $subCond = "(pr.company_id = ? AND pr.reviewer_user_id = ?)";
            $params[] = $filterCompanyId;
        }
        $orConditions[] = $subCond;
        $params[] = $userId;
    }

    // 3. Employee own reviews (only submitted or acknowledged)
    if ($myEmpId > 0) {
        $subCond = "(pr.employee_id = ? AND pr.status IN ('submitted', 'acknowledged'))";
        if ($filterCompanyId && $filterCompanyId > 0) {
            $subCond = "(pr.company_id = ? AND " . $subCond . ")";
            $params[] = $filterCompanyId;
        }
        $orConditions[] = $subCond;
        $params[] = $myEmpId;
    }

    if (empty($orConditions)) {
        return ['where_sql' => '1=0', 'params' => []]; // No access
    }

    return [
        'where_sql' => '(' . implode(' OR ', $orConditions) . ')',
        'params'    => $params,
    ];
}
