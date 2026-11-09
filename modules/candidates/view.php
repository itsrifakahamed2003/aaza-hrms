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
        "SELECT ie.*, i.application_id, i.round_no, u.full_name AS evaluator_name
           FROM interview_evaluations ie
           JOIN interviews i ON i.id = ie.interview_id
           LEFT JOIN users u ON u.id = ie.evaluator_id
          WHERE i.application_id IN ($eph)
          ORDER BY i.round_no ASC, ie.evaluated_at DESC"
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

// Fetch email history for this candidate
$emailHistory = [];
$historySql = "SELECT al.*, u.full_name AS sender_name
                 FROM activity_logs al
                 LEFT JOIN users u ON u.id = al.user_id
                WHERE al.action IN ('candidate.email_sent', 'candidate.email_failed')
                  AND (
                    (al.entity_type = 'candidate' AND al.entity_id = :cand_id)
                    OR (al.entity_type = 'candidate_application' " . (!empty($appIds) ? "AND al.entity_id IN (" . implode(',', array_map('intval', $appIds)) . ")" : "AND 1=0") . ")
                  )
                ORDER BY al.created_at DESC
                LIMIT 50";
$hQuery = $pdo->prepare($historySql);
$hQuery->execute([':cand_id' => $id]);
$emailHistory = $hQuery->fetchAll();

$sources = candidate_source_list();
$docTypes = candidate_doc_type_list();
$canManageCandidates = user_can('candidate.manage');
$mailIsConfigured = mail_is_configured();
$candidateHasEmail = !empty(trim((string)($candidate['email'] ?? '')));

$emailTemplateLabels = [
    'application_received' => 'Application Received',
    'shortlisted'          => 'Shortlisted',
    'interview_invite'     => 'Interview Invitation',
    'selected'             => 'Selected / Next Steps',
    'offer_sent'           => 'Offer Letter Sent',
    'rejected'             => 'Not Selected (Polite Rejection)',
];

$canDeleteDoc = is_group_admin() || user_can('document.delete');
$chkCandDel = check_candidate_deletable($id, $pdo);
$canDeleteCand = $chkCandDel['can_delete'] && is_group_admin() && user_can('candidate.delete');

$pageTitle = 'Candidate: ' . $candidate['first_name'] . ' ' . $candidate['last_name'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/candidates/index.php')) ?>">Candidates</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($candidate['candidate_code']) ?></li>
        </ol>
    </nav>

    <!-- Detail Header -->
    <div class="detail-header">
        <div class="detail-header-main">
            <div class="detail-header-title-row">
                <h1 class="detail-header-title"><?= e($candidate['first_name'] . ' ' . $candidate['last_name']) ?></h1>
                <span class="badge text-bg-primary fs-6"><?= e($candidate['candidate_code']) ?></span>
                <?php if ($candidate['consent_given']): ?>
                    <span class="badge text-bg-success-subtle text-success border border-success-subtle">Consent Verified</span>
                <?php endif; ?>
            </div>
            <p class="detail-header-subtitle">
                Registered Candidate Record &bull; <?= count($applications) ?> application<?= count($applications) === 1 ? '' : 's' ?> on file
            </p>
        </div>

        <div class="detail-header-actions">
            <!-- ONE Primary Button -->
            <?php if ($canManageCandidates): ?>
                <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . $id)) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Apply to Vacancy
                </a>
            <?php endif; ?>

            <!-- ONE "More" 3-dot menu with secondary links -->
            <?php
            $headerMore = [];
            if ($canManageCandidates) {
                $headerMore[] = [
                    'type' => 'link',
                    'icon' => 'bi-pencil',
                    'label' => 'Edit Profile',
                    'url' => 'modules/candidates/form.php?id=' . $id,
                ];
            }
            $headerMore[] = [
                'type' => 'link',
                'icon' => 'bi-arrow-left',
                'label' => 'Back to Candidates',
                'url' => 'modules/candidates/index.php',
            ];
            if ($canDeleteCand) {
                $headerMore[] = [
                    'type' => 'button',
                    'icon' => 'bi-trash',
                    'label' => 'Delete Candidate',
                    'onclick' => 'confirmDeleteCandidate(' . $id . ', \'' . e(addslashes($candidate['first_name'] . ' ' . $candidate['last_name'])) . '\')',
                    'danger' => true,
                ];
            }
            echo action_menu($headerMore, [
                'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
                'aria_label' => 'More candidate actions',
            ]);
            ?>
        </div>
    </div>

    <!-- Body: 2 columns (col-lg-4 side, col-lg-8 main), align-items-start -->
    <div class="row g-3 align-items-start">
        <!-- Left (side) column: Profile & Documents -->
        <div class="col-lg-4">
            <!-- Profile Card (3 groups with .kv-list) -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Profile</span>
                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($candidate['candidate_code']) ?></span>
                </div>
                <div class="card-body">
                    <div class="kv-list kv-list-single">
                        <!-- Group 1: Contact Information -->
                        <div class="kv-section-title">Contact Information</div>
                        <div class="kv-item">
                            <span class="kv-label">Email Address</span>
                            <span class="kv-value"><?= e($candidate['email'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Phone Number</span>
                            <span class="kv-value"><?= e($candidate['phone'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Residential Address</span>
                            <span class="kv-value small"><?= !empty($candidate['address']) ? nl2br(e($candidate['address'])) : '—' ?></span>
                        </div>

                        <!-- Group 2: Identity & Personal -->
                        <div class="kv-section-title">Identity &amp; Personal</div>
                        <div class="kv-item">
                            <span class="kv-label">NIC / Passport</span>
                            <span class="kv-value font-monospace"><?= e($candidate['nic_passport'] ?? '—') ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Date of Birth</span>
                            <span class="kv-value"><?= !empty($candidate['dob']) ? e(format_date($candidate['dob'])) : '—' ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Gender</span>
                            <span class="kv-value"><?= !empty($candidate['gender']) ? e(ucfirst($candidate['gender'])) : '—' ?></span>
                        </div>

                        <!-- Group 3: Professional Background -->
                        <div class="kv-section-title">Professional</div>
                        <div class="kv-item">
                            <span class="kv-label">Experience</span>
                            <span class="kv-value"><?= $candidate['experience_years'] !== null ? e((string)$candidate['experience_years']) . ' years' : '—' ?></span>
                        </div>
                        <div class="kv-item">
                            <span class="kv-label">Recruitment Source</span>
                            <span class="kv-value">
                                <span class="badge bg-body-secondary text-body border"><?= e($sources[$candidate['source']] ?? ucfirst((string)$candidate['source'])) ?></span>
                            </span>
                        </div>
                        <?php if (!empty($candidate['skills'])): ?>
                            <div class="kv-item">
                                <span class="kv-label">Skills &amp; Expertise</span>
                                <span class="kv-value small"><?= e($candidate['skills']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($candidate['qualifications'])): ?>
                            <div class="kv-item">
                                <span class="kv-label">Qualifications</span>
                                <span class="kv-value small"><?= nl2br(e($candidate['qualifications'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Registered by/on as a small muted footer -->
                    <div class="pt-3 mt-3 border-top small text-muted">
                        <div>Registered by: <strong class="text-body"><?= e($candidate['creator_name'] ?? 'System') ?></strong></div>
                        <div>Registered on: <?= e(format_datetime($candidate['created_at'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Documents & CV Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span>Documents &amp; CV</span>
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
                                        <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$doc['id'])) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.75rem;" title="Download">
                                            Download
                                        </a>
                                        <?php if ($canDeleteDoc): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#deleteCandDocModal<?= (int)$doc['id'] ?>" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canManageCandidates): ?>
                        <div class="p-2 bg-body-tertiary rounded border">
                            <div class="small fw-semibold mb-2">Upload Document</div>
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
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Upload File</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right (main) column: Job Applications & Email History -->
        <div class="col-lg-8">
            <!-- Job Applications Section -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="fs-6 fw-bold mb-0 text-body">
                    <i class="bi bi-briefcase me-2 text-primary"></i>Job Applications
                </h5>
                <span class="badge bg-body-secondary text-body border"><?= count($applications) ?> record<?= count($applications) === 1 ? '' : 's' ?></span>
            </div>

            <?php if (empty($applications)): ?>
                <div class="detail-card text-center py-4 text-muted mb-3">
                    <i class="bi bi-briefcase fs-2 d-block mb-2 text-secondary"></i>
                    <p class="mb-2 small">This candidate has not applied to any vacancy in your accessible companies yet.</p>
                    <?php if ($canManageCandidates): ?>
                        <a href="<?= e(url('modules/candidates/apply.php?candidate_id=' . $id)) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> Apply to Vacancy
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $app): ?>
                    <?php
                    $canManageThisApp = user_can('candidate.manage', (int)$app['company_id']);
                    $canInterviewThisApp = user_can('interview.manage', (int)$app['company_id'])
                        && !in_array($app['status'], ['hired', 'rejected', 'withdrawn', 'offer_declined'], true);
                    $appHistory = $historyByApp[(int)$app['id']] ?? [];
                    $appInterviews = $interviewsByApp[(int)$app['id']] ?? [];
                    $appOffer = $offersByApp[(int)$app['id']] ?? null;
                    $canOfferManage = user_can('offer.manage', (int)$app['company_id']);
                    $canOfferView   = user_can('offer.view', (int)$app['company_id']);
                    $evalParam      = user_can('interview.view_evaluations', (int)$app['company_id']) ? 'true' : 'false';
                    $titleEsc       = addslashes($app['vacancy_title']);

                    // Slim Stage Progress mapping
                    $stKey = (string)$app['status'];
                    $stepAppliedDone = in_array($stKey, ['shortlisted', 'interviewed', 'selected', 'hired'], true);
                    $stepShortlistedDone = in_array($stKey, ['interviewed', 'selected', 'hired'], true);
                    $stepInterviewDone = in_array($stKey, ['selected', 'hired'], true);
                    $stepOfferDone = ($stKey === 'hired') || (!empty($appOffer) && in_array($appOffer['status'], ['sent', 'accepted'], true));
                    $stepHiredDone = ($stKey === 'hired');

                    // Default email template recommendation by stage
                    $defaultTpl = 'application_received';
                    if ($stKey === 'shortlisted') $defaultTpl = 'shortlisted';
                    elseif ($stKey === 'interviewed') $defaultTpl = 'interview_invite';
                    elseif ($stKey === 'selected') $defaultTpl = 'selected';
                    elseif ($stKey === 'rejected') $defaultTpl = 'rejected';
                    elseif (!empty($appOffer)) $defaultTpl = 'offer_sent';
                    ?>
                    <div class="detail-card mb-3 application-item">
                        <div class="card-body">
                            <!-- Row 1: Company chip + Vacancy title + Status badge (right) -->
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-1">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge bg-body-secondary text-body border font-monospace"><?= e($app['company_code']) ?></span>
                                    <a href="<?= e(url('modules/vacancies/view.php?id=' . (int)$app['vacancy_id'])) ?>" class="fw-bold fs-6 text-decoration-none text-body">
                                        <?= e($app['vacancy_title']) ?>
                                    </a>
                                </div>
                                <div>
                                    <?= application_status_badge($app['status']) ?>
                                </div>
                            </div>

                            <!-- Row 2: Muted meta: vacancy code | applied date -->
                            <div class="small text-muted mb-2">
                                <span class="font-monospace"><?= e($app['vacancy_code']) ?></span>
                                &bull;
                                <span>Applied: <?= e(format_date($app['application_date'])) ?></span>
                            </div>

                            <!-- Row 3: Slim stage progress (Applied > Shortlisted > Interview > Offer > Hired) -->
                            <div class="stage-tracker-slim py-1 px-2 my-2">
                                <span class="stage-step <?= $stKey === 'applied' ? 'active' : ($stepAppliedDone ? 'completed' : '') ?>">
                                    <i class="bi <?= $stepAppliedDone ? 'bi-check-circle-fill text-success' : ($stKey === 'applied' ? 'bi-dot' : 'bi-circle') ?>"></i>
                                    <span>Applied</span>
                                </span>
                                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                                <span class="stage-step <?= $stKey === 'shortlisted' ? 'active' : ($stepShortlistedDone ? 'completed' : '') ?>">
                                    <i class="bi <?= $stepShortlistedDone ? 'bi-check-circle-fill text-success' : ($stKey === 'shortlisted' ? 'bi-dot' : 'bi-circle') ?>"></i>
                                    <span>Shortlisted</span>
                                </span>
                                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                                <span class="stage-step <?= $stKey === 'interviewed' ? 'active' : ($stepInterviewDone ? 'completed' : '') ?>">
                                    <i class="bi <?= $stepInterviewDone ? 'bi-check-circle-fill text-success' : ($stKey === 'interviewed' ? 'bi-dot' : 'bi-circle') ?>"></i>
                                    <span>Interview</span>
                                </span>
                                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                                <?php if ($stKey === 'rejected'): ?>
                                    <span class="stage-step active text-danger border-danger-subtle bg-danger-subtle">
                                        <i class="bi bi-x-circle-fill text-danger"></i>
                                        <span>Rejected</span>
                                    </span>
                                <?php elseif ($stKey === 'withdrawn'): ?>
                                    <span class="stage-step active text-warning-emphasis border-warning-subtle bg-warning-subtle">
                                        <i class="bi bi-dash-circle-fill text-warning"></i>
                                        <span>Withdrawn</span>
                                    </span>
                                <?php else: ?>
                                    <span class="stage-step <?= ($stKey === 'selected' || (!empty($appOffer) && $stKey !== 'hired')) ? 'active' : ($stepOfferDone ? 'completed' : '') ?>">
                                        <i class="bi <?= $stepOfferDone ? 'bi-check-circle-fill text-success' : (($stKey === 'selected' || !empty($appOffer)) ? 'bi-dot' : 'bi-circle') ?>"></i>
                                        <span>Offer</span>
                                    </span>
                                <?php endif; ?>
                                <span class="stage-step-arrow"><i class="bi bi-chevron-right"></i></span>

                                <span class="stage-step <?= $stepHiredDone ? 'active completed' : '' ?>">
                                    <i class="bi <?= $stepHiredDone ? 'bi-check2-all text-success' : 'bi-circle' ?>"></i>
                                    <span>Hired</span>
                                </span>
                            </div>

                            <!-- Row 4: Notes (one line) and interviews as a compact list -->
                            <?php if (!empty($app['notes'])): ?>
                                <div class="small text-muted py-1 px-2 rounded bg-body-tertiary mb-2 border-start border-3 border-secondary text-truncate">
                                    <i class="bi bi-chat-quote text-secondary me-1"></i><?= e($app['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($appInterviews)): ?>
                                <div class="mb-2 p-2 rounded bg-body-tertiary border">
                                    <div class="small fw-semibold text-secondary mb-1">
                                        <i class="bi bi-calendar-check text-primary me-1"></i>Interviews (<?= count($appInterviews) ?>):
                                    </div>
                                    <div class="d-flex flex-column gap-1">
                                        <?php foreach ($appInterviews as $ai): ?>
                                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom flex-wrap gap-1" style="font-size:0.8rem;">
                                                <div class="text-truncate me-2" style="max-width: 250px;">
                                                    <strong>Round <?= (int)$ai['round_no'] ?></strong> &bull; <?= e(format_datetime($ai['scheduled_at'], 'd M Y, h:i A')) ?>
                                                    <?php if (!empty($ai['panel_names'])): ?>
                                                        <span class="text-muted ms-1">(<?= e($ai['panel_names']) ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                                    <?= interview_status_badge($ai['status']) ?>
                                                    <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$ai['id'])) ?>" class="btn btn-sm btn-outline-primary py-0 px-2 text-nowrap" style="font-size:0.75rem; line-height: 1.5;">View</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($appOffer)): ?>
                                <div class="mb-2 p-2 rounded bg-body-tertiary border d-flex justify-content-between align-items-center flex-wrap gap-2 small">
                                    <div>
                                        <span class="text-secondary fw-semibold me-1"><i class="bi bi-file-earmark-check text-primary me-1"></i>Offer:</span>
                                        <?= offer_status_badge($appOffer['status']) ?>
                                    </div>
                                    <?php if (!empty($appOffer['converted_employee_id'])): ?>
                                        <span class="badge text-bg-success-subtle text-success border border-success-subtle font-monospace">
                                            <i class="bi bi-person-check me-1"></i>Hired: <?= e($appOffer['converted_emp_code']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Row 5 (actions): at most TWO visible buttons + ONE More 3-dot menu -->
                            <div class="d-flex flex-wrap gap-2 pt-2 border-top justify-content-end align-items-center">
                                <!-- Button 1: ONE primary by stage -->
                                <?php
                                $primaryRendered = false;
                                if ($stKey === 'shortlisted' && $canInterviewThisApp):
                                    $primaryRendered = true;
                                ?>
                                    <a href="<?= e(url('modules/interviews/schedule.php?application_id=' . (int)$app['id'])) ?>" class="btn btn-sm btn-success text-nowrap">
                                        <i class="bi bi-calendar-plus me-1"></i> Schedule Interview
                                    </a>
                                <?php elseif ($stKey === 'interviewed' && $canManageThisApp):
                                    $primaryRendered = true;
                                ?>
                                    <button type="button" class="btn btn-sm btn-primary text-nowrap" onclick="openStatusChangeModal(<?= (int)$app['id'] ?>, '<?= e($app['status']) ?>', '<?= $titleEsc ?>', <?= $evalParam ?>)">
                                        <i class="bi bi-arrow-left-right me-1"></i> Update Stage
                                    </button>
                                <?php elseif (!empty($appOffer) && !empty($appOffer['converted_employee_id'])):
                                    $primaryRendered = true;
                                ?>
                                    <a href="<?= e(url('modules/employees/view.php?id=' . (int)$appOffer['converted_employee_id'])) ?>" class="btn btn-sm btn-primary text-nowrap">
                                        <i class="bi bi-person-badge me-1"></i> Employee Profile (<?= e($appOffer['converted_emp_code'] ?? 'EMP') ?>)
                                    </a>
                                <?php elseif (!empty($appOffer) && $appOffer['status'] === 'accepted' && $canOfferManage):
                                    $primaryRendered = true;
                                ?>
                                    <a href="<?= e(url('modules/offers/view.php?id=' . (int)$appOffer['id'])) ?>#convert" class="btn btn-sm btn-success text-nowrap">
                                        <i class="bi bi-person-check me-1"></i> Convert to Employee
                                    </a>
                                <?php elseif (empty($appOffer) && $stKey === 'selected' && $canOfferManage):
                                    $primaryRendered = true;
                                ?>
                                    <a href="<?= e(url('modules/offers/form.php?application_id=' . (int)$app['id'])) ?>" class="btn btn-sm btn-success text-nowrap">
                                        <i class="bi bi-file-earmark-plus me-1"></i> Draft Offer
                                    </a>
                                <?php elseif ($canManageThisApp):
                                    $primaryRendered = true;
                                ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" onclick="openStatusChangeModal(<?= (int)$app['id'] ?>, '<?= e($app['status']) ?>', '<?= $titleEsc ?>', <?= $evalParam ?>)">
                                        <i class="bi bi-arrow-left-right me-1"></i> Update Stage
                                    </button>
                                <?php endif; ?>

                                <!-- Button 2: "Email candidate" Split Button -->
                                <?php
                                $canEmailThisApp = $canManageThisApp
                                    || user_can('interview.manage', (int)$app['company_id'])
                                    || user_can('interview.schedule', (int)$app['company_id']);
                                ?>
                                <?php if ($canEmailThisApp): ?>
                                    <?php if (!$candidateHasEmail): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" disabled title="Candidate has no email address on file">
                                            <i class="bi bi-envelope me-1"></i>Email candidate
                                        </button>
                                    <?php else: ?>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary text-nowrap" onclick="openEmailCandidateModal(<?= (int)$app['id'] ?>, '<?= e($defaultTpl) ?>')">
                                                <i class="bi bi-envelope me-1"></i>Email candidate
                                            </button>
                                            <button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" id="emailCandidateDropdown<?= (int)$app['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Select Email Template">
                                                <span class="visually-hidden">Toggle Dropdown</span>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="emailCandidateDropdown<?= (int)$app['id'] ?>">
                                                <li class="dropdown-header small text-uppercase">Select Template</li>
                                                <?php foreach ($emailTemplateLabels as $tplKey => $tplLabel): ?>
                                                    <?php if (!$canManageThisApp && $tplKey !== 'interview_invite') continue; ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item d-flex align-items-center gap-2" onclick="openEmailCandidateModal(<?= (int)$app['id'] ?>, '<?= e($tplKey) ?>')">
                                                            <i class="bi bi-file-earmark-text text-primary"></i>
                                                            <span><?= e($tplLabel) ?></span>
                                                        </button>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- ONE "More" 3-dot menu for remaining secondary actions -->
                                <?php
                                $appMore = [];
                                if ($canManageThisApp && ($stKey !== 'interviewed' && $stKey !== 'applied')) {
                                    $appMore[] = [
                                        'type' => 'button',
                                        'icon' => 'bi-arrow-left-right',
                                        'label' => 'Update Stage / Status',
                                        'onclick' => 'openStatusChangeModal(' . (int)$app['id'] . ', \'' . e($app['status']) . '\', \'' . $titleEsc . '\', ' . $evalParam . ')',
                                    ];
                                }
                                if ($canInterviewThisApp && $stKey !== 'shortlisted') {
                                    $appMore[] = [
                                        'type' => 'link',
                                        'icon' => 'bi-calendar-plus',
                                        'label' => 'Schedule Interview',
                                        'url' => 'modules/interviews/schedule.php?application_id=' . (int)$app['id'],
                                    ];
                                }
                                if (!empty($appOffer) && empty($appOffer['converted_employee_id']) && $canOfferView) {
                                    $appMore[] = [
                                        'type' => 'link',
                                        'icon' => 'bi-file-earmark-check',
                                        'label' => 'View Job Offer',
                                        'url' => 'modules/offers/view.php?id=' . (int)$appOffer['id'],
                                    ];
                                }
                                $appMore[] = [
                                    'type' => 'button',
                                    'icon' => 'bi-clock-history',
                                    'label' => 'Application History',
                                    'onclick' => 'openAppHistoryModal(' . (int)$app['id'] . ')',
                                ];

                                echo action_menu($appMore, [
                                    'btn_class' => 'btn btn-outline-secondary btn-sm action-menu-btn',
                                    'aria_label' => 'More application actions',
                                ]);
                                ?>
                            </div>

                            <!-- Text link to Pipeline Board in card footer -->
                            <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center">
                                <a href="<?= e(url('modules/vacancies/pipeline.php?id=' . (int)$app['vacancy_id'])) ?>" class="text-decoration-none small text-secondary">
                                    <i class="bi bi-kanban me-1 text-info"></i> Open Pipeline Board &rarr;
                                </a>
                                <span class="small text-muted font-monospace">App #<?= (int)$app['id'] ?></span>
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
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Email History Section (Collapsible card, collapsed when empty, no min-height) -->
            <div class="detail-card mb-3">
                <div class="card-header cursor-pointer d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#emailHistoryCollapse" aria-expanded="<?= !empty($emailHistory) ? 'true' : 'false' ?>" style="cursor: pointer;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-envelope-paper text-primary"></i>
                        <span>Email History</span>
                        <span class="badge bg-body-secondary text-body border rounded-pill small"><?= count($emailHistory) ?></span>
                    </div>
                    <i class="bi bi-chevron-down text-muted small"></i>
                </div>
                <div class="collapse <?= !empty($emailHistory) ? 'show' : '' ?>" id="emailHistoryCollapse">
                    <div class="card-body p-0">
                        <?php if (empty($emailHistory)): ?>
                            <div class="p-3 text-muted small">No emails sent yet.</div>
                        <?php else: ?>
                            <div class="table-responsive mb-0">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">When</th>
                                            <th>Template</th>
                                            <th>Sent by</th>
                                            <th class="pe-3 text-end">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($emailHistory as $emLog): ?>
                                            <?php
                                            $emDetails = !empty($emLog['details']) ? (json_decode((string)$emLog['details'], true) ?: []) : [];
                                            $tplKeyRaw = (string)($emDetails['template'] ?? 'notification');
                                            $tplLabel  = $emailTemplateLabels[$tplKeyRaw] ?? ucwords(str_replace('_', ' ', $tplKeyRaw));
                                            $isSentOk  = ($emLog['action'] === 'candidate.email_sent') || !empty($emDetails['ok']);
                                            $whoName   = !empty($emLog['sender_name']) ? (string)$emLog['sender_name'] : 'System';
                                            ?>
                                            <tr>
                                                <td class="ps-3 text-muted"><?= e(format_datetime($emLog['created_at'])) ?></td>
                                                <td class="fw-semibold text-body"><?= e($tplLabel) ?></td>
                                                <td class="text-secondary"><?= e($whoName) ?></td>
                                                <td class="pe-3 text-end">
                                                    <?php if ($isSentOk): ?>
                                                        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-danger"><i class="bi bi-exclamation-circle me-1"></i>Failed</span>
                                                    <?php endif; ?>
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

                    <!-- Optional Email the candidate checkbox (shown for shortlisted / selected / rejected) -->
                    <div class="form-check p-2 ps-4 rounded bg-body-tertiary border" id="statusEmailCheckWrapper">
                        <input class="form-check-input"
                               type="checkbox"
                               name="send_email"
                               id="modalSendEmailCheckbox"
                               value="1"
                               <?= ($mailIsConfigured && $candidateHasEmail) ? 'checked' : '' ?>
                               <?= !$candidateHasEmail ? 'disabled' : '' ?>>
                        <label class="form-check-label small fw-semibold" for="modalSendEmailCheckbox">
                            <i class="bi bi-envelope me-1 text-primary"></i>Email the candidate
                        </label>
                        <div class="form-text small mt-0">
                            <?php if (!$candidateHasEmail): ?>
                                Candidate has no email address on file.
                            <?php elseif ($mailIsConfigured): ?>
                                Sends automatic stage notification email when status is Shortlisted, Selected, or Rejected.
                            <?php else: ?>
                                Outgoing SMTP is not configured; uncheck or configure SMTP in config.php to send automatically.
                            <?php endif; ?>
                        </div>
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

<!-- Email Candidate Preview & Send Modal -->
<div class="modal fade" id="emailCandidateModal" tabindex="-1" aria-labelledby="emailCandidateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/candidates/email.php')) ?>" id="emailCandidateForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="application_id" id="emailModalAppId" value="0">
                <input type="hidden" name="template" id="emailModalTemplate" value="">
                <input type="hidden" name="redirect_to" value="<?= e(url('modules/candidates/view.php?id=' . $id)) ?>">

                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="emailCandidateModalLabel">
                        <i class="bi bi-envelope-paper text-primary"></i>
                        <span>Email Candidate</span>
                        <span class="badge bg-body-secondary text-body border small fw-normal" id="emailModalTemplateBadge"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="emailModalErrorAlert" class="alert alert-danger small d-none mb-3"></div>
                    <div id="emailModalLoading" class="text-center py-4 d-none">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted small">Loading template preview...</span>
                    </div>
                    <div id="emailModalFields">
                        <div class="mb-3">
                            <label for="emailModalTo" class="form-label small fw-semibold text-muted mb-1">To (Candidate Email)</label>
                            <input type="email" id="emailModalTo" class="form-control form-control-sm bg-body-tertiary" readonly value="<?= e((string)($candidate['email'] ?? '')) ?>">
                        </div>
                        <div class="mb-3">
                            <label for="emailModalSubject" class="form-label small fw-semibold mb-1">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="emailModalSubject" class="form-control" maxlength="200" required oninput="updateEmailModalMailtoLink()">
                        </div>
                        <div class="mb-2">
                            <label for="emailModalBody" class="form-label small fw-semibold mb-1">Message Body <span class="text-danger">*</span></label>
                            <textarea name="body" id="emailModalBody" class="form-control font-monospace small" rows="8" required oninput="updateEmailModalMailtoLink()"></textarea>
                            <div class="form-text small">
                                You can customize the subject and body before sending. All HTML is safely escaped and wrapped in the company stationery layout.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <div class="small text-muted">
                        <?php if ($mailIsConfigured): ?>
                            <i class="bi bi-check-circle text-success me-1"></i>Direct email delivery enabled
                        <?php else: ?>
                            <i class="bi bi-info-circle text-secondary me-1"></i>Email not configured &mdash; opens in your default mail client
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if ($mailIsConfigured): ?>
                            <button type="submit" id="emailModalSendBtn" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Send
                            </button>
                        <?php else: ?>
                            <a href="mailto:" id="emailModalMailtoBtn" class="btn btn-primary">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open in mail app
                            </a>
                        <?php endif; ?>
                    </div>
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
const MAIL_IS_CONFIGURED = <?= $mailIsConfigured ? 'true' : 'false' ?>;
const CANDIDATE_HAS_EMAIL = <?= $candidateHasEmail ? 'true' : 'false' ?>;
const EMAIL_PREVIEW_URL = <?= json_encode(url('modules/candidates/email.php')) ?>;
const CSRF_TOKEN_VAL = <?= json_encode(csrf_token()) ?>;

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

    const emailWrap = document.getElementById('statusEmailCheckWrapper');
    const emailChk  = document.getElementById('modalSendEmailCheckbox');
    if (emailWrap && emailChk) {
        if (val === 'shortlisted' || val === 'selected' || val === 'rejected') {
            emailWrap.classList.remove('d-none');
            emailChk.disabled = !CANDIDATE_HAS_EMAIL;
            emailChk.checked = (MAIL_IS_CONFIGURED && CANDIDATE_HAS_EMAIL);
        } else {
            emailWrap.classList.add('d-none');
            emailChk.checked = false;
        }
    }
}

function buildSafeMailtoHref(toEmail, subject, body) {
    const cleanTo = (toEmail || '').trim();
    const cleanSubj = (subject || '').trim().substring(0, 200);
    let normBody = (body || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    const prefix = 'mailto:' + encodeURIComponent(cleanTo) + '?subject=' + encodeURIComponent(cleanSubj) + '&body=';
    let full = prefix + encodeURIComponent(normBody);
    while (full.length > 1800 && normBody.length > 0) {
        normBody = normBody.substring(0, Math.max(0, normBody.length - 25));
        full = prefix + encodeURIComponent(normBody.trimEnd() + '...');
    }
    return full.substring(0, 1800);
}

function updateEmailModalMailtoLink() {
    const mailtoBtn = document.getElementById('emailModalMailtoBtn');
    if (!mailtoBtn) return;
    const toVal   = document.getElementById('emailModalTo').value;
    const subjVal = document.getElementById('emailModalSubject').value;
    const bodyVal = document.getElementById('emailModalBody').value;
    mailtoBtn.setAttribute('href', buildSafeMailtoHref(toVal, subjVal, bodyVal));
}

function openEmailCandidateModal(appId, templateKey) {
    document.getElementById('emailModalAppId').value = appId;
    document.getElementById('emailModalTemplate').value = templateKey;

    const badge = document.getElementById('emailModalTemplateBadge');
    if (badge) {
        badge.textContent = templateKey.replace(/_/g, ' ');
    }

    const errBox = document.getElementById('emailModalErrorAlert');
    const loading = document.getElementById('emailModalLoading');
    const fields = document.getElementById('emailModalFields');
    errBox.classList.add('d-none');
    errBox.textContent = '';
    loading.classList.remove('d-none');
    fields.classList.add('opacity-50');

    const modalEl = document.getElementById('emailCandidateModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const formData = new URLSearchParams();
    formData.set('csrf_token', CSRF_TOKEN_VAL);
    formData.set('action', 'preview');
    formData.set('application_id', String(appId));
    formData.set('template', templateKey);

    fetch(EMAIL_PREVIEW_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(r => r.json())
    .then(data => {
        loading.classList.add('d-none');
        fields.classList.remove('opacity-50');
        if (!data || !data.ok) {
            errBox.textContent = (data && data.error) ? data.error : 'Failed to load email preview.';
            errBox.classList.remove('d-none');
            return;
        }
        document.getElementById('emailModalTo').value = data.to || '';
        document.getElementById('emailModalSubject').value = data.subject || '';
        document.getElementById('emailModalBody').value = data.body || data.text || '';
        const mailtoBtn = document.getElementById('emailModalMailtoBtn');
        if (mailtoBtn) {
            if (data.mailto && data.mailto.length <= 1800) {
                mailtoBtn.setAttribute('href', data.mailto);
            } else {
                updateEmailModalMailtoLink();
            }
        }
    })
    .catch(() => {
        loading.classList.add('d-none');
        fields.classList.remove('opacity-50');
        errBox.textContent = 'Network error while loading email preview.';
        errBox.classList.remove('d-none');
    });
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
                        const recClass = ev.recommendation === 'strong_hire' ? 'text-bg-success' : (ev.recommendation === 'hire' ? 'text-bg-primary' : (ev.recommendation === 'reject' ? 'text-bg-danger' : 'text-bg-warning'));
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

<?php if ($canDeleteCand): ?>
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
<?php endif; ?>
</script>

<?php if ($canDeleteCand): ?>
<form method="post" action="<?= e(url('modules/candidates/delete.php')) ?>" id="deleteCandidateForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
</form>
<?php endif; ?>

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
