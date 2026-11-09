<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();

// GET request: show confirmation form with Login Account select
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $offerId = (int)($_GET['offer_id'] ?? 0);
    if ($offerId <= 0) {
        abort(404, 'Job offer not found.');
    }
    $initSt = $pdo->prepare('SELECT jo.*, comp.name AS company_name, comp.code AS comp_code,
                                    c.first_name, c.last_name, c.email, c.phone,
                                    d.name AS department_name, des.title AS designation_title
                               FROM job_offers jo
                               JOIN candidate_applications ca ON ca.id = jo.application_id
                               JOIN candidates c ON c.id = ca.candidate_id
                               JOIN companies comp ON comp.id = jo.company_id
                          LEFT JOIN departments d ON d.id = jo.department_id
                          LEFT JOIN designations des ON des.id = jo.designation_id
                              WHERE jo.id = ? LIMIT 1');
    $initSt->execute([$offerId]);
    $offer = $initSt->fetch();
    if (!$offer) {
        abort(404, 'Job offer not found.');
    }
    $companyId = (int)$offer['company_id'];
    require_company_access($companyId, 'offer.manage');

    if ((string)$offer['status'] !== 'accepted' || !empty($offer['converted_employee_id'])) {
        if (!empty($offer['converted_employee_id'])) {
            flash_set('warning', 'This offer has already been converted to an employee record.');
            redirect('modules/employees/view.php?id=' . (int)$offer['converted_employee_id']);
        }
        flash_set('danger', 'Only accepted job offers can be converted to an employee record.');
        redirect('modules/offers/view.php?id=' . $offerId);
    }

    $availableUsers = get_available_users_for_company($pdo, $companyId);
    $pageTitle = 'Convert Candidate to Employee';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="page-header">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-clean">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/index.php')) ?>">Job Offers</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/offers/view.php?id=' . $offerId)) ?>">Offer #<?= $offerId ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Convert</li>
                </ol>
            </nav>
            <h1 class="page-header-title">Convert Candidate to Employee</h1>
            <p class="page-header-desc">Onboard <?= e($offer['first_name'] . ' ' . $offer['last_name']) ?> as an active employee in <?= e($offer['company_name']) ?>.</p>
        </div>
        <div class="page-header-actions page-actions">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('modules/offers/view.php?id=' . $offerId)) ?>">
                <i class="bi bi-arrow-left me-1"></i> Back to Offer
            </a>
        </div>
    </div>

    <div class="row g-4" style="max-width: 800px;">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0 fs-6 fw-bold">Conversion Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="text-muted small">Candidate Name</div>
                            <div class="fw-semibold"><?= e($offer['first_name'] . ' ' . $offer['last_name']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Target Company</div>
                            <div class="fw-semibold"><?= e($offer['company_name']) ?> (<code><?= e($offer['comp_code']) ?></code>)</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Department</div>
                            <div><?= e($offer['department_name'] ?? 'Unassigned') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Designation</div>
                            <div><?= e($offer['designation_title'] ?? 'Unassigned') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Employment Type</div>
                            <div><?= e(employment_type_list()[$offer['employment_type']] ?? $offer['employment_type']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Proposed Joining Date</div>
                            <div><?= e($offer['proposed_join_date'] ?? date('Y-m-d')) ?></div>
                        </div>
                    </div>

                    <form method="post" action="<?= e(url('modules/offers/convert.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="offer_id" value="<?= $offerId ?>">

                        <!-- Login Account Configuration (Optional) -->
                        <div class="card bg-body-tertiary border p-3 mb-4">
                            <div class="fw-semibold text-body mb-1">
                                <i class="bi bi-person-badge me-1 text-primary"></i> System Login Account (Optional)
                            </div>
                            <p class="small text-muted mb-2">
                                An employee record does <strong>not</strong> require a login account immediately. They can be created and managed in the system right away. You can invite or assign a user account at any time later in <strong>Administration &gt; Users</strong>.
                            </p>
                            <?php if (!empty($availableUsers)): ?>
                                <label class="form-label small fw-semibold" for="userId">Link existing user account now:</label>
                                <select class="form-select form-select-sm" id="userId" name="user_id">
                                    <option value="0">-- None (Do not link a user account now) --</option>
                                    <?php foreach ($availableUsers as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>">
                                            <?= e($u['full_name']) ?> (<?= e($u['username']) ?><?= !empty($u['email']) ? ' &bull; ' . e($u['email']) : '' ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="user_id" value="0">
                                <div class="alert alert-light border small text-muted mb-0 py-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-info-circle text-primary fs-5"></i>
                                    <div>
                                        <strong>No unlinked user accounts currently available.</strong><br>
                                        The employee will be created with <strong>Unlinked Login</strong>. You can create a new login account for this employee anytime in <a href="<?= e(url('modules/users/form.php')) ?>" target="_blank" class="alert-link">Administration &gt; Users</a>.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4 shadow-sm" data-confirm="Confirm candidate conversion to active employee?" data-confirm-type="success">
                                <i class="bi bi-person-check-fill me-1"></i> Confirm &amp; Create Employee Record
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= e(url('modules/offers/view.php?id=' . $offerId)) ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed.');
}

csrf_require();

$offerId = (int)($_POST['offer_id'] ?? 0);
if ($offerId <= 0) {
    abort(404, 'Job offer not found.');
}

// Pre-fetch offer without lock to authorize company access BEFORE transaction & row lock
$initSt = $pdo->prepare('SELECT id, company_id FROM job_offers WHERE id = ? LIMIT 1');
$initSt->execute([$offerId]);
$initOffer = $initSt->fetch();

if (!$initOffer) {
    abort(404, 'Job offer not found.');
}

$companyId = (int)$initOffer['company_id'];
require_company_access($companyId, 'offer.manage');

// Read and validate optional user_id
$targetUserId = null;
if (isset($_POST['user_id'])) {
    $rawUserId = trim((string)$_POST['user_id']);
    if ($rawUserId !== '' && (int)$rawUserId > 0) {
        $targetUserId = (int)$rawUserId;
        $linkError = validate_employee_user_link($pdo, $targetUserId, $companyId);
        if ($linkError !== null) {
            flash_set('danger', $linkError);
            redirect('modules/offers/view.php?id=' . $offerId);
        }
    }
}

try {
    $pdo->beginTransaction();

    // 1. Lock the offer row FOR UPDATE
    $st = $pdo->prepare(
        'SELECT jo.*,
                ca.id AS app_id, ca.status AS app_status, ca.vacancy_id, ca.candidate_id,
                c.first_name, c.last_name, c.email, c.phone, c.nic_passport, c.dob, c.gender, c.address,
                comp.id AS comp_id, comp.code AS comp_code, comp.name AS comp_name
           FROM job_offers jo
           JOIN candidate_applications ca ON ca.id = jo.application_id
           JOIN candidates c ON c.id = ca.candidate_id
           JOIN companies comp ON comp.id = jo.company_id
          WHERE jo.id = ?
          FOR UPDATE'
    );
    $st->execute([$offerId]);
    $offer = $st->fetch();

    if (!$offer) {
        $pdo->rollBack();
        abort(404, 'Job offer not found.');
    }

    // 2. Validate state: Allowed ONLY for an offer in status 'accepted' with converted_employee_id IS NULL
    if ((string)$offer['status'] !== 'accepted' || !empty($offer['converted_employee_id'])) {
        $pdo->rollBack();
        if (!empty($offer['converted_employee_id'])) {
            flash_set('warning', 'This offer has already been converted to an employee record.');
            redirect('modules/employees/view.php?id=' . (int)$offer['converted_employee_id']);
        }
        flash_set('danger', 'Only accepted job offers can be converted to an employee record (current status: ' . (offer_status_list()[$offer['status']] ?? $offer['status']) . ').');
        redirect('modules/offers/view.php?id=' . $offerId);
    }

    // 3. Check the linked application's status is 'offer_accepted' inside the lock
    if ((string)$offer['app_status'] !== 'offer_accepted') {
        $pdo->rollBack();
        $curAppStatus = application_status_list()[$offer['app_status']] ?? $offer['app_status'];
        flash_set('danger', "The linked candidate application must be in 'Offer Accepted' status to convert (current application status: '{$curAppStatus}').");
        redirect('modules/offers/view.php?id=' . $offerId);
    }

    // 4. Lock company row FOR UPDATE before generating employee code
    $lockComp = $pdo->prepare('SELECT id, code FROM companies WHERE id = ? FOR UPDATE');
    $lockComp->execute([$companyId]);

    // 5. Duplicate checks: email and NIC
    $candEmail = trim((string)($offer['email'] ?? ''));
    $candNic   = trim((string)($offer['nic_passport'] ?? ''));

    // 5a. Same-company duplicates: block if same email or NIC exists in the SAME company, regardless of status
    $sameCompEmp = null;
    if ($candEmail !== '' || $candNic !== '') {
        $where = [];
        $params = [$companyId];
        if ($candEmail !== '') {
            $where[] = 'email = ?';
            $params[] = $candEmail;
        }
        if ($candNic !== '') {
            $where[] = 'nic_passport = ?';
            $params[] = $candNic;
        }
        $sameSql = 'SELECT id, employee_code, first_name, last_name, company_id, employment_status
                      FROM employees
                     WHERE company_id = ? AND (' . implode(' OR ', $where) . ')
                     LIMIT 1';
        $sameSt = $pdo->prepare($sameSql);
        $sameSt->execute($params);
        $sameCompEmp = $sameSt->fetch();
    }

    if ($sameCompEmp) {
        $pdo->rollBack();
        log_activity('offer.convert_blocked', 'job_offers', $offerId, [
            'reason'      => 'same_company_duplicate',
            'employee_id' => (int)$sameCompEmp['id'],
        ], $companyId);

        if (user_can('employee.view', $companyId)) {
            $dupName = $sameCompEmp['first_name'] . ' ' . $sameCompEmp['last_name'];
            $dupCode = $sameCompEmp['employee_code'];
            flash_set('danger', "An employee with this email or NIC already exists in this company: {$dupName} ({$dupCode}). Conversion stopped to prevent duplicate records.");
        } else {
            flash_set('danger', "An employee with this email or NIC already exists in this company. Conversion stopped to prevent duplicate records.");
        }
        redirect('modules/offers/view.php?id=' . $offerId);
    }

    // 5b. Cross-company duplicates:
    // Block ONLY when matching employee has employment_status IN ('active', 'on_leave') in another company.
    // A person with resigned, terminated, retired, or transferred may be hired as a NEW employee record.
    $previousRecordNotice = '';
    if ($candEmail !== '' || $candNic !== '') {
        $where = [];
        $params = [$companyId];
        if ($candEmail !== '') {
            $where[] = 'email = ?';
            $params[] = $candEmail;
        }
        if ($candNic !== '') {
            $where[] = 'nic_passport = ?';
            $params[] = $candNic;
        }
        $otherSql = 'SELECT id, employee_code, first_name, last_name, company_id, employment_status
                       FROM employees
                      WHERE company_id != ? AND (' . implode(' OR ', $where) . ')
                      ORDER BY id DESC';
        $otherSt = $pdo->prepare($otherSql);
        $otherSt->execute($params);
        $otherEmps = $otherSt->fetchAll();

        $activeElsewhere = null;
        $prevRecords = [];

        foreach ($otherEmps as $oe) {
            if (in_array($oe['employment_status'], ['active', 'on_leave'], true)) {
                if ($activeElsewhere === null) {
                    $activeElsewhere = $oe;
                }
            } else {
                $prevRecords[] = $oe;
            }
        }

        if ($activeElsewhere) {
            $pdo->rollBack();
            log_activity('offer.convert_blocked', 'job_offers', $offerId, [
                'reason'           => 'active_elsewhere',
                'employee_id'      => (int)$activeElsewhere['id'],
                'other_company_id' => (int)$activeElsewhere['company_id'],
            ], $companyId);

            $canViewOther = user_can('employee.view', (int)$activeElsewhere['company_id']);
            if (!$canViewOther) {
                flash_set('danger', 'An active employee record with the same email or NIC exists in another AaZa company. Contact Group HR.');
            } else {
                $oeName = $activeElsewhere['first_name'] . ' ' . $activeElsewhere['last_name'];
                $oeCode = $activeElsewhere['employee_code'];
                flash_set('danger', "An active employee record with the same email or NIC exists in another AaZa company: {$oeName} ({$oeCode}). Contact Group HR.");
            }
            redirect('modules/offers/view.php?id=' . $offerId);
        }

        if (!empty($prevRecords)) {
            $firstPrev = $prevRecords[0];
            if (user_can('employee.view', (int)$firstPrev['company_id'])) {
                $previousRecordNotice = ' (Previous employment record exists: ' . $firstPrev['employee_code'] . ')';
            }
        }
    }

    // 6. Generate employee code
    $empCode = generate_employee_code($pdo, $companyId);
    $currentUserId = current_user_id();

    // 7. Create employee in the OFFER's company
    // basic_salary is intentionally copied from the accepted offer
    $insEmp = $pdo->prepare(
        "INSERT INTO employees (
            employee_code, company_id, user_id, candidate_id, first_name, last_name,
            email, phone, nic_passport, dob, gender, address,
            department_id, designation_id, joining_date, employment_type,
            employment_status, basic_salary, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())"
    );
    $insEmp->execute([
        $empCode,
        $companyId,
        $targetUserId,
        (int)$offer['candidate_id'],
        $offer['first_name'],
        $offer['last_name'],
        $candEmail !== '' ? $candEmail : null,
        !empty($offer['phone']) ? $offer['phone'] : null,
        $candNic !== '' ? $candNic : null,
        !empty($offer['dob']) ? $offer['dob'] : null,
        !empty($offer['gender']) ? $offer['gender'] : null,
        !empty($offer['address']) ? $offer['address'] : null,
        !empty($offer['department_id']) ? (int)$offer['department_id'] : null,
        !empty($offer['designation_id']) ? (int)$offer['designation_id'] : null,
        !empty($offer['proposed_join_date']) ? $offer['proposed_join_date'] : date('Y-m-d'),
        $offer['employment_type'],
        $offer['offered_salary'] !== null ? (float)$offer['offered_salary'] : null,
        $currentUserId,
    ]);

    $newEmployeeId = (int)$pdo->lastInsertId();

    // 7. Write employment-history row (same structure as employees/form.php)
    $stHist = $pdo->prepare(
        'INSERT INTO employment_history (
            employee_id, company_id, department_id, designation_id,
            employment_type, start_date, end_date, change_type, remarks, recorded_by
        ) VALUES (?, ?, ?, ?, ?, ?, NULL, "joined", ?, ?)'
    );
    $historyRemarks = 'Initial appointment from accepted Job Offer #' . $offerId;
    $stHist->execute([
        $newEmployeeId,
        $companyId,
        !empty($offer['department_id']) ? (int)$offer['department_id'] : null,
        !empty($offer['designation_id']) ? (int)$offer['designation_id'] : null,
        $offer['employment_type'],
        !empty($offer['proposed_join_date']) ? $offer['proposed_join_date'] : date('Y-m-d'),
        $historyRemarks,
        $currentUserId,
    ]);

    // 8. Set job_offers.converted_employee_id
    $upOffer = $pdo->prepare('UPDATE job_offers SET converted_employee_id = ? WHERE id = ?');
    $upOffer->execute([$newEmployeeId, $offerId]);

    // 9. Set candidate_applications status to 'hired'
    update_candidate_application_status(
        $pdo,
        (int)$offer['application_id'],
        'hired',
        'Candidate hired and converted to employee ' . $empCode . ' via Offer #' . $offerId,
        $currentUserId
    );

    // 10. Automatically mark vacancy 'closed' if hired >= positions_count
    $vacancyId = (int)$offer['vacancy_id'];
    if ($vacancyId > 0) {
        $vacSt = $pdo->prepare('SELECT id, positions_count, status FROM job_vacancies WHERE id = ? FOR UPDATE');
        $vacSt->execute([$vacancyId]);
        $vacRow = $vacSt->fetch();
        if ($vacRow) {
            $hiredSt = $pdo->prepare('SELECT COUNT(*) FROM candidate_applications WHERE vacancy_id = ? AND status = "hired"');
            $hiredSt->execute([$vacancyId]);
            $hiredCount = (int)$hiredSt->fetchColumn();
            $positionsCount = (int)$vacRow['positions_count'];
            if ($positionsCount > 0 && $hiredCount >= $positionsCount && (string)$vacRow['status'] === 'open') {
                $closeVac = $pdo->prepare('UPDATE job_vacancies SET status = "closed", updated_at = NOW() WHERE id = ?');
                $closeVac->execute([$vacancyId]);
                log_activity('vacancy.status_change', 'job_vacancy', $vacancyId, [
                    'old_status'      => 'open',
                    'new_status'      => 'closed',
                    'positions_count' => $positionsCount,
                    'hired_count'     => $hiredCount,
                    'reason'          => 'All positions filled by candidate conversion',
                ], $companyId);
            }
        }
    }

    // 11. Copy candidate's CV document into employee documents (non-blocking fallback)
    $docNote = '';
    try {
        $candCvSt = $pdo->prepare(
            "SELECT * FROM candidate_documents
              WHERE candidate_id = ? AND doc_type = 'cv'
              ORDER BY id DESC LIMIT 1"
        );
        $candCvSt->execute([(int)$offer['candidate_id']]);
        $cvDoc = $candCvSt->fetch();

        if ($cvDoc && !empty($cvDoc['stored_name'])) {
            $srcPath = STORAGE_PATH . '/uploads/candidates/' . basename((string)$cvDoc['stored_name']);
            if (is_file($srcPath)) {
                $destDir = STORAGE_PATH . '/uploads/employees';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                $ext = pathinfo((string)$cvDoc['original_name'], PATHINFO_EXTENSION);
                $ext = $ext !== '' ? ('.' . strtolower($ext)) : '';
                $newStoredName = bin2hex(random_bytes(16)) . $ext;
                $destPath = $destDir . '/' . $newStoredName;

                if (copy($srcPath, $destPath)) {
                    $insDoc = $pdo->prepare(
                        "INSERT INTO employee_documents (
                            employee_id, company_id, doc_type, title,
                            original_name, stored_name, mime_type, file_size,
                            expiry_date, is_confidential, uploaded_by, uploaded_at
                        ) VALUES (?, ?, 'cv', 'Candidate CV / Resume', ?, ?, ?, ?, NULL, 0, ?, NOW())"
                    );
                    $insDoc->execute([
                        $newEmployeeId,
                        $companyId,
                        $cvDoc['original_name'],
                        $newStoredName,
                        $cvDoc['mime_type'],
                        $cvDoc['file_size'],
                        $currentUserId,
                    ]);
                } else {
                    $docNote = ' (Note: candidate CV file could not be copied to employee documents directory)';
                    error_log("Failed to copy candidate CV from $srcPath to $destPath");
                }
            } else {
                $docNote = ' (Note: candidate CV file was not found on disk, skipped copy)';
            }
        }
    } catch (Throwable $docEx) {
        error_log('CV document copy error during conversion: ' . $docEx->getMessage());
        $docNote = ' (Note: candidate CV copy encountered an error and was skipped)';
    }

    // 12. Commit transaction
    $pdo->commit();

    // Notify HR about candidate conversion to employee
    $newEmpName = trim($offer['first_name'] . ' ' . $offer['last_name']);
    notify_permission(
        $pdo,
        $companyId,
        'employee.view',
        'candidate_converted',
        'Candidate Converted to Employee',
        "{$newEmpName} was successfully converted to employee ({$empCode}).",
        'modules/employees/view.php?id=' . $newEmployeeId,
        'offer:convert:' . $offerId,
        current_user_id()
    );

    // 13. Audit logs
    log_activity('offer.convert', 'job_offer', $offerId, [
        'employee_id'   => $newEmployeeId,
        'employee_code' => $empCode,
    ], $companyId);

    log_activity('employee.create', 'employee', $newEmployeeId, [
        'code'          => $empCode,
        'name'          => $offer['first_name'] . ' ' . $offer['last_name'],
        'from_offer_id' => $offerId,
    ], $companyId);

    if ($targetUserId !== null) {
        log_activity('employee.link_user', 'employee', $newEmployeeId, [
            'old_user_id' => null,
            'new_user_id' => $targetUserId,
        ], $companyId);
    }

    flash_set('success', "Candidate successfully converted to employee $empCode!{$previousRecordNotice}$docNote");
    redirect('modules/employees/view.php?id=' . $newEmployeeId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Conversion error: ' . $e->getMessage());
    flash_set('danger', 'Failed to convert candidate to employee due to a system error.');
    redirect('modules/offers/view.php?id=' . $offerId);
}
