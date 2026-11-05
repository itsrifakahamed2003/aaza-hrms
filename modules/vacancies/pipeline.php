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
            d.name AS department_name, des.title AS designation_title
       FROM job_vacancies v
       JOIN companies c ON c.id = v.company_id
       LEFT JOIN departments d ON d.id = v.department_id
       LEFT JOIN designations des ON des.id = v.designation_id
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

$canManage = user_can('candidate.manage', $companyId);

// Handle status change submission directly if POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (!$canManage) {
        abort(403, 'You do not have permission to change candidate status.');
    }

    $appId = (int)($_POST['application_id'] ?? 0);
    $newStatus = trim((string)($_POST['new_status'] ?? ''));
    $remarks = mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 255);

    // Check application belongs to this vacancy
    $appCheck = $pdo->prepare('SELECT id, status FROM candidate_applications WHERE id = ? AND vacancy_id = ? LIMIT 1');
    $appCheck->execute([$appId, $id]);
    $currentApp = $appCheck->fetch();

    if (!$currentApp) {
        flash_set('danger', 'Candidate application not found for this vacancy.');
        redirect('modules/vacancies/pipeline.php?id=' . $id);
    }

    $manualStatuses = manual_status_list();
    if (!isset($manualStatuses[$newStatus])) {
        flash_set('danger', 'Invalid destination status selected.');
        redirect('modules/vacancies/pipeline.php?id=' . $id);
    }

    try {
        $pdo->beginTransaction();

        // Re-read and lock application row FOR UPDATE
        $stLock = $pdo->prepare('SELECT id, candidate_id, company_id, vacancy_id, status FROM candidate_applications WHERE id = ? AND vacancy_id = ? FOR UPDATE');
        $stLock->execute([$appId, $id]);
        $lockedApp = $stLock->fetch();

        if (!$lockedApp) {
            throw new RuntimeException('Candidate application not found for this vacancy.');
        }

        $curStatus = (string)$lockedApp['status'];
        $hasActiveOffer = has_active_offer($pdo, $appId);

        // Allowed current statuses
        $allowedCurrent = ['applied', 'under_review', 'shortlisted', 'interviewed', 'selected', 'on_hold'];

        if (!in_array($curStatus, $allowedCurrent, true)) {
            // Exception: allow 'rejected' from 'interview_scheduled' (an HR decision) but ONLY IF no active (draft/sent) offer
            if ($curStatus === 'interview_scheduled' && $newStatus === 'rejected') {
                if ($hasActiveOffer) {
                    throw new RuntimeException("Cannot reject this application while an active job offer exists. Please withdraw the offer first.");
                }
            } else {
                $curLabel = application_status_list()[$curStatus] ?? ucfirst($curStatus);
                $guidance = match ($curStatus) {
                    'interview_scheduled' => 'Please cancel the interview first.',
                    'offered'             => 'Please withdraw the offer first.',
                    'offer_accepted'      => 'The offer has been accepted. Please withdraw the offer or complete conversion.',
                    'offer_declined'      => 'The offer was declined.',
                    'hired'               => 'The candidate is already hired.',
                    'rejected'            => 'The application is already rejected.',
                    'withdrawn'           => 'The application has already been withdrawn.',
                    default               => 'Manual status change is not allowed from this status.',
                };
                throw new RuntimeException("Cannot change status manually because the application is currently '{$curLabel}'. {$guidance}");
            }
        }

        // Also block 'on_hold' and 'rejected' while an offer in draft/sent exists for that application
        if (in_array($newStatus, ['on_hold', 'rejected'], true) && $hasActiveOffer) {
            $statusLabel = $manualStatuses[$newStatus] ?? $newStatus;
            throw new RuntimeException("Cannot mark application as {$statusLabel} while an active job offer exists. Please withdraw the offer first.");
        }

        // Selected rule: evaluated on the locked row, must be 'interviewed' with at least 1 evaluation
        if ($newStatus === 'selected') {
            if ($curStatus !== 'interviewed') {
                throw new RuntimeException("An application can only be marked as Selected from the 'Interviewed' stage (current status: '{$curStatus}').");
            }

            $evalCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM interview_evaluations ie
                   JOIN interviews i ON ie.interview_id = i.id
                  WHERE i.application_id = ?'
            );
            $evalCheck->execute([$appId]);
            if ((int)$evalCheck->fetchColumn() < 1) {
                throw new RuntimeException('At least one interview evaluation is required before selecting an application.');
            }
        }

        // Require a remark for rejected and on_hold
        if (in_array($newStatus, ['rejected', 'on_hold'], true)) {
            if ($remarks === '') {
                $statusLabel = $manualStatuses[$newStatus] ?? $newStatus;
                throw new InvalidArgumentException("A remark is required when moving an application to {$statusLabel}.");
            }
        }

        update_candidate_application_status($pdo, $appId, $newStatus, $remarks !== '' ? $remarks : null, current_user_id());
        $pdo->commit();

        if (in_array($newStatus, ['shortlisted', 'selected', 'rejected'], true) && !empty($vacancy['created_by'])) {
            $candSt = $pdo->prepare('SELECT first_name, last_name FROM candidates WHERE id = ?');
            $candSt->execute([(int)$lockedApp['candidate_id']]);
            $candRow = $candSt->fetch();
            $candName = $candRow ? trim($candRow['first_name'] . ' ' . $candRow['last_name']) : 'A candidate';
            notify_user(
                $pdo,
                (int)$vacancy['created_by'],
                'candidate_' . $newStatus,
                'Candidate ' . ucfirst($newStatus),
                "Candidate {$candName} was marked as " . ($manualStatuses[$newStatus] ?? $newStatus) . " for \"{$vacancy['title']}\".",
                'modules/candidates/view.php?id=' . (int)$lockedApp['candidate_id'],
                $companyId,
                'app:status:' . $appId . ':' . $newStatus
            );
        }

        flash_set('success', 'Application status successfully updated to ' . ($manualStatuses[$newStatus] ?? $newStatus) . '.');
    } catch (RuntimeException|InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('danger', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Status transition error: ' . $e->getMessage());
        flash_set('danger', 'Failed to update status due to a system error.');
    }

    redirect('modules/vacancies/pipeline.php?id=' . $id);
}

// Fetch all applications for this vacancy
$appSql = "SELECT ca.id AS app_id, ca.status AS app_status, ca.application_date, ca.notes,
                  c.id AS candidate_id, c.candidate_code, c.first_name, c.last_name, c.email, c.phone,
                  c.experience_years, c.skills,
                  (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id = c.id AND cd.doc_type = 'cv' ORDER BY cd.id DESC LIMIT 1) AS cv_doc_id
             FROM candidate_applications ca
             JOIN candidates c ON c.id = ca.candidate_id
            WHERE ca.vacancy_id = ?
            ORDER BY ca.application_date DESC, ca.id DESC";
$appSt = $pdo->prepare($appSql);
$appSt->execute([$id]);
$allApplications = $appSt->fetchAll();

// Fetch status history for all applications of this vacancy for timeline modals
$appIds = array_column($allApplications, 'app_id');
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

// Fetch interviews for each application in pipeline
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

// Fetch offers for each application in pipeline
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

// Fetch evaluations for each application in pipeline
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

$canScheduleInterview = user_can('interview.manage', $companyId);
$canOfferManage       = user_can('offer.manage', $companyId);
$canOfferView         = user_can('offer.view', $companyId);
$canViewEvaluations   = user_can('interview.view_evaluations', $companyId);

// 6 stages required: applied, under_review, shortlisted, selected, on_hold, rejected
$stages = pipeline_stages();

// Group applications into columns
$columnCards = [];
foreach ($stages as $stageKey => $stageLabel) {
    $columnCards[$stageKey] = [];
}

foreach ($allApplications as $app) {
    $stg = (string)$app['app_status'];
    // Map non-standard intermediate statuses into closest stage column if needed
    if (!isset($columnCards[$stg])) {
        if (in_array($stg, ['interview_scheduled', 'interviewed'], true)) {
            $columnCards['under_review'][] = $app;
        } elseif (in_array($stg, ['offered', 'offer_accepted', 'hired'], true)) {
            $columnCards['selected'][] = $app;
        } else {
            $columnCards['applied'][] = $app;
        }
    } else {
        $columnCards[$stg][] = $app;
    }
}

$pageTitle = 'Pipeline: ' . ($vacancy['vacancy_code'] ?? '');
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.kanban-board {
    display: flex;
    overflow-x: auto;
    padding-bottom: 1.5rem;
    gap: 1rem;
    align-items: flex-start;
    min-height: calc(100vh - 260px);
}
.kanban-col {
    flex: 0 0 280px;
    background-color: #f8fafc;
    border-radius: 0.5rem;
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 240px);
}
.kanban-col-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    background-color: #ffffff;
    border-top-left-radius: 0.5rem;
    border-top-right-radius: 0.5rem;
}
.kanban-col-body {
    padding: 0.75rem;
    overflow-y: auto;
    flex-grow: 1;
    min-height: 200px;
}
.kanban-card {
    background-color: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 0.375rem;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: grab;
}
.kanban-card:hover {
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.kanban-card.dragging {
    opacity: 0.4;
}
.kanban-col-body.drag-over {
    background-color: #e0f2fe;
    border: 2px dashed #0284c7;
}
</style>

<div class="container-fluid py-3">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/index.php')) ?>">Vacancies</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/vacancies/view.php?id=' . $id)) ?>"><?= e($vacancy['vacancy_code']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Pipeline Board</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h4 mb-0"><?= e($vacancy['title']) ?></h1>
                <span class="badge bg-body-secondary text-body border"><?= e($vacancy['company_code']) ?></span>
                <?= vacancy_status_badge($vacancy['status']) ?>
                <span class="text-muted small">
                    &bull; <?= (int)$vacancy['positions_count'] ?> position(s) &bull; <?= count($allApplications) ?> total applicant(s)
                </span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(url('modules/vacancies/view.php?id=' . $id)) ?>" class="btn btn-outline-secondary btn-sm">
                Vacancy Overview
            </a>
            <?php if ($canManage): ?>
                <a href="<?= e(url('modules/candidates/apply.php?vacancy_id=' . $id)) ?>" class="btn btn-primary btn-sm">
                    + Add Applicant
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Kanban Columns -->
    <div class="kanban-board">
        <?php foreach ($stages as $stageKey => $stageLabel): ?>
            <?php
            $cards = $columnCards[$stageKey] ?? [];
            $headerColor = match($stageKey) {
                'applied'      => 'border-top: 3px solid #0dcaf0;',
                'under_review' => 'border-top: 3px solid #0d6efd;',
                'shortlisted'  => 'border-top: 3px solid #6c757d;',
                'selected'     => 'border-top: 3px solid #198754;',
                'on_hold'      => 'border-top: 3px solid #ffc107;',
                'rejected'     => 'border-top: 3px solid #dc3545;',
                default        => '',
            };
            ?>
            <div class="kanban-col" data-stage="<?= e($stageKey) ?>" style="<?= $headerColor ?>">
                <div class="kanban-col-header d-flex justify-content-between align-items-center">
                    <span class="fw-bold small text-uppercase text-secondary"><?= e($stageLabel) ?></span>
                    <span class="badge rounded-pill bg-body-tertiary text-body border"><?= count($cards) ?></span>
                </div>
                <div class="kanban-col-body" id="col-<?= e($stageKey) ?>" ondragover="allowDrop(event)" ondrop="handleDrop(event, '<?= e($stageKey) ?>')">
                    <?php if (empty($cards)): ?>
                        <div class="text-center text-muted small py-4 empty-placeholder">
                            No candidates in <?= strtolower(e($stageLabel)) ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($cards as $card): ?>
                            <?php
                            $cardHistory = $historyByApp[(int)$card['app_id']] ?? [];
                            ?>
                            <div class="kanban-card"
                                 id="card-<?= (int)$card['app_id'] ?>"
                                 draggable="<?= $canManage ? 'true' : 'false' ?>"
                                 ondragstart="handleDragStart(event, <?= (int)$card['app_id'] ?>, '<?= e($stageKey) ?>', '<?= e(addslashes($card['first_name'] . ' ' . $card['last_name'])) ?>', '<?= e($card['app_status']) ?>')">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$card['candidate_id'])) ?>" class="fw-semibold text-body text-decoration-none">
                                        <?= e($card['first_name'] . ' ' . $card['last_name']) ?>
                                    </a>
                                    <span class="badge bg-body-secondary text-body border small" style="font-size: 0.7rem;"><?= e($card['candidate_code']) ?></span>
                                </div>

                                <div class="small text-muted mb-2">
                                    <?php if (!empty($card['email'])): ?>
                                        <div class="text-truncate"><?= e($card['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($card['phone'])): ?>
                                        <div><?= e($card['phone']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($card['experience_years'] !== null): ?>
                                        <div class="fw-medium text-secondary"><?= e((string)$card['experience_years']) ?> yrs exp</div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                    <div class="small text-muted" style="font-size:0.75rem;">
                                        Applied: <?= e(format_date($card['application_date'])) ?>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <?php if (!empty($card['cv_doc_id'])): ?>
                                            <a href="<?= e(url('modules/candidates/download.php?id=' . (int)$card['cv_doc_id'])) ?>"
                                               class="btn btn-sm btn-outline-secondary p-1"
                                               title="Download CV" style="line-height:1; font-size:0.75rem;">
                                                CV
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($canScheduleInterview && !in_array($card['app_status'], ['hired', 'rejected', 'withdrawn', 'offer_declined'], true)): ?>
                                            <a href="<?= e(url('modules/interviews/schedule.php?application_id=' . (int)$card['app_id'])) ?>"
                                               class="btn btn-sm btn-outline-success p-1"
                                               style="line-height:1; font-size:0.75rem;"
                                               title="Schedule Interview">
                                                <i class="bi bi-calendar-plus"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php $cardOffer = $offersByApp[(int)$card['app_id']] ?? null; ?>
                                        <?php if (!empty($cardOffer) && $canOfferView): ?>
                                            <a href="<?= e(url('modules/offers/view.php?id=' . (int)$cardOffer['id'])) ?>"
                                               class="btn btn-sm btn-outline-primary p-1"
                                               style="line-height:1; font-size:0.75rem;"
                                               title="View Job Offer">
                                                <i class="bi bi-file-earmark-check"></i>
                                            </a>
                                            <?php if (!empty($cardOffer['converted_employee_id'])): ?>
                                                <a href="<?= e(url('modules/employees/view.php?id=' . (int)$cardOffer['converted_employee_id'])) ?>"
                                                   class="btn btn-sm btn-outline-success p-1"
                                                   style="line-height:1; font-size:0.75rem;"
                                                   title="View Employee Profile">
                                                    <i class="bi bi-person-check"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif (empty($cardOffer) && $card['app_status'] === 'selected' && $canOfferManage): ?>
                                            <a href="<?= e(url('modules/offers/form.php?application_id=' . (int)$card['app_id'])) ?>"
                                               class="btn btn-sm btn-success p-1"
                                               style="line-height:1; font-size:0.75rem;"
                                               title="Draft Job Offer">
                                                <i class="bi bi-plus-circle"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($canManage): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary p-1"
                                                    style="line-height:1; font-size:0.75rem;"
                                                    onclick="openMoveModal(<?= (int)$card['app_id'] ?>, '<?= e($stageKey) ?>', '<?= e(addslashes($card['first_name'] . ' ' . $card['last_name'])) ?>', '<?= e($card['app_status']) ?>')"
                                                    title="Move Stage">
                                                Move
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($cardHistory)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info p-1"
                                                    style="line-height:1; font-size:0.75rem;"
                                                    onclick="openHistoryModal(<?= (int)$card['app_id'] ?>)"
                                                    title="Status History">
                                                Log
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Offer badge on card if exists -->
                                <?php if (!empty($cardOffer)): ?>
                                    <div class="mt-2 pt-1 border-top d-flex justify-content-between align-items-center" style="font-size:0.75rem;">
                                        <span class="text-secondary fw-semibold"><i class="bi bi-file-earmark-check text-primary me-1"></i>Offer:</span>
                                        <div>
                                            <?= offer_status_badge($cardOffer['status']) ?>
                                            <?php if (!empty($cardOffer['converted_employee_id'])): ?>
                                                <span class="badge badge-soft-success ms-1"><i class="bi bi-person-check me-1"></i>Hired</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php $cardInterviews = $interviewsByApp[(int)$card['app_id']] ?? []; ?>
                                <?php if (!empty($cardInterviews)): ?>
                                    <div class="mt-2 pt-1 border-top" style="font-size:0.75rem;">
                                        <div class="text-secondary fw-semibold mb-1"><i class="bi bi-calendar-event me-1 text-primary"></i>Interviews (<?= count($cardInterviews) ?>):</div>
                                        <?php foreach ($cardInterviews as $cInt): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-1 bg-body-tertiary p-1 px-2 rounded">
                                                <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$cInt['id'])) ?>" class="text-decoration-none text-body fw-medium text-truncate" style="max-width: 140px;" title="View Round <?= (int)$cInt['round_no'] ?>">
                                                    R<?= (int)$cInt['round_no'] ?>: <?= e(format_datetime($cInt['scheduled_at'], 'd M, h:i A')) ?>
                                                </a>
                                                <?= interview_status_badge($cInt['status']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Hidden json payload for history modal -->
                                <script type="application/json" id="history-data-<?= (int)$card['app_id'] ?>">
                                    <?= json_encode($cardHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                </script>

                                <!-- Hidden json payload for evaluations summary -->
                                <script type="application/json" id="evaluations-data-<?= (int)$card['app_id'] ?>">
                                    <?= json_encode($evaluationsByApp[(int)$card['app_id']] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                </script>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Move Stage Modal -->
<div class="modal fade" id="moveModal" tabindex="-1" aria-labelledby="moveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e(url('modules/vacancies/pipeline.php?id=' . $id)) ?>" id="moveForm">
                <?= csrf_field() ?>
                <input type="hidden" name="application_id" id="moveAppId" value="0">

                <div class="modal-header">
                    <h5 class="modal-title" id="moveModalLabel">Move Pipeline Stage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Updating status for candidate <strong id="moveCandidateName"></strong>. Every status transition is tracked in candidate history.
                    </p>

                    <!-- Evaluation Scores Summary Box -->
                    <div id="moveEvaluationsContainer" class="mb-3 d-none">
                        <label class="form-label fw-semibold text-secondary small d-flex align-items-center gap-1">
                            <i class="bi bi-star-fill text-warning"></i> Interview Evaluations Summary
                        </label>
                        <div id="moveEvaluationsList" class="bg-body-tertiary p-2 rounded border small"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Destination Stage <span class="text-danger">*</span></label>
                        <select name="new_status" id="moveDestinationStage" class="form-select" required onchange="handleMoveStageChange(this.value)">
                            <?php foreach ($stages as $sk => $sl): ?>
                                <option value="<?= e($sk) ?>" id="move-opt-<?= e($sk) ?>"><?= e($sl) ?></option>
                            <?php endforeach; ?>
                            <option value="withdrawn">Withdrawn</option>
                        </select>
                        <div id="moveSelectedHelp" class="form-text text-danger d-none">
                            Selected is only permitted from the Interviewed stage after at least one evaluation.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" id="moveRemarksLabel">Remarks / Transition Notes</label>
                        <textarea name="remarks" id="moveRemarks" class="form-control" rows="3" maxlength="255" placeholder="e.g. Cleared technical interview, Moved to shortlist, Rejected due to missing prerequisite qualification..."></textarea>
                        <div class="form-text" id="moveRemarksHelp">Required for Rejected and On Hold transitions (max 255 characters).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm &amp; Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Status History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalLabel">Status Transition History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-active">
                            <tr>
                                <th class="ps-3">Date & Time</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Remarks</th>
                                <th>Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <!-- Populated dynamically via JS -->
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
let draggedAppId = null;
let draggedCandidateName = '';
let draggedSourceStage = '';
let draggedAppStatus = '';

function handleDragStart(e, appId, stageKey, candidateName, appStatus) {
    draggedAppId = appId;
    draggedCandidateName = candidateName;
    draggedSourceStage = stageKey;
    draggedAppStatus = appStatus;
    e.dataTransfer.setData('text/plain', appId);
    e.target.classList.add('dragging');
}

function allowDrop(e) {
    e.preventDefault();
    const colBody = e.currentTarget;
    colBody.classList.add('drag-over');
}

function handleDrop(e, targetStage) {
    e.preventDefault();
    const colBody = e.currentTarget;
    colBody.classList.remove('drag-over');

    document.querySelectorAll('.kanban-card').forEach(c => c.classList.remove('dragging'));

    if (draggedAppId && targetStage !== draggedSourceStage) {
        openMoveModal(draggedAppId, targetStage, draggedCandidateName, draggedAppStatus);
    }
}

document.querySelectorAll('.kanban-col-body').forEach(col => {
    col.addEventListener('dragleave', (e) => {
        col.classList.remove('drag-over');
    });
});

function handleMoveStageChange(val) {
    const remarks = document.getElementById('moveRemarks');
    const label = document.getElementById('moveRemarksLabel');
    if (val === 'rejected' || val === 'on_hold') {
        remarks.required = true;
        label.innerHTML = 'Remarks / Reason <span class="text-danger">*</span>';
    } else {
        remarks.required = false;
        label.innerHTML = 'Remarks / Transition Notes';
    }
}

function openMoveModal(appId, targetStage, candidateName, currentStatus) {
    document.getElementById('moveAppId').value = appId;
    document.getElementById('moveCandidateName').textContent = candidateName;
    document.getElementById('moveDestinationStage').value = targetStage;
    document.getElementById('moveRemarks').value = '';

    // Selected status guard
    const selectedOpt = document.getElementById('move-opt-selected');
    const selectedHelp = document.getElementById('moveSelectedHelp');
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

    handleMoveStageChange(targetStage);

    // Evaluation scores summary
    const container = document.getElementById('moveEvaluationsContainer');
    const list = document.getElementById('moveEvaluationsList');
    list.innerHTML = '';
    container.classList.add('d-none');

    <?php if ($canViewEvaluations): ?>
        const rawEvals = document.getElementById('evaluations-data-' + appId);
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
    <?php endif; ?>

    const modal = new bootstrap.Modal(document.getElementById('moveModal'));
    modal.show();
}

function openHistoryModal(appId) {
    const rawData = document.getElementById('history-data-' + appId);
    if (!rawData) return;

    let items = [];
    try {
        items = JSON.parse(rawData.textContent);
    } catch(e) {
        console.error(e);
    }

    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No status transitions recorded yet.</td></tr>';
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

    const modal = new bootstrap.Modal(document.getElementById('historyModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
