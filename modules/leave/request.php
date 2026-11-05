<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$currentUserId = current_user_id();

// 1. Linking logins to employees: user must be linked to an active employee profile
$employee = get_linked_employee_for_user($pdo, $currentUserId);
if (!$employee) {
    $pageTitle = 'Leave Request - Account Not Linked';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container-fluid py-5" style="max-width: 750px;">
        <div class="card border-0 shadow-sm text-center p-5">
            <div class="mb-3">
                <i class="bi bi-person-x-fill text-warning" style="font-size: 3.5rem;"></i>
            </div>
            <h3 class="card-title fw-bold">Employee Profile Link Required</h3>
            <p class="text-muted mb-4">
                Your user login is not currently linked to an active employee record in AaZa HRMS.
                Leave applications can only be submitted by active staff members.
            </p>
            <div class="alert alert-info d-inline-block text-start mx-auto mb-4" style="max-width: 500px;">
                <strong>Next Steps:</strong>
                <ul class="mb-0 ps-3 mt-1 small">
                    <li>Contact your Company Administrator or HR Manager.</li>
                    <li>Request that your system user account be linked to your Employee Profile under <em>People &gt; Employees</em>.</li>
                </ul>
            </div>
            <div>
                <a href="<?= e(url('public/index.php')) ?>" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$employeeId = (int)$employee['id'];
$companyId  = (int)$employee['company_id'];

// Available active leave types for this employee (group-wide OR matching employee company)
$stTypes = $pdo->prepare(
    "SELECT * FROM leave_types
      WHERE is_active = 1
        AND (company_id IS NULL OR company_id = ?)
      ORDER BY (company_id IS NULL) DESC, name ASC"
);
$stTypes->execute([$companyId]);
$leaveTypes = $stTypes->fetchAll();

// Pre-calculate balances for each leave type for display
$currentYear = (int)date('Y');
$balances = [];
foreach ($leaveTypes as $lt) {
    $balances[(int)$lt['id']] = leave_balance($pdo, $employeeId, (int)$lt['id'], $currentYear);
}

// Active company & group-wide holidays for live preview
$holidaysSt = $pdo->prepare(
    "SELECT holiday_date, name
       FROM company_holidays
      WHERE is_active = 1
        AND (company_id IS NULL OR company_id = ?)
      ORDER BY holiday_date ASC"
);
$holidaysSt->execute([$companyId]);
$reqHolidaysMap = [];
foreach ($holidaysSt->fetchAll() as $hRow) {
    $reqHolidaysMap[(string)$hRow['holiday_date']] = (string)$hRow['name'];
}

$reqWeeklyOff = [6, 7];
if (function_exists('get_company_setting')) {
    $jsonOff = get_company_setting($pdo, $companyId, 'attendance.weekly_off_days', null);
    if ($jsonOff !== null) {
        $dec = json_decode((string)$jsonOff, true);
        if (is_array($dec) && !empty($dec)) {
            $reqWeeklyOff = array_map('intval', $dec);
        }
    }
}

$errors = [];
$formData = [
    'leave_type_id' => (int)($_POST['leave_type_id'] ?? 0),
    'start_date'    => trim((string)($_POST['start_date'] ?? date('Y-m-d'))),
    'end_date'      => trim((string)($_POST['end_date'] ?? date('Y-m-d'))),
    'half_day'      => !empty($_POST['half_day']) ? 1 : 0,
    'reason'        => trim((string)($_POST['reason'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $selectedTypeId = (int)($formData['leave_type_id']);
    $rawStartDate   = $formData['start_date'];
    $rawEndDate     = $formData['end_date'];
    $isHalfDay      = $formData['half_day'] === 1;
    $reason         = mb_substr($formData['reason'], 0, 1000);

    // 1. Validate leave type
    $typeFound = null;
    foreach ($leaveTypes as $lt) {
        if ((int)$lt['id'] === $selectedTypeId) {
            $typeFound = $lt;
            break;
        }
    }

    if (!$typeFound) {
        $errors[] = 'Please select a valid, active leave type.';
    }

    // 2. Strict Date Validation using parse_ymd
    $startDate = parse_ymd($rawStartDate);
    $endDate   = parse_ymd($rawEndDate);

    if ($startDate === null) {
        $errors[] = 'A valid start date is required (format YYYY-MM-DD).';
    }
    if ($endDate === null) {
        $errors[] = 'A valid end date is required (format YYYY-MM-DD).';
    }

    if ($startDate !== null && $endDate !== null) {
        $dStart = new DateTime($startDate);
        $dEnd   = new DateTime($endDate);
        $intervalDays = (int)$dStart->diff($dEnd)->format('%r%a');

        if ($intervalDays < 0) {
            $errors[] = 'End date cannot be earlier than start date.';
        } else {
            $maxRangeDays = function_exists('get_company_setting')
                ? (int)get_company_setting($pdo, $companyId, 'leave.max_request_range_days', defined('LEAVE_MAX_RANGE_DAYS') ? (int)LEAVE_MAX_RANGE_DAYS : 90)
                : (defined('LEAVE_MAX_RANGE_DAYS') ? (int)LEAVE_MAX_RANGE_DAYS : 90);
            if ($maxRangeDays <= 0) {
                $maxRangeDays = 90;
            }
            if (($intervalDays + 1) > $maxRangeDays) {
                $errors[] = "Leave date range cannot exceed {$maxRangeDays} calendar days (requested " . ($intervalDays + 1) . " days).";
            }
        }

        // Check back-dated leave rules against company policy
        $allowBackdatedRaw = function_exists('get_company_setting')
            ? get_company_setting($pdo, $companyId, 'leave.allow_backdated_days', null)
            : null;
        if ($allowBackdatedRaw !== null && $allowBackdatedRaw !== '') {
            $allowBackdatedDays = (int)$allowBackdatedRaw;
            $earliestAllowed = $allowBackdatedDays > 0 ? date('Y-m-d', strtotime("-{$allowBackdatedDays} days")) : date('Y-m-d');
            if ($startDate < $earliestAllowed) {
                $errors[] = $allowBackdatedDays > 0
                    ? "Leave requests cannot be back-dated by more than {$allowBackdatedDays} days."
                    : 'Back-dated leave requests are not permitted for this company.';
            }
        } else {
            // Block start dates more than 12 months in the past (system fallback)
            $twelveMonthsAgo = date('Y-m-d', strtotime('-12 months'));
            if ($startDate < $twelveMonthsAgo) {
                $errors[] = 'Leave requests with start dates older than 12 months in the past are not permitted.';
            }
        }

        // Block end dates later than 1 year after today
        $oneYearAhead = date('Y-m-d', strtotime('+1 year'));
        if ($endDate > $oneYearAhead) {
            $errors[] = 'Leave requests with end dates later than 1 year in the future are not permitted.';
        }

        // Half day only allowed when start = end
        if ($isHalfDay && $startDate !== $endDate) {
            $errors[] = 'Half-day leave can only be requested for a single date (start and end date must match).';
        }
    }

    // 3. Server-side computation of working days
    $computedDays = 0.0;
    if (empty($errors) && $startDate !== null && $endDate !== null) {
        $computedDays = calculate_working_days($startDate, $endDate, $isHalfDay, null, $companyId);
        if ($computedDays <= 0.0) {
            $errors[] = 'The selected date range contains no working days (e.g., falls entirely on weekend off-days).';
        }
    }

    // 4. Overlap rejection
    if (empty($errors) && $startDate !== null && $endDate !== null) {
        if (has_overlapping_leave($pdo, $employeeId, $startDate, $endDate)) {
            $errors[] = 'You already have an overlapping pending or approved leave request for the selected dates.';
        }
    }

    // 5. Balance check at submit time: allowance - taken - pending
    if (empty($errors) && $typeFound && $startDate !== null && $endDate !== null) {
        $allowance = (float)$typeFound['days_per_year'];
        if ($allowance > 0.0) {
            $startYear = (int)date('Y', strtotime($startDate));
            $endYear   = (int)date('Y', strtotime($endDate));

            for ($y = $startYear; $y <= min($endYear, $startYear + 2); $y++) {
                $bal = leave_balance($pdo, $employeeId, $selectedTypeId, $y);
                $available = max(0.0, $bal['allowance'] - $bal['taken'] - $bal['pending']);
                $yearStart = "$y-01-01";
                $yearEnd   = "$y-12-31";
                $overlapS  = max($startDate, $yearStart);
                $overlapE  = min($endDate, $yearEnd);
                $daysInY   = calculate_working_days($overlapS, $overlapE, $isHalfDay, null, $companyId);

                if ($daysInY > $available) {
                    $errors[] = "Requested {$daysInY} day(s), available {$available} day(s) ({$bal['pending']} day(s) already pending approval for {$typeFound['name']} in {$y}).";
                    break;
                }
            }
        }
    }

    // 6. Supporting Document Validation (Validation only; moving file happens after DB insert)
    $documentPath = null;
    $targetFile = null;
    $uploadDir = STORAGE_PATH . '/uploads/leave';
    $requiresDoc = $typeFound && (int)$typeFound['requires_document'] === 1;

    if ($requiresDoc && (empty($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE)) {
        $errors[] = "A supporting document (medical certificate / proof) is mandatory for {$typeFound['name']}.";
    }

    if (!empty($_FILES['document']) && $_FILES['document']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['document'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code: ' . $file['error'];
        } else {
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Supporting document exceeds the maximum allowed size of 5 MB.';
            }

            $origName = (string)$file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowedExts, true)) {
                $errors[] = 'Invalid file extension. Only PDF, JPG, and PNG documents are allowed.';
            }

            // Real finfo MIME check (prevents .php renamed to .pdf)
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Invalid file format. Uploaded file content does not match allowed types.';
            }

            if (empty($errors)) {
                $storedFileName = bin2hex(random_bytes(16)) . '.' . $ext;
                $documentPath = $storedFileName;
                $targetFile = $uploadDir . '/' . $storedFileName;
            }
        }
    }

    // 7. Save leave request inside Transaction with Employee Lock
    if (empty($errors) && $startDate !== null && $endDate !== null) {
        try {
            $pdo->beginTransaction();

            // Lock the leave-owning employee first to serialise submissions
            $stEmpLock = $pdo->prepare("SELECT id FROM employees WHERE id = ? FOR UPDATE");
            $stEmpLock->execute([$employeeId]);
            if (!$stEmpLock->fetch()) {
                throw new RuntimeException('Employee record not found.');
            }

            // Re-verify overlap inside lock
            if (has_overlapping_leave($pdo, $employeeId, $startDate, $endDate)) {
                throw new RuntimeException('An overlapping leave request was submitted concurrently.');
            }

            // Re-verify balance inside lock
            if ($typeFound && (float)$typeFound['days_per_year'] > 0.0) {
                $startYear = (int)date('Y', strtotime($startDate));
                $endYear   = (int)date('Y', strtotime($endDate));
                for ($y = $startYear; $y <= min($endYear, $startYear + 2); $y++) {
                    $bal = leave_balance($pdo, $employeeId, $selectedTypeId, $y);
                    $available = max(0.0, $bal['allowance'] - $bal['taken'] - $bal['pending']);
                    $overlapS  = max($startDate, "$y-01-01");
                    $overlapE  = min($endDate, "$y-12-31");
                    $daysInY   = calculate_working_days($overlapS, $overlapE, $isHalfDay, null, $companyId);

                    if ($daysInY > $available) {
                        throw new RuntimeException("Requested {$daysInY} day(s), available {$available} day(s) ({$bal['pending']} day(s) already pending approval).");
                    }
                }
            }

            // Insert request into database
            $ins = $pdo->prepare(
                "INSERT INTO leave_requests (
                    employee_id, company_id, leave_type_id, start_date, end_date,
                    total_days, half_day, reason, document_path, status, requested_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
            );
            $ins->execute([
                $employeeId,
                $companyId,
                $selectedTypeId,
                $startDate,
                $endDate,
                $computedDays,
                $isHalfDay ? 1 : 0,
                $reason !== '' ? $reason : null,
                $documentPath,
            ]);
            $newReqId = (int)$pdo->lastInsertId();

            // Move uploaded file AFTER DB insert succeeded inside transaction
            if ($targetFile !== null && !empty($_FILES['document']['tmp_name'])) {
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (!move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
                    if (file_exists($targetFile)) {
                        @unlink($targetFile);
                    }
                    throw new RuntimeException('Failed to save the uploaded document to secure storage.');
                }
            }

            $pdo->commit();

            // Notify leave approvers in this company
            $empName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
            notify_permission(
                $pdo,
                $companyId,
                'leave.approve',
                'leave_submitted',
                'Leave Request Submitted',
                "{$empName} ({$employee['employee_code']}) submitted a {$typeFound['name']} request for {$computedDays} day(s).",
                'modules/leave/view.php?id=' . $newReqId,
                'leave:submit:' . $newReqId,
                current_user_id()
            );

            log_activity(
                'leave.request',
                'leave_request',
                $newReqId,
                [
                    'employee_id' => $employeeId,
                    'leave_type'  => $typeFound['name'],
                    'start_date'  => $startDate,
                    'end_date'    => $endDate,
                    'total_days'  => $computedDays,
                    'half_day'    => $isHalfDay ? 1 : 0,
                ],
                $companyId
            );

            flash_set('success', "Leave request submitted successfully for {$computedDays} day(s). It is now pending approval.");
            redirect('modules/leave/my_leaves.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Delete orphan uploaded file if one was written
            if ($targetFile !== null && file_exists($targetFile)) {
                @unlink($targetFile);
            }
            error_log('Leave request submission error: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Submit Leave Request';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 960px;">
    <!-- Breadcrumb -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/leave/my_leaves.php')) ?>">My Leave</a></li>
                    <li class="breadcrumb-item active" aria-current="page">New Request</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0">Apply for Leave</h1>
        </div>
        <a href="<?= e(url('modules/leave/my_leaves.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to My Requests
        </a>
    </div>

    <!-- Error Alert -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger shadow-sm border-0 mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                <span class="fw-semibold">Please correct the following errors:</span>
            </div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Form Column -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Leave Application Details</h5>
                </div>
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('modules/leave/request.php')) ?>" enctype="multipart/form-data" id="leaveRequestForm" autocomplete="off">
                        <?= csrf_field() ?>

                        <!-- Employee Info Banner -->
                        <div class="bg-body-tertiary p-3 rounded mb-4 border d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <span class="badge bg-body-secondary text-body border me-1"><?= e($employee['company_code']) ?></span>
                                <span class="fw-semibold text-body"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></span>
                                <span class="text-muted small ms-1">(<?= e($employee['employee_code']) ?>)</span>
                            </div>
                            <div class="small text-muted">
                                <?= e($employee['designation_title'] ?? 'Staff') ?> &bull; <?= e($employee['department_name'] ?? 'General') ?>
                            </div>
                        </div>

                        <!-- Leave Type -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
                            <select name="leave_type_id" id="leaveTypeSelect" class="form-select" required onchange="handleTypeChange()">
                                <option value="">-- Choose Leave Type --</option>
                                <?php foreach ($leaveTypes as $lt): ?>
                                    <?php
                                    $bal = $balances[(int)$lt['id']] ?? null;
                                    $balText = ($bal && $bal['is_unlimited']) ? 'Unlimited' : (($bal ? $bal['remaining'] : $lt['days_per_year']) . ' days left');
                                    $reqDoc = (int)$lt['requires_document'] === 1;
                                    ?>
                                    <option value="<?= (int)$lt['id'] ?>"
                                            data-requires-doc="<?= $reqDoc ? '1' : '0' ?>"
                                            data-unlimited="<?= $bal && $bal['is_unlimited'] ? '1' : '0' ?>"
                                            data-remaining="<?= $bal ? $bal['remaining'] : 0 ?>"
                                            <?= $formData['leave_type_id'] === (int)$lt['id'] ? 'selected' : '' ?>>
                                        <?= e($lt['name']) ?> (<?= e($balText) ?>)<?= $reqDoc ? ' [Doc Required]' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="typeDescHelp">Select an active leave policy.</div>
                        </div>

                        <!-- Date Range -->
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="startDate" class="form-control" required
                                       value="<?= e($formData['start_date']) ?>" onchange="syncDates()">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" id="endDate" class="form-control" required
                                       value="<?= e($formData['end_date']) ?>" onchange="syncDates()">
                            </div>
                        </div>

                        <!-- Half Day Checkbox -->
                        <div class="form-check form-switch mb-3" id="halfDayContainer">
                            <input class="form-check-input" type="checkbox" role="switch" name="half_day" id="halfDayCheck" value="1"
                                   <?= $formData['half_day'] === 1 ? 'checked' : '' ?> onchange="toggleHalfDay()">
                            <label class="form-check-label fw-medium" for="halfDayCheck">
                                Half-Day Leave (0.5 working day)
                            </label>
                            <div class="form-text">Applicable only when start date and end date are the same.</div>
                        </div>

                        <!-- Live Calculation Preview -->
                        <div class="alert alert-secondary py-2 px-3 small mb-4" id="calcPreviewAlert">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-info-circle me-1"></i> Working Days (excluding weekends &amp; holidays):</span>
                                <span class="fw-bold font-monospace fs-6 text-primary" id="calcDaysPreview">Calculated on submit</span>
                            </div>
                            <div id="holidayPreviewNotes" class="mt-2 pt-2 border-top border-secondary-subtle small d-none"></div>
                        </div>

                        <!-- Reason -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason for Leave</label>
                            <textarea name="reason" class="form-control" rows="3" maxlength="1000"
                                      placeholder="State reason or relevant handover details..."><?= e($formData['reason']) ?></textarea>
                            <div class="form-text">Optional but recommended for prompt manager approval.</div>
                        </div>

                        <!-- Supporting Document -->
                        <div class="mb-4" id="documentUploadSection">
                            <label class="form-label fw-semibold" id="docLabel">
                                Supporting Document <span id="docRequiredStar" class="text-danger d-none">*</span>
                            </label>
                            <input type="file" name="document" id="docInput" class="form-control"
                                   accept=".pdf,.jpg,.jpeg,.png">
                            <div class="form-text">PDF, JPG, or PNG (Max 5 MB). Required for certified sick and medical leaves.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-send me-1"></i> Submit Application
                            </button>
                            <a href="<?= e(url('modules/leave/my_leaves.php')) ?>" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar Balances Column -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h6 class="card-title mb-0 fw-bold">Your <?= e((string)$currentYear) ?> Leave Balances</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($leaveTypes as $lt): ?>
                            <?php
                            $b = $balances[(int)$lt['id']] ?? null;
                            $isUnlim = $b && $b['is_unlimited'];
                            $avail = $isUnlim ? INF : max(0.0, ($b ? $b['allowance'] - $b['taken'] - $b['pending'] : (float)$lt['days_per_year']));
                            ?>
                            <li class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-body"><?= e($lt['name']) ?></span>
                                    <?php if ($isUnlim): ?>
                                        <span class="badge bg-body-secondary text-body border">Unlimited</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-primary"><?= e(number_format($avail, 1)) ?> available</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted d-flex justify-content-between flex-wrap gap-1" style="font-size:0.75rem;">
                                    <span>Allowance: <?= $isUnlim ? 'Unlimited' : number_format($b ? $b['allowance'] : $lt['days_per_year'], 1) ?></span>
                                    <span>Used: <?= number_format($b ? $b['taken'] : 0, 1) ?></span>
                                    <span>Pending: <?= number_format($b ? $b['pending'] : 0, 1) ?></span>
                                    <span class="fw-bold text-success">Available: <?= $isUnlim ? 'Unlimited' : number_format($avail, 1) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm bg-body-tertiary">
                <div class="card-body small text-muted">
                    <h6 class="fw-semibold text-body mb-2"><i class="bi bi-shield-check me-1 text-primary"></i> Leave Rules</h6>
                    <ul class="ps-3 mb-0">
                        <li>Weekend days (Sat &amp; Sun) are automatically excluded.</li>
                        <li>Overlapping requests are strictly blocked.</li>
                        <li>Balance is deducted upon formal manager approval.</li>
                        <li>Approved future requests may be cancelled if not yet taken.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const companyHolidays = <?= json_encode($reqHolidaysMap) ?>;
const companyWeeklyOff = <?= json_encode($reqWeeklyOff) ?>;

function updateWorkingDaysPreview() {
    const sVal = document.getElementById('startDate').value;
    const eVal = document.getElementById('endDate').value;
    const halfCheck = document.getElementById('halfDayCheck');
    const previewEl = document.getElementById('calcDaysPreview');
    const notesEl = document.getElementById('holidayPreviewNotes');

    if (!sVal || !eVal) {
        previewEl.textContent = 'Select dates';
        notesEl.classList.add('d-none');
        notesEl.innerHTML = '';
        return;
    }

    const start = new Date(sVal + 'T00:00:00');
    const end = new Date(eVal + 'T00:00:00');

    if (start > end) {
        previewEl.textContent = 'Invalid date range';
        notesEl.classList.add('d-none');
        notesEl.innerHTML = '';
        return;
    }

    let workingCount = 0;
    const encounteredHolidays = [];
    const cur = new Date(start);

    while (cur <= end) {
        const jsDay = cur.getDay(); // 0 = Sun ... 6 = Sat
        const isoDay = jsDay === 0 ? 7 : jsDay;
        const ymd = cur.getFullYear() + '-' + String(cur.getMonth() + 1).padStart(2, '0') + '-' + String(cur.getDate()).padStart(2, '0');

        const isWeekend = companyWeeklyOff.includes(isoDay);
        const isHoliday = Object.prototype.hasOwnProperty.call(companyHolidays, ymd);

        if (isHoliday && !encounteredHolidays.some(h => h.date === ymd)) {
            encounteredHolidays.push({ date: ymd, name: companyHolidays[ymd] });
        }

        if (!isWeekend && !isHoliday) {
            workingCount++;
        }
        cur.setDate(cur.getDate() + 1);
    }

    let finalDays = workingCount;
    if (halfCheck && halfCheck.checked && sVal === eVal) {
        finalDays = workingCount > 0 ? 0.5 : 0;
    }

    previewEl.textContent = finalDays + (finalDays === 1 ? ' working day' : ' working days');

    if (encounteredHolidays.length > 0) {
        notesEl.classList.remove('d-none');
        const hBadges = encounteredHolidays.map(h => `<span class="badge text-bg-warning text-body me-1 mb-1"><i class="bi bi-calendar-event me-1"></i>${h.name} (${h.date})</span>`).join(' ');
        notesEl.innerHTML = '<div class="fw-semibold text-body mb-1"><i class="bi bi-info-circle me-1 text-warning"></i>Excluded Public Holidays:</div>' + hBadges;
    } else {
        notesEl.classList.add('d-none');
        notesEl.innerHTML = '';
    }
}

function handleTypeChange() {
    const sel = document.getElementById('leaveTypeSelect');
    const opt = sel.options[sel.selectedIndex];
    const reqDoc = opt.getAttribute('data-requires-doc') === '1';
    const star = document.getElementById('docRequiredStar');
    const docInput = document.getElementById('docInput');

    if (reqDoc) {
        star.classList.remove('d-none');
        docInput.setAttribute('required', 'required');
    } else {
        star.classList.add('d-none');
        docInput.removeAttribute('required');
    }
}

function syncDates() {
    const sDate = document.getElementById('startDate').value;
    const eDate = document.getElementById('endDate').value;
    const halfCheck = document.getElementById('halfDayCheck');
    const halfContainer = document.getElementById('halfDayContainer');

    if (sDate && eDate && sDate === eDate) {
        halfContainer.classList.remove('d-none');
    } else {
        halfCheck.checked = false;
        halfContainer.classList.add('d-none');
    }
    updateWorkingDaysPreview();
}

function toggleHalfDay() {
    const halfCheck = document.getElementById('halfDayCheck');
    if (halfCheck.checked) {
        const sDate = document.getElementById('startDate').value;
        if (sDate) {
            document.getElementById('endDate').value = sDate;
        }
    }
    updateWorkingDaysPreview();
}

document.addEventListener('DOMContentLoaded', () => {
    handleTypeChange();
    syncDates();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
