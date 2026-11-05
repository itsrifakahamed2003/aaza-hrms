<?php
declare(strict_types=1);

/**
 * AaZa Group HR - Employee module helpers.
 * Code generation, status formatting, document validation, and scoped queries.
 */

/**
 * Auto-generate next employee code for a company (e.g., AZT-0001).
 * Uses company code as prefix and increments the highest numerical suffix.
 *
 * NOTE: Callers must lock the company row first inside a transaction using:
 * SELECT id FROM companies WHERE id = ? FOR UPDATE
 */
function generate_employee_code(PDO $pdo, int $companyId): string
{
    $st = $pdo->prepare('SELECT code FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $companyCode = strtoupper(trim((string)$st->fetchColumn()));
    if ($companyCode === '') {
        $companyCode = 'EMP';
    }

    $customPrefix = function_exists('get_company_setting')
        ? get_company_setting($pdo, $companyId, 'employee.code_prefix', null)
        : null;
    $prefixCode = !empty($customPrefix) ? strtoupper(trim((string)$customPrefix)) : $companyCode;
    $prefix = $prefixCode . '-';

    $padding = function_exists('get_company_setting')
        ? (int)get_company_setting($pdo, $companyId, 'employee.code_padding', 4)
        : 4;
    if ($padding < 3 || $padding > 8) {
        $padding = 4;
    }

    // Query existing codes starting with prefix
    $st = $pdo->prepare(
        'SELECT employee_code FROM employees WHERE company_id = ? AND employee_code LIKE ?'
    );
    $st->execute([$companyId, $prefix . '%']);

    $maxNum = 0;
    while ($code = $st->fetchColumn()) {
        $suffix = substr((string)$code, strlen($prefix));
        if (ctype_digit($suffix)) {
            $num = (int)$suffix;
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    $nextNum = $maxNum + 1;
    return sprintf('%s%0' . $padding . 'd', $prefix, $nextNum);
}

/**
 * Employment statuses defined in schema:
 * ENUM('active','on_leave','transferred','resigned','terminated','retired')
 */
function employee_status_list(): array
{
    return [
        'active'      => 'Active',
        'on_leave'    => 'On Leave',
        'transferred' => 'Transferred',
        'resigned'    => 'Resigned',
        'terminated'  => 'Terminated',
        'retired'     => 'Retired',
    ];
}

/**
 * Return Bootstrap badge HTML for employee status.
 */
function employee_status_badge(string $status): string
{
    return status_badge('employee', $status);
}

/**
 * Employment types defined in schema:
 * ENUM('full_time','part_time','contract','intern','probation')
 */
function employment_type_list(): array
{
    return [
        'full_time' => 'Full Time',
        'part_time' => 'Part Time',
        'contract'  => 'Contract',
        'intern'    => 'Intern',
        'probation' => 'Probation',
    ];
}

/**
 * Document types defined in schema:
 * ENUM('cv','appointment_letter','agreement','education_certificate','id_document','training_certificate','other')
 */
function employee_doc_type_list(): array
{
    return [
        'cv'                    => 'Curriculum Vitae (CV)',
        'appointment_letter'    => 'Appointment Letter',
        'agreement'             => 'Employment Agreement / Contract',
        'education_certificate' => 'Educational Certificate',
        'id_document'           => 'National ID / Passport',
        'training_certificate'  => 'Training Certificate',
        'other'                 => 'Other Supporting Document',
    ];
}

/**
 * Validate an uploaded employee document against allowed extensions, size, and real MIME types.
 *
 * @param array $file $_FILES['document']
 * @return array{0: bool, 1: string|array} [true, details] or [false, errorMessage]
 */
function validate_employee_document_upload(array $file): array
{
    // Handle $_FILES['document'] being an array (multiple files) safely
    if (isset($file['name']) && is_array($file['name'])) {
        $firstKey = array_key_first($file['name']);
        $file = [
            'name'     => $file['name'][$firstKey] ?? '',
            'type'     => $file['type'][$firstKey] ?? '',
            'tmp_name' => $file['tmp_name'][$firstKey] ?? '',
            'error'    => $file['error'][$firstKey] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $file['size'][$firstKey] ?? 0,
        ];
    } elseif (isset($file[0]) && is_array($file[0])) {
        $file = $file[0];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the maximum allowed file size.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select a file to upload.',
            default => 'File upload failed with error code ' . ($file['error'] ?? 'unknown'),
        };
        return [false, $err];
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return [false, 'File size must be between 1 byte and 5 MB.'];
    }

    $originalName = (string)($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];
    if (!in_array($ext, $allowedExts, true)) {
        return [false, 'Only PDF, JPG, PNG, and DOCX files are allowed.'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return [false, 'Uploaded temporary file could not be read.'];
    }

    // Check real MIME type using finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string)$finfo->file($tmpPath);

    $mimeAllowed = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ];

    $expectedMimes = $mimeAllowed[$ext] ?? [];
    if (!in_array($realMime, $expectedMimes, true)) {
        return [false, "Invalid file format detected ($realMime). Please upload a valid $ext file."];
    }

    // For docx, additionally open the file with ZipArchive and require word/document.xml and [Content_Types].xml
    if ($ext === 'docx') {
        $zip = new ZipArchive();
        $res = $zip->open($tmpPath);
        if ($res !== true) {
            return [false, 'The uploaded DOCX file is not a valid archive.'];
        }
        $hasDoc = ($zip->locateName('word/document.xml') !== false);
        $hasContentTypes = ($zip->locateName('[Content_Types].xml') !== false);
        $zip->close();

        if (!$hasDoc || !$hasContentTypes) {
            return [false, 'Invalid DOCX structure: missing word/document.xml or [Content_Types].xml.'];
        }
    }

    // Generate random filename to store outside public
    $randomName = bin2hex(random_bytes(16)) . '.' . $ext;

    return [
        true,
        [
            'stored_name'   => $randomName,
            'original_name' => $originalName,
            'mime_type'     => $realMime,
            'file_size'     => $size,
            'tmp_path'      => $tmpPath,
        ],
    ];
}

/**
 * Permanently deletes an employee document row and removes the file from disk.
 * Admin (group admin) is always allowed.
 *
 * @param int $docId
 * @param PDO $pdo
 * @return array{success: bool, message: string, employee_id: int}
 */
function delete_employee_document(int $docId, PDO $pdo): array
{
    if ($docId <= 0) {
        return ['success' => false, 'message' => 'Invalid document ID.', 'employee_id' => 0];
    }

    $st = $pdo->prepare('SELECT id, employee_id, company_id, original_name, stored_name FROM employee_documents WHERE id = ? LIMIT 1');
    $st->execute([$docId]);
    $doc = $st->fetch();

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.', 'employee_id' => 0];
    }

    $empId      = (int)$doc['employee_id'];
    $companyId  = (int)$doc['company_id'];
    $storedName = (string)$doc['stored_name'];
    $origName   = (string)$doc['original_name'];

    // Access control: Group Admin or document.delete permission
    if (!is_group_admin() && !user_can('document.delete', $companyId)) {
        return ['success' => false, 'message' => 'Permission denied: only administrators can delete documents.', 'employee_id' => $empId];
    }

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('DELETE FROM employee_documents WHERE id = ?')->execute([$docId]);

        log_activity('employee.document_delete', 'employee_documents', $docId, [
            'original_name' => $origName,
            'employee_id'   => $empId,
        ], $companyId);

        if (!$inTransaction) {
            $pdo->commit();
        }

        // Remove file from disk after successful DB delete
        $filePath = STORAGE_PATH . '/uploads/employees/' . $storedName;
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        return [
            'success'     => true,
            'message'     => "Document '{$origName}' was permanently deleted.",
            'employee_id' => $empId,
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete employee document {$docId}: " . $e->getMessage());
        return [
            'success'     => false,
            'message'     => 'Failed to delete document: ' . $e->getMessage(),
            'employee_id' => $empId,
        ];
    }
}

/**
 * Check if an employee record can be safely deleted.
 * Rules:
 * - Employment status must be 'terminated' or 'resigned' (not active, probation, suspended)
 * - Must not be a reporting manager for any active/probation employees
 * - Must not be linked to an active user account
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, employee: ?array}
 */
function check_employee_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return ['can_delete' => false, 'reason' => 'Invalid employee ID.', 'employee' => null];
    }

    $st = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $emp = $st->fetch();

    if (!$emp) {
        return ['can_delete' => false, 'reason' => 'Employee record not found.', 'employee' => null];
    }

    // 1. Status rule: must be terminated or resigned
    if (!in_array($emp['employment_status'], ['terminated', 'resigned'], true)) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete an active employee (status: {$emp['employment_status']}). Employee must be marked as terminated or resigned first.",
            'employee'   => $emp,
        ];
    }

    // 2. Reporting manager check
    $stReports = $pdo->prepare(
        "SELECT COUNT(*) FROM employees
          WHERE reporting_manager_id = ?
            AND employment_status IN ('active', 'probation')"
    );
    $stReports->execute([$id]);
    $reportCount = (int)$stReports->fetchColumn();
    if ($reportCount > 0) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete employee: Still assigned as reporting manager for {$reportCount} active staff. Reassign them first.",
            'employee'   => $emp,
        ];
    }

    // 3. Active linked user account check
    if (!empty($emp['user_id'])) {
        $stUser = $pdo->prepare("SELECT id, username, is_active FROM users WHERE id = ? LIMIT 1");
        $stUser->execute([$emp['user_id']]);
        $u = $stUser->fetch();
        if ($u && (int)$u['is_active'] === 1) {
            return [
                'can_delete' => false,
                'reason'     => "Cannot delete employee: Linked to active user account '@{$u['username']}'. Deactivate or unlink the user account first.",
                'employee'   => $emp,
            ];
        }
    }

    return ['can_delete' => true, 'reason' => '', 'employee' => $emp];
}

/**
 * Safely delete a terminated/resigned employee and clean up all dependent history,
 * leaves, attendance, evaluations, and document files.
 *
 * @param int $id
 * @param PDO $pdo
 * @param int $deletedBy
 * @return array{success: bool, message: string}
 */
function delete_employee(int $id, PDO $pdo, int $deletedBy): array
{
    $chk = check_employee_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $emp = $chk['employee'];
    $companyId = (int)$emp['company_id'];
    $filesToDelete = [];

    // Collect document files
    $stDocs = $pdo->prepare("SELECT stored_name FROM employee_documents WHERE employee_id = ?");
    $stDocs->execute([$id]);
    foreach ($stDocs->fetchAll() as $d) {
        if (!empty($d['stored_name'])) {
            $filesToDelete[] = STORAGE_PATH . '/uploads/employees/' . $d['stored_name'];
        }
    }

    $pdo->beginTransaction();
    try {
        // Delete performance goals and reviews
        $pdo->prepare("DELETE FROM performance_goals WHERE employee_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM performance_reviews WHERE employee_id = ?")->execute([$id]);

        // Delete leave approvals for employee requests
        $pdo->prepare(
            "DELETE FROM leave_approvals WHERE leave_request_id IN (SELECT id FROM leave_requests WHERE employee_id = ?)"
        )->execute([$id]);
        $pdo->prepare("DELETE FROM leave_requests WHERE employee_id = ?")->execute([$id]);

        // Delete attendance
        $pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$id]);

        // Delete employment history and transfers
        $pdo->prepare("DELETE FROM employment_history WHERE employee_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM employee_transfers WHERE employee_id = ?")->execute([$id]);

        // Delete employee documents
        $pdo->prepare("DELETE FROM employee_documents WHERE employee_id = ?")->execute([$id]);

        // Clear reporting_manager_id on any other employees pointing to this employee
        $pdo->prepare("UPDATE employees SET reporting_manager_id = NULL WHERE reporting_manager_id = ?")->execute([$id]);

        // Delete employee row
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);

        log_activity('employee.delete', 'employees', $id, [
            'name'          => $emp['first_name'] . ' ' . $emp['last_name'],
            'employee_code' => $emp['employee_code'],
            'company_id'    => $companyId,
        ], $companyId, $deletedBy);

        $pdo->commit();

        // Remove disk files after commit
        foreach ($filesToDelete as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        return ['success' => true, 'message' => "Employee '{$emp['first_name']} {$emp['last_name']}' ({$emp['employee_code']}) was permanently deleted."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete employee {$id}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete employee: ' . $e->getMessage()];
    }
}

