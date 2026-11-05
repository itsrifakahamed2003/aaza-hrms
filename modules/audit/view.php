<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('audit.view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Invalid audit log record ID.');
}

$pdo = db();
$st = $pdo->prepare(
    "SELECT a.*,
            u.full_name AS user_full_name,
            u.username AS user_username,
            u.email AS user_email,
            c.name AS company_name,
            c.code AS company_code
       FROM activity_logs a
  LEFT JOIN users u ON a.user_id = u.id
  LEFT JOIN companies c ON a.company_id = c.id
      WHERE a.id = ?
      LIMIT 1"
);
$st->execute([$id]);
$log = $st->fetch();

if (!$log) {
    abort(404, 'Audit log record not found.');
}

// Scoping enforcement
$isGroupPrivileged = is_group_admin() || user_can('dashboard.group');
$accessibleCompIds = accessible_company_ids('audit.view');

if ($log['company_id'] !== null) {
    $cid = (int)$log['company_id'];
    if (!$isGroupPrivileged && !in_array($cid, $accessibleCompIds, true)) {
        abort(403, 'You do not have permission to inspect audit records for this company.');
    }
} else {
    if (!$isGroupPrivileged) {
        abort(403, 'You do not have permission to view global or system-level audit logs.');
    }
}

// Parse and mask sensitive details
$detailsRaw = (string)($log['details'] ?? '');
$detailsDecoded = null;
if ($detailsRaw !== '') {
    $decoded = json_decode($detailsRaw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $detailsDecoded = mask_sensitive_audit_data($decoded);
    } else {
        $detailsDecoded = $detailsRaw;
    }
}

$prettyJson = '';
if (is_array($detailsDecoded)) {
    $prettyJson = json_encode($detailsDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} elseif ($detailsDecoded !== null) {
    $prettyJson = (string)$detailsDecoded;
}

$pageTitle = 'Audit Log Details #' . $id;
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('modules/audit/index.php')) ?>">Audit Logs</a></li>
                <li class="breadcrumb-item active" aria-current="page">Record #<?= $id ?></li>
            </ol>
        </nav>
        <h2 class="h4 mb-0 fw-bold text-body font-sans">
            <i class="bi bi-shield-check text-primary me-2"></i>Audit Log Record #<?= $id ?>
        </h2>
    </div>
    <div>
        <a href="<?= e(url('modules/audit/index.php')) ?>" class="btn btn-outline-secondary font-sans">
            <i class="bi bi-arrow-left me-1"></i>Back to Audit List
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Metadata Summary Card -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-body border-bottom py-3">
                <h5 class="card-title mb-0 fw-semibold text-body font-sans">
                    <i class="bi bi-info-circle text-primary me-2"></i>Event Information
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 font-sans">
                        <tbody>
                            <tr>
                                <th class="ps-3 text-muted fw-medium" style="width: 38%;">Log ID</th>
                                <td class="pe-3 font-monospace fw-bold text-body">#<?= (int)$log['id'] ?></td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">Timestamp</th>
                                <td class="pe-3 font-monospace text-body">
                                    <?= e(format_date($log['created_at'], 'd M Y, H:i:s')) ?>
                                    <span class="text-muted small d-block"><?= e($log['created_at']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">Action Code</th>
                                <td class="pe-3">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1">
                                        <?= e($log['action']) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">User</th>
                                <td class="pe-3">
                                    <?php if (!empty($log['user_id'])): ?>
                                        <div class="fw-bold text-body"><?= e($log['user_full_name'] ?? 'User #' . $log['user_id']) ?></div>
                                        <div class="small text-muted font-monospace">@<?= e($log['user_username'] ?? '—') ?></div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">System / Anonymous</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">Company Scope</th>
                                <td class="pe-3">
                                    <?php if (!empty($log['company_id'])): ?>
                                        <span class="fw-semibold text-body"><?= e($log['company_name'] ?? 'Company #' . $log['company_id']) ?></span>
                                        <span class="badge text-bg-secondary ms-1"><?= e($log['company_code'] ?? '') ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-body-secondary text-body border text-secondary font-sans">Global / System</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">Entity Type</th>
                                <td class="pe-3">
                                    <?php if (!empty($log['entity_type'])): ?>
                                        <span class="badge bg-body-secondary text-body border text-body font-monospace"><?= e($log['entity_type']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">Entity ID</th>
                                <td class="pe-3 font-monospace">
                                    <?= !empty($log['entity_id']) ? '#' . (int)$log['entity_id'] : '<span class="text-muted">—</span>' ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-3 text-muted fw-medium">IP Address</th>
                                <td class="pe-3 font-monospace">
                                    <i class="bi bi-globe me-1 text-muted"></i><?= e($log['ip_address'] ?? '—') ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-2 px-3 small text-muted font-sans">
                <i class="bi bi-lock-fill me-1 text-success"></i>Append-only immutable record. Edits and deletions are prohibited.
            </div>
        </div>
    </div>

    <!-- JSON Payload Card -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-body border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-semibold text-body font-sans">
                    <i class="bi bi-file-earmark-code text-primary me-2"></i>Event Payload &amp; Context
                </h5>
                <span class="badge bg-secondary-subtle text-secondary font-sans">Masked &amp; Sanitized</span>
            </div>
            <div class="card-body p-3">
                <?php if ($prettyJson !== ''): ?>
                    <pre class="bg-dark text-light p-3 rounded font-monospace small mb-0" style="max-height: 480px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"><?= e($prettyJson) ?></pre>
                <?php else: ?>
                    <div class="text-center py-5 text-muted font-sans">
                        <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                        No additional details payload recorded for this action.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
