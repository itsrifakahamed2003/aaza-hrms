<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Vacancy not found.');
}

$pdo = db();

$st = $pdo->prepare(
    'SELECT v.*,
            c.code AS company_code, c.name AS company_name,
            d.name AS department_name, des.title AS designation_title,
            u.full_name AS creator_name
       FROM job_vacancies v
       JOIN companies c ON c.id = v.company_id
       LEFT JOIN departments d ON d.id = v.department_id
       LEFT JOIN designations des ON des.id = v.designation_id
       LEFT JOIN users u ON u.id = v.created_by
      WHERE v.id = ?
      LIMIT 1'
);
$st->execute([$id]);
$vacancy = $st->fetch();

if (!$vacancy) {
    abort(404, 'Vacancy not found.');
}

$companyId = (int)$vacancy['company_id'];
require_company_access($companyId, 'vacancy.view');

$canManage = user_can('vacancy.manage', $companyId);
$canManageCandidates = user_can('candidate.manage', $companyId);

// Fetch applications for this vacancy
$appSql = "SELECT ca.*,
                  c.candidate_code, c.first_name, c.last_name, c.email, c.phone, c.experience_years,
                  (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id = c.id AND cd.doc_type = 'cv' ORDER BY cd.id DESC LIMIT 1) AS cv_doc_id
             FROM candidate_applications ca
             JOIN candidates c ON c.id = ca.candidate_id
            WHERE ca.vacancy_id = ?
            ORDER BY ca.application_date DESC, ca.id DESC";
$appSt = $pdo->prepare($appSql);
$appSt->execute([$id]);
$applications = $appSt->fetchAll();

// Count per pipeline stage
$stageCounts = [];
foreach (pipeline_stages() as $stgKey => $stgLabel) {
    $stageCounts[$stgKey] = 0;
}
foreach ($applications as $a) {
    $stg = (string)$a['status'];
    if (isset($stageCounts[$stg])) {
        $stageCounts[$stg]++;
    }
}

// Positions filled count
$hiredCountSt = $pdo->prepare("SELECT COUNT(*) FROM candidate_applications WHERE vacancy_id = ? AND status = 'hired'");
$hiredCountSt->execute([$id]);
$hiredCount = (int)$hiredCountSt->fetchColumn();
$positionsCount = (int)$vacancy['positions_count'];

$types = employment_type_list();

$pageTitle = 'Vacancy: ' . ($vacancy['vacancy_code'] ?? '');
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/index.php')) ?>">Vacancies</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($vacancy['vacancy_code']) ?></li>
        </ol>
    </nav>

    <!-- Detail Header -->
    <div class="detail-header">
        <div class="detail-header-main">
            <div class="detail-header-title-row">
                <h1 class="detail-header-title"><?= e($vacancy['title']) ?></h1>
                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($vacancy['vacancy_code']) ?></span>
                <?= vacancy_status_badge($vacancy['status']) ?>
            </div>
            <p class="detail-header-subtitle">
                <span class="badge bg-body-secondary text-body border font-monospace"><?= e($vacancy['company_code']) ?></span> <?= e($vacancy['company_name']) ?>
                <?php if (!empty($vacancy['department_name'])): ?> &bull; <?= e($vacancy['department_name']) ?><?php endif; ?>
                &bull; <?= (int)$vacancy['positions_count'] ?> position(s) &bull; Posted <?= e(format_datetime($vacancy['created_at'])) ?>
            </p>
        </div>

        <div class="detail-header-actions">
            <!-- ONE Primary Button -->
            <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-kanban me-1"></i> Pipeline Board
            </a>

            <!-- ONE "More" 3-dot menu -->
            <?php
            $vacMore = [];
            if ($canManage) {
                $vacMore[] = [
                    'type' => 'link',
                    'icon' => 'bi-pencil',
                    'label' => 'Edit Vacancy',
                    'url' => 'modules/vacancies/form.php?id=' . $id,
                ];
            }
            if ($canManageCandidates) {
                $vacMore[] = [
                    'type' => 'link',
                    'icon' => 'bi-plus-lg',
                    'label' => 'Add Applicant',
                    'url' => 'modules/candidates/apply.php?vacancy_id=' . $id,
                ];
            }
            $vacMore[] = [
                'type' => 'link',
                'icon' => 'bi-arrow-left',
                'label' => 'Back to Vacancies',
                'url' => 'modules/vacancies/index.php',
            ];
            if (is_group_admin() && user_can('vacancy.delete')) {
                $vacMore[] = [
                    'type' => 'button',
                    'icon' => 'bi-trash',
                    'label' => 'Delete Vacancy',
                    'onclick' => 'confirmDeleteVacancy(' . $id . ', \'' . e(addslashes((string)($vacancy['title'] ?? ''))) . '\')',
                    'danger' => true,
                ];
            }
            echo action_menu($vacMore, [
                'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
                'aria_label' => 'More vacancy actions',
            ]);
            ?>
        </div>
    </div>

    <!-- Pipeline Stage Counters Banner -->
    <div class="row g-2 mb-3">
        <?php foreach (pipeline_stages() as $stgKey => $stgLabel): ?>
            <div class="col-6 col-sm-4 col-md-2">
                <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id . '#' . $stgKey)) ?>" class="text-decoration-none">
                    <div class="card border-0 shadow-sm text-center py-2 h-100 <?= $stageCounts[$stgKey] > 0 ? 'border-bottom border-3 border-primary' : '' ?>">
                        <div class="card-body p-2">
                            <div class="small text-muted text-truncate"><?= e($stgLabel) ?></div>
                            <div class="h4 mb-0 fw-bold text-body"><?= (int)$stageCounts[$stgKey] ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 align-items-start">
        <!-- Main Details Column -->
        <div class="col-lg-8">
            <div class="detail-card">
                <div class="card-header">
                    <span>Role Overview &amp; Details</span>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($vacancy['vacancy_code']) ?></span>
                </div>
                <div class="card-body">
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-label">Company</span>
                            <span class="kv-value"><?= e($vacancy['company_name']) ?> (<?= e($vacancy['company_code']) ?>)</span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Department</span>
                            <span class="kv-value"><?= e($vacancy['department_name'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Designation</span>
                            <span class="kv-value fw-semibold"><?= e($vacancy['designation_title'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Employment Type</span>
                            <span class="kv-value">
                                <span class="badge bg-body-secondary text-body border"><?= e($types[$vacancy['employment_type']] ?? ucfirst($vacancy['employment_type'])) ?></span>
                            </span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Positions Needed</span>
                            <span class="kv-value"><?= (int)$vacancy['positions_count'] ?> vacancy position(s)</span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Positions Filled</span>
                            <span class="kv-value">
                                <span class="text-success fw-bold"><?= $hiredCount ?></span> / <?= $positionsCount ?> hired
                                <?php if ($positionsCount > 0 && $hiredCount >= $positionsCount): ?>
                                    <span class="badge badge-soft-success ms-1">Fully Filled</span>
                                <?php elseif ($positionsCount > 0 && $hiredCount > 0): ?>
                                    <span class="badge badge-soft-info ms-1"><?= round(($hiredCount / $positionsCount) * 100) ?>%</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Closing Date</span>
                            <span class="kv-value">
                                <?php if (!empty($vacancy['closing_date'])): ?>
                                    <?= e(format_date($vacancy['closing_date'])) ?>
                                    <?php if ($vacancy['closing_date'] < date('Y-m-d')): ?>
                                        <span class="badge badge-soft-danger ms-1">Closed / Expired</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Posted By</span>
                            <span class="kv-value"><?= e($vacancy['creator_name'] ?? 'System') ?></span>
                        </div>
                    </div>

                    <?php if (!empty($vacancy['description'])): ?>
                        <div class="mt-3 pt-3 border-top">
                            <h6 class="fw-bold text-body small text-uppercase mb-2">Job Description</h6>
                            <div class="text-secondary small" style="white-space: pre-line;"><?= e($vacancy['description']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($vacancy['requirements'])): ?>
                        <div class="mt-3 pt-3 border-top">
                            <h6 class="fw-bold text-body small text-uppercase mb-2">Requirements &amp; Qualifications</h6>
                            <div class="text-secondary small" style="white-space: pre-line;"><?= e($vacancy['requirements']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Applications List for this Vacancy -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Applicants (<?= count($applications) ?>)</span>
                    <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.8rem;">
                        Pipeline View &rarr;
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Candidate</th>
                                    <th>Contact</th>
                                    <th>Experience</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                    <th>CV</th>
                                    <th class="text-end pe-3 text-nowrap" style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($applications)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            No candidates have applied for this vacancy yet.
                                            <?php if ($canManageCandidates): ?>
                                                <div class="mt-2">
                                                    <a href="<?= e(url('modules/candidates/apply.php?vacancy_id=' . $id)) ?>" class="btn btn-sm btn-primary">
                                                        Add Applicant Now
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($applications as $app): ?>
                                        <tr>
                                            <td class="ps-3">
                                                <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$app['candidate_id'])) ?>" class="fw-semibold text-decoration-none">
                                                    <?= e($app['first_name'] . ' ' . $app['last_name']) ?>
                                                </a>
                                                <div class="small text-muted font-monospace"><?= e($app['candidate_code']) ?></div>
                                            </td>
                                            <td>
                                                <div class="small"><?= e($app['email'] ?? '—') ?></div>
                                                <div class="small text-muted"><?= e($app['phone'] ?? '—') ?></div>
                                            </td>
                                            <td>
                                                <?= $app['experience_years'] !== null ? e((string)$app['experience_years']) . ' yrs' : '—' ?>
                                            </td>
                                            <td>
                                                <?= e(format_date($app['application_date'])) ?>
                                            </td>
                                            <td>
                                                <?= application_status_badge($app['status']) ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($app['cv_doc_id'])): ?>
                                                    <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$app['cv_doc_id'])) ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Download CV">
                                                        CV
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$app['candidate_id'])) ?>" class="btn btn-sm btn-outline-primary text-nowrap">
                                                    Profile
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar / Actions Column -->
        <div class="col-lg-4">
            <div class="detail-card">
                <div class="card-header">
                    <span>Quick Candidate Actions</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Assign an existing registered candidate or register a new candidate directly to this vacancy.
                    </p>
                    <div class="d-grid gap-2">
                        <?php if ($canManageCandidates): ?>
                            <a href="<?= e(url('modules/candidates/apply.php?vacancy_id=' . $id)) ?>" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-person-plus me-1"></i> Assign Existing Candidate
                            </a>
                            <a href="<?= e(url('modules/candidates/form.php')) ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-person-badge me-1"></i> Register New Candidate
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
