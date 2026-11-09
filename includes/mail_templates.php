<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Candidate Notification Email Templates & Dispatcher.
 *
 * Security rules:
 * - Every variable interpolated into HTML is escaped with e().
 * - Responsive inline-CSS layout (max-width: 600px), zero external images or scripts.
 * - Includes plain-text fallback for every template.
 * - Enforces company-scoped RBAC (candidate.manage / interview.schedule) and rate limiting.
 */

/**
 * Wrap inner HTML body content in a responsive 600px inline-CSS email layout.
 * All dynamic strings ($companyName, $heading) are escaped with e().
 */
function mail_render_html_layout(string $companyName, string $heading, string $innerHtml): string
{
    $escCompany = e($companyName);
    $escHeading = e($heading);
    $footerLine = 'This is an automated message from ' . $escCompany . ' HR.';

    return '<!DOCTYPE html>'
        . '<html lang="en">'
        . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $escHeading . '</title></head>'
        . '<body style="margin:0;padding:24px 12px;background-color:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;line-height:1.6;">'
        . '<div style="max-width:600px;margin:0 auto;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">'
        . '<div style="background-color:#0f172a;color:#ffffff;padding:20px 24px;border-bottom:3px solid #2563eb;">'
        . '<div style="font-size:18px;font-weight:700;letter-spacing:0.2px;">' . $escCompany . '</div>'
        . '<div style="font-size:13px;color:#cbd5e1;margin-top:2px;">Human Resources &amp; Talent Acquisition</div>'
        . '</div>'
        . '<div style="padding:24px;">'
        . '<h2 style="margin:0 0 16px 0;font-size:18px;font-weight:600;color:#0f172a;">' . $escHeading . '</h2>'
        . $innerHtml
        . '</div>'
        . '<div style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:16px 24px;font-size:12px;color:#64748b;text-align:center;">'
        . $footerLine
        . '</div>'
        . '</div>'
        . '</body></html>';
}

/**
 * Unpack common candidate template arguments whether passed positionally or as an array.
 *
 * @return array{candidate_name: string, vacancy_title: string, company_name: string, hr_sender_name: string}
 */
function mail_unpack_base_args(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team'
): array {
    if (is_array($candidateName)) {
        return [
            'candidate_name' => (string)($candidateName['candidate_name'] ?? $candidateName['candidate'] ?? 'Candidate'),
            'vacancy_title'  => (string)($candidateName['vacancy_title'] ?? $candidateName['vacancy'] ?? ''),
            'company_name'   => (string)($candidateName['company_name'] ?? $candidateName['company'] ?? 'Company'),
            'hr_sender_name' => (string)($candidateName['hr_sender_name'] ?? $candidateName['hr_sender'] ?? 'HR Team'),
        ];
    }
    return [
        'candidate_name' => $candidateName,
        'vacancy_title'  => $vacancyTitle,
        'company_name'   => $companyName,
        'hr_sender_name' => $hrSenderName !== '' ? $hrSenderName : 'HR Team',
    ];
}

/**
 * 1. Application Received Template
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_application_received(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team'
): array {
    $args = mail_unpack_base_args($candidateName, $vacancyTitle, $companyName, $hrSenderName);
    $cand = $args['candidate_name'];
    $vac  = $args['vacancy_title'];
    $comp = $args['company_name'];
    $hr   = $args['hr_sender_name'];

    $subject = "Application Received: {$vac} - {$comp}";

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 12px 0;">Thank you for applying for the position of <strong>' . e($vac) . '</strong> at <strong>' . e($comp) . '</strong>.</p>'
        . '<p style="margin:0 0 16px 0;">We have received your application and our recruitment team will review your profile carefully. If your qualifications match our requirements, we will contact you regarding the next steps.</p>'
        . '<p style="margin:0;">Best regards,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Application Received', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "Thank you for applying for the position of {$vac} at {$comp}.\n\n"
        . "We have received your application and our recruitment team will review your profile carefully. "
        . "If your qualifications match our requirements, we will contact you regarding the next steps.\n\n"
        . "Best regards,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * 2. Shortlisted Template
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_shortlisted(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team'
): array {
    $args = mail_unpack_base_args($candidateName, $vacancyTitle, $companyName, $hrSenderName);
    $cand = $args['candidate_name'];
    $vac  = $args['vacancy_title'];
    $comp = $args['company_name'];
    $hr   = $args['hr_sender_name'];

    $subject = "Application Shortlisted: {$vac} - {$comp}";

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 12px 0;">We are pleased to inform you that your application for the role of <strong>' . e($vac) . '</strong> at <strong>' . e($comp) . '</strong> has been <strong>shortlisted</strong>.</p>'
        . '<p style="margin:0 0 16px 0;">Our HR team will be in touch with you shortly to coordinate the interview schedule and share further details.</p>'
        . '<p style="margin:0;">Best regards,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Your Application Has Been Shortlisted', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "We are pleased to inform you that your application for the role of {$vac} at {$comp} has been shortlisted.\n\n"
        . "Our HR team will be in touch with you shortly to coordinate the interview schedule and share further details.\n\n"
        . "Best regards,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * 3. Interview Invite Template
 * Includes: vacancy, date, time, interview type, location/meeting link, interviewer names, HR contact.
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_interview_invite(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team',
    string|array $date = '',
    string $time = '',
    string $interviewType = 'Interview',
    string $locationOrLink = '',
    string $interviewerNames = '',
    string $hrContact = ''
): array {
    if (is_array($candidateName)) {
        $d = $candidateName;
        $cand = (string)($d['candidate_name'] ?? $d['candidate'] ?? 'Candidate');
        $vac  = (string)($d['vacancy_title'] ?? $d['vacancy'] ?? '');
        $comp = (string)($d['company_name'] ?? $d['company'] ?? 'Company');
        $hr   = (string)($d['hr_sender_name'] ?? $d['hr_sender'] ?? 'HR Team');
        $intDate = (string)($d['date'] ?? '');
        $intTime = (string)($d['time'] ?? '');
        $intType = (string)($d['interview_type'] ?? $d['type'] ?? 'Interview');
        $loc     = (string)($d['location'] ?? $d['location_or_link'] ?? $d['meeting_link'] ?? '');
        $panel   = (string)($d['interviewer_names'] ?? $d['interviewers'] ?? '');
        $contact = (string)($d['hr_contact'] ?? $d['contact'] ?? '');
    } elseif (is_array($date)) {
        $d = $date;
        $cand = $candidateName;
        $vac  = $vacancyTitle;
        $comp = $companyName;
        $hr   = $hrSenderName !== '' ? $hrSenderName : 'HR Team';
        $intDate = (string)($d['date'] ?? '');
        $intTime = (string)($d['time'] ?? '');
        $intType = (string)($d['interview_type'] ?? $d['type'] ?? 'Interview');
        $loc     = (string)($d['location'] ?? $d['location_or_link'] ?? $d['meeting_link'] ?? '');
        $panel   = (string)($d['interviewer_names'] ?? $d['interviewers'] ?? '');
        $contact = (string)($d['hr_contact'] ?? $d['contact'] ?? '');
    } else {
        $cand    = $candidateName;
        $vac     = $vacancyTitle;
        $comp    = $companyName;
        $hr      = $hrSenderName !== '' ? $hrSenderName : 'HR Team';
        $intDate = $date;
        $intTime = $time;
        $intType = $interviewType !== '' ? $interviewType : 'Interview';
        $loc     = $locationOrLink;
        $panel   = $interviewerNames;
        $contact = $hrContact;
    }

    $subject = "Interview Invitation: {$vac} at {$comp}";

    $detailsRowsHtml = ''
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;width:150px;">Vacancy</td><td style="padding:6px 10px;color:#0f172a;">' . e($vac) . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">Date</td><td style="padding:6px 10px;color:#0f172a;">' . e($intDate !== '' ? $intDate : 'TBD') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">Time</td><td style="padding:6px 10px;color:#0f172a;">' . e($intTime !== '' ? $intTime : 'TBD') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">Interview Type</td><td style="padding:6px 10px;color:#0f172a;">' . e($intType) . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">Location / Link</td><td style="padding:6px 10px;color:#0f172a;">' . e($loc !== '' ? $loc : 'To be shared by HR') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">Interviewer(s)</td><td style="padding:6px 10px;color:#0f172a;">' . e($panel !== '' ? $panel : 'Interview Panel') . '</td></tr>'
        . '<tr><td style="padding:6px 10px;font-weight:600;color:#475569;">HR Contact</td><td style="padding:6px 10px;color:#0f172a;">' . e($contact !== '' ? $contact : $hr) . '</td></tr>';

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 14px 0;">You are invited to attend an interview for the position of <strong>' . e($vac) . '</strong> at <strong>' . e($comp) . '</strong>. Please find the interview schedule details below:</p>'
        . '<table style="width:100%;border-collapse:collapse;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin:0 0 16px 0;font-size:14px;">'
        . $detailsRowsHtml
        . '</table>'
        . '<p style="margin:0 0 16px 0;">Please be available 5–10 minutes prior to the scheduled start time. If you have any questions, please reach out to the HR contact listed above.</p>'
        . '<p style="margin:0;">Best regards,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Interview Invitation', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "You are invited to attend an interview for the position of {$vac} at {$comp}.\n\n"
        . "Interview Details:\n"
        . "- Vacancy: {$vac}\n"
        . "- Date: " . ($intDate !== '' ? $intDate : 'TBD') . "\n"
        . "- Time: " . ($intTime !== '' ? $intTime : 'TBD') . "\n"
        . "- Interview Type: {$intType}\n"
        . "- Location / Link: " . ($loc !== '' ? $loc : 'To be shared by HR') . "\n"
        . "- Interviewer(s): " . ($panel !== '' ? $panel : 'Interview Panel') . "\n"
        . "- HR Contact: " . ($contact !== '' ? $contact : $hr) . "\n\n"
        . "Best regards,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * 4. Selected Template (Next Steps)
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_selected(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team',
    string $nextSteps = ''
): array {
    $args = mail_unpack_base_args($candidateName, $vacancyTitle, $companyName, $hrSenderName);
    $cand = $args['candidate_name'];
    $vac  = $args['vacancy_title'];
    $comp = $args['company_name'];
    $hr   = $args['hr_sender_name'];
    if (is_array($candidateName) && isset($candidateName['next_steps'])) {
        $nextSteps = (string)$candidateName['next_steps'];
    }
    if ($nextSteps === '') {
        $nextSteps = 'Our HR team is preparing your formal employment offer and will contact you shortly regarding onboarding documentation and joining formalities.';
    }

    $subject = "Congratulations! Selected for {$vac} - {$comp}";

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 12px 0;">Congratulations! Following your recent interviews, we are delighted to inform you that you have been <strong>selected</strong> for the position of <strong>' . e($vac) . '</strong> at <strong>' . e($comp) . '</strong>.</p>'
        . '<div style="background-color:#f0fdf4;border-left:4px solid #16a34a;padding:12px 16px;margin:0 0 16px 0;color:#14532d;font-size:14px;">'
        . '<strong>Next Steps:</strong><br>' . e($nextSteps)
        . '</div>'
        . '<p style="margin:0;">Warm regards,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Selection Notification & Next Steps', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "Congratulations! Following your recent interviews, we are delighted to inform you that you have been selected for the position of {$vac} at {$comp}.\n\n"
        . "Next Steps:\n{$nextSteps}\n\n"
        . "Warm regards,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * 5. Rejected Template (Polite)
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_rejected(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team'
): array {
    $args = mail_unpack_base_args($candidateName, $vacancyTitle, $companyName, $hrSenderName);
    $cand = $args['candidate_name'];
    $vac  = $args['vacancy_title'];
    $comp = $args['company_name'];
    $hr   = $args['hr_sender_name'];

    $subject = "Update on Your Application for {$vac} - {$comp}";

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 12px 0;">Thank you for taking the time to apply and speak with us regarding the <strong>' . e($vac) . '</strong> role at <strong>' . e($comp) . '</strong>.</p>'
        . '<p style="margin:0 0 16px 0;">After careful consideration of all applicants, we regret to inform you that we will not be moving forward with your application at this time. We truly appreciate your interest in ' . e($comp) . ' and wish you every success in your career endeavors.</p>'
        . '<p style="margin:0;">Sincerely,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Application Status Update', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "Thank you for taking the time to apply and speak with us regarding the {$vac} role at {$comp}.\n\n"
        . "After careful consideration of all applicants, we regret to inform you that we will not be moving forward with your application at this time. "
        . "We truly appreciate your interest in {$comp} and wish you every success in your career endeavors.\n\n"
        . "Sincerely,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * 6. Offer Sent Template
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_tpl_offer_sent(
    string|array $candidateName,
    string $vacancyTitle = '',
    string $companyName = '',
    string $hrSenderName = 'HR Team',
    array $offerDetails = []
): array {
    $args = mail_unpack_base_args($candidateName, $vacancyTitle, $companyName, $hrSenderName);
    $cand = $args['candidate_name'];
    $vac  = $args['vacancy_title'];
    $comp = $args['company_name'];
    $hr   = $args['hr_sender_name'];

    $joiningDate = (string)($offerDetails['joining_date'] ?? '');
    $expiryDate  = (string)($offerDetails['expiry_date'] ?? '');

    $subject = "Job Offer: {$vac} at {$comp}";

    $extraHtml = '';
    $extraText = '';
    if ($joiningDate !== '' || $expiryDate !== '') {
        $extraHtml .= '<div style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin:0 0 16px 0;font-size:14px;">';
        if ($joiningDate !== '') {
            $extraHtml .= '<div><strong>Proposed Joining Date:</strong> ' . e($joiningDate) . '</div>';
            $extraText .= "- Proposed Joining Date: {$joiningDate}\n";
        }
        if ($expiryDate !== '') {
            $extraHtml .= '<div><strong>Offer Valid Until:</strong> ' . e($expiryDate) . '</div>';
            $extraText .= "- Offer Valid Until: {$expiryDate}\n";
        }
        $extraHtml .= '</div>';
        $extraText .= "\n";
    }

    $innerHtml = '<p style="margin:0 0 12px 0;">Dear <strong>' . e($cand) . '</strong>,</p>'
        . '<p style="margin:0 0 12px 0;">We are thrilled to extend an official offer of employment for the position of <strong>' . e($vac) . '</strong> at <strong>' . e($comp) . '</strong>.</p>'
        . $extraHtml
        . '<p style="margin:0 0 16px 0;">Please review the offer details shared by our HR department and confirm your acceptance at your earliest convenience.</p>'
        . '<p style="margin:0;">Warm regards,<br><strong>' . e($hr) . '</strong><br>' . e($comp) . '</p>';

    $html = mail_render_html_layout($comp, 'Official Offer of Employment', $innerHtml);

    $text = "Dear {$cand},\n\n"
        . "We are thrilled to extend an official offer of employment for the position of {$vac} at {$comp}.\n\n"
        . $extraText
        . "Please review the offer details shared by our HR department and confirm your acceptance at your earliest convenience.\n\n"
        . "Warm regards,\n{$hr}\n{$comp}\n\n"
        . "This is an automated message from {$comp} HR.";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Normalize template key (supports 'offer' alias for 'offer_sent' and strips 'mail_tpl_' prefix).
 */
function mail_normalize_template_key(string $template): string
{
    $tplKey = strtolower(trim($template));
    if (str_starts_with($tplKey, 'mail_tpl_')) {
        $tplKey = substr($tplKey, strlen('mail_tpl_'));
    }
    if ($tplKey === 'offer') {
        $tplKey = 'offer_sent';
    }
    return $tplKey;
}

/**
 * Build a safe mailto: URI using rawurlencode, strictly kept under $maxLen characters (default 1800).
 */
function mail_build_mailto_url(string $to, string $subject, string $textBody, int $maxLen = 1800): string
{
    $cleanTo = mail_clean_header($to);
    $cleanSubject = mb_substr(mail_clean_header($subject), 0, 200);
    $normBody = str_replace(["\r\n", "\r"], "\n", trim($textBody));

    $prefix = 'mailto:' . rawurlencode($cleanTo) . '?subject=' . rawurlencode($cleanSubject) . '&body=';
    $encodedBody = rawurlencode($normBody);

    if (strlen($prefix . $encodedBody) <= $maxLen) {
        return $prefix . $encodedBody;
    }

    // Truncate body until the total mailto URL is strictly under $maxLen
    $workBody = $normBody;
    while (mb_strlen($workBody) > 0) {
        $workBody = mb_substr($workBody, 0, max(0, mb_strlen($workBody) - 25));
        $candidate = $prefix . rawurlencode(rtrim($workBody) . '...');
        if (strlen($candidate) <= $maxLen) {
            return $candidate;
        }
    }

    return substr($prefix, 0, $maxLen);
}

/**
 * Convert plain-text body edited by HR into safe HTML paragraphs wrapped in the standard email layout.
 */
function mail_render_custom_body_html(string $companyName, string $subject, string $customText): string
{
    $norm = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], trim($customText));
    $footerText = 'This is an automated message from ' . $companyName . ' HR.';
    // Strip trailing automated footer from body paragraphs since layout footer renders it
    if (str_ends_with($norm, $footerText)) {
        $norm = rtrim(substr($norm, 0, strlen($norm) - strlen($footerText)));
    }

    $paragraphs = preg_split("/\n{2,}/", $norm) ?: [$norm];
    $htmlParts = [];
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        $lines = explode("\n", $para);
        $escapedLines = array_map(static fn(string $line): string => e($line), $lines);
        $htmlParts[] = '<p style="margin:0 0 14px 0;">' . implode('<br>', $escapedLines) . '</p>';
    }

    $innerHtml = implode("\n", $htmlParts);
    return mail_render_html_layout($companyName, $subject, $innerHtml);
}

/**
 * Enrich $extra with interview or offer metadata from DB when not explicitly provided.
 */
function mail_enrich_candidate_template_extra(PDO $pdo, int $applicationId, string $tplKey, array $extra): array
{
    if ($tplKey === 'interview_invite' && empty($extra['date'])) {
        try {
            $intId = (int)($extra['interview_id'] ?? 0);
            if ($intId > 0) {
                $stInt = $pdo->prepare(
                    "SELECT i.*, GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') AS panel_names
                       FROM interviews i
                       LEFT JOIN interview_panel ip ON ip.interview_id = i.id
                       LEFT JOIN users u ON u.id = ip.user_id
                      WHERE i.id = ? AND i.application_id = ?
                      GROUP BY i.id
                      LIMIT 1"
                );
                $stInt->execute([$intId, $applicationId]);
            } else {
                $stInt = $pdo->prepare(
                    "SELECT i.*, GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') AS panel_names
                       FROM interviews i
                       LEFT JOIN interview_panel ip ON ip.interview_id = i.id
                       LEFT JOIN users u ON u.id = ip.user_id
                      WHERE i.application_id = ?
                      GROUP BY i.id
                      ORDER BY (CASE WHEN i.status IN ('scheduled','rescheduled') THEN 0 ELSE 1 END), i.scheduled_at DESC, i.id DESC
                      LIMIT 1"
                );
                $stInt->execute([$applicationId]);
            }
            $intRow = $stInt->fetch(PDO::FETCH_ASSOC);
            if ($intRow) {
                $schedTs = !empty($intRow['scheduled_at']) ? strtotime((string)$intRow['scheduled_at']) : false;
                $extra['date'] = $schedTs ? date('d M Y', $schedTs) : (string)($intRow['scheduled_at'] ?? 'TBD');
                $extra['time'] = $schedTs ? date('h:i A', $schedTs) : 'TBD';
                $typeMap = ['online' => 'Online Video', 'in_person' => 'In-Person', 'phone' => 'Phone'];
                $rawType = (string)($intRow['interview_type'] ?? 'online');
                $extra['interview_type'] = $typeMap[$rawType] ?? ucfirst(str_replace('_', ' ', $rawType));
                $extra['location_or_link'] = ($rawType === 'online')
                    ? (string)($intRow['meeting_link'] ?: $intRow['location'] ?: 'Link will be shared prior to the session')
                    : (string)($intRow['location'] ?: $intRow['meeting_link'] ?: 'Main Office');
                if (empty($extra['interviewer_names']) && !empty($intRow['panel_names'])) {
                    $extra['interviewer_names'] = (string)$intRow['panel_names'];
                }
            }
        } catch (Throwable) {
            // Non-fatal fallback
        }
    } elseif ($tplKey === 'offer_sent' && empty($extra['designation'])) {
        try {
            $stOff = $pdo->prepare(
                "SELECT jo.proposed_join_date, jo.expiry_date, des.title AS designation_title
                   FROM job_offers jo
                   LEFT JOIN designations des ON des.id = jo.designation_id
                  WHERE jo.application_id = ?
                  ORDER BY jo.id DESC
                  LIMIT 1"
            );
            $stOff->execute([$applicationId]);
            $offRow = $stOff->fetch(PDO::FETCH_ASSOC);
            if ($offRow) {
                if (!empty($offRow['designation_title'])) {
                    $extra['designation'] = (string)$offRow['designation_title'];
                }
                if (!empty($offRow['proposed_join_date'])) {
                    $jTs = strtotime((string)$offRow['proposed_join_date']);
                    $extra['joining_date'] = $jTs ? date('d M Y', $jTs) : (string)$offRow['proposed_join_date'];
                }
                if (!empty($offRow['expiry_date'])) {
                    $eTs = strtotime((string)$offRow['expiry_date']);
                    $extra['expiry_date'] = $eTs ? date('d M Y', $eTs) : (string)$offRow['expiry_date'];
                }
            }
        } catch (Throwable) {
            // Non-fatal fallback
        }
    }

    return $extra;
}

/**
 * Load application, candidate (real unmasked email), vacancy and company from DB,
 * verify permission, and return rendered preview without sending.
 *
 * @return array{ok: bool, error?: ?string, to?: string, candidate_name?: string, company_name?: string, company_id?: int, template?: string, subject?: string, html?: string, text?: string, mailto?: string}
 */
function mail_build_candidate_template_preview(PDO $pdo, int $applicationId, string $template, array $extra = []): array
{
    try {
        if ($applicationId <= 0) {
            return ['ok' => false, 'error' => 'Invalid application ID.'];
        }

        $st = $pdo->prepare(
            'SELECT ca.id AS application_id,
                    ca.company_id,
                    ca.candidate_id,
                    ca.vacancy_id,
                    ca.status AS application_status,
                    c.first_name,
                    c.last_name,
                    c.email AS candidate_email,
                    v.title AS vacancy_title,
                    v.vacancy_code,
                    comp.name AS company_name,
                    comp.email AS company_email,
                    comp.phone AS company_phone
               FROM candidate_applications ca
               JOIN candidates c ON c.id = ca.candidate_id
               JOIN job_vacancies v ON v.id = ca.vacancy_id
               JOIN companies comp ON comp.id = ca.company_id
              WHERE ca.id = ?
              LIMIT 1'
        );
        $st->execute([$applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'error' => 'Application not found.'];
        }

        $companyId = (int)$row['company_id'];
        $tplKey = mail_normalize_template_key($template);

        $hasPerm = user_can('candidate.manage', $companyId);
        if (!$hasPerm && $tplKey === 'interview_invite') {
            $hasPerm = user_can('interview.schedule', $companyId) || user_can('interview.manage', $companyId);
        }
        if (!$hasPerm) {
            return ['ok' => false, 'error' => 'Permission denied to email candidates for this company.'];
        }

        $candidateName  = trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name']));
        $vacancyTitle   = (string)$row['vacancy_title'];
        $companyName    = (string)$row['company_name'];
        $recipientEmail = trim((string)$row['candidate_email']);

        $currentUser = function_exists('current_user') ? current_user() : null;
        $hrSenderName = trim((string)($extra['hr_sender_name'] ?? ($currentUser['full_name'] ?? '')));
        if ($hrSenderName === '') {
            $hrSenderName = $companyName . ' HR';
        }

        $extra = mail_enrich_candidate_template_extra($pdo, $applicationId, $tplKey, $extra);

        switch ($tplKey) {
            case 'application_received':
                $rendered = mail_tpl_application_received($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'shortlisted':
                $rendered = mail_tpl_shortlisted($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'interview_invite':
                $date = (string)($extra['date'] ?? '');
                $time = (string)($extra['time'] ?? '');
                $intType = (string)($extra['interview_type'] ?? $extra['type'] ?? 'Interview');
                $location = (string)($extra['location_or_link'] ?? $extra['location'] ?? $extra['meeting_link'] ?? '');
                $interviewers = (string)($extra['interviewer_names'] ?? $extra['interviewers'] ?? '');
                $hrContact = (string)($extra['hr_contact'] ?? $row['company_email'] ?? $row['company_phone'] ?? $hrSenderName);
                $rendered = mail_tpl_interview_invite(
                    $candidateName,
                    $vacancyTitle,
                    $companyName,
                    $hrSenderName,
                    $date,
                    $time,
                    $intType,
                    $location,
                    $interviewers,
                    $hrContact
                );
                break;

            case 'selected':
                $nextSteps = (string)($extra['next_steps'] ?? '');
                $rendered = mail_tpl_selected($candidateName, $vacancyTitle, $companyName, $hrSenderName, $nextSteps);
                break;

            case 'rejected':
                $rendered = mail_tpl_rejected($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'offer_sent':
                $rendered = mail_tpl_offer_sent($candidateName, $vacancyTitle, $companyName, $hrSenderName, $extra);
                break;

            default:
                return ['ok' => false, 'error' => 'Invalid email template specified.'];
        }

        $mailto = $recipientEmail !== ''
            ? mail_build_mailto_url($recipientEmail, $rendered['subject'], $rendered['text'], 1800)
            : '';

        return [
            'ok'             => true,
            'to'             => $recipientEmail,
            'candidate_name' => $candidateName,
            'company_name'   => $companyName,
            'company_id'     => $companyId,
            'template'       => $tplKey,
            'subject'        => $rendered['subject'],
            'html'           => $rendered['html'],
            'text'           => $rendered['text'],
            'mailto'         => $mailto,
        ];
    } catch (Throwable $e) {
        $safeErr = function_exists('mail_scrub_secrets') ? mail_scrub_secrets($e->getMessage()) : 'Unexpected mail error';
        return ['ok' => false, 'error' => $safeErr];
    }
}

/**
 * Load application, candidate (real unmasked email), vacancy and company from DB,
 * enforce permission & per-user rate limit, render template, send email, and record audit log.
 *
 * @return array{ok: bool, error: ?string}
 */
function mail_send_candidate_template(PDO $pdo, int $applicationId, string $template, array $extra = []): array
{
    try {
        if ($applicationId <= 0) {
            return ['ok' => false, 'error' => 'Invalid application ID.'];
        }

        $st = $pdo->prepare(
            'SELECT ca.id AS application_id,
                    ca.company_id,
                    ca.candidate_id,
                    ca.vacancy_id,
                    ca.status AS application_status,
                    c.first_name,
                    c.last_name,
                    c.email AS candidate_email,
                    v.title AS vacancy_title,
                    v.vacancy_code,
                    comp.name AS company_name,
                    comp.email AS company_email,
                    comp.phone AS company_phone
               FROM candidate_applications ca
               JOIN candidates c ON c.id = ca.candidate_id
               JOIN job_vacancies v ON v.id = ca.vacancy_id
               JOIN companies comp ON comp.id = ca.company_id
              WHERE ca.id = ?
              LIMIT 1'
        );
        $st->execute([$applicationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'error' => 'Application not found.'];
        }

        $companyId = (int)$row['company_id'];
        $tplKey = mail_normalize_template_key($template);

        // Permission check: candidate.manage (or interview.schedule / interview.manage for interview_invite) for application's company
        $hasPerm = user_can('candidate.manage', $companyId);
        if (!$hasPerm && $tplKey === 'interview_invite') {
            $hasPerm = user_can('interview.schedule', $companyId) || user_can('interview.manage', $companyId);
        }

        if (!$hasPerm) {
            log_activity('candidate.email_failed', 'candidate_application', $applicationId, [
                'template' => $tplKey,
                'ok'       => false,
                'reason'   => 'permission_denied',
            ], $companyId);
            return ['ok' => false, 'error' => 'Permission denied to send candidate email for this company.'];
        }

        // Rate limit: 10 emails per 60 seconds per user
        if (!check_rate_limit('candidate_email', 10, 60)) {
            log_activity('candidate.email_failed', 'candidate_application', $applicationId, [
                'template' => $tplKey,
                'ok'       => false,
                'reason'   => 'rate_limited',
            ], $companyId);
            return ['ok' => false, 'error' => 'Rate limit exceeded. Maximum 10 emails per minute allowed.'];
        }

        $candidateName = trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name']));
        $vacancyTitle  = (string)$row['vacancy_title'];
        $companyName   = (string)$row['company_name'];
        $recipientEmail = trim((string)$row['candidate_email']);

        $currentUser = function_exists('current_user') ? current_user() : null;
        $hrSenderName = trim((string)($extra['hr_sender_name'] ?? ($currentUser['full_name'] ?? '')));
        if ($hrSenderName === '') {
            $hrSenderName = $companyName . ' HR';
        }

        $extra = mail_enrich_candidate_template_extra($pdo, $applicationId, $tplKey, $extra);

        // Build the requested template
        switch ($tplKey) {
            case 'application_received':
                $rendered = mail_tpl_application_received($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'shortlisted':
                $rendered = mail_tpl_shortlisted($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'interview_invite':
                $date = (string)($extra['date'] ?? '');
                $time = (string)($extra['time'] ?? '');
                $intType = (string)($extra['interview_type'] ?? $extra['type'] ?? 'Interview');
                $location = (string)($extra['location_or_link'] ?? $extra['location'] ?? $extra['meeting_link'] ?? '');
                $interviewers = (string)($extra['interviewer_names'] ?? $extra['interviewers'] ?? '');
                $hrContact = (string)($extra['hr_contact'] ?? $row['company_email'] ?? $row['company_phone'] ?? $hrSenderName);
                $rendered = mail_tpl_interview_invite(
                    $candidateName,
                    $vacancyTitle,
                    $companyName,
                    $hrSenderName,
                    $date,
                    $time,
                    $intType,
                    $location,
                    $interviewers,
                    $hrContact
                );
                break;

            case 'selected':
                $nextSteps = (string)($extra['next_steps'] ?? '');
                $rendered = mail_tpl_selected($candidateName, $vacancyTitle, $companyName, $hrSenderName, $nextSteps);
                break;

            case 'rejected':
                $rendered = mail_tpl_rejected($candidateName, $vacancyTitle, $companyName, $hrSenderName);
                break;

            case 'offer_sent':
                $rendered = mail_tpl_offer_sent($candidateName, $vacancyTitle, $companyName, $hrSenderName, $extra);
                break;

            default:
                log_activity('candidate.email_failed', 'candidate_application', $applicationId, [
                    'template' => $tplKey,
                    'ok'       => false,
                    'reason'   => 'invalid_template',
                ], $companyId);
                return ['ok' => false, 'error' => 'Invalid email template specified.'];
        }

        // Apply optional HR-customized subject / body (sanitized and HTML-escaped)
        if (isset($extra['custom_subject']) && trim((string)$extra['custom_subject']) !== '') {
            $customSubj = mb_substr(mail_clean_header(trim((string)$extra['custom_subject'])), 0, 200);
            if ($customSubj !== '') {
                $rendered['subject'] = $customSubj;
            }
        }

        if (isset($extra['custom_body']) && trim((string)$extra['custom_body']) !== '') {
            $customText = mb_substr(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], trim((string)$extra['custom_body'])), 0, 20000);
            if ($customText !== '') {
                $footerLine = "This is an automated message from {$companyName} HR.";
                if (!str_contains($customText, $footerLine)) {
                    $customText .= "\n\n" . $footerLine;
                }
                $rendered['text'] = $customText;
                $rendered['html'] = mail_render_custom_body_html($companyName, $rendered['subject'], $customText);
            }
        }

        // Send via mail_send using REAL candidate email
        $sendResult = mail_send($recipientEmail, $rendered['subject'], $rendered['html'], $rendered['text']);
        $ok = !empty($sendResult['ok']);

        log_activity(
            $ok ? 'candidate.email_sent' : 'candidate.email_failed',
            'candidate_application',
            $applicationId,
            [
                'template' => $tplKey,
                'ok'       => $ok,
            ],
            $companyId
        );

        return $sendResult;
    } catch (Throwable $e) {
        $safeErr = function_exists('mail_scrub_secrets') ? mail_scrub_secrets($e->getMessage()) : 'Unexpected mail error';
        return ['ok' => false, 'error' => $safeErr];
    }
}

