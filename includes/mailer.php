<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Plain PHP Outgoing Email Engine (SMTP & PHP mail()).
 *
 * Security rules:
 * - Never throws uncaught exceptions; always returns ['ok' => bool, 'error' => ?string].
 * - Validates recipient with FILTER_VALIDATE_EMAIL and blocks CR/LF/NUL header injection.
 * - Limits Subject to 200 characters and Body to 50 KB.
 * - Never logs or exposes MAIL_PASSWORD in any error message, log file, HTML, or DB row.
 */

if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER', 'none');          // 'smtp' | 'phpmail' | 'none'
}
if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', 'smtp.gmail.com');
}
if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', 587);
}
if (!defined('MAIL_ENCRYPTION')) {
    define('MAIL_ENCRYPTION', 'tls');       // 'tls' | 'ssl'
}
if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', '');
}
if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', '');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', '');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'AaZa HRMS');
}

/**
 * Retrieve a mail configuration setting, honoring runtime test overrides if set.
 */
function mail_cfg(string $key, mixed $default = null): mixed
{
    if (isset($GLOBALS['MAIL_CONFIG_OVERRIDE']) && is_array($GLOBALS['MAIL_CONFIG_OVERRIDE']) && array_key_exists($key, $GLOBALS['MAIL_CONFIG_OVERRIDE'])) {
        return $GLOBALS['MAIL_CONFIG_OVERRIDE'][$key];
    }
    return defined($key) ? constant($key) : $default;
}

/**
 * Returns true when MAIL_DRIVER is configured to 'smtp' or 'phpmail'.
 */
function mail_is_configured(): bool
{
    $driver = strtolower(trim((string)mail_cfg('MAIL_DRIVER', 'none')));
    return in_array($driver, ['smtp', 'phpmail'], true);
}

/**
 * Strip CR, LF and NUL bytes from any header value to prevent header injection.
 */
function mail_clean_header(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

/**
 * Encode a UTF-8 header display name or subject per RFC 2047.
 */
function mail_encode_header_utf8(string $value): string
{
    $clean = mail_clean_header($value);
    if ($clean === '') {
        return '';
    }
    return '=?UTF-8?B?' . base64_encode($clean) . '?=';
}

/**
 * Remove any occurrence of MAIL_PASSWORD (raw or base64 encoded) from error strings.
 */
function mail_scrub_secrets(string $message): string
{
    $secrets = [];
    $cfgPass = (string)mail_cfg('MAIL_PASSWORD', '');
    $constPass = defined('MAIL_PASSWORD') ? (string)MAIL_PASSWORD : '';
    $cfgUser = (string)mail_cfg('MAIL_USERNAME', '');

    foreach ([$cfgPass, $constPass] as $pw) {
        if ($pw !== '') {
            $secrets[] = $pw;
            $secrets[] = base64_encode($pw);
            if ($cfgUser !== '') {
                $secrets[] = base64_encode("\0" . $cfgUser . "\0" . $pw);
            }
        }
    }

    if (!empty($secrets)) {
        $message = str_replace($secrets, '[REDACTED]', $message);
    }

    return $message;
}

/**
 * Log mail outcome without exposing recipient local-part, message body, or credentials.
 */
function mail_log_outcome(string $recipientDomain, bool $ok, ?string $error = null): void
{
    $domain = preg_replace('/[^A-Za-z0-9._-]/', '', $recipientDomain) ?: 'invalid';
    $status = $ok ? 'success' : 'failure';
    $errPart = ($error !== null && $error !== '') ? ' error="' . str_replace('"', "'", mail_scrub_secrets($error)) . '"' : '';
    error_log("[Mailer] domain={$domain} status={$status}{$errPart}");
}

/**
 * Build a multipart/alternative MIME message (headers + CRLF body).
 *
 * @return array{from_email: string, subject_encoded: string, headers: array<int, string>, body: string, raw_message: string}
 */
function mail_build_mime_message(string $to, string $subject, string $htmlBody, string $textBody): array
{
    $fromEmail = mail_clean_header((string)mail_cfg('MAIL_FROM_EMAIL', ''));
    $username = mail_clean_header((string)mail_cfg('MAIL_USERNAME', ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        if ($username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $username;
        } else {
            $fromEmail = 'noreply@aaza-hrms.local';
        }
    }

    $fromName = mail_clean_header((string)mail_cfg('MAIL_FROM_NAME', 'AaZa HRMS'));
    $fromHeader = $fromName !== ''
        ? mail_encode_header_utf8($fromName) . ' <' . $fromEmail . '>'
        : '<' . $fromEmail . '>';

    $encodedSubject = mail_encode_header_utf8($subject);
    $dateHeader = date(DATE_RFC2822);
    $fromDomain = substr(strrchr($fromEmail, '@') ?: '@aaza-hrms.local', 1);
    $fromDomain = preg_replace('/[^A-Za-z0-9._-]/', '', $fromDomain) ?: 'aaza-hrms.local';
    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $fromDomain . '>';
    $boundary = '=_AaZaBoundary_' . bin2hex(random_bytes(12));

    // Normalize text and HTML parts to CRLF before quoted-printable encoding
    $normText = str_replace(["\r\n", "\r"], "\n", $textBody);
    $normText = str_replace("\n", "\r\n", $normText);
    $normHtml = str_replace(["\r\n", "\r"], "\n", $htmlBody);
    $normHtml = str_replace("\n", "\r\n", $normHtml);

    $qpText = quoted_printable_encode($normText);
    $qpHtml = quoted_printable_encode($normHtml);

    $bodyParts = [
        "--{$boundary}",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: quoted-printable",
        "",
        $qpText,
        "--{$boundary}",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: quoted-printable",
        "",
        $qpHtml,
        "--{$boundary}--",
        "",
    ];
    $mimeBody = implode("\r\n", $bodyParts);

    $headers = [
        "Date: {$dateHeader}",
        "From: {$fromHeader}",
        "To: <{$to}>",
        "Subject: {$encodedSubject}",
        "Message-ID: {$messageId}",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    ];

    $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $mimeBody;

    return [
        'from_email'      => $fromEmail,
        'from_header'     => $fromHeader,
        'date_header'     => $dateHeader,
        'message_id'      => $messageId,
        'boundary'        => $boundary,
        'subject_encoded' => $encodedSubject,
        'headers'         => $headers,
        'body'            => $mimeBody,
        'raw_message'     => $rawMessage,
    ];
}

/**
 * Read a complete SMTP response (handling multi-line 250-... responses).
 *
 * @param resource $socket
 * @return array{code: int, text: string}
 */
function mail_smtp_read_response($socket): array
{
    $response = '';
    $code = 0;
    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }

    $meta = stream_get_meta_data($socket);
    if (!empty($meta['timed_out'])) {
        throw new RuntimeException('SMTP connection timed out while reading response.');
    }
    if ($response === '') {
        throw new RuntimeException('SMTP server closed connection unexpectedly.');
    }

    return [
        'code' => $code,
        'text' => trim($response),
    ];
}

/**
 * Write a command line to the SMTP socket with CRLF ending.
 *
 * @param resource $socket
 */
function mail_smtp_write($socket, string $data): void
{
    $len = strlen($data);
    $written = 0;
    while ($written < $len) {
        $bytes = @fwrite($socket, substr($data, $written));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Failed to write data to SMTP socket.');
        }
        $written += $bytes;
    }
}

/**
 * Perform dot-stuffing per RFC 5321 Section 4.5.2 and normalize line endings to CRLF.
 */
function mail_smtp_dot_stuff(string $rawMessage): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $rawMessage);
    $lines = explode("\n", $normalized);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);
    return implode("\r\n", $lines);
}

/**
 * Send email using the built-in plain-PHP SMTP client.
 */
function mail_send_smtp(string $to, array $mime): array
{
    $host = mail_clean_header((string)mail_cfg('MAIL_HOST', 'smtp.gmail.com'));
    $port = (int)mail_cfg('MAIL_PORT', 587);
    $encryption = strtolower(trim((string)mail_cfg('MAIL_ENCRYPTION', 'tls')));
    $username = (string)mail_cfg('MAIL_USERNAME', '');
    $password = (string)mail_cfg('MAIL_PASSWORD', '');
    $timeout = 10;

    if ($host === '' || $port <= 0 || $port > 65535) {
        return ['ok' => false, 'error' => 'Invalid SMTP host or port configuration.'];
    }

    // Port 465 uses implicit SSL; port 587 uses explicit STARTTLS
    $useImplicitSsl = ($encryption === 'ssl' || ($port === 465 && $encryption !== 'none' && $encryption !== ''));
    $useStartTls = (!$useImplicitSsl && ($encryption === 'tls' || ($port === 587 && $encryption !== 'none' && $encryption !== '')));

    $remoteSocket = ($useImplicitSsl ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $remoteSocket,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        $safeErr = mail_scrub_secrets("SMTP connect failed ({$errno}): {$errstr}");
        return ['ok' => false, 'error' => $safeErr];
    }

    stream_set_timeout($socket, $timeout);

    try {
        // 1. Server Greeting
        $greeting = mail_smtp_read_response($socket);
        if ($greeting['code'] !== 220) {
            throw new RuntimeException('Unexpected SMTP greeting (' . $greeting['code'] . ').');
        }

        // 2. EHLO
        $ehloHost = 'localhost';
        mail_smtp_write($socket, "EHLO {$ehloHost}\r\n");
        $ehlo = mail_smtp_read_response($socket);
        if ($ehlo['code'] !== 250) {
            throw new RuntimeException('SMTP EHLO failed (' . $ehlo['code'] . ').');
        }

        // 3. STARTTLS when using port 587 / tls
        if ($useStartTls) {
            mail_smtp_write($socket, "STARTTLS\r\n");
            $tlsResp = mail_smtp_read_response($socket);
            if ($tlsResp['code'] !== 220) {
                throw new RuntimeException('SMTP STARTTLS rejected (' . $tlsResp['code'] . ').');
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            if ($cryptoOk !== true) {
                throw new RuntimeException('SMTP TLS handshake and certificate verification failed.');
            }

            // Re-issue EHLO after TLS negotiation
            mail_smtp_write($socket, "EHLO {$ehloHost}\r\n");
            $ehloTls = mail_smtp_read_response($socket);
            if ($ehloTls['code'] !== 250) {
                throw new RuntimeException('SMTP post-TLS EHLO failed (' . $ehloTls['code'] . ').');
            }
        }

        // 4. Authentication (AUTH LOGIN with AUTH PLAIN fallback)
        if ($username !== '' || $password !== '') {
            mail_smtp_write($socket, "AUTH LOGIN\r\n");
            $authInit = mail_smtp_read_response($socket);

            if ($authInit['code'] === 334) {
                mail_smtp_write($socket, base64_encode($username) . "\r\n");
                $userStep = mail_smtp_read_response($socket);
                if ($userStep['code'] !== 334) {
                    throw new RuntimeException('SMTP AUTH LOGIN username rejected (' . $userStep['code'] . ').');
                }

                mail_smtp_write($socket, base64_encode($password) . "\r\n");
                $passStep = mail_smtp_read_response($socket);
                if ($passStep['code'] !== 235) {
                    throw new RuntimeException('SMTP authentication failed (' . $passStep['code'] . ').');
                }
            } else {
                // Fallback to AUTH PLAIN
                $plainToken = base64_encode("\0" . $username . "\0" . $password);
                mail_smtp_write($socket, "AUTH PLAIN {$plainToken}\r\n");
                $plainResp = mail_smtp_read_response($socket);
                if ($plainResp['code'] !== 235) {
                    throw new RuntimeException('SMTP AUTH PLAIN failed (' . $plainResp['code'] . ').');
                }
            }
        }

        // 5. MAIL FROM
        $fromEmail = $mime['from_email'];
        mail_smtp_write($socket, "MAIL FROM:<{$fromEmail}>\r\n");
        $mailFrom = mail_smtp_read_response($socket);
        if ($mailFrom['code'] !== 250) {
            throw new RuntimeException('SMTP MAIL FROM rejected (' . $mailFrom['code'] . ').');
        }

        // 6. RCPT TO
        mail_smtp_write($socket, "RCPT TO:<{$to}>\r\n");
        $rcptTo = mail_smtp_read_response($socket);
        if ($rcptTo['code'] !== 250 && $rcptTo['code'] !== 251) {
            throw new RuntimeException('SMTP RCPT TO rejected (' . $rcptTo['code'] . ').');
        }

        // 7. DATA
        mail_smtp_write($socket, "DATA\r\n");
        $dataCmd = mail_smtp_read_response($socket);
        if ($dataCmd['code'] !== 354) {
            throw new RuntimeException('SMTP DATA command rejected (' . $dataCmd['code'] . ').');
        }

        // 8. Message Content with Dot-Stuffing and CRLF termination
        $stuffed = mail_smtp_dot_stuff($mime['raw_message']);
        mail_smtp_write($socket, $stuffed . "\r\n.\r\n");
        $dataDone = mail_smtp_read_response($socket);
        if ($dataDone['code'] !== 250) {
            throw new RuntimeException('SMTP message body rejected (' . $dataDone['code'] . ').');
        }

        // 9. QUIT
        mail_smtp_write($socket, "QUIT\r\n");
        try {
            mail_smtp_read_response($socket);
        } catch (Throwable) {
            // Ignore close after QUIT
        }
        @fclose($socket);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        return [
            'ok'    => false,
            'error' => mail_scrub_secrets($e->getMessage()),
        ];
    }
}

/**
 * Send email using PHP's native mail() with safe headers.
 */
function mail_send_phpmail(string $to, array $mime): array
{
    $additionalHeaders = [
        'Date: ' . $mime['date_header'],
        'From: ' . $mime['from_header'],
        'Message-ID: ' . $mime['message_id'],
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $mime['boundary'] . '"',
    ];
    $headersStr = implode("\r\n", $additionalHeaders);

    $sent = @mail($to, $mime['subject_encoded'], $mime['body'], $headersStr);
    if (!$sent) {
        return ['ok' => false, 'error' => 'PHP mail() failed to accepted message for delivery.'];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Send an email using the configured driver ('smtp', 'phpmail', or 'none').
 * Never throws: catches Throwable and returns ['ok' => bool, 'error' => ?string].
 *
 * @return array{ok: bool, error: ?string}
 */
function mail_send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array
{
    try {
        // 1. Block CR/LF/NUL injection in recipient
        if (str_contains($to, "\r") || str_contains($to, "\n") || str_contains($to, "\0")) {
            mail_log_outcome('invalid', false, 'Recipient contains forbidden CR/LF characters');
            return ['ok' => false, 'error' => 'Invalid recipient email address (CR/LF injection blocked).'];
        }

        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            mail_log_outcome('invalid', false, 'Recipient failed FILTER_VALIDATE_EMAIL');
            return ['ok' => false, 'error' => 'Invalid recipient email address.'];
        }

        $domain = substr(strrchr($to, '@') ?: '@invalid', 1);

        // 2. Clean and bound Subject (max 200 chars)
        $cleanSubject = mail_clean_header($subject);
        if ($cleanSubject === '') {
            mail_log_outcome($domain, false, 'Empty email subject');
            return ['ok' => false, 'error' => 'Email subject cannot be empty.'];
        }
        if (mb_strlen($cleanSubject, 'UTF-8') > 200) {
            $cleanSubject = mb_substr($cleanSubject, 0, 200, 'UTF-8');
        }

        // 3. Enforce 50 KB maximum body size
        $maxBytes = 50 * 1024;
        if (strlen($htmlBody) > $maxBytes || ($textBody !== null && strlen($textBody) > $maxBytes)) {
            mail_log_outcome($domain, false, 'Email body exceeds 50 KB limit');
            return ['ok' => false, 'error' => 'Email body exceeds maximum allowed size of 50 KB.'];
        }

        // 4. Derive plain-text fallback if not provided
        if ($textBody === null || trim($textBody) === '') {
            $stripped = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $htmlBody) ?? $htmlBody;
            $stripped = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])\b[^>]*>/i', "\n", $stripped) ?? $stripped;
            $textBody = trim(html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // 5. Check configured driver
        $driver = strtolower(trim((string)mail_cfg('MAIL_DRIVER', 'none')));
        if ($driver === 'none' || $driver === '') {
            mail_log_outcome($domain, false, 'Email is not configured');
            return ['ok' => false, 'error' => 'Email is not configured'];
        }

        $mime = mail_build_mime_message($to, $cleanSubject, $htmlBody, $textBody);

        if ($driver === 'smtp') {
            $result = mail_send_smtp($to, $mime);
        } elseif ($driver === 'phpmail') {
            $result = mail_send_phpmail($to, $mime);
        } else {
            $result = ['ok' => false, 'error' => 'Unsupported mail driver.'];
        }

        mail_log_outcome($domain, $result['ok'], $result['error']);
        return $result;
    } catch (Throwable $e) {
        $safeError = mail_scrub_secrets($e->getMessage());
        mail_log_outcome('unknown', false, $safeError);
        return ['ok' => false, 'error' => $safeError];
    }
}

