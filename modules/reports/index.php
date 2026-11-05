<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Scoped access per report category via report_scope_companies()
$recScope   = report_scope_companies(['candidate.view', 'vacancy.view']);
$empScope   = report_scope_companies('employee.view');
$attScope   = report_scope_companies('attendance.view');
$leaveScope = report_scope_companies(['leave.view', 'leave.approve']);
$perfScope  = report_scope_companies(['performance.view', 'performance.manage']);

$allAccessible = array_unique(array_merge($recScope, $empScope, $attScope, $leaveScope, $perfScope));
if (empty($allAccessible)) {
    abort(403, 'You do not have permission to view reports.');
}

$canRecruitment = !empty($recScope);
$canEmployees   = !empty($empScope);
$canAttendance  = !empty($attScope);
$canLeave       = !empty($leaveScope);
$canPerformance = !empty($perfScope);

$pageTitle = 'Reports & Analytics Hub';
require __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
                <li class="breadcrumb-item active" aria-current="page">Reports</li>
            </ol>
        </nav>
        <h1 class="page-header-title mb-0">Reports &amp; Analytics Hub</h1>
        <p class="page-header-desc mb-0 mt-1">Select an analytical report or summary for cross-company workforce intelligence.</p>
    </div>
    <div class="page-header-actions d-flex gap-2">
        <a href="<?= e(url('public/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-grid-1x2 me-1"></i> Dashboard
        </a>
    </div>
</div>

<!-- SECTION 1: RECRUITMENT REPORTS -->
<?php if ($canRecruitment): ?>
    <div class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="p-2 rounded bg-primary-subtle text-primary"><i class="bi bi-briefcase fs-5"></i></div>
            <h5 class="mb-0 fw-bold">Recruitment &amp; Talent Reports</h5>
        </div>
        <div class="row g-3">
            <!-- 1. Applicants by Job Position -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">Requisitions</span>
                            <span class="small text-muted font-monospace">Summary</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Applicants by Job Position</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Application volume, shortlisted, selected, rejected, and hired candidates across positions.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/applicants_by_position.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Candidates Selected for Interview -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pipeline</span>
                            <span class="small text-muted font-monospace">Detailed</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Candidates Selected for Interview</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Individual candidate records qualifying for interview rounds, scheduling details, and statuses.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/candidates_selected.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Interview Results -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">Evaluation</span>
                            <span class="small text-muted font-monospace">Authorized</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Interview Results &amp; Scores</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Completed interview rounds, score benchmarks, and evaluators' select/reject recommendations.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/interview_results.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Candidate Selection Rate -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-success-subtle text-success border border-success-subtle">KPI %</span>
                            <span class="small text-muted font-monospace">Ratio</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Candidate Selection Rate</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Selection and hire percentages per vacancy and operating company.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/selection_rate.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. Hired Candidates -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-success-subtle text-success border border-success-subtle">Placement</span>
                            <span class="small text-muted font-monospace">Detailed</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Hired Candidates Report</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Candidates who accepted job offers and converted into active staff members with assigned codes.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/hired_candidates.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 6. Recruitment Status Summary -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-secondary-subtle text-secondary border border-secondary-subtle">Stages</span>
                            <span class="small text-muted font-monospace">Aggregated</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Recruitment Status Summary</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Aggregated distribution and percentage breakdown across all application workflow statuses.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/recruitment_status.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 7. Recruitment Source Analysis -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">Channels</span>
                            <span class="small text-muted font-monospace">Conversion</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Recruitment Source Analysis</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Effectiveness of candidate acquisition channels (LinkedIn, referrals, portal, walk-in).
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/recruitment_source.php')) ?>" class="btn btn-sm btn-outline-primary w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- SECTION 2: EMPLOYEE & WORKFORCE REPORTS -->
<?php if ($canEmployees): ?>
    <div class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="p-2 rounded bg-success-subtle text-success"><i class="bi bi-people fs-5"></i></div>
            <h5 class="mb-0 fw-bold">Employee &amp; Workforce Reports</h5>
        </div>
        <div class="row g-3">
            <!-- 8. Total Employees with Company Breakdown -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">Headcount</span>
                            <span class="small text-muted font-monospace">Company-wise</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Employees by Company</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Multi-company employee headcount breakdown across active, on-leave, and separated statuses.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/employees_by_company.php')) ?>" class="btn btn-sm btn-outline-success w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 9. Department-Wise Employee Count -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">Structure</span>
                            <span class="small text-muted font-monospace">Department</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Department-Wise Employee Count</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Staff distribution and active counts grouped by business department and unit.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/employees_by_department.php')) ?>" class="btn btn-sm btn-outline-success w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 10. Employment-Type Summary -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">Contracts</span>
                            <span class="small text-muted font-monospace">Summary</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Employment-Type Summary</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Staff composition by contract category: full-time, part-time, contract, intern, and probation.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/employment_types.php')) ?>" class="btn btn-sm btn-outline-success w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 11. Active and Inactive Employees -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-secondary-subtle text-secondary border border-secondary-subtle">Status</span>
                            <span class="small text-muted font-monospace">Retention</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Active and Inactive Employees</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Workforce active status breakdown: active, on-leave, transferred, resigned, and terminated.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/employee_status.php')) ?>" class="btn btn-sm btn-outline-success w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 12. Employee Joining History -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-success-subtle text-success border border-success-subtle">Onboarding</span>
                            <span class="small text-muted font-monospace">Timeline</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Employee Joining History</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Monthly joining statistics and chronological staff onboarding registry.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/employee_joining.php')) ?>" class="btn btn-sm btn-outline-success w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- SECTION 3: ATTENDANCE & LEAVE MODULE REPORTS -->
<?php if ($canAttendance || $canLeave): ?>
    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="p-2 rounded bg-warning-subtle text-body"><i class="bi bi-calendar2-check fs-5"></i></div>
            <h5 class="mb-0 fw-bold">Time &amp; Leave Reports (Phase 8 Integrated)</h5>
        </div>
        <div class="row g-3">
            <?php if ($canAttendance): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow">
                        <div class="card-body p-3 d-flex flex-column">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">Attendance</span>
                                <span class="small text-muted font-monospace">Daily &bull; Monthly</span>
                            </div>
                            <h6 class="card-title fw-bold text-body mb-1">Attendance Reports</h6>
                            <p class="card-text text-muted small flex-grow-1">
                                Daily attendance logs, monthly summaries, and individual employee punch records.
                            </p>
                            <div class="mt-3 pt-2 border-top">
                                <a href="<?= e(url('modules/attendance/reports.php')) ?>" class="btn btn-sm btn-outline-dark w-100">
                                    Open Attendance Analytics &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canLeave): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm hover-shadow">
                        <div class="card-body p-3 d-flex flex-column">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge text-bg-warning-subtle text-body border border-warning-subtle">Leave</span>
                                <span class="small text-muted font-monospace">Balances &bull; History</span>
                            </div>
                            <h6 class="card-title fw-bold text-body mb-1">Leave Reports &amp; Balances</h6>
                            <p class="card-text text-muted small flex-grow-1">
                                Annual leave balance tracking, request history, and approval distribution reports.
                            </p>
                            <div class="mt-3 pt-2 border-top">
                                <a href="<?= e(url('modules/leave/reports.php')) ?>" class="btn btn-sm btn-outline-dark w-100">
                                    Open Leave Analytics &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- SECTION 5: PERFORMANCE & APPRAISAL REPORTS -->
<?php if ($canPerformance): ?>
    <div class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="p-2 rounded bg-warning-subtle text-warning"><i class="bi bi-award fs-5"></i></div>
            <h5 class="mb-0 fw-bold">Performance &amp; Appraisal Intelligence</h5>
        </div>
        <div class="row g-3">
            <!-- 1. Rating Distribution by Department -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">Ratings</span>
                            <span class="small text-muted font-monospace">Distribution</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Rating Distribution by Department</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Breakdown of appraisal competency ratings (1 to 5) across departments, weighted averages, and high-performer tallies.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/performance_ratings.php')) ?>" class="btn btn-sm btn-outline-warning text-dark w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Reviews Due & Pending Appraisals -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm hover-shadow">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-danger-subtle text-danger border border-danger-subtle">Appraisal Cycle</span>
                            <span class="small text-muted font-monospace">Status</span>
                        </div>
                        <h6 class="card-title fw-bold text-body mb-1">Reviews Due &amp; Pending Appraisals</h6>
                        <p class="card-text text-muted small flex-grow-1">
                            Active staff with missing appraisal cycles, drafts in progress, and reviews awaiting employee acknowledgment.
                        </p>
                        <div class="mt-3 pt-2 border-top">
                            <a href="<?= e(url('modules/reports/performance_due.php')) ?>" class="btn btn-sm btn-outline-danger w-100">
                                Open Report &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
