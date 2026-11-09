<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$isEdit = ($id > 0);

$vacancy = null;
if ($isEdit) {
    $st = $pdo->prepare('SELECT * FROM job_vacancies WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $vacancy = $st->fetch();
    if (!$vacancy) {
        abort(404, 'Vacancy not found.');
    }
    require_company_access((int)$vacancy['company_id'], 'vacancy.manage');
}

// Allowed companies for creation (must hold vacancy.manage)
$manageCompanyIds = accessible_company_ids('vacancy.manage');
if (!$isEdit && empty($manageCompanyIds)) {
    abort(403, 'You do not have permission to create vacancies in any company.');
}

$ph = implode(',', array_fill(0, count($manageCompanyIds ?: [0]), '?'));
$st = $pdo->prepare("SELECT id, code, name FROM companies WHERE id IN ($ph) AND is_active = 1 ORDER BY name");
$st->execute($manageCompanyIds ?: [0]);
$allowedCompanies = $st->fetchAll();

// Default values
$errors = [];
$companyId = (int)($vacancy['company_id'] ?? ($_POST['company_id'] ?? ($allowedCompanies[0]['id'] ?? 0)));
$title = (string)($vacancy['title'] ?? ($_POST['title'] ?? ''));
$deptId = (int)($vacancy['department_id'] ?? ($_POST['department_id'] ?? 0));
$desigId = (int)($vacancy['designation_id'] ?? ($_POST['designation_id'] ?? 0));
$positionsCount = (int)($vacancy['positions_count'] ?? ($_POST['positions_count'] ?? 1));
$employmentType = (string)($vacancy['employment_type'] ?? ($_POST['employment_type'] ?? 'full_time'));
$closingDate = (string)($vacancy['closing_date'] ?? ($_POST['closing_date'] ?? ''));
$status = (string)($vacancy['status'] ?? ($_POST['status'] ?? 'draft'));
$description = (string)($vacancy['description'] ?? ($_POST['description'] ?? ''));
$requirements = (string)($vacancy['requirements'] ?? ($_POST['requirements'] ?? ''));

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($isEdit) {
        $companyId = (int)$vacancy['company_id']; // lock company on edit
    } else {
        $companyId = (int)($_POST['company_id'] ?? 0);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $deptId = (int)($_POST['department_id'] ?? 0);
    $desigId = (int)($_POST['designation_id'] ?? 0);
    $positionsCount = (int)($_POST['positions_count'] ?? 1);
    $employmentType = trim((string)($_POST['employment_type'] ?? 'full_time'));
    $closingDate = trim((string)($_POST['closing_date'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'draft'));
    $description = trim((string)($_POST['description'] ?? ''));
    $requirements = trim((string)($_POST['requirements'] ?? ''));

    // Validation
    if ($companyId <= 0 || !in_array($companyId, $manageCompanyIds, true)) {
        $errors['company_id'] = 'Please select a valid company you have permission to manage.';
    }

    if ($title === '') {
        $errors['title'] = 'Job title is required.';
    } elseif (mb_strlen($title) > 150) {
        $errors['title'] = 'Job title must not exceed 150 characters.';
    }

    if ($positionsCount < 1) {
        $errors['positions_count'] = 'Number of positions must be at least 1.';
    }

    $types = employment_type_list();
    if (!isset($types[$employmentType])) {
        $errors['employment_type'] = 'Invalid employment type selected.';
    }

    $statuses = vacancy_status_list();
    if (!isset($statuses[$status])) {
        $errors['status'] = 'Invalid status selected.';
    }

    if ($closingDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $closingDate);
        if (!$d || $d->format('Y-m-d') !== $closingDate) {
            $errors['closing_date'] = 'Invalid closing date format. Use YYYY-MM-DD.';
        }
    } else {
        $closingDate = null;
    }

    // Verify department belongs to the company if provided
    if ($deptId > 0) {
        $st = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$deptId, $companyId]);
        if (!$st->fetchColumn()) {
            $errors['department_id'] = 'The selected department does not belong to this company or is inactive.';
        }
    } else {
        $deptId = null;
    }

    // Verify designation belongs to the company if provided
    if ($desigId > 0) {
        $st = $pdo->prepare('SELECT id FROM designations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$desigId, $companyId]);
        if (!$st->fetchColumn()) {
            $errors['designation_id'] = 'The selected designation does not belong to this company or is inactive.';
        }
    } else {
        $desigId = null;
    }

    if (empty($errors)) {
        if ($isEdit) {
            // Update vacancy
            $upSql = 'UPDATE job_vacancies
                         SET title = ?, department_id = ?, designation_id = ?,
                             positions_count = ?, employment_type = ?, description = ?,
                             requirements = ?, closing_date = ?, status = ?, updated_at = NOW()
                       WHERE id = ?';
            $st = $pdo->prepare($upSql);
            $st->execute([
                $title,
                $deptId,
                $desigId,
                $positionsCount,
                $employmentType,
                $description !== '' ? $description : null,
                $requirements !== '' ? $requirements : null,
                $closingDate,
                $status,
                $id,
            ]);

            log_activity('vacancy.update', 'job_vacancies', $id, [
                'title'           => $title,
                'status'          => $status,
                'positions_count' => $positionsCount,
            ], $companyId);

            flash_set('success', 'Vacancy updated successfully.');
            redirect('modules/vacancies/view.php?id=' . $id);
        } else {
            // Create new vacancy inside transaction with company row lock
            $pdo->beginTransaction();
            try {
                // Lock company row first
                $lockSt = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
                $lockSt->execute([$companyId]);

                $vacCode = generate_vacancy_code($pdo, $companyId);

                $insSql = 'INSERT INTO job_vacancies
                            (company_id, vacancy_code, title, department_id, designation_id,
                             positions_count, employment_type, description, requirements,
                             closing_date, status, created_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
                $st = $pdo->prepare($insSql);
                $st->execute([
                    $companyId,
                    $vacCode,
                    $title,
                    $deptId,
                    $desigId,
                    $positionsCount,
                    $employmentType,
                    $description !== '' ? $description : null,
                    $requirements !== '' ? $requirements : null,
                    $closingDate,
                    $status,
                    current_user_id(),
                ]);
                $newId = (int)$pdo->lastInsertId();

                $pdo->commit();

                log_activity('vacancy.create', 'job_vacancies', $newId, [
                    'vacancy_code'    => $vacCode,
                    'title'           => $title,
                    'status'          => $status,
                    'positions_count' => $positionsCount,
                ], $companyId);

                flash_set('success', "Vacancy $vacCode created successfully.");
                redirect('modules/vacancies/view.php?id=' . $newId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error creating vacancy: ' . $e->getMessage());
                $errors['general'] = 'Failed to create vacancy due to a system error. Please try again.';
            }
        }
    }
}

// Fetch all active departments and designations for client-side dynamic filtering
$allDepts = [];
$allDesigs = [];
if (!empty($manageCompanyIds) || $isEdit) {
    $cids = $isEdit ? [(int)$vacancy['company_id']] : $manageCompanyIds;
    $ph = implode(',', array_fill(0, count($cids), '?'));

    $st = $pdo->prepare("SELECT id, company_id, name FROM departments WHERE company_id IN ($ph) AND is_active = 1 ORDER BY name");
    $st->execute($cids);
    $allDepts = $st->fetchAll();

    $st = $pdo->prepare("SELECT id, company_id, title FROM designations WHERE company_id IN ($ph) AND is_active = 1 ORDER BY title");
    $st->execute($cids);
    $allDesigs = $st->fetchAll();
}

$pageTitle = $isEdit ? 'Edit Vacancy: ' . ($vacancy['vacancy_code'] ?? '') : 'Create Job Vacancy';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 900px;">
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/index.php')) ?>">Vacancies</a></li>
                    <?php if ($isEdit): ?>
                        <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/view.php?id=' . (int)$vacancy['id'])) ?>"><?= e($vacancy['vacancy_code']) ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit</li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">New Vacancy</li>
                    <?php endif; ?>
                </ol>
            </nav>
            <h1 class="page-header-title h3 mb-0"><?= $isEdit ? 'Edit Vacancy (' . e($vacancy['vacancy_code']) . ')' : 'Create New Job Vacancy' ?></h1>
        </div>
        <div class="page-actions">
            <a href="<?= e($isEdit ? url('modules/vacancies/view.php?id=' . (int)$vacancy['id']) : url('modules/vacancies/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="post" action="<?= $isEdit ? e(url('modules/vacancies/form.php?id=' . (int)$id)) : e(url('modules/vacancies/form.php')) ?>" id="vacancyForm" autocomplete="off">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <!-- Company -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                        <?php if ($isEdit): ?>
                            <?php
                            $cName = '';
                            foreach ($allowedCompanies as $c) {
                                if ((int)$c['id'] === (int)$vacancy['company_id']) {
                                    $cName = $c['code'] . ' - ' . $c['name'];
                                    break;
                                }
                            }
                            ?>
                            <input type="text" class="form-control bg-body-tertiary" value="<?= e($cName ?: 'Company #' . $vacancy['company_id']) ?>" readonly>
                            <input type="hidden" name="company_id" id="companySelect" value="<?= (int)$vacancy['company_id'] ?>">
                            <div class="form-text">Company cannot be changed after creation.</div>
                        <?php else: ?>
                            <select name="company_id" id="companySelect" class="form-select <?= isset($errors['company_id']) ? 'is-invalid' : '' ?>" required>
                                <?php foreach ($allowedCompanies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"<?= $companyId === (int)$c['id'] ? ' selected' : '' ?>>
                                        <?= e($c['code'] . ' - ' . $c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['company_id'])): ?>
                                <div class="invalid-feedback"><?= e($errors['company_id']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Status -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>" required>
                            <?php foreach (vacancy_status_list() as $val => $lbl): ?>
                                <option value="<?= e($val) ?>"<?= $status === $val ? ' selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <div class="invalid-feedback"><?= e($errors['status']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Job Title -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Job Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                               value="<?= e($title) ?>" placeholder="e.g. Senior Software Engineer" required maxlength="150">
                        <?php if (isset($errors['title'])): ?>
                            <div class="invalid-feedback"><?= e($errors['title']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Department -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Department</label>
                        <select name="department_id" id="departmentSelect" class="form-select <?= isset($errors['department_id']) ? 'is-invalid' : '' ?>">
                            <option value="0">-- Select Department (Optional) --</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" data-company-id="<?= (int)$d['company_id'] ?>"<?= $deptId === (int)$d['id'] ? ' selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['department_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['department_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Designation -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Designation</label>
                        <select name="designation_id" id="designationSelect" class="form-select <?= isset($errors['designation_id']) ? 'is-invalid' : '' ?>">
                            <option value="0">-- Select Designation (Optional) --</option>
                            <?php foreach ($allDesigs as $des): ?>
                                <option value="<?= (int)$des['id'] ?>" data-company-id="<?= (int)$des['company_id'] ?>"<?= $desigId === (int)$des['id'] ? ' selected' : '' ?>>
                                    <?= e($des['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['designation_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['designation_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Employment Type -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                        <select name="employment_type" class="form-select <?= isset($errors['employment_type']) ? 'is-invalid' : '' ?>" required>
                            <?php foreach (employment_type_list() as $val => $lbl): ?>
                                <option value="<?= e($val) ?>"<?= $employmentType === $val ? ' selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['employment_type'])): ?>
                            <div class="invalid-feedback"><?= e($errors['employment_type']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Positions Count -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Positions Count <span class="text-danger">*</span></label>
                        <input type="number" name="positions_count" class="form-control <?= isset($errors['positions_count']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string)$positionsCount) ?>" min="1" max="999" required>
                        <?php if (isset($errors['positions_count'])): ?>
                            <div class="invalid-feedback"><?= e($errors['positions_count']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Closing Date -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Closing Date</label>
                        <input type="date" name="closing_date" class="form-control <?= isset($errors['closing_date']) ? 'is-invalid' : '' ?>"
                               value="<?= e($closingDate) ?>">
                        <div class="form-text">Leave blank if no deadline.</div>
                        <?php if (isset($errors['closing_date'])): ?>
                            <div class="invalid-feedback"><?= e($errors['closing_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Job Description -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Job Description</label>
                        <textarea name="description" class="form-control" rows="5" placeholder="Key responsibilities, team overview, role summary..."><?= e($description) ?></textarea>
                    </div>

                    <!-- Requirements -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Requirements & Qualifications</label>
                        <textarea name="requirements" class="form-control" rows="5" placeholder="Required experience, technical skills, education, certifications..."><?= e($requirements) ?></textarea>
                    </div>

                    <!-- Submit buttons -->
                    <div class="col-12 mt-4 d-flex justify-content-between align-items-center">
                        <a href="<?= $isEdit ? e(url('modules/vacancies/view.php?id=' . (int)$id)) : e(url('modules/vacancies/index.php')) ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <?= $isEdit ? 'Update Vacancy' : 'Create Vacancy' ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const companySelect = document.getElementById('companySelect');
    const deptSelect = document.getElementById('departmentSelect');
    const desigSelect = document.getElementById('designationSelect');

    function filterOptions() {
        const selectedCompany = companySelect ? companySelect.value : '';
        if (!selectedCompany) return;

        // Filter departments
        if (deptSelect) {
            Array.from(deptSelect.options).forEach(opt => {
                if (!opt.value || opt.value === '0') return;
                const optCompany = opt.getAttribute('data-company-id');
                opt.hidden = (optCompany !== selectedCompany);
            });
            if (deptSelect.selectedOptions[0] && deptSelect.selectedOptions[0].hidden) {
                deptSelect.value = '0';
            }
        }

        // Filter designations
        if (desigSelect) {
            Array.from(desigSelect.options).forEach(opt => {
                if (!opt.value || opt.value === '0') return;
                const optCompany = opt.getAttribute('data-company-id');
                opt.hidden = (optCompany !== selectedCompany);
            });
            if (desigSelect.selectedOptions[0] && desigSelect.selectedOptions[0].hidden) {
                desigSelect.value = '0';
            }
        }
    }

    if (companySelect) {
        companySelect.addEventListener('change', filterOptions);
        filterOptions();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
