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
<div class="container-fluid py-4">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/index.php')) ?>">Vacancies</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($vacancy['vacancy_code']) ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <h1 class="h3 mb-0"><?= e($vacancy['title']) ?></h1>
                <?= vacancy_status_badge($vacancy['status']) ?>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" class="btn btn-outline-primary">
                <i class="bi bi-kanban"></i> Pipeline Kanban
            </a>
            <?php if ($canManageCandidates): ?>
                <a href="<?= e(url('modules/candidates/apply.php?vacancy_id=' . $id)) ?>" class="btn btn-success">
                    + Add Applicant
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a href="<?= e(url('modules/vacancies/form.php?id=' . $id)) ?>" class="btn btn-secondary">
                    Edit Vacancy
                </a>
            <?php endif; ?>
            <?php if (is_group_admin() && user_can('vacancy.delete')): ?>
                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteVacancy(<?= $id ?>, '<?= e(addslashes((string)($vacancy['title'] ?? ''))) ?>')">
                    <i class="bi bi-trash me-1"></i> Delete Vacancy
                </button>
                <form method="post" action="<?= e(url('modules/vacancies/delete.php')) ?>" id="deleteVacancyForm" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                </form>
                <script>
                function confirmDeleteVacancy(id, title) {
                    if (typeof showConfirmModal === 'function') {
                        showConfirmModal({
                            title: 'Delete Vacancy',
                            message: 'Are you sure you want to permanently delete vacancy "' + title + '"? This vacancy must be closed or cancelled and have no active applications. This action cannot be undone.',
                            confirmText: 'Delete Vacancy',
                            variant: 'danger',
                            onConfirm: function() {
                                document.getElementById('deleteVacancyForm').submit();
                            }
                        });
                    } else if (confirm('Are you sure you want to permanently delete vacancy "' + title + '"?')) {
                        document.getElementById('deleteVacancyForm').submit();
                    }
                }
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pipeline Stage Counters Banner -->
    <div class="row g-2 mb-4">
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

    <div class="row g-4">
        <!-- Main Details Column -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Role Overview & Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Vacancy Code</div>
                            <div class="fw-semibold text-primary"><?= e($vacancy['vacancy_code']) ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Company</div>
                            <div class="fw-semibold"><?= e($vacancy['company_name']) ?> (<?= e($vacancy['company_code']) ?>)</div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Department</div>
                            <div class="fw-semibold"><?= e($vacancy['department_name'] ?? 'Not specified') ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Designation</div>
                            <div class="fw-semibold"><?= e($vacancy['designation_title'] ?? 'Not specified') ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Employment Type</div>
                            <div class="fw-semibold"><?= e($types[$vacancy['employment_type']] ?? ucfirst($vacancy['employment_type'])) ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Positions Needed</div>
                            <div class="fw-semibold"><?= (int)$vacancy['positions_count'] ?> vacancy position(s)</div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Positions Filled</div>
                            <div class="fw-semibold text-body">
                                <span class="text-success fw-bold"><?= $hiredCount ?></span> / <?= $positionsCount ?> hired
                                <?php if ($positionsCount > 0 && $hiredCount >= $positionsCount): ?>
                                    <span class="badge badge-soft-success ms-1">Fully Filled</span>
                                <?php elseif ($positionsCount > 0 && $hiredCount > 0): ?>
                                    <span class="badge badge-soft-info ms-1"><?= round(($hiredCount / $positionsCount) * 100) ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Closing Date</div>
                            <div class="fw-semibold">
                                <?php if (!empty($vacancy['closing_date'])): ?>
                                    <?= e(format_date($vacancy['closing_date'])) ?>
                                    <?php if ($vacancy['closing_date'] < date('Y-m-d')): ?>
                                        <span class="badge badge-soft-danger ms-1">Closed / Expired</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">No deadline specified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Posted By</div>
                            <div class="fw-semibold"><?= e($vacancy['creator_name'] ?? 'System') ?></div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="text-muted small">Date Posted</div>
                            <div class="fw-semibold"><?= e(format_datetime($vacancy['created_at'])) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($vacancy['description'])): ?>
                        <div class="mb-4">
                            <h6 class="fw-bold text-body border-bottom pb-2">Job Description</h6>
                            <div class="text-secondary" style="white-space: pre-line;"><?= e($vacancy['description']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($vacancy['requirements'])): ?>
                        <div>
                            <h6 class="fw-bold text-body border-bottom pb-2">Requirements & Qualifications</h6>
                            <div class="text-secondary" style="white-space: pre-line;"><?= e($vacancy['requirements']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Applications List for this Vacancy -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Applicants (<?= count($applications) ?>)
                    </h5>
                    <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" class="btn btn-sm btn-outline-primary">
                        Open Pipeline View &rarr;
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-active">
                                <tr>
                                    <th class="ps-3">Candidate</th>
                                    <th>Contact</th>
                                    <th>Experience</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                    <th>CV</th>
                                    <th class="text-end pe-3">Action</th>
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
                                                <div class="small text-muted"><?= e($app['candidate_code']) ?></div>
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
                                                    <span class="text-muted small">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$app['candidate_id'])) ?>" class="btn btn-sm btn-outline-primary">
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
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h6 class="card-title mb-0">Pipeline Quick Actions</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Move candidates across hiring stages, review resumes, record feedback, and track full applicant history.
                    </p>
                    <div class="d-grid gap-2">
                        <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" class="btn btn-primary">
                            Visual Pipeline Board
                        </a>
                        <?php if ($canManageCandidates): ?>
                            <a href="<?= e(url('modules/candidates/apply.php?vacancy_id=' . $id)) ?>" class="btn btn-outline-success">
                                Assign Existing Candidate
                            </a>
                            <a href="<?= e(url('modules/candidates/form.php')) ?>" class="btn btn-outline-secondary">
                                Register New Candidate
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
