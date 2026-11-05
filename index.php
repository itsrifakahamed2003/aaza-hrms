<?php
declare(strict_types=1);

// If system is not yet configured or installed, direct visitor to setup wizard
if ((!is_file(__DIR__ . '/config/config.php') || !is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/storage/installed.lock')) && is_file(__DIR__ . '/public/setup.php')) {
    header('Location: public/setup.php');
    exit;
}

// Project root: send visitors to the app.
header('Location: public/index.php');
exit;
