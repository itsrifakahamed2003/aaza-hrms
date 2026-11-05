<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('candidate.view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Candidate not found.');
}

$pdo = db();

$st = $pdo->prepare(
    'SELECT c.*, u.full_name AS creator_name
       FROM candidates c
       LEFT JOIN users u ON u.id = c.created_by
      WHERE c.id = ?
      LIMIT 1'
);
$st->execute([$id]);
$candidate = $st->fetch();

if (!$candidate) {
    abort(404, 'Candidate not found.');
}

$hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');
$scopedCompanyIds = scoped_company_ids('candidate.view');

// Verify visibility:
// If non-cross-company user, check if candidate has any application in user's companies or user is creator
if (!$hasCrossCompany) {
    $hasAccess = false;
    if (!empty($scopedCompanyIds)) {
        $cph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $permSt = $pdo->prepare(
            "SELECT 1 FROM candidate_applications WHERE candidate_id = ? AND company_id IN ($cph) LIMIT 1"
        );
        $permSt->execute(array_merge([$id], $scopedCompanyIds));
        $hasAccess = (bool)$permSt->fetchColumn();
    }
    if (!$hasAccess && (int)$candidate['created_by'] === current_user_id()) {
        $hasAccess = true;
    }
    if (!$hasAccess) {
        abort(403, 'You do not have permission to view cross-company candidate profiles.');
    }
}

// Fetch applications:
// Visibility rule: "users see applications only for their companies. Full candidate pool across companies only with candidate.cross_company."
$appWhere = 'WHERE ca.candidate_id = ?';
$appParams = [$id];

if (!$hasCrossCompany) {
    if (empty($scopedCompanyIds)) {
        $appWhere .= ' AND 1 = 0';
    } else {
        $cph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $appWhere .= " AND ca.company_id IN ($cph)";
        $appParams = array_merge($appParams, $scopedCompanyIds);
    }
}

$appSql = "SELECT ca.*,
                  v.vacancy_code, v.title AS vacancy_title, v.status AS vacancy_status,
                  c.code AS company_code, c.name AS company_name
             FROM candidate_applications ca
             JOIN job_vacancies v ON v.id = ca.vacancy_id
             JOIN companies c ON c.id = ca.company_id
            $appWhere
            ORDER BY ca.application_date DESC, ca.id DESC";

$appSt = $pdo->prepare($appSql);
$appSt->execute($appParams);
$applications = $appSt->fetchAll();

// Fetch status history for each application
$appIds = array_column($applications, 'id');
$historyByApp = [];
if (!empty($appIds)) {
    $ph = implode(',', array_fill(0, count($appIds), '?'));
    $hSt = $pdo->prepare(
        "SELECT csh.*, u.full_name AS changer_name
           FROM candidate_status_history csh
           LEFT JOIN users u ON u.id = csh.changed_by
          WHERE csh.application_id IN ($ph)
          ORDER BY csh.changed_at DESC"
    );
    $hSt->execute($appIds);
    foreach ($hSt->fetchAll() as $hRow) {
        $historyByApp[(int)$hRow['application_id']][] = $hRow;
    }
}

// Fetch interviews for each application
$interviewsByApp = [];
if (!empty($appIds)) {
    $iph = implode(',', array_fill(0, count($appIds), '?'));
    $iSt = $pdo->prepare(
        "SELECT i.*,
                GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') AS panel_names
           FROM interviews i
           LEFT JOIN interview_panel ip ON ip.interview_id = i.id
           LEFT JOIN users u ON u.id = ip.user_id
          WHERE i.application_id IN ($iph)
          GROUP BY i.id
          ORDER BY i.scheduled_at DESC"
    );
    $iSt->execute($appIds);
    foreach ($iSt->fetchAll() as $iRow) {
        $interviewsByApp[(int)$iRow['application_id']][] = $iRow;
    }
}

// Fetch offers for each application
$offersByApp = [];
if (!empty($appIds)) {
    $oph = implode(',', array_fill(0, count($appIds), '?'));
    $oSt = $pdo->prepare(
        "SELECT jo.*, emp.employee_code AS converted_emp_code
           FROM job_offers jo
           LEFT JOIN employees emp ON emp.id = jo.converted_employee_id
          WHERE jo.application_id IN ($oph)
          ORDER BY jo.id DESC"
    );
    $oSt->execute($appIds);
    foreach ($oSt->fetchAll() as $oRow) {
        $aId = (int)$oRow['application_id'];
        if (!isset($offersByApp[$aId])) {
            $offersByApp[$aId] = $oRow;
        }
    }
}

// Fetch interview evaluations for each application
$evaluationsByApp = [];
if (!empty($appIds)) {
    $eph = implode(',', array_fill(0, count($appIds), '?'));
    $eSt = $pdo->prepare(
        "SELECT ie.*, u.full_name AS evaluator_name, i.round_no, i.interview_type, i.application_id
           FROM interview_evaluations ie
           JOIN interviews i ON ie.interview_id = i.id
           JOIN users u ON ie.evaluator_id = u.id
          WHERE i.application_id IN ($eph)
          ORDER BY i.round_no ASC, ie.id ASC"
    );
    $eSt->execute($appIds);
    foreach ($eSt->fetchAll() as $eRow) {
        $evaluationsByApp[(int)$eRow['application_id']][] = $eRow;
    }
}

// Fetch candidate documents
$docSt = $pdo->prepare(
    "SELECT cd.*, u.full_name AS uploader_name
       FROM candidate_documents cd
       LEFT JOIN users u ON u.id = cd.uploaded_by
      WHERE cd.candidate_id = ?
      ORDER BY cd.uploaded_at DESC"
);
$docSt->execute([$id]);
$documents = $docSt->fetchAll();

$sources = candidate_source_list();
$docTypes = candidate_doc_type_list();
$canManageCandidates = user_can('candidate.manage');
$canDeleteDoc = is_group_admin() || user_can('document.delete');

$pageTitle = 'Candidate Profile: ' . ($candidate['first_name'] . ' ' . $candidate['last_name']);
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/index.php')) ?>">Candidates</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($candidate['candidate_code']) ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <h1 class="h3 mb-0"><?= e($candidate['first_name'] . ' ' . $candidate['last_name']) ?></h1>
                <span class="badge text-bg-primary fs-6"><?= e($candidate['candidate_code']) ?></span>
                <?php if ($candidate['consent_given']): ?>
                    <span class="badge text-bg-success-subtle text-success border border-success-subtle">Consent Verified</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($canManageCandidates): ?>
                <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . $id)) ?>" class="btn btn-success">
                    + Apply to Vacancy
                </a>
                <a href="<?= e(url('modules/candidates/form.php?id=' . $id)) ?>" class="btn btn-outline-secondary">
                    Edit Profile
                </a>
            <?php endif; ?>
            <?php if (is_group_admin() && user_can('candidate.delete')): ?>
                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteCandidate(<?= $id ?>, '<?= e(addslashes($candidate['first_name'] . ' ' . $candidate['last_name'])) ?>')">
                    <i class="bi bi-trash me-1"></i> Delete Candidate
                </button>
                <form method="post" action="<?= e(url('modules/candidates/delete.php')) ?>" id="deleteCandidateForm" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                </form>
                <script>
                function confirmDeleteCandidate(id, name) {
                    if (typeof showConfirmModal === 'function') {
                        showConfirmModal({
                            title: 'Delete Candidate Profile',
                            message: 'Are you sure you want to permanently delete candidate "' + name + '" and all associated applications? This action cannot be undone.',
                            confirmText: 'Delete Candidate',
                            variant: 'danger',
                            onConfirm: function() {
                                document.getElementById('deleteCandidateForm').submit();
                            }
                        });
                    } else if (confirm('Are you sure you want to permanently delete candidate "' + name + '"?')) {
                        document.getElementById('deleteCandidateForm').submit();
                    }
                }
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Candidate Information Details -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3">
                    <h5 class="card-title mb-0">Candidate Profile</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="text-muted small">Candidate Code</div>
                        <div class="fw-bold text-primary"><?= e($candidate['candidate_code']) ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Email</div>
                        <div class="fw-semibold"><?= e($candidate['email'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Phone</div>
                        <div class="fw-semibold"><?= e($candidate['phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">NIC / Passport</div>
                        <div class="fw-semibold"><?= e($candidate['nic_passport'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="text-muted small">Date of Birth</div>
                            <div class="fw-semibold"><?= !empty($candidate['dob']) ? e(format_date($candidate['dob'])) : '—' ?></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Gender</div>
                            <div class="fw-semibold"><?= !empty($candidate['gender']) ? e(ucfirst($candidate['gender'])) : '—' ?></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Experience</div>
                        <div class="fw-semibold">
                            <?= $candidate['experience_years'] !== null ? e((string)$candidate['experience_years']) . ' years' : 'Not specified' ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Source / Recruitment Channel</div>
                        <span class="badge bg-body-secondary text-body border text-secondary">
                            <?= e($sources[$candidate['source']] ?? ucfirst((string)$candidate['source'])) ?>
                        </span>
                    </div>
                    <?php if (!empty($candidate['skills'])): ?>
                        <div class="mb-3">
                            <div class="text-muted small">Skills & Expertise</div>
                            <div class="text-body small mt-1"><?= e($candidate['skills']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['address'])): ?>
                        <div class="mb-3">
                            <div class="text-muted small">Residential Address</div>
                            <div class="small text-secondary" style="white-space: pre-line;"><?= e($candidate['address']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['qualifications'])): ?>
                        <div class="mb-3">
                            <div class="text-muted small">Qualifications</div>
                            <div class="small text-secondary" style="white-space: pre-line;"><?= e($candidate['qualifications']) ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="pt-2 border-top small text-muted">
                        <div>Registered by: <?= e($candidate['creator_name'] ?? 'System') ?></div>
                        <div>Registered on: <?= e(format_datetime($candidate['created_at'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Documents Section -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Documents & CV</h5>
                    <span class="badge text-bg-secondary rounded-pill"><?= count($documents) ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                        <p class="text-muted small mb-3">No documents uploaded yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush mb-3">
                            <?php foreach ($documents as $doc): ?>
                                <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                    <div class="text-truncate me-2">
                                        <div class="fw-semibold text-truncate small" title="<?= e($doc['original_name']) ?>">
                                            <?= e($doc['original_name']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <span class="badge bg-body-secondary text-body border me-1"><?= e($docTypes[$doc['doc_type']] ?? $doc['doc_type']) ?></span>
                                            <?= round((int)$doc['file_size'] / 1024, 1) ?> KB
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$doc['id'])) ?>" class="btn btn-sm btn-outline-primary" title="Download securely">
                                            Download
                                        </a>
                                        <?php if ($canDeleteDoc): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteCandDocModal<?= (int)$doc['id'] ?>" title="Delete document">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <?php if ($canManageCandidates): ?>
                        <div class="p-3 bg-body-tertiary rounded border">
                            <h6 class="small fw-bold mb-2">Upload New Document</h6>
                            <form method="post" action="<?= e(url('modules/candidates/document_upload.php')) ?>" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" name="candidate_id" value="<?= $id ?>">
                                <div class="mb-2">
                                    <select name="doc_type" class="form-select form-select-sm" required>
                                        <?php foreach ($docTypes as $dVal => $dLbl): ?>
                                            <option value="<?= e($dVal) ?>"><?= e($dLbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <input type="file" name="document" class="form-control form-control-sm" accept=".pdf,.docx,.jpg,.jpeg,.png" required>
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary w-100">Upload File</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Applications Section -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-0">Job Applications (<?= count($applications) ?>)</h5>
                        <small class="text-muted">Applications across group vacancies. Rejected candidate records are permanently preserved.</small>
                    </div>
                    <?php if ($canManageCandidates): ?>
                        <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . $id)) ?>" class="btn btn-sm btn-primary">
                            + Apply to Vacancy
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($applications)): ?>
                        <div class="text-center py-5 text-muted">
                            <p class="mb-2">This candidate has not applied to any vacancy in your accessible companies yet.</p>
                            <?php if ($canManageCandidates): ?>
                                <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . $id)) ?>" class="btn btn-sm btn-primary">
                                    Create First Application
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-active">
                                    <tr>
                                        <th class="ps-3">Company & Vacancy</th>
                                        <th>Applied Date</th>
                                        <th>Current Stage</th>
                                        <th>Notes</th>
                                        <th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $app): ?>
                                        <?php
                                        $canManageThisApp = user_can('candidate.manage', (int)$app['company_id']);
                                        $canInterviewThisApp = user_can('interview.manage', (int)$app['company_id'])
                                            && !in_array($app['status'], ['hired', 'rejected', 'withdrawn', 'offer_declined'], true);
                                        $appHistory = $historyByApp[(int)$app['id']] ?? [];
                                        $appInterviews = $interviewsByApp[(int)$app['id']] ?? [];
                                        ?>
                                        <tr>
                                            <td class="ps-3">
                                                <div>
                                                    <span class="badge bg-body-secondary text-body border me-1"><?= e($app['company_code']) ?></span>
                                                    <a href="<?= e(url('modules/vacancies/view.php?id=' . (int)$app['vacancy_id'])) ?>" class="fw-semibold text-decoration-none">
                                                        <?= e($app['vacancy_title']) ?>
                                                    </a>
                                                </div>
                                                <div class="small text-muted mb-1">
                                                    Vacancy: <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$app['vacancy_id'])) ?>"><?= e($app['vacancy_code']) ?></a>
                                                </div>

                                                <?php if (!empty($appInterviews)): ?>
                                                    <div class="mt-2 pt-2 border-top" style="max-width: 420px;">
                                                        <small class="fw-semibold text-secondary d-block mb-1">
                                                            <i class="bi bi-calendar-check text-primary me-1"></i>Interviews (<?= count($appInterviews) ?>):
                                                        </small>
                                                        <div class="d-flex flex-column gap-1">
                                                            <?php foreach ($appInterviews as $ai): ?>
                                                                <div class="d-flex justify-content-between align-items-center bg-body-tertiary p-1 px-2 rounded" style="font-size:0.75rem;">
                                                                    <div>
                                                                        <strong>R<?= (int)$ai['round_no'] ?></strong> &bull; <?= e(format_datetime($ai['scheduled_at'], 'd M, h:i A')) ?>
                                                                    </div>
                                                                    <div class="d-flex align-items-center gap-1">
                                                                        <?= interview_status_badge($ai['status']) ?>
                                                                        <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$ai['id'])) ?>" class="btn btn-outline-primary py-0 px-1" style="font-size:0.7rem;">View</a>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php $appOffer = $offersByApp[(int)$app['id']] ?? null; ?>
                                                <?php if (!empty($appOffer)): ?>
                                                    <div class="mt-2 pt-1 border-top" style="font-size:0.75rem;">
                                                        <span class="text-secondary fw-semibold"><i class="bi bi-file-earmark-check text-primary me-1"></i>Offer:</span>
                                                        <?= offer_status_badge($appOffer['status']) ?>
                                                        <?php if (!empty($appOffer['converted_employee_id'])): ?>
                                                            <span class="badge badge-soft-success ms-1"><i class="bi bi-person-check me-1"></i>Hired (<?= e($appOffer['converted_emp_code']) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= e(format_date($app['application_date'])) ?>
                                            </td>
                                            <td>
                                                <?= application_status_badge($app['status']) ?>
                                            </td>
                                            <td>
                                                <div class="small text-truncate" style="max-width: 180px;" title="<?= e($app['notes'] ?? '') ?>">
                                                    <?= e($app['notes'] ?? '—') ?>
                                                </div>
                                            </td>
                                            <td class="text-end pe-3">
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($canInterviewThisApp): ?>
                                                        <a href="<?= e(url('modules/interviews/schedule.php?application_id=' . (int)$app['id'])) ?>"
                                                           class="btn btn-outline-success" title="Schedule Interview">
                                                            <i class="bi bi-calendar-plus me-1"></i>Interview
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php
                                                    $canOfferManage = user_can('offer.manage', (int)$app['company_id']);
                                                    $canOfferView   = user_can('offer.view', (int)$app['company_id']);
                                                    ?>
                                                    <?php if (!empty($appOffer) && $canOfferView): ?>
                                                        <a href="<?= e(url('modules/offers/view.php?id=' . (int)$appOffer['id'])) ?>" class="btn btn-outline-primary" title="View Job Offer">
                                                            Offer
                                                        </a>
                                                        <?php if (!empty($appOffer['converted_employee_id'])): ?>
                                                            <a href="<?= e(url('modules/employees/view.php?id=' . (int)$appOffer['converted_employee_id'])) ?>" class="btn btn-outline-success" title="View Employee Profile">
                                                                Employee
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif (empty($appOffer) && (string)$app['status'] === 'selected' && $canOfferManage): ?>
                                                        <a href="<?= e(url('modules/offers/form.php?application_id=' . (int)$app['id'])) ?>" class="btn btn-success" title="Draft Job Offer">
                                                            + Offer
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$app['vacancy_id'])) ?>" class="btn btn-outline-info" title="Pipeline Board">
                                                        Pipeline
                                                    </a>
                                                    <?php if ($canManageThisApp): ?>
                                                        <button type="button" class="btn btn-outline-primary"
                                                                onclick="openStatusChangeModal(<?= (int)$app['id'] ?>, '<?= e($app['status']) ?>', '<?= e(addslashes($app['vacancy_title'])) ?>', <?= user_can('interview.view_evaluations', (int)$app['company_id']) ? 'true' : 'false' ?>)">
                                                            Status
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($appHistory)): ?>
                                                        <button type="button" class="btn btn-outline-secondary"
                                                                onclick="openAppHistoryModal(<?= (int)$app['id'] ?>)">
                                                            History
                                                        </button>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Hidden json history payload -->
                                                <script type="application/json" id="app-history-<?= (int)$app['id'] ?>">
                                                    <?= json_encode($appHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                                </script>

                                                <!-- Hidden json evaluations payload -->
                                                <?php $appEvals = $evaluationsByApp[(int)$app['id']] ?? []; ?>
                                                <script type="application/json" id="app-evaluations-<?= (int)$app['id'] ?>">
                                                    <?= json_encode($appEvals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                                </script>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Status Modal -->
<div class="modal fade" id="statusChangeModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/candidates/status_change.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="application_id" id="modalAppId" value="0">
                <input type="hidden" name="return_to" value="<?= e(url('modules/candidates/view.php?id=' . $id)) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="statusModalLabel">Change Application Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-muted small">Vacancy</label>
                        <div class="fw-semibold" id="modalVacancyTitle"></div>
                    </div>

                    <!-- Evaluation Scores Summary (Shown when deciding) -->
                    <div id="evaluationsSummaryContainer" class="mb-3 d-none">
                        <label class="form-label fw-semibold text-secondary small d-flex align-items-center gap-1">
                            <i class="bi bi-star-fill text-warning"></i> Interview Evaluations Summary
                        </label>
                        <div id="evaluationsSummaryList" class="bg-body-tertiary p-2 rounded border small"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Stage / Status <span class="text-danger">*</span></label>
                        <select name="new_status" id="modalNewStatus" class="form-select" required onchange="handleStatusSelectionChange(this.value)">
                            <?php foreach (manual_status_list() as $val => $lbl): ?>
                                <option value="<?= e($val) ?>" id="status-opt-<?= e($val) ?>"><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="selectedStatusHelp" class="form-text text-danger d-none">
                            Selected is only permitted from the Interviewed stage after at least one evaluation.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" id="remarksLabel">Remarks &amp; Notes</label>
                        <textarea name="remarks" id="modalRemarks" class="form-control" rows="3" maxlength="255" placeholder="Reason for transition, interview feedback, score, or rejection reason..."></textarea>
                        <div class="form-text" id="remarksHelp">Required for Rejected and On Hold transitions (max 255 characters).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Application History Modal -->
<div class="modal fade" id="appHistoryModal" tabindex="-1" aria-labelledby="appHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appHistoryModalLabel">Candidate Status Transition History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Timestamp</th>
                                <th>Old Status</th>
                                <th>New Status</th>
                                <th>Remarks</th>
                                <th>Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="appHistoryTableBody">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function handleStatusSelectionChange(val) {
    const remarks = document.getElementById('modalRemarks');
    const label = document.getElementById('remarksLabel');
    if (val === 'rejected' || val === 'on_hold') {
        remarks.required = true;
        label.innerHTML = 'Remarks &amp; Reason <span class="text-danger">*</span>';
    } else {
        remarks.required = false;
        label.innerHTML = 'Remarks &amp; Notes';
    }
}

function openStatusChangeModal(appId, currentStatus, vacancyTitle, canViewEvals) {
    document.getElementById('modalAppId').value = appId;
    document.getElementById('modalVacancyTitle').textContent = vacancyTitle;
    document.getElementById('modalNewStatus').value = currentStatus;

    // Selected status guard
    const selectedOpt = document.getElementById('status-opt-selected');
    const selectedHelp = document.getElementById('selectedStatusHelp');
    if (selectedOpt) {
        if (currentStatus !== 'interviewed') {
            selectedOpt.disabled = true;
            selectedOpt.textContent = 'Selected (Requires Interviewed status)';
            selectedHelp.classList.remove('d-none');
        } else {
            selectedOpt.disabled = false;
            selectedOpt.textContent = 'Selected';
            selectedHelp.classList.add('d-none');
        }
    }

    handleStatusSelectionChange(currentStatus);

    // Evaluation scores summary
    const container = document.getElementById('evaluationsSummaryContainer');
    const list = document.getElementById('evaluationsSummaryList');
    list.innerHTML = '';
    container.classList.add('d-none');

    if (canViewEvals) {
        const rawEvals = document.getElementById('app-evaluations-' + appId);
        if (rawEvals) {
            try {
                const evals = JSON.parse(rawEvals.textContent);
                if (evals && evals.length > 0) {
                    evals.forEach(ev => {
                        const recClass = ev.recommendation === 'strong_hire' ? 'text-bg-success' : (ev.recommendation === 'hire' ? 'text-bg-primary' : (ev.recommendation === 'reject' ? 'text-bg-danger' : 'text-bg-warning text-body'));
                        const div = document.createElement('div');
                        div.className = 'py-1 border-bottom d-flex justify-content-between align-items-center';
                        div.innerHTML = `
                            <div>
                                <strong>R${ev.round_no || 1}</strong> &bull; ${ev.evaluator_name || 'Evaluator'}:
                                Score: <strong class="text-primary">${ev.overall_score !== null ? ev.overall_score : '—'}</strong> / 5
                            </div>
                            <span class="badge ${recClass}">${ev.recommendation ? ev.recommendation.replace('_', ' ') : '—'}</span>
                        `;
                        list.appendChild(div);
                    });
                    container.classList.remove('d-none');
                }
            } catch(e) {
                console.error(e);
            }
        }
    }

    const modal = new bootstrap.Modal(document.getElementById('statusChangeModal'));
    modal.show();
}

function openAppHistoryModal(appId) {
    const rawData = document.getElementById('app-history-' + appId);
    if (!rawData) return;

    let items = [];
    try {
        items = JSON.parse(rawData.textContent);
    } catch(e) {
        console.error(e);
    }

    const tbody = document.getElementById('appHistoryTableBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No history recorded yet.</td></tr>';
    } else {
        items.forEach(it => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-3 text-muted small">${it.changed_at || '—'}</td>
                <td><span class="badge bg-body-secondary text-body border">${it.old_status || 'Initial'}</span></td>
                <td><span class="badge text-bg-primary">${it.new_status || '—'}</span></td>
                <td class="small">${it.remarks ? it.remarks : '<span class="text-muted">—</span>'}</td>
                <td class="small text-secondary">${it.changer_name || 'System'}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('appHistoryModal'));
    modal.show();
}
</script>

<?php if ($canDeleteDoc && !empty($documents)): ?>
    <?php foreach ($documents as $doc): ?>
        <?php $delCandDocId = (int)$doc['id']; ?>
        <div class="modal fade" id="deleteCandDocModal<?= $delCandDocId ?>" tabindex="-1" aria-labelledby="deleteCandDocModalLabel<?= $delCandDocId ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger shadow">
                    <form method="post" action="<?= e(url('modules/candidates/document_upload.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="candidate_id" value="<?= $id ?>">
                        <input type="hidden" name="document_id" value="<?= $delCandDocId ?>">
                        <div class="modal-header border-danger">
                            <h5 class="modal-title text-danger fs-6 fw-bold" id="deleteCandDocModalLabel<?= $delCandDocId ?>">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Document: <?= e($doc['original_name']) ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <p class="mb-2">Are you sure you want to permanently delete document <strong><?= e($doc['original_name']) ?></strong>?</p>
                            <div class="alert alert-warning small mb-0">
                                <i class="bi bi-shield-lock me-1"></i>
                                <strong>Warning:</strong> This will remove the database record and permanently delete the physical file from storage.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="bi bi-trash me-1"></i> Delete Document
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
