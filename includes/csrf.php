<?php
declare(strict_types=1);

/** CSRF protection: one token per session, checked on every state-changing POST. */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return is_string($submitted) && $submitted !== ''
        && hash_equals((string)($_SESSION['csrf'] ?? ''), $submitted);
}

/** Call at the top of any POST handler. Stops the request if the token is bad. */
function csrf_require(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_verify()) {
        abort(419, 'Your form session expired. Go back, refresh the page and try again.');
    }
}
