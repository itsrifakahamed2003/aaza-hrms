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
    'SELECT ed.*, e.employee_code, e.first_name, e.last_name
       FROM employee_documents ed
       JOIN employees e ON e.id = ed.employee_id
      WHERE ed.id = ?
      LIMIT 1'
);
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc) {
    abort(404, 'Document not found.');
}

$companyId = (int)$doc['company_id'];

// Check company access and document.view permission
require_company_access($companyId, 'document.view');

// Check confidential flag
if (!empty($doc['is_confidential']) && !user_can('document.confidential', $companyId)) {
    log_activity('document.download_denied', 'employee_document', $id, [
        'reason'      => 'confidential_restricted',
        'employee_id' => (int)$doc['employee_id'],
    ], $companyId);
    abort(403, 'This document is confidential. You do not have permission to view confidential documents.');
}

// Locate physical file in storage outside public
$filePath = STORAGE_PATH . '/uploads/employees/' . basename($doc['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    error_log("Missing employee document file on disk: $filePath (doc_id: $id)");
    abort(404, 'The requested file could not be found on the server.');
}

// Log download activity
log_activity('document.download', 'employee_document', $id, [
    'title'         => $doc['title'] ?? $doc['original_name'],
    'original_name' => $doc['original_name'],
    'employee_id'   => (int)$doc['employee_id'],
    'doc_type'      => $doc['doc_type'],
], $companyId);

// Serve file securely
// Prevent directory traversal or unwanted output
$safeOriginal = str_replace(['"', "\r", "\n"], '', basename((string)$doc['original_name']));
if ($safeOriginal === '') {
    $safeOriginal = 'document';
}

$mime = (string)$doc['mime_type'];
if ($mime === '') {
    $mime = 'application/octet-stream';
}

// Clean any previous output buffer
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
