<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
redirect('modules/roles/index.php');
