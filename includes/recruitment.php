<?php
declare(strict_types=1);

/**
 * AaZa Group HR - Recruitment module helpers.
 * Vacancies, candidates, applications, pipeline, and secure candidate documents.
 */

/**
 * Auto-generate next vacancy code for a company (e.g. AZT-VAC-0001).
 *
 * NOTE: Callers must lock the company row first inside a transaction:
 * SELECT id FROM companies WHERE id = ? FOR UPDATE
 */
function generate_vacancy_code(PDO $pdo, int $companyId): string
{
    $st = $pdo->prepare('SELECT code FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $companyCode = strtoupper(trim((string)$st->fetchColumn()));
    if ($companyCode === '') {
        $companyCode = 'VAC';
    }

    $prefix = $companyCode . '-VAC-';

    $st = $pdo->prepare(
        'SELECT vacancy_code FROM job_vacancies WHERE company_id = ? AND vacancy_code LIKE ?'
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
    return sprintf('%s%04d', $prefix, $nextNum);
}

/**
 * Vacancy statuses defined in schema:
 * ENUM('draft','open','on_hold','closed','cancelled')
 */
function vacancy_status_list(): array
{
    return [
        'draft'     => 'Draft',
        'open'      => 'Open',
        'on_hold'   => 'On Hold',
        'closed'    => 'Closed',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * Return Bootstrap badge HTML for vacancy status.
 */
function vacancy_status_badge(string $status): string
{
    return status_badge('vacancy', $status);
}

/**
 * Auto-generate next candidate code (e.g. CAN-000001) for the central talent pool.
 * Central pool is group-wide.
 */
function generate_candidate_code(PDO $pdo): string
{
    $prefix = 'CAN-';
    $st = $pdo->query("SELECT candidate_code FROM candidates WHERE candidate_code LIKE 'CAN-%' ORDER BY id DESC LIMIT 200");
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

    // Also check max id as a baseline safeguard
    $maxIdSt = $pdo->query('SELECT MAX(id) FROM candidates');
    $maxId = (int)$maxIdSt->fetchColumn();
    if ($maxId > $maxNum) {
        $maxNum = $maxId;
    }

    $nextNum = $maxNum + 1;
    return sprintf('%s%06d', $prefix, $nextNum);
}

/**
 * Candidate document types defined in schema:
 * ENUM('cv','certificate','id_document','other')
 */
function candidate_doc_type_list(): array
{
    return [
        'cv'          => 'Curriculum Vitae (CV)',
        'certificate' => 'Certificate / Qualification',
        'id_document' => 'ID Document / Passport',
        'other'       => 'Other Document',
    ];
}

/**
 * Candidate gender options.
 */
function candidate_gender_list(): array
{
    return [
        'male'   => 'Male',
        'female' => 'Female',
    ];
}

/**
 * Candidate source options.
 */
function candidate_source_list(): array
{
    return [
        'linkedin'        => 'LinkedIn',
        'job_portal'      => 'Job Portal / Website',
        'referral'        => 'Employee Referral',
        'company_website' => 'Careers Page / Direct',
        'walk_in'         => 'Walk-in',
        'agency'          => 'Recruitment Agency',
        'other'           => 'Other',
    ];
}

/**
 * The 6 Pipeline stages requested for per-vacancy pipeline view.
 */
function pipeline_stages(): array
{
    return [
        'applied'      => 'Applied',
        'under_review' => 'Under Review',
        'shortlisted'  => 'Shortlisted',
        'selected'     => 'Selected',
        'on_hold'      => 'On Hold',
        'rejected'     => 'Rejected',
    ];
}

/**
 * Manual statuses allowed for manual pipeline/status updates:
 * pipeline_stages() + 'withdrawn'.
 * interview_*, offer_*, and hired are reserved for later modules.
 */
function manual_status_list(): array
{
    $stages = pipeline_stages();
    $stages['withdrawn'] = 'Withdrawn';
    return $stages;
}

/**
 * Complete application statuses defined in schema:
 * ENUM('applied','under_review','shortlisted','interview_scheduled','interviewed',
 *      'selected','on_hold','rejected','offered','offer_accepted','offer_declined',
 *      'hired','withdrawn')
 */
function application_status_list(): array
{
    return [
        'applied'             => 'Applied',
        'under_review'        => 'Under Review',
        'shortlisted'         => 'Shortlisted',
        'interview_scheduled' => 'Interview Scheduled',
        'interviewed'         => 'Interviewed',
        'selected'            => 'Selected',
        'on_hold'             => 'On Hold',
        'rejected'            => 'Rejected',
        'offered'             => 'Offered',
        'offer_accepted'      => 'Offer Accepted',
        'offer_declined'      => 'Offer Declined',
        'hired'               => 'Hired',
        'withdrawn'           => 'Withdrawn',
    ];
}

/**
 * Return Bootstrap badge HTML for application status.
 */
function application_status_badge(string $status): string
{
    return status_badge('application', $status);
}

/**
 * Check whether the current user can access a candidate.
 * True for group admin or candidate.cross_company;
 * or if candidate is stored in $_SESSION['linkable_candidates'][$candidateId] (duplicate reuse flow);
 * otherwise candidate has an application in scoped_company_ids('candidate.view')
 * or created_by = current_user_id().
 */
function can_access_candidate(PDO $pdo, int $candidateId): bool
{
    if ($candidateId <= 0) {
        return false;
    }

    if (is_group_admin() || user_can('candidate.cross_company')) {
        return true;
    }

    if (!empty($_SESSION['linkable_candidates'][$candidateId])) {
        return true;
    }

    $scopedCompanyIds = scoped_company_ids('candidate.view');
    $currentUserId = current_user_id();

    if (!empty($scopedCompanyIds)) {
        $ph = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
        $st = $pdo->prepare(
            "SELECT 1 FROM candidates c
              WHERE c.id = ?
                AND (
                    c.created_by = ?
                    OR EXISTS (
                        SELECT 1 FROM candidate_applications ca
                         WHERE ca.candidate_id = c.id
                           AND ca.company_id IN ($ph)
                    )
                )
             LIMIT 1"
        );
        $st->execute(array_merge([$candidateId, $currentUserId], $scopedCompanyIds));
        return (bool)$st->fetchColumn();
    }

    $st = $pdo->prepare('SELECT 1 FROM candidates WHERE id = ? AND created_by = ? LIMIT 1');
    $st->execute([$candidateId, $currentUserId]);
    return (bool)$st->fetchColumn();
}

/**
 * Mask candidate email (e.g. j***@gmail.com).
 */
function mask_candidate_email(?string $email): string
{
    $email = trim((string)$email);
    if ($email === '' || !str_contains($email, '@')) {
        return '—';
    }
    [$name, $domain] = explode('@', $email, 2);
    $first = mb_substr($name, 0, 1);
    return ($first !== '' ? $first : '*') . '***@' . $domain;
}

/**
 * Mask candidate phone (keeping only last 3 digits, e.g. ***456).
 */
function mask_candidate_phone(?string $phone): string
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return '—';
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) >= 3) {
        return '***' . substr($digits, -3);
    }
    return '***' . substr($phone, -3);
}

/**
 * Check for duplicate candidates by email, phone, and nic_passport.
 *
 * @return array<int, array<string, mixed>> List of matching candidate records
 */
function check_candidate_duplicates(
    PDO $pdo,
    ?string $email,
    ?string $phone,
    ?string $nicPassport,
    ?int $excludeId = null
): array {
    $email = trim((string)$email);
    $phone = trim((string)$phone);
    $nic = trim((string)$nicPassport);

    $conditions = [];
    $params = [];

    if ($email !== '') {
        $conditions[] = 'LOWER(email) = LOWER(?)';
        $params[] = $email;
    }
    if ($phone !== '') {
        $conditions[] = 'phone = ?';
        $params[] = $phone;
    }
    if ($nic !== '') {
        $conditions[] = 'LOWER(nic_passport) = LOWER(?)';
        $params[] = $nic;
    }

    if (empty($conditions)) {
        return [];
    }

    $sql = 'SELECT id, candidate_code, first_name, last_name, email, phone, nic_passport, experience_years, created_at
              FROM candidates
             WHERE (' . implode(' OR ', $conditions) . ')';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }

    $sql .= ' ORDER BY id DESC LIMIT 10';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Validate an uploaded candidate document against allowed extensions, size, and real MIME types.
 * Same secure rules as employee documents in includes/employees.php.
 *
 * @param array $file $_FILES['document']
 * @return array{0: bool, 1: string|array} [true, details] or [false, errorMessage]
 */
function validate_candidate_document_upload(array $file): array
{
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
 * Transition application status with validation and status history logging.
 *
 * @throws RuntimeException on validation failure
 */
function update_candidate_application_status(
    PDO $pdo,
    int $applicationId,
    string $newStatus,
    ?string $remarks = null,
    ?int $changedBy = null
): array {
    $validStatuses = application_status_list();
    if (!isset($validStatuses[$newStatus])) {
        throw new InvalidArgumentException("Invalid application status: $newStatus");
    }

    $st = $pdo->prepare('SELECT id, candidate_id, company_id, vacancy_id, status FROM candidate_applications WHERE id = ? FOR UPDATE');
    $st->execute([$applicationId]);
    $app = $st->fetch();
    if (!$app) {
        throw new RuntimeException("Application #$applicationId not found.");
    }

    $oldStatus = (string)$app['status'];

    if ($remarks !== null) {
        $remarks = mb_substr(trim($remarks), 0, 255);
        if ($remarks === '') {
            $remarks = null;
        }
    }

    // Update application
    $upSt = $pdo->prepare('UPDATE candidate_applications SET status = ?, updated_at = NOW() WHERE id = ?');
    $upSt->execute([$newStatus, $applicationId]);

    // Record in candidate_status_history
    $histSt = $pdo->prepare(
        'INSERT INTO candidate_status_history (application_id, old_status, new_status, remarks, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $histSt->execute([$applicationId, $oldStatus, $newStatus, $remarks, $changedBy]);

    log_activity('candidate.status_change', 'candidate_application', $applicationId, [
        'candidate_id' => (int)$app['candidate_id'],
        'vacancy_id'   => (int)$app['vacancy_id'],
        'old_status'   => $oldStatus,
        'new_status'   => $newStatus,
        'remarks'      => $remarks,
    ], (int)$app['company_id']);

    return [
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'app'        => $app,
    ];
}

/**
 * Format a date string (YYYY-MM-DD) nicely for display.
 */
function format_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date || trim($date) === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : $date;
}

/**
 * Format a datetime timestamp nicely for display.
 */
function format_datetime(?string $datetime, string $format = 'd M Y, h:i A'): string
{
    if (!$datetime || trim($datetime) === '') {
        return '';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : $datetime;
}

/**
 * Count scheduled interviews for today, scoped by user company access.
 */
function count_today_interviews(PDO $pdo, ?int $companyId = null): int
{
    $today = date('Y-m-d');
    if ($companyId !== null) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM interviews
              WHERE company_id = ?
                AND DATE(scheduled_at) = ?
                AND status = 'scheduled'"
        );
        $st->execute([$companyId, $today]);
        return (int)$st->fetchColumn();
    }

    if (is_group_admin()) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM interviews WHERE DATE(scheduled_at) = ? AND status = 'scheduled'");
        $st->execute([$today]);
        return (int)$st->fetchColumn();
    }

    $scoped = scoped_company_ids('interview.view');
    if (empty($scoped)) {
        return 0;
    }
    [$sql, $params] = company_in_sql($scoped, 'company_id');
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM interviews
          WHERE $sql
            AND DATE(scheduled_at) = ?
            AND status = 'scheduled'"
    );
    $st->execute(array_merge($params, [$today]));
    return (int)$st->fetchColumn();
}

/**
 * Defined interview statuses: scheduled, completed, cancelled, no_show, rescheduled.
 */
function interview_status_list(): array
{
    return [
        'scheduled'   => 'Scheduled',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
        'no_show'     => 'No Show',
        'rescheduled' => 'Rescheduled',
    ];
}

/**
 * Return Bootstrap badge HTML for interview status.
 */
function interview_status_badge(string $status): string
{
    return status_badge('interview', $status);
}

/**
 * Defined interview types (phone, video, onsite, etc.).
 */
function interview_type_list(): array
{
    return [
        'phone'     => 'Phone',
        'video'     => 'Video Call',
        'onsite'    => 'On-Site',
        'hr'        => 'HR Round',
        'technical' => 'Technical Round',
        'final'     => 'Final Round',
        'other'     => 'Other',
    ];
}

/**
 * Defined recommendation options for interview evaluations.
 */
function evaluation_recommendation_list(): array
{
    return [
        'strong_hire' => 'Strong Hire',
        'hire'        => 'Hire',
        'hold'        => 'Hold',
        'reject'      => 'Reject',
    ];
}

/**
 * Return Bootstrap badge HTML for evaluation recommendation.
 */
function evaluation_recommendation_badge(string $rec): string
{
    return status_badge('interview', $rec);
}

/**
 * Permanently deletes a candidate document row and removes the file from disk.
 * Admin (group admin) is always allowed.
 *
 * @param int $docId
 * @param PDO $pdo
 * @return array{success: bool, message: string, candidate_id: int}
 */
function delete_candidate_document(int $docId, PDO $pdo): array
{
    if ($docId <= 0) {
        return ['success' => false, 'message' => 'Invalid document ID.', 'candidate_id' => 0];
    }

    $st = $pdo->prepare('SELECT id, candidate_id, original_name, stored_name FROM candidate_documents WHERE id = ? LIMIT 1');
    $st->execute([$docId]);
    $doc = $st->fetch();

    if (!$doc) {
        return ['success' => false, 'message' => 'Document not found.', 'candidate_id' => 0];
    }

    $candId     = (int)$doc['candidate_id'];
    $storedName = (string)$doc['stored_name'];
    $origName   = (string)$doc['original_name'];

    // Access control: Group Admin or document.delete / candidate.manage
    if (!is_group_admin() && !user_can('document.delete')) {
        return ['success' => false, 'message' => 'Permission denied: only administrators can delete documents.', 'candidate_id' => $candId];
    }

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('DELETE FROM candidate_documents WHERE id = ?')->execute([$docId]);

        log_activity('candidate.document_delete', 'candidate_documents', $docId, [
            'original_name' => $origName,
            'candidate_id'  => $candId,
        ]);

        if (!$inTransaction) {
            $pdo->commit();
        }

        // Remove file from disk after successful DB delete
        $filePath = STORAGE_PATH . '/uploads/candidates/' . $storedName;
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        return [
            'success'      => true,
            'message'      => "Document '{$origName}' was permanently deleted.",
            'candidate_id' => $candId,
        ];
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete candidate document {$docId}: " . $e->getMessage());
        return [
            'success'      => false,
            'message'      => 'Failed to delete document: ' . $e->getMessage(),
            'candidate_id' => $candId,
        ];
    }
}

/**
 * Check if a candidate can be safely deleted.
 * Rules:
 * - Must not have any application with status = 'hired' (converted to employee)
 * - Must not have any active/in-progress applications (applied, under_review, shortlisted, selected, offered)
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, candidate: ?array}
 */
function check_candidate_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return ['can_delete' => false, 'reason' => 'Invalid candidate ID.', 'candidate' => null];
    }

    $st = $pdo->prepare('SELECT * FROM candidates WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $cand = $st->fetch();

    if (!$cand) {
        return ['can_delete' => false, 'reason' => 'Candidate record not found.', 'candidate' => null];
    }

    // 1. Check if converted to employee (status = 'hired')
    $stHired = $pdo->prepare("SELECT COUNT(*) FROM candidate_applications WHERE candidate_id = ? AND status = 'hired'");
    $stHired->execute([$id]);
    if ((int)$stHired->fetchColumn() > 0) {
        return [
            'can_delete' => false,
            'reason'     => 'Cannot delete candidate: Application was hired and converted into an employee record.',
            'candidate'  => $cand,
        ];
    }

    // 2. Check for active/in-progress applications
    $stActive = $pdo->prepare(
        "SELECT COUNT(*) FROM candidate_applications
          WHERE candidate_id = ?
            AND status IN ('applied', 'under_review', 'shortlisted', 'selected', 'offered')"
    );
    $stActive->execute([$id]);
    $activeCount = (int)$stActive->fetchColumn();
    if ($activeCount > 0) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete candidate: Has {$activeCount} active recruitment application(s). Reject or withdraw them first.",
            'candidate'  => $cand,
        ];
    }

    return ['can_delete' => true, 'reason' => '', 'candidate' => $cand];
}

/**
 * Safely delete a candidate record and clean up associated applications, interviews, and document files.
 *
 * @param int $id
 * @param PDO $pdo
 * @param int $deletedBy
 * @return array{success: bool, message: string}
 */
function delete_candidate(int $id, PDO $pdo, int $deletedBy): array
{
    $chk = check_candidate_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $cand = $chk['candidate'];
    $filesToDelete = [];

    // Collect candidate documents
    $stDocs = $pdo->prepare("SELECT stored_name FROM candidate_documents WHERE candidate_id = ?");
    $stDocs->execute([$id]);
    foreach ($stDocs->fetchAll() as $d) {
        if (!empty($d['stored_name'])) {
            $filesToDelete[] = STORAGE_PATH . '/uploads/candidates/' . $d['stored_name'];
        }
    }

    // Collect resume file if any
    if (!empty($cand['resume_path'])) {
        $filesToDelete[] = STORAGE_PATH . '/uploads/resumes/' . $cand['resume_path'];
        $filesToDelete[] = STORAGE_PATH . '/uploads/candidates/' . $cand['resume_path'];
    }

    $pdo->beginTransaction();
    try {
        // Collect applications
        $stApps = $pdo->prepare("SELECT id FROM candidate_applications WHERE candidate_id = ?");
        $stApps->execute([$id]);
        $appIds = $stApps->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($appIds)) {
            $phApps = implode(',', array_fill(0, count($appIds), '?'));

            // Find interviews
            $stInts = $pdo->prepare("SELECT id FROM interviews WHERE application_id IN ($phApps)");
            $stInts->execute($appIds);
            $intIds = $stInts->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($intIds)) {
                $phInts = implode(',', array_fill(0, count($intIds), '?'));
                $pdo->prepare("DELETE FROM interview_evaluations WHERE interview_id IN ($phInts)")->execute($intIds);
                $pdo->prepare("DELETE FROM interview_panel WHERE interview_id IN ($phInts)")->execute($intIds);
                $pdo->prepare("DELETE FROM interviews WHERE id IN ($phInts)")->execute($intIds);
            }

            // Delete job offers
            $pdo->prepare("DELETE FROM job_offers WHERE application_id IN ($phApps)")->execute($appIds);

            // Delete applications
            $pdo->prepare("DELETE FROM candidate_applications WHERE candidate_id = ?")->execute([$id]);
        }

        // Delete candidate documents
        $pdo->prepare("DELETE FROM candidate_documents WHERE candidate_id = ?")->execute([$id]);

        // Delete candidate row
        $pdo->prepare("DELETE FROM candidates WHERE id = ?")->execute([$id]);

        log_activity('candidate.delete', 'candidate', $id, [
            'name'  => $cand['first_name'] . ' ' . $cand['last_name'],
            'email' => $cand['email'],
        ], null, $deletedBy);

        $pdo->commit();

        // Physical file unlink after commit
        foreach ($filesToDelete as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        return ['success' => true, 'message' => "Candidate '{$cand['first_name']} {$cand['last_name']}' was permanently deleted."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete candidate {$id}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete candidate: ' . $e->getMessage()];
    }
}

/**
 * Check if a vacancy can be safely deleted.
 * Rules:
 * - Status must be 'closed' or 'cancelled' (not 'open' or 'draft')
 * - Must not have any active or hired applications
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, vacancy: ?array}
 */
function check_vacancy_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return ['can_delete' => false, 'reason' => 'Invalid vacancy ID.', 'vacancy' => null];
    }

    $st = $pdo->prepare('SELECT * FROM job_vacancies WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $vac = $st->fetch();

    if (!$vac) {
        return ['can_delete' => false, 'reason' => 'Job vacancy record not found.', 'vacancy' => null];
    }

    // 1. Status check: must be closed or cancelled
    if (!in_array($vac['status'], ['closed', 'cancelled'], true)) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete an active vacancy (status: {$vac['status']}). Close or cancel the vacancy first.",
            'vacancy'    => $vac,
        ];
    }

    // 2. Active or hired applications check
    $stActive = $pdo->prepare(
        "SELECT COUNT(*) FROM candidate_applications
          WHERE vacancy_id = ?
            AND status IN ('hired', 'applied', 'under_review', 'shortlisted', 'selected', 'offered')"
    );
    $stActive->execute([$id]);
    $activeCount = (int)$stActive->fetchColumn();
    if ($activeCount > 0) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete vacancy: Has {$activeCount} active or hired candidate application(s). Reject or clear them first.",
            'vacancy'    => $vac,
        ];
    }

    return ['can_delete' => true, 'reason' => '', 'vacancy' => $vac];
}

/**
 * Safely delete a closed/cancelled job vacancy and clean up any remaining terminal applications.
 *
 * @param int $id
 * @param PDO $pdo
 * @param int $deletedBy
 * @return array{success: bool, message: string}
 */
function delete_vacancy(int $id, PDO $pdo, int $deletedBy): array
{
    $chk = check_vacancy_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $vac = $chk['vacancy'];

    $pdo->beginTransaction();
    try {
        // Collect remaining rejected/withdrawn applications
        $stApps = $pdo->prepare("SELECT id FROM candidate_applications WHERE vacancy_id = ?");
        $stApps->execute([$id]);
        $appIds = $stApps->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($appIds)) {
            $phApps = implode(',', array_fill(0, count($appIds), '?'));

            $stInts = $pdo->prepare("SELECT id FROM interviews WHERE application_id IN ($phApps)");
            $stInts->execute($appIds);
            $intIds = $stInts->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($intIds)) {
                $phInts = implode(',', array_fill(0, count($intIds), '?'));
                $pdo->prepare("DELETE FROM interview_evaluations WHERE interview_id IN ($phInts)")->execute($intIds);
                $pdo->prepare("DELETE FROM interview_panel WHERE interview_id IN ($phInts)")->execute($intIds);
                $pdo->prepare("DELETE FROM interviews WHERE id IN ($phInts)")->execute($intIds);
            }

            $pdo->prepare("DELETE FROM job_offers WHERE application_id IN ($phApps)")->execute($appIds);
            $pdo->prepare("DELETE FROM candidate_applications WHERE vacancy_id = ?")->execute([$id]);
        }

        $pdo->prepare("DELETE FROM job_vacancies WHERE id = ?")->execute([$id]);

        log_activity('vacancy.delete', 'job_vacancies', $id, [
            'title'        => $vac['title'],
            'vacancy_code' => $vac['vacancy_code'],
            'company_id'   => $vac['company_id'],
        ], (int)$vac['company_id'], $deletedBy);

        $pdo->commit();
        return ['success' => true, 'message' => "Job vacancy '{$vac['title']}' ({$vac['vacancy_code']}) was permanently deleted."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete vacancy {$id}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete vacancy: ' . $e->getMessage()];
    }
}

/**
 * Check if an interview can be safely deleted.
 * Rules:
 * - Status must be 'cancelled' or 'completed'
 * - Active scheduled sessions must be cancelled first
 *
 * @param int $id
 * @param PDO $pdo
 * @return array{can_delete: bool, reason: string, interview: ?array}
 */
function check_interview_deletable(int $id, PDO $pdo): array
{
    if ($id <= 0) {
        return ['can_delete' => false, 'reason' => 'Invalid interview ID.', 'interview' => null];
    }

    $st = $pdo->prepare('SELECT * FROM interviews WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $intv = $st->fetch();

    if (!$intv) {
        return ['can_delete' => false, 'reason' => 'Interview session not found.', 'interview' => null];
    }

    if (in_array($intv['status'], ['scheduled', 'rescheduled'], true)) {
        return [
            'can_delete' => false,
            'reason'     => "Cannot delete an active scheduled interview. Please cancel the interview session first.",
            'interview'  => $intv,
        ];
    }

    return ['can_delete' => true, 'reason' => '', 'interview' => $intv];
}

/**
 * Safely delete an interview session and its panel members and evaluations.
 *
 * @param int $id
 * @param PDO $pdo
 * @param int $deletedBy
 * @return array{success: bool, message: string}
 */
function delete_interview(int $id, PDO $pdo, int $deletedBy): array
{
    $chk = check_interview_deletable($id, $pdo);
    if (!$chk['can_delete']) {
        return ['success' => false, 'message' => $chk['reason']];
    }

    $intv = $chk['interview'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM interview_evaluations WHERE interview_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM interview_panel WHERE interview_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM interviews WHERE id = ?")->execute([$id]);

        log_activity('interview.delete', 'interviews', $id, [
            'round_no'       => $intv['round_no'],
            'application_id' => $intv['application_id'],
            'company_id'     => $intv['company_id'],
        ], (int)$intv['company_id'], $deletedBy);

        $pdo->commit();
        return ['success' => true, 'message' => "Interview session was permanently deleted."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete interview {$id}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete interview: ' . $e->getMessage()];
    }
}


