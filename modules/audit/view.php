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

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('modules/audit/index.php')) ?>">Audit Logs</a></li>
        <li class="breadcrumb-item active" aria-current="page">Record #<?= $id ?></li>
    </ol>
</nav>

<!-- Detail Header -->
<div class="detail-header">
    <div class="detail-header-main">
        <div class="detail-header-title-row">
            <h1 class="detail-header-title"><i class="bi bi-shield-check text-primary me-2"></i>Audit Record #<?= $id ?></h1>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1">
                <?= e($log['action']) ?>
            </span>
        </div>
        <p class="detail-header-subtitle">
            <?= e(format_date($log['created_at'], 'd M Y, H:i:s')) ?>
            &bull; Scope: <strong><?= !empty($log['company_name']) ? e($log['company_name']) : 'System / Global' ?></strong>
            &bull; User: <strong><?= e($log['user_full_name'] ?? ($log['user_username'] ?? 'System')) ?></strong>
        </p>
    </div>

    <div class="detail-header-actions">
        <a href="<?= e(url('modules/audit/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Audit List
        </a>
    </div>
</div>

<div class="row g-3 align-items-start">
    <!-- Metadata Summary Card -->
    <div class="col-lg-5">
        <div class="detail-card">
            <div class="card-header">
                <span><i class="bi bi-info-circle text-primary me-2"></i>Event Information</span>
            </div>
            <div class="card-body">
                <div class="kv-list kv-list-single">
                    <div class="kv-item">
                        <span class="kv-label">Log ID</span>
                        <span class="kv-value font-monospace fw-bold">#<?= (int)$log['id'] ?></span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Timestamp</span>
                        <span class="kv-value font-monospace small">
                            <?= e(format_date($log['created_at'], 'd M Y, H:i:s')) ?>
                            <span class="text-muted d-block"><?= e($log['created_at']) ?></span>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Action Code</span>
                        <span class="kv-value">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                                <?= e($log['action']) ?>
                            </span>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">User</span>
                        <span class="kv-value">
                            <?php if (!empty($log['user_id'])): ?>
                                <div class="fw-bold text-body"><?= e($log['user_full_name'] ?? 'User #' . $log['user_id']) ?></div>
                                <div class="small text-muted font-monospace">@<?= e($log['user_username'] ?? '—') ?></div>
                            <?php else: ?>
                                <span class="text-muted fst-italic">System / Anonymous</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Company Scope</span>
                        <span class="kv-value">
                            <?php if (!empty($log['company_id'])): ?>
                                <span class="fw-semibold text-body"><?= e($log['company_name'] ?? 'Company #' . $log['company_id']) ?></span>
                                <span class="badge text-bg-secondary ms-1 font-monospace"><?= e($log['company_code'] ?? '') ?></span>
                            <?php else: ?>
                                <span class="text-muted fst-italic">Global / All Companies</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">Target Entity</span>
                        <span class="kv-value font-monospace small">
                            <?= !empty($log['entity_type']) ? e($log['entity_type']) . ' #' . (int)$log['entity_id'] : '<span class="text-muted fst-italic">—</span>' ?>
                        </span>
                    </div>
                    <div class="kv-item">
                        <span class="kv-label">IP Address</span>
                        <span class="kv-value font-monospace small">
                            <?= !empty($log['ip_address']) ? e($log['ip_address']) : '<span class="text-muted fst-italic">—</span>' ?>
                        </span>
                    </div>
                    <div class="kv-item pt-2 border-top">
                        <span class="kv-label">User Agent</span>
                        <span class="kv-value small text-muted text-break" style="font-size: 0.8rem;">
                            <?= !empty($log['user_agent']) ? e($log['user_agent']) : '<span class="fst-italic">—</span>' ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-body-tertiary border-top py-2 px-3 small text-muted font-sans">
                <i class="bi bi-lock-fill me-1 text-success"></i>Append-only immutable record. Edits and deletions are prohibited.
            </div>
        </div>
    </div>

    <!-- JSON Payload Card -->
    <div class="col-lg-7">
        <div class="detail-card">
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
