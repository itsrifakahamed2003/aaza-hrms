<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('candidate.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method not allowed.');
}

csrf_require();

$candidateId = (int)($_POST['candidate_id'] ?? 0);
$docType = trim((string)($_POST['doc_type'] ?? 'cv'));

if ($candidateId <= 0) {
    abort(404, 'Candidate not found.');
}

$pdo = db();

// Fetch candidate
$st = $pdo->prepare('SELECT id, candidate_code, first_name, last_name, created_by FROM candidates WHERE id = ? LIMIT 1');
$st->execute([$candidateId]);
$candidate = $st->fetch();

if (!$candidate) {
    abort(404, 'Candidate not found.');
}

$action = trim((string)($_POST['action'] ?? 'upload'));
if ($action === 'delete') {
    if (!is_group_admin() && !user_can('document.delete')) {
        abort(403, 'Permission denied: only administrators can delete documents.');
    }
    $docId = (int)($_POST['document_id'] ?? 0);
    $res = delete_candidate_document($docId, $pdo);
    if ($res['success']) {
        flash_set('success', $res['message']);
    } else {
        flash_set('danger', $res['message']);
    }
    redirect('modules/candidates/view.php?id=' . $candidateId);
}

// Access check:
// Must be group admin OR have candidate.cross_company OR candidate has application in user's company OR created by user
if (!is_group_admin() && !user_can('candidate.cross_company')) {
    $scopedCompanyIds = scoped_company_ids('candidate.manage');
    $cph = implode(',', array_fill(0, count($scopedCompanyIds ?: [0]), '?'));
    $permSt = $pdo->prepare(
        "SELECT 1 FROM candidate_applications WHERE candidate_id = ? AND company_id IN ($cph) LIMIT 1"
    );
    $permSt->execute(array_merge([$candidateId], $scopedCompanyIds ?: [0]));
    $hasAccess = (bool)$permSt->fetchColumn() || ((int)$candidate['created_by'] === current_user_id());
    if (!$hasAccess) {
        abort(403, 'You do not have access to upload documents for this candidate.');
    }
}

$allowedDocTypes = candidate_doc_type_list();
if (!isset($allowedDocTypes[$docType])) {
    flash_set('danger', 'Invalid document type selected.');
    redirect('modules/candidates/view.php?id=' . $candidateId);
}

if (empty($_FILES['document']['name'])) {
    flash_set('danger', 'Please select a file to upload.');
    redirect('modules/candidates/view.php?id=' . $candidateId);
}

[$ok, $res] = validate_candidate_document_upload($_FILES['document']);
if (!$ok) {
    flash_set('danger', (string)$res);
    redirect('modules/candidates/view.php?id=' . $candidateId);
}

$destDir = STORAGE_PATH . '/uploads/candidates';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

$destPath = $destDir . '/' . basename((string)$res['stored_name']);
if (!move_uploaded_file((string)$res['tmp_path'], $destPath)) {
    error_log("Failed to move uploaded candidate document to $destPath");
    flash_set('danger', 'Failed to save the uploaded file on the server. Please check folder permissions.');
    redirect('modules/candidates/view.php?id=' . $candidateId);
}

try {
    $insSt = $pdo->prepare(
        "INSERT INTO candidate_documents (candidate_id, doc_type, original_name, stored_name, mime_type, file_size, uploaded_by, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insSt->execute([
        $candidateId,
        $docType,
        $res['original_name'],
        $res['stored_name'],
        $res['mime_type'],
        $res['file_size'],
        current_user_id(),
    ]);
    $docId = (int)$pdo->lastInsertId();

    log_activity('candidate.document_upload', 'candidate_documents', $docId, [
        'candidate_id'   => $candidateId,
        'candidate_code' => $candidate['candidate_code'],
        'doc_type'       => $docType,
        'original_name'  => $res['original_name'],
    ]);

    flash_set('success', 'Document uploaded successfully.');
} catch (Throwable $e) {
    if (is_file($destPath)) {
        @unlink($destPath);
    }
    error_log('Error saving candidate document record: ' . $e->getMessage());
    flash_set('danger', 'Failed to record document due to a system error.');
}

redirect('modules/candidates/view.php?id=' . $candidateId);
