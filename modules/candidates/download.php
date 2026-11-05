<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Document not found.');
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT cd.*, c.candidate_code, c.first_name, c.last_name, c.created_by AS candidate_created_by
       FROM candidate_documents cd
       JOIN candidates c ON c.id = cd.candidate_id
      WHERE cd.id = ?
      LIMIT 1'
);
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc) {
    abort(404, 'Document not found.');
}

// Authorized download script that checks company access:
// Cross-company users / Group Admin can view all candidate documents.
// Company-scoped users can only download if the candidate has an application in their accessible companies (or user is creator).
$hasCrossCompany = is_group_admin() || user_can('candidate.cross_company');

if (!$hasCrossCompany) {
    $scopedCompanyIds = scoped_company_ids('candidate.view');
    $hasAccess = false;

    if (!empty($scopedCompanyIds)) {
        $cph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $permSt = $pdo->prepare(
            "SELECT 1 FROM candidate_applications WHERE candidate_id = ? AND company_id IN ($cph) LIMIT 1"
        );
        $permSt->execute(array_merge([(int)$doc['candidate_id']], $scopedCompanyIds));
        $hasAccess = (bool)$permSt->fetchColumn();
    }

    if (!$hasAccess && (int)$doc['candidate_created_by'] === current_user_id()) {
        $hasAccess = true;
    }

    if (!$hasAccess) {
        log_activity('candidate.document_download_denied', 'candidate_document', $id, [
            'reason'       => 'no_company_application_match',
            'candidate_id' => (int)$doc['candidate_id'],
        ]);
        abort(403, 'You do not have permission to view documents for this candidate.');
    }
}

// Locate physical file in storage outside public root
$filePath = STORAGE_PATH . '/uploads/candidates/' . basename((string)$doc['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    error_log("Missing candidate document file on disk: $filePath (doc_id: $id)");
    abort(404, 'The requested file could not be found on the server.');
}

// Log download activity
log_activity('candidate.document_download', 'candidate_document', $id, [
    'original_name'  => $doc['original_name'],
    'candidate_id'   => (int)$doc['candidate_id'],
    'candidate_code' => $doc['candidate_code'],
    'doc_type'       => $doc['doc_type'],
]);

// Serve file securely
$safeOriginal = str_replace(['"', "\r", "\n"], '', basename((string)$doc['original_name']));
if ($safeOriginal === '') {
    $safeOriginal = 'document';
}

$mime = (string)$doc['mime_type'];
if ($mime === '') {
    $mime = 'application/octet-stream';
}

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $safeOriginal . '"; filename*=UTF-8\'\'' . rawurlencode($safeOriginal));
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
