<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('candidate.view');

$pdo = db();

$hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');
$scopedCompanyIds = scoped_company_ids('candidate.view');

// Build visibility constraints
// Requirement: "Visibility: users see applications only for their companies. Full candidate pool across companies only with candidate.cross_company."
$whereClauses = [];
$params = [];

if (!$hasCrossCompany) {
    if (empty($scopedCompanyIds)) {
        $whereClauses[] = '1 = 0';
    } else {
        $cph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        // Candidate must have applied to at least one vacancy in user's accessible companies or was created by user
        $whereClauses[] = "(c.id IN (SELECT DISTINCT ca.candidate_id FROM candidate_applications ca WHERE ca.company_id IN ($cph)) OR c.created_by = ?)";
        $params = array_merge($params, $scopedCompanyIds, [current_user_id()]);
    }
}

// Search: name, code, email, phone, skills
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $whereClauses[] = '(c.candidate_code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.skills LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
}

// Filter: source
$filterSource = trim((string)($_GET['source'] ?? ''));
if ($filterSource !== '') {
    $whereClauses[] = 'c.source = ?';
    $params[] = $filterSource;
}

// Filter: min experience
$filterExp = (string)($_GET['min_exp'] ?? '');
if ($filterExp !== '' && is_numeric($filterExp)) {
    $whereClauses[] = 'c.experience_years >= ?';
    $params[] = (float)$filterExp;
}

// Filter: has CV
$filterHasCv = (string)($_GET['has_cv'] ?? '');
if ($filterHasCv === 'yes') {
    $whereClauses[] = "EXISTS (SELECT 1 FROM candidate_documents cd WHERE cd.candidate_id = c.id AND cd.doc_type = 'cv')";
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Count total
$countSt = $pdo->prepare("SELECT COUNT(*) FROM candidates c $whereSql");
$countSt->execute($params);
$totalCount = (int)$countSt->fetchColumn();

$pg = paginate($totalCount, (int)($_GET['page'] ?? 1), 15);

// Fetch candidates with application counts and latest CV document ID
// Scoped application count for users without cross_company
$appCountSql = $hasCrossCompany
    ? "COUNT(DISTINCT ca.id) AS total_applications"
    : "COUNT(DISTINCT CASE WHEN ca.company_id IN (" . (empty($scopedCompanyIds) ? '0' : implode(',', $scopedCompanyIds)) . ") THEN ca.id ELSE NULL END) AS total_applications";

$sql = "SELECT c.*,
               $appCountSql,
               (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id = c.id AND cd.doc_type = 'cv' ORDER BY cd.id DESC LIMIT 1) AS cv_doc_id
          FROM candidates c
          LEFT JOIN candidate_applications ca ON ca.candidate_id = c.id
         $whereSql
         GROUP BY c.id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";

$st = $pdo->prepare($sql);
$st->execute($params);
$candidates = $st->fetchAll();

$sources = candidate_source_list();
$canManageCandidates = user_can('candidate.manage');

$pageTitle = 'Candidates Central Pool';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4">
    <!-- Top Header -->
    <div class="page-header mb-4">
        <div class="page-header-title-block">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="page-header-title h3 mb-0">Candidate Talent Pool</h1>
                <?php if ($hasCrossCompany): ?>
                    <span class="badge text-bg-success">Cross-Company Pool Access</span>
                <?php else: ?>
                    <span class="badge text-bg-info text-body">Company Scoped</span>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0 mt-1">
                Centralized applicant database shared across AaZa Group. Track profiles, applications, and documents.
            </p>
        </div>
        <div class="page-actions">
            <?php if ($canManageCandidates): ?>
                <a href="<?= e(url('modules/candidates/apply.php')) ?>" class="btn btn-outline-primary">
                    + Apply to Vacancy
                </a>
                <a href="<?= e(url('modules/candidates/form.php')) ?>" class="btn btn-primary">
                    + New Candidate
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters & Search Form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="<?= e(url('modules/candidates/index.php')) ?>" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" name="q" class="form-control" placeholder="Search code, name, email, phone, skills..." value="<?= e($q) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Source</label>
                    <select name="source" class="form-select">
                        <option value="">All Sources</option>
                        <?php foreach ($sources as $sVal => $sLbl): ?>
                            <option value="<?= e($sVal) ?>"<?= $filterSource === $sVal ? ' selected' : '' ?>>
                                <?= e($sLbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Min Experience (Yrs)</label>
                    <input type="number" step="0.5" min="0" name="min_exp" class="form-control" placeholder="e.g. 2" value="<?= e($filterExp) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">CV Uploaded</label>
                    <select name="has_cv" class="form-select">
                        <option value="">Any</option>
                        <option value="yes"<?= $filterHasCv === 'yes' ? ' selected' : '' ?>>Has CV attached</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <?php if ($q !== '' || $filterSource !== '' || $filterExp !== '' || $filterHasCv !== ''): ?>
                        <a href="<?= e(url('modules/candidates/index.php')) ?>" class="btn btn-outline-secondary" title="Clear filters">&times;</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Candidates Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fs-6 fw-bold">Candidate Talent Pool</h5>
                <small class="text-muted d-block mt-0.5">
                    <i class="bi bi-info-circle me-1"></i>Tip: Double-click or double-tap on any candidate row to view full profile.
                </small>
            </div>
            <span class="badge bg-body-secondary text-body border"><?= (int)$totalCount ?> candidate<?= (int)$totalCount === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-active">
                        <tr>
                            <th class="ps-3">Candidate</th>
                            <th>Contact Information</th>
                            <th>NIC / Passport</th>
                            <th>Experience & Skills</th>
                            <th>Source</th>
                            <th>Applications</th>
                            <th class="text-end pe-3 action-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($candidates)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    No candidate profiles found.
                                    <?php if ($canManageCandidates): ?>
                                        <div class="mt-2">
                                            <a href="<?= e(url('modules/candidates/form.php')) ?>" class="btn btn-sm btn-outline-primary">Register First Candidate</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($candidates as $c): ?>
                                <?php $candViewUrl = url('modules/candidates/view.php?id=' . (int)$c['id']); ?>
                                <tr class="clickable-row candidate-row" data-href="<?= e($candViewUrl) ?>" title="Double-click to view candidate <?= e($c['candidate_code']) ?>">
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <div>
                                                <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$c['id'])) ?>" class="fw-semibold text-primary text-decoration-none">
                                                    <?= e($c['first_name'] . ' ' . $c['last_name']) ?>
                                                </a>
                                                <div class="small text-muted"><?= e($c['candidate_code']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-medium text-body"><?= e($c['email'] ?? '—') ?></div>
                                        <div class="small text-muted"><?= e($c['phone'] ?? '—') ?></div>
                                    </td>
                                    <td>
                                        <?= !empty($c['nic_passport']) ? '<span class="badge bg-body-secondary text-body border">' . e($c['nic_passport']) . '</span>' : '<span class="text-muted small">—</span>' ?>
                                    </td>
                                    <td>
                                        <div>
                                            <?= $c['experience_years'] !== null ? '<strong>' . e((string)$c['experience_years']) . '</strong> yrs exp' : '<span class="text-muted small">Not specified</span>' ?>
                                        </div>
                                        <?php if (!empty($c['skills'])): ?>
                                            <div class="small text-secondary text-truncate" style="max-width: 200px;" title="<?= e($c['skills']) ?>">
                                                <?= e($c['skills']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-body-secondary text-body text-secondary border">
                                            <?= e($sources[$c['source']] ?? ucfirst((string)$c['source'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-body-tertiary text-body border">
                                            <?= (int)$c['total_applications'] ?> application<?= (int)$c['total_applications'] === 1 ? '' : 's' ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3 action-col">
                                        <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                            <a href="<?= e(url('modules/candidates/view.php?id=' . (int)$c['id'])) ?>" class="btn btn-sm btn-outline-primary py-1 px-2.5" title="View candidate profile">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                            <?= action_menu([
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Download CV',
                                                    'url'        => 'modules/candidates/download.php?id=' . (int)$c['cv_doc_id'],
                                                    'icon'       => 'bi-file-earmark-arrow-down',
                                                    'permission' => !empty($c['cv_doc_id']),
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Apply to Vacancy',
                                                    'url'        => 'modules/candidates/apply.php?candidate_id=' . (int)$c['id'],
                                                    'icon'       => 'bi-send',
                                                    'permission' => (bool)$canManageCandidates,
                                                ],
                                                [
                                                    'type'       => 'link',
                                                    'label'      => 'Edit Profile',
                                                    'url'        => 'modules/candidates/form.php?id=' . (int)$c['id'],
                                                    'icon'       => 'bi-pencil',
                                                    'permission' => (bool)$canManageCandidates,
                                                ],
                                            ]) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($pg['pages'] > 1): ?>
            <div class="card-footer bg-body d-flex justify-content-between align-items-center py-3">
                <span class="small text-muted">
                    Showing <?= $pg['offset'] + 1 ?> to <?= min($pg['offset'] + $pg['per_page'], $totalCount) ?> of <?= $totalCount ?> candidates
                </span>
                <?= render_pagination($pg) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
