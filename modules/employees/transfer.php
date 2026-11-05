<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_REQUEST['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Employee not found.');
}

$pdo = db();
$st = $pdo->prepare('SELECT e.*, c.name AS company_name, c.code AS company_code,
                            d.name AS department_name, des.title AS designation_title
                       FROM employees e
                       JOIN companies c ON c.id = e.company_id
                       LEFT JOIN departments d ON d.id = e.department_id
                       LEFT JOIN designations des ON des.id = e.designation_id
                      WHERE e.id = ?
                      LIMIT 1');
$st->execute([$id]);
$emp = $st->fetch();

if (!$emp) {
    abort(404, 'Employee not found.');
}

$fromCompanyId = (int)$emp['company_id'];

// Check permission employee.transfer in origin company
require_company_access($fromCompanyId, 'employee.transfer');

// Destination companies: must have employee.transfer permission in target company as well
$allTransferCompanyIds = accessible_company_ids('employee.transfer');
$targetCompanyIds = array_values(array_filter($allTransferCompanyIds, fn($cid) => $cid !== $fromCompanyId));

$targetCompanies = [];
$targetDepts = [];
$targetDesigs = [];

if ($targetCompanyIds) {
    $ph = implode(',', array_fill(0, count($targetCompanyIds), '?'));
    $st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
    $st->execute($targetCompanyIds);
    $targetCompanies = $st->fetchAll();

    if ($targetCompanies) {
        $activeTargetIds = array_map(fn($c) => (int)$c['id'], $targetCompanies);
        $phActive = implode(',', array_fill(0, count($activeTargetIds), '?'));

        $st = $pdo->prepare(
            "SELECT id, company_id, name FROM departments WHERE company_id IN ($phActive) AND is_active = 1 ORDER BY name"
        );
        $st->execute($activeTargetIds);
        $targetDepts = $st->fetchAll();

        $st = $pdo->prepare(
            "SELECT id, company_id, title FROM designations WHERE company_id IN ($phActive) AND is_active = 1 ORDER BY title"
        );
        $st->execute($activeTargetIds);
        $targetDesigs = $st->fetchAll();
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $toCompanyId   = (int)($_POST['to_company_id'] ?? 0);
    $toDeptId      = (int)($_POST['to_department_id'] ?? 0);
    $toDesigId     = (int)($_POST['to_designation_id'] ?? 0);
    $transferDate  = trim((string)($_POST['transfer_date'] ?? ''));
    $reason        = trim((string)($_POST['reason'] ?? ''));

    if ($toCompanyId <= 0 || !in_array($toCompanyId, $targetCompanyIds, true)) {
        $errors[] = 'Please select a valid destination company where you have transfer permissions.';
    } else {
        // Enforce permission in target company
        require_company_access($toCompanyId, 'employee.transfer');
    }

    if ($toCompanyId === $fromCompanyId) {
        $errors[] = 'Destination company must be different from the current company.';
    }

    if ($transferDate === '') {
        $errors[] = 'Transfer date is required.';
    } else {
        $td = DateTime::createFromFormat('Y-m-d', $transferDate);
        if (!$td || $td->format('Y-m-d') !== $transferDate) {
            $errors[] = 'Transfer date must be in YYYY-MM-DD format.';
        } elseif ($transferDate < $emp['joining_date']) {
            $errors[] = 'Transfer date cannot be earlier than employee joining date (' . $emp['joining_date'] . ').';
        }
    }

    // Verify destination department belongs to destination company and is active
    if ($toDeptId > 0 && $toCompanyId > 0) {
        $st = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$toDeptId, $toCompanyId]);
        if (!$st->fetch()) {
            $errors[] = 'The selected department does not belong to the destination company.';
        }
    } else {
        $toDeptId = null;
    }

    // Verify destination designation belongs to destination company and is active
    if ($toDesigId > 0 && $toCompanyId > 0) {
        $st = $pdo->prepare('SELECT id FROM designations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$toDesigId, $toCompanyId]);
        if (!$st->fetch()) {
            $errors[] = 'The selected designation does not belong to the destination company.';
        }
    } else {
        $toDesigId = null;
    }

    if (mb_strlen($reason) > 255) {
        $errors[] = 'Reason must not exceed 255 characters.';
    }

    if (!$errors) {
        // Fetch to_company name
        $st = $pdo->prepare('SELECT name, code FROM companies WHERE id = ? LIMIT 1');
        $st->execute([$toCompanyId]);
        $toComp = $st->fetch();

        $currentUserId = (int)current_user()['id'];

        $pdo->beginTransaction();
        try {
            // 1. Re-read the employee row with SELECT ... FOR UPDATE
            $stLock = $pdo->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
            $stLock->execute([$id]);
            $lockedEmp = $stLock->fetch();

            if (!$lockedEmp) {
                $pdo->rollBack();
                flash_set('danger', 'Employee record not found during transfer.');
                redirect('modules/employees/index.php');
            }

            // Abort if company_id changed or employment_status is not 'active'
            if ((int)$lockedEmp['company_id'] !== $fromCompanyId) {
                $pdo->rollBack();
                flash_set('danger', 'Transfer aborted: employee company was modified concurrently.');
                redirect('modules/employees/view.php?id=' . $id);
            }

            if ($lockedEmp['employment_status'] !== 'active') {
                $pdo->rollBack();
                flash_set('danger', 'Transfer aborted: only active employees can be transferred.');
                redirect('modules/employees/view.php?id=' . $id);
            }

            // Check transfer_date is not before joining_date
            if ($transferDate < $lockedEmp['joining_date']) {
                $pdo->rollBack();
                flash_set('danger', 'Transfer aborted: transfer date cannot be before joining date (' . $lockedEmp['joining_date'] . ').');
                redirect('modules/employees/transfer.php?id=' . $id);
            }

            // Lock destination company row inside transaction before generating employee code
            $stLockComp = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
            $stLockComp->execute([$toCompanyId]);

            // Generate NEW employee code for destination company
            $newEmployeeCode = generate_employee_code($pdo, $toCompanyId);

            // 2. Insert into employee_transfers
            $stTrans = $pdo->prepare(
                'INSERT INTO employee_transfers (
                    employee_id, from_company_id, to_company_id,
                    from_department_id, to_department_id,
                    from_designation_id, to_designation_id,
                    transfer_date, reason, status, requested_by, approved_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "completed", ?, ?)'
            );
            $stTrans->execute([
                $id,
                $fromCompanyId,
                $toCompanyId,
                $lockedEmp['department_id'],
                $toDeptId,
                $lockedEmp['designation_id'],
                $toDesigId,
                $transferDate,
                $reason ?: null,
                $currentUserId,
                $currentUserId,
            ]);
            $transferId = (int)$pdo->lastInsertId();

            // 3. Employment history: get open row's start_date for transfer_out
            $stOpen = $pdo->prepare(
                'SELECT start_date FROM employment_history WHERE employee_id = ? AND end_date IS NULL ORDER BY start_date DESC, id DESC LIMIT 1'
            );
            $stOpen->execute([$id]);
            $openRow = $stOpen->fetch();
            $outStartDate = $openRow ? (string)$openRow['start_date'] : (string)$lockedEmp['joining_date'];

            // Close the currently open row before inserting new history row
            $stCloseHist = $pdo->prepare(
                'UPDATE employment_history SET end_date = ? WHERE employee_id = ? AND end_date IS NULL'
            );
            $stCloseHist->execute([$transferDate, $id]);

            // 4. Write employment_history: transfer_out with open row's start_date
            $remarksOut = "Transferred from {$emp['company_name']} to {$toComp['name']}";
            if ($reason !== '') {
                $remarksOut .= ': ' . $reason;
            }
            if (mb_strlen($remarksOut) > 255) {
                $remarksOut = mb_substr($remarksOut, 0, 255);
            }

            $stOut = $pdo->prepare(
                'INSERT INTO employment_history (
                    employee_id, company_id, department_id, designation_id,
                    employment_type, start_date, end_date, change_type, remarks, recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, "transfer_out", ?, ?)'
            );
            $stOut->execute([
                $id,
                $fromCompanyId,
                $lockedEmp['department_id'],
                $lockedEmp['designation_id'],
                $lockedEmp['employment_type'],
                $outStartDate,
                $transferDate,
                $remarksOut,
                $currentUserId,
            ]);

            // Close any open row before inserting transfer_in
            $stCloseHist->execute([$transferDate, $id]);

            // 5. Write employment_history: transfer_in
            $remarksIn = "Transferred to {$toComp['name']} from {$emp['company_name']}";
            if ($reason !== '') {
                $remarksIn .= ': ' . $reason;
            }
            if (mb_strlen($remarksIn) > 255) {
                $remarksIn = mb_substr($remarksIn, 0, 255);
            }

            $stIn = $pdo->prepare(
                'INSERT INTO employment_history (
                    employee_id, company_id, department_id, designation_id,
                    employment_type, start_date, end_date, change_type, remarks, recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?, NULL, "transfer_in", ?, ?)'
            );
            $stIn->execute([
                $id,
                $toCompanyId,
                $toDeptId,
                $toDesigId,
                $lockedEmp['employment_type'],
                $transferDate,
                $remarksIn,
                $currentUserId,
            ]);

            // 6. In the same transaction, UPDATE employee_documents SET company_id = <new company> WHERE employee_id = ?
            $stDocUp = $pdo->prepare('UPDATE employee_documents SET company_id = ? WHERE employee_id = ?');
            $stDocUp->execute([$toCompanyId, $id]);

            // 7. Clear reporting manager for subordinates who reported to the transferred employee
            $stClearSubordinates = $pdo->prepare(
                'UPDATE employees SET reporting_manager_id = NULL WHERE reporting_manager_id = ?'
            );
            $stClearSubordinates->execute([$id]);

            // 8. Update employees table: new company, new employee_code, new department, new designation
            $stUp = $pdo->prepare(
                'UPDATE employees SET
                    company_id = ?, employee_code = ?, department_id = ?, designation_id = ?,
                    reporting_manager_id = NULL, employment_status = "active", updated_at = NOW()
                 WHERE id = ?'
            );
            $stUp->execute([
                $toCompanyId,
                $newEmployeeCode,
                $toDeptId,
                $toDesigId,
                $id,
            ]);

            $pdo->commit();

            // 5. Audit logs for both companies
            log_activity('employee.transfer', 'employee', $id, [
                'from_company' => $emp['company_name'],
                'to_company'   => $toComp['name'],
                'old_code'     => $emp['employee_code'],
                'new_code'     => $newEmployeeCode,
                'transfer_id'  => $transferId,
                'reason'       => $reason,
            ], $toCompanyId);

            log_activity('employee.transfer_out', 'employee', $id, [
                'to_company'   => $toComp['name'],
                'old_code'     => $emp['employee_code'],
                'new_code'     => $newEmployeeCode,
                'transfer_id'  => $transferId,
            ], $fromCompanyId);

            flash_set('success', "Employee successfully transferred to {$toComp['name']}. New employee code: {$newEmployeeCode}.");
            redirect('modules/employees/view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$pageTitle = 'Transfer employee: ' . $emp['first_name'] . ' ' . $emp['last_name'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0 small">
            Inter-company transfer across the AaZa Group. Full employment history will be preserved.
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('modules/employees/view.php?id=' . $id)) ?>">
        &larr; Back to profile
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
        <div class="fw-semibold mb-1">Please fix the following:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$targetCompanies): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <h2 class="h5 text-warning mb-2">No Destination Company Available</h2>
            <p class="text-muted mb-3" style="max-width:540px; margin:0 auto;">
                Transferring an employee requires <code>employee.transfer</code> permission in both the source company (<strong><?= e($emp['company_name']) ?></strong>) and a destination company. You are not currently assigned transfer permissions in any other active company.
            </p>
            <a class="btn btn-primary" href="<?= e(url('modules/employees/view.php?id=' . $id)) ?>">Return to employee profile</a>
        </div>
    </div>
<?php else: ?>
    <form method="post" novalidate id="transferForm" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="row g-3">
            <!-- Current Status (Origin) -->
            <div class="col-12 col-md-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-body fw-semibold">Current Employment Details</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <th class="text-muted ps-0" style="width:40%">Employee:</th>
                                    <td class="fw-semibold"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Current Code:</th>
                                    <td><span class="font-monospace fw-bold"><?= e($emp['employee_code']) ?></span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Current Company:</th>
                                    <td><span class="badge text-bg-dark"><?= e($emp['company_code']) ?></span> <?= e($emp['company_name']) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Department:</th>
                                    <td><?= e($emp['department_name'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Designation:</th>
                                    <td><?= e($emp['designation_title'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Joined:</th>
                                    <td><?= e(date('M d, Y', strtotime((string)$emp['joining_date']))) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted ps-0">Status:</th>
                                    <td><?= employee_status_badge((string)$emp['employment_status']) ?></td>
                                </tr>
                            </table>
                        </div>

                        <hr>
                        <div class="alert alert-info border small mb-0">
                            <strong>Note:</strong> Upon transfer:
                            <ul class="mb-0 ps-3 mt-1">
                                <li>A new employee code will be automatically generated for the destination company.</li>
                                <li>A <code>transfer_out</code> entry will close the position at <?= e($emp['company_name']) ?>.</li>
                                <li>A <code>transfer_in</code> entry will record the new position at the destination company.</li>
                                <li>Employment history is permanently preserved and cannot be erased.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfer Destination Details -->
            <div class="col-12 col-md-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-body fw-semibold">Transfer Destination</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="toCompanyId">Destination Company <span class="text-danger">*</span></label>
                            <select class="form-select" id="toCompanyId" name="to_company_id" required onchange="onTargetCompanyChange()">
                                <option value="0">-- Select destination company --</option>
                                <?php foreach ($targetCompanies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= (int)($_POST['to_company_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>>
                                        <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Requires <code>employee.transfer</code> permission in both companies.</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="toDeptId">New Department</label>
                                <select class="form-select" id="toDeptId" name="to_department_id">
                                    <option value="0">-- Select Department --</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="toDesigId">New Designation</label>
                                <select class="form-select" id="toDesigId" name="to_designation_id">
                                    <option value="0">-- Select Designation --</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="transferDate">Transfer Date <span class="text-danger">*</span></label>
                            <input class="form-control" type="date" id="transferDate" name="transfer_date" required
                                   value="<?= e($_POST['transfer_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="reason">Transfer Reason / Remarks</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="255"
                                      placeholder="e.g. Relocation, group-level reorganization, promotional transfer..."><?= e($_POST['reason'] ?? '') ?></textarea>
                            <div class="form-text">Recorded in the official transfer history and employment timeline.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 pt-2">
                            <a class="btn btn-outline-secondary" href="<?= e(url('modules/employees/view.php?id=' . $id)) ?>">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4"
                                    data-confirm="Are you sure you want to transfer this employee to the selected company? This action writes permanent history records." data-confirm-type="warning" data-confirm-title="Confirm Employee Transfer" data-confirm-btn="Execute Transfer">
                                Execute Transfer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
    const allTargetDepts = <?= json_encode($targetDepts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const allTargetDesigs = <?= json_encode($targetDesigs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const preselectedDept = <?= (int)($_POST['to_department_id'] ?? 0) ?>;
    const preselectedDesig = <?= (int)($_POST['to_designation_id'] ?? 0) ?>;

    function onTargetCompanyChange() {
        const compEl = document.getElementById('toCompanyId');
        const cid = parseInt(compEl.value, 10) || 0;

        // Departments
        const deptSelect = document.getElementById('toDeptId');
        deptSelect.innerHTML = '<option value="0">-- Select Department --</option>';
        allTargetDepts.filter(d => parseInt(d.company_id, 10) === cid).forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            if (parseInt(d.id, 10) === preselectedDept) {
                opt.selected = true;
            }
            deptSelect.appendChild(opt);
        });

        // Designations
        const desigSelect = document.getElementById('toDesigId');
        desigSelect.innerHTML = '<option value="0">-- Select Designation --</option>';
        allTargetDesigs.filter(d => parseInt(d.company_id, 10) === cid).forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.title;
            if (parseInt(d.id, 10) === preselectedDesig) {
                opt.selected = true;
            }
            desigSelect.appendChild(opt);
        });
    }

    document.addEventListener('DOMContentLoaded', onTargetCompanyChange);
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
