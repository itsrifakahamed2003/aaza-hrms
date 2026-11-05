<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Sign-out is POST + CSRF only, so another site cannot log users out with a link or image.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('public/index.php');
}
csrf_require();
logout_user();
flash_set('success', 'You have been signed out.');
redirect('public/login.php');
