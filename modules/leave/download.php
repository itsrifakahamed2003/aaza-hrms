<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    abort(404, 'Leave request not found.');
}

$pdo = db();
$st = $pdo->prepare(
    "SELECT lr.id, lr.document_path, lr.company_id, lr.employee_id,
            e.user_id AS employee_user_id, e.employee_code, e.first_name, e.last_name
       FROM leave_requests lr
       JOIN employees e ON e.id = lr.employee_id
      WHERE lr.id = ?
      LIMIT 1"
);
$st->execute([$id]);
$req = $st->fetch();

if (!$req || empty($req['document_path'])) {
    abort(404, 'Supporting document not found for this leave request.');
}

$companyId = (int)$req['company_id'];
$isOwner   = ($req['employee_user_id'] !== null && (int)$req['employee_user_id'] === current_user_id());
$canAccess = $isOwner || user_can('leave.approve', $companyId) || user_can('leave.view', $companyId);

if (!$canAccess) {
    abort(403, 'You do not have permission to view or download this document.');
}

// Sanitize filename to prevent directory traversal
$storedFileName = basename((string)$req['document_path']);
$filePath = STORAGE_PATH . '/uploads/leave/' . $storedFileName;

if (!is_file($filePath)) {
    abort(404, 'Document file is missing from secure storage.');
}

// Audit log access
log_activity(
    'leave.document_download',
    'leave_request',
    $id,
    [
        'employee_id' => (int)$req['employee_id'],
        'file'        => $storedFileName,
        'as_owner'    => $isOwner,
    ],
    $companyId
);

// Determine MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath) ?: 'application/octet-stream';
$fileSize = filesize($filePath);
$downloadName = 'leave_doc_' . $req['employee_code'] . '_' . $id . '.' . pathinfo($storedFileName, PATHINFO_EXTENSION);

// Send clean headers
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
