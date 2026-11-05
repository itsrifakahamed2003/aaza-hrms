<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
csrf_require();

$empId = (int)($_POST['employee_id'] ?? 0);
if ($empId <= 0) {
    abort(404, 'Employee not found.');
}

$pdo = db();
$st = $pdo->prepare('SELECT id, company_id, employee_code, first_name, last_name FROM employees WHERE id = ? LIMIT 1');
$st->execute([$empId]);
$emp = $st->fetch();

if (!$emp) {
    abort(404, 'Employee not found.');
}

$companyId = (int)$emp['company_id'];
$redirectUrl = 'modules/employees/view.php?id=' . $empId . '&tab=documents';

$action = trim((string)($_POST['action'] ?? 'upload'));
if ($action === 'delete') {
    if (!is_group_admin() && !user_can('document.delete', $companyId)) {
        abort(403, 'Permission denied: only administrators can delete documents.');
    }
    $docId = (int)($_POST['document_id'] ?? 0);
    $res = delete_employee_document($docId, $pdo);
    if ($res['success']) {
        flash_set('success', $res['message']);
    } else {
        flash_set('danger', $res['message']);
    }
    redirect($redirectUrl);
}

require_company_access($companyId, 'document.upload');

$docType = trim((string)($_POST['doc_type'] ?? 'other'));
$allowedTypes = employee_doc_type_list();
if (!array_key_exists($docType, $allowedTypes)) {
    flash_set('danger', 'Invalid document type selected.');
    redirect($redirectUrl);
}

$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') {
    $title = $allowedTypes[$docType];
}
if (mb_strlen($title) > 150) {
    flash_set('danger', 'Document title must not exceed 150 characters.');
    redirect($redirectUrl);
}

$expiryDate = trim((string)($_POST['expiry_date'] ?? ''));
if ($expiryDate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $expiryDate);
    if (!$d || $d->format('Y-m-d') !== $expiryDate) {
        flash_set('danger', 'Expiry date must be in YYYY-MM-DD format.');
        redirect($redirectUrl);
    }
} else {
    $expiryDate = null;
}

$isConfidential = !empty($_POST['is_confidential']) ? 1 : 0;
if ($isConfidential && !user_can('document.confidential', $companyId)) {
    flash_set('danger', 'You do not have permission to upload confidential documents (document.confidential required).');
    redirect($redirectUrl);
}

if (!isset($_FILES['document'])) {
    flash_set('danger', 'Please choose a document file to upload.');
    redirect($redirectUrl);
}

[$valid, $fileResult] = validate_employee_document_upload($_FILES['document']);
if (!$valid) {
    flash_set('danger', (string)$fileResult);
    redirect($redirectUrl);
}

$details = (array)$fileResult;
$destinationDir = STORAGE_PATH . '/uploads/employees';
if (!is_dir($destinationDir)) {
    mkdir($destinationDir, 0755, true);
}

$targetPath = $destinationDir . '/' . $details['stored_name'];

if (!move_uploaded_file($details['tmp_path'], $targetPath)) {
    flash_set('danger', 'Failed to save uploaded file to storage. Please check permissions.');
    redirect($redirectUrl);
}

// Save document record
$currentUserId = (int)current_user()['id'];
try {
    $st = $pdo->prepare(
        'INSERT INTO employee_documents (
            employee_id, company_id, doc_type, title, original_name, stored_name,
            mime_type, file_size, expiry_date, is_confidential, uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $st->execute([
        $empId,
        $companyId,
        $docType,
        $title,
        $details['original_name'],
        $details['stored_name'],
        $details['mime_type'],
        $details['file_size'],
        $expiryDate,
        $isConfidential,
        $currentUserId,
    ]);

    $docId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
    throw $e;
}

log_activity('document.upload', 'employee_document', $docId, [
    'employee_id'   => $empId,
    'employee_code' => $emp['employee_code'],
    'title'         => $title,
    'original_name' => $details['original_name'],
    'doc_type'      => $docType,
    'file_size'     => $details['file_size'],
    'confidential'  => (bool)$isConfidential,
], $companyId);

flash_set('success', "Document \"{$title}\" uploaded successfully.");
redirect($redirectUrl);
