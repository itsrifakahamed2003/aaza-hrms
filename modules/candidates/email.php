<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort(405, 'Method Not Allowed');
}

csrf_require();

$pdo = db();
$action        = strtolower(trim((string)($_POST['action'] ?? 'preview')));
$applicationId = (int)($_POST['application_id'] ?? 0);
$template      = trim((string)($_POST['template'] ?? ''));
$interviewId   = (int)($_POST['interview_id'] ?? 0);

$extra = [];
if ($interviewId > 0) {
    $extra['interview_id'] = $interviewId;
}

if ($action === 'preview') {
    header('Content-Type: application/json; charset=UTF-8');

    $preview = mail_build_candidate_template_preview($pdo, $applicationId, $template, $extra);
    if (empty($preview['ok'])) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => $preview['error'] ?? 'Unable to generate email preview.',
        ]);
        exit;
    }

    echo json_encode([
        'ok'              => true,
        'application_id'  => $applicationId,
        'template'        => $preview['template'],
        'to'              => $preview['to'],
        'candidate_name'  => $preview['candidate_name'],
        'company_name'    => $preview['company_name'],
        'subject'         => $preview['subject'],
        'body'            => $preview['text'],
        'text'            => $preview['text'],
        'html'            => $preview['html'],
        'mailto'          => $preview['mailto'],
        'mail_configured' => mail_is_configured(),
    ]);
    exit;
}

if ($action === 'send') {
    // Resolve fallback redirect target from application's candidate_id
    $fallbackPath = url('modules/candidates/index.php');
    if ($applicationId > 0) {
        $stApp = $pdo->prepare('SELECT candidate_id FROM candidate_applications WHERE id = ? LIMIT 1');
        $stApp->execute([$applicationId]);
        $candId = (int)$stApp->fetchColumn();
        if ($candId > 0) {
            $fallbackPath = url('modules/candidates/view.php?id=' . $candId);
        }
    }

    $rawRedirect = trim((string)($_POST['redirect_to'] ?? ''));
    if ($rawRedirect !== '' && !str_starts_with($rawRedirect, '/') && !str_contains($rawRedirect, '://')) {
        $rawRedirect = url($rawRedirect);
    }
    $returnPath = safe_return_path($rawRedirect, $fallbackPath);

    $customSubject = trim((string)($_POST['subject'] ?? ''));
    $customBody    = trim((string)($_POST['body'] ?? ''));

    if ($customSubject !== '') {
        $extra['custom_subject'] = $customSubject;
    }
    if ($customBody !== '') {
        $extra['custom_body'] = $customBody;
    }

    $res = mail_send_candidate_template($pdo, $applicationId, $template, $extra);

    if (!empty($res['ok'])) {
        flash_set('success', 'Email sent to candidate successfully.');
    } else {
        $errMsg = trim((string)($res['error'] ?? 'Unknown mail error'));
        flash_set('danger', 'Could not send email to candidate: ' . $errMsg);
    }

    redirect_path($returnPath);
}

abort(400, 'Invalid email action.');

