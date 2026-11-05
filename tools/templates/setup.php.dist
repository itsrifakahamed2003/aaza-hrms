<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Automated 3-Step Production Web Installer
 * 
 * Step 1: System Requirements & Directory Permissions
 * Step 2: Database Connection & Migration (database/aaza_hrms_full.sql)
 * Step 3: Organization Setup & First Group Administrator Provisioning
 * Step 4: Installation Completed - Finish Screen & Safe Self-Deletion
 * 
 * Usable only while storage/installed.lock does not exist and no users exist.
 */

$rootDir = dirname(__DIR__);
$lockFile = $rootDir . '/storage/installed.lock';
$dbConfigFile = $rootDir . '/config/database.php';
$mainConfigFile = $rootDir . '/config/config.php';

// Session initialization
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('AAZA_INSTALLER');
    session_start();
}

// CSRF token generation
if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['installer_csrf'];

// Helper for escaping
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Maps database exceptions to user-friendly messages and logs technical details.
 */
function map_db_exception(PDOException $e): string {
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/app.log';
    $entry = '[' . date('Y-m-d H:i:s') . '] [AaZa Installer DB Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
    error_log('[AaZa Installer DB Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $driverCode = (int)($e->errorInfo[1] ?? 0);
    $msg = strtolower($e->getMessage());

    if ($driverCode === 1062 || str_contains($msg, 'duplicate entry') || str_contains($msg, 'unique constraint')) {
        return 'That value already exists';
    }
    if ($driverCode === 1451 || $driverCode === 1452 || str_contains($msg, 'foreign key constraint') || str_contains($msg, 'cannot add or update a child row')) {
        return 'Related record missing';
    }
    return 'Database error: ' . $e->getMessage();
}

// Per-IP simple rate limiting (max 40 requests/minute)
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateKey = 'install_rate_' . md5($ip);
$rateData = $_SESSION[$rateKey] ?? ['count' => 0, 'time' => time()];
if (time() - $rateData['time'] < 60) {
    if ($rateData['count'] > 40) {
        http_response_code(429);
        die('Too many installation requests. Please wait a minute and refresh.');
    }
    $rateData['count']++;
} else {
    $rateData = ['count' => 1, 'time' => time()];
}
$_SESSION[$rateKey] = $rateData;

// Handle explicit POST action to delete installer from Finish screen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_installer')) {
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postCsrf)) {
        http_response_code(403);
        die('Security token mismatch. Please try again.');
    }
    $unlinked = @unlink(__FILE__);
    unset($_SESSION['install_completed'], $_SESSION['install_step']);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Installer Removal · AaZa HRMS</title>
        <link rel="icon" type="image/png" href="../assets/img/favicon.png">
        <link rel="stylesheet" href="../assets/vendor/inter/inter.css">
        <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
        <style>body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }</style>
    </head>
    <body>
        <div class="container py-5" style="max-width: 580px;">
            <div class="card shadow-sm border-0 rounded-3 p-4 text-center">
                <?php if ($unlinked): ?>
                    <div class="text-success mb-3" style="font-size: 3rem;">&#10004;</div>
                    <h4 class="fw-bold text-success mb-2">Installer Deleted Successfully</h4>
                    <p class="text-secondary small mb-4"><code>public/setup.php</code> has been safely and permanently removed from the server.</p>
                <?php else: ?>
                    <div class="text-warning mb-3" style="font-size: 3rem;">&#9888;</div>
                    <h4 class="fw-bold text-warning mb-2">Manual Deletion Required</h4>
                    <p class="text-secondary small mb-4">Could not automatically delete <code>public/setup.php</code> due to filesystem permissions. Please delete it manually using your hosting File Manager or FTP.</p>
                <?php endif; ?>
                <a href="login.php" class="btn btn-primary px-4">Go to Login &rarr;</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Lock Enforcement: Refuse to run once installed.lock exists
if (is_file($lockFile)) {
    // Only allow showing the finish screen if this exact session just finished installation
    $justFinished = !empty($_SESSION['install_completed']) && (($_GET['step'] ?? '') === 'finish');
    if (!$justFinished) {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Installer Locked</title>'
            . '<link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css"></head>'
            . '<body class="bg-light"><div class="container py-5" style="max-width:540px"><div class="card shadow-sm border-0 p-4 text-center">'
            . '<h4 class="text-danger fw-bold">Installation Locked</h4>'
            . '<p class="text-secondary small">AaZa HRMS is already installed. For security, please delete <code>public/setup.php</code> from your server.</p>'
            . '<a href="login.php" class="btn btn-primary btn-sm">Go to Login</a></div></div></body></html>';
        exit;
    }
}

// Check if database is already configured and users exist
if (is_file($dbConfigFile)) {
    try {
        require_once $dbConfigFile;
        if (function_exists('db')) {
            $checkPdo = db();
            $userCount = (int)$checkPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($userCount > 0) {
                if (!is_file($lockFile)) {
                    @file_put_contents($lockFile, json_encode([
                        'installed_at' => date('c'),
                        'installed_by' => 'system_autolock',
                        'note'         => 'Pre-existing installation detected with registered users',
                    ], JSON_PRETTY_PRINT));
                    @chmod($lockFile, 0640);
                }
                if (empty($_SESSION['install_completed']) || ($_GET['step'] ?? '') !== 'finish') {
                    http_response_code(403);
                    die('System already contains registered user records. For security, setup is disabled. Please delete public/setup.php from your server.');
                }
            }
        }
    } catch (Throwable) {
        // If DB fails to connect, proceed with setup wizard
    }
}

// Wizard Step Navigation
$currentStep = (int)($_SESSION['install_step'] ?? 1);
if (isset($_GET['step'])) {
    if ($_GET['step'] === 'finish' && !empty($_SESSION['install_completed'])) {
        $currentStep = 4;
    } elseif (in_array((int)$_GET['step'], [1, 2, 3], true)) {
        $reqStep = (int)$_GET['step'];
        if ($reqStep <= $currentStep) {
            $currentStep = $reqStep;
        }
    }
}

$errors = [];
$successMsg = '';

// Read existing companies from database for Step 3
$existingCompanies = [];
if (is_file($dbConfigFile)) {
    try {
        require_once $dbConfigFile;
        if (function_exists('db')) {
            $stExisting = db()->query("SELECT id, code, name, is_active FROM companies ORDER BY id ASC");
            $existingCompanies = $stExisting->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable) {
        $existingCompanies = [];
    }
}
$hasExistingCompanies = !empty($existingCompanies);

// -------------------------------------------------------------
// POST Handling per Step
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postCsrf)) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // Step 1: Requirements Check confirmed
        if ($action === 'step1_next') {
            $_SESSION['install_step'] = 2;
            header('Location: setup.php?step=2');
            exit;
        }

        // Step 2: Database Setup & Migration
        if ($action === 'step2_db') {
            $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
            $dbPort = (int)($_POST['db_port'] ?? 3306);
            $dbName = trim((string)($_POST['db_name'] ?? ''));
            $dbUser = trim((string)($_POST['db_user'] ?? ''));
            $dbPass = (string)($_POST['db_pass'] ?? '');

            $reuseExisting = !empty($_POST['reuse_existing']);

            // Strict parameter validation
            if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $dbHost)) {
                $errors[] = 'Invalid database host format. Only alphanumeric characters, dots, colons, underscores, and dashes are allowed (max 255 chars).';
            }
            if ($dbPort <= 0 || $dbPort > 65535) {
                $errors[] = 'Valid database port is required (1-65535).';
            }
            if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $dbName)) {
                $errors[] = 'Invalid database name format. Only alphanumeric characters, _, $, ., and - are allowed (max 64 chars).';
            }
            if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $dbUser)) {
                $errors[] = 'Invalid database username format. Only alphanumeric characters, _, $, ., and - are allowed (max 64 chars).';
            }

            if (empty($errors)) {
                try {
                    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                    $testPdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES         => false,
                        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    ]);

                    // Check if target database already contains tables
                    $tblStmt = $testPdo->query("SHOW TABLES");
                    $existingTables = $tblStmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($existingTables)) {
                        if (!$reuseExisting) {
                            throw new RuntimeException("Target database '{$dbName}' already contains " . count($existingTables) . " table(s). To use this database, tick the confirmation checkbox.");
                        }

                        // When reusing, verify the users table does not have existing users
                        if (in_array('users', $existingTables, true)) {
                            $userCount = (int)$testPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                            if ($userCount > 0) {
                                throw new RuntimeException("Target database already contains {$userCount} registered user(s). The installer refuses to overwrite an active installation.");
                            }
                        }
                    }

                    // Locate master single-file SQL schema
                    $sqlFile = $rootDir . '/database/aaza_hrms_full.sql';
                    if (!is_file($sqlFile)) {
                        throw new RuntimeException("Master schema file 'database/aaza_hrms_full.sql' not found on server.");
                    }

                    $rawSql = (string)file_get_contents($sqlFile);
                    if (trim($rawSql) === '') {
                        throw new RuntimeException("Master schema file 'database/aaza_hrms_full.sql' is empty.");
                    }

                    // Parse and execute statements
                    $testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    try {
                        $len = strlen($rawSql);
                        $stmt = '';
                        $inString = false;
                        $stringChar = '';
                        $statementCount = 0;

                        $executeStmt = static function (PDO $pdo, string $sql, int &$count): void {
                            $cleanStmt = trim($sql);
                            if ($cleanStmt === '') {
                                return;
                            }
                            $count++;
                            try {
                                $res = $pdo->query($cleanStmt);
                                if ($res instanceof PDOStatement) {
                                    if ($res->columnCount() > 0) {
                                        $res->fetchAll();
                                    }
                                    $res->closeCursor();
                                }
                            } catch (PDOException $pe) {
                                $snippet = mb_substr($cleanStmt, 0, 160) . '...';
                                throw new RuntimeException("Migration failed on statement #{$count}: " . $pe->getMessage() . " | [{$snippet}]", 0, $pe);
                            }
                        };

                        for ($i = 0; $i < $len; $i++) {
                            $char = $rawSql[$i];

                            // Skip single-line comments (-- or #) when not inside string
                            if (!$inString && ($char === '-' && ($rawSql[$i + 1] ?? '') === '-') || (!$inString && $char === '#')) {
                                $nextNL = strpos($rawSql, "\n", $i);
                                if ($nextNL === false) break;
                                $i = $nextNL;
                                continue;
                            }

                            // Skip multi-line comments (/* ... */) when not inside string
                            if (!$inString && $char === '/' && ($rawSql[$i + 1] ?? '') === '*') {
                                $endComment = strpos($rawSql, '*/', $i + 2);
                                if ($endComment === false) break;
                                $i = $endComment + 1;
                                continue;
                            }

                            // String toggle
                            if (($char === "'" || $char === '"') && !$inString) {
                                $inString = true;
                                $stringChar = $char;
                            } elseif ($inString && $char === $stringChar) {
                                if ($rawSql[$i - 1] !== '\\') {
                                    $inString = false;
                                }
                            }

                            // Statement terminator outside strings
                            if (!$inString && $char === ';') {
                                $executeStmt($testPdo, $stmt, $statementCount);
                                $stmt = '';
                                continue;
                            }

                            $stmt .= $char;
                        }

                        if (trim($stmt) !== '') {
                            $executeStmt($testPdo, $stmt, $statementCount);
                        }
                    } finally {
                        try {
                            $testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                        } catch (Throwable) {
                        }
                    }

                    // Write config/database.php using var_export() and strict type casting
                    $vHost = var_export($dbHost, true);
                    $vPort = (int)$dbPort;
                    $vName = var_export($dbName, true);
                    $vUser = var_export($dbUser, true);
                    $vPass = var_export($dbPass, true);

                    $dbConfigCode = "<?php\ndeclare(strict_types=1);\n\n"
                        . "/**\n * AaZa HRMS - Production Database Configuration\n"
                        . " * Generated automatically by installer on " . date('Y-m-d H:i:s') . "\n */\n\n"
                        . "define('DB_HOST', {$vHost});\n"
                        . "define('DB_PORT', {$vPort});\n"
                        . "define('DB_NAME', {$vName});\n"
                        . "define('DB_USER', {$vUser});\n"
                        . "define('DB_PASS', {$vPass});\n"
                        . "define('DB_CHARSET', 'utf8mb4');\n\n"
                        . "function db_create_connection(): PDO\n{\n"
                        . "    \$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;\n"
                        . "    \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [\n"
                        . "        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,\n"
                        . "        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,\n"
                        . "        PDO::ATTR_EMULATE_PREPARES         => false,\n"
                        . "        PDO::ATTR_PERSISTENT               => false,\n"
                        . "        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,\n"
                        . "    ]);\n"
                        . "    \$pdo->exec(\"SET time_zone = '\" . date('P') . \"'\");\n"
                        . "    return \$pdo;\n"
                        . "}\n\n"
                        . "function db(): PDO\n{\n"
                        . "    static \$pdo = null;\n"
                        . "    static \$reconnected = false;\n"
                        . "    if (\$pdo === null) {\n"
                        . "        \$pdo = db_create_connection();\n"
                        . "    } else {\n"
                        . "        try {\n"
                        . "            if (!\$reconnected) { \$pdo->query('SELECT 1'); }\n"
                        . "        } catch (Throwable \$e) {\n"
                        . "            \$errCode = (int)(\$e->errorInfo[1] ?? 0);\n"
                        . "            if (!\$reconnected && (\$errCode === 2006 || \$errCode === 2013)) {\n"
                        . "                \$reconnected = true;\n"
                        . "                \$pdo = db_create_connection();\n"
                        . "            } else {\n"
                        . "                throw \$e;\n"
                        . "            }\n"
                        . "        }\n"
                        . "    }\n"
                        . "    return \$pdo;\n"
                        . "}\n";

                    file_put_contents($dbConfigFile, $dbConfigCode);
                    @chmod($dbConfigFile, 0640);

                    $_SESSION['install_step'] = 3;
                    header('Location: setup.php?step=3');
                    exit;

                } catch (PDOException $pe) {
                    $errors[] = map_db_exception($pe);
                } catch (Throwable $e) {
                    error_log('[AaZa Installer DB Error] ' . $e->getMessage());
                    $logDir = dirname(__DIR__) . '/storage/logs';
                    if (!is_dir($logDir)) {
                        @mkdir($logDir, 0755, true);
                    }
                    @file_put_contents($logDir . '/app.log', '[' . date('Y-m-d H:i:s') . '] [AaZa Installer DB Error] ' . $e->getMessage() . "\n", FILE_APPEND);
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }

        // Step 3: Organization & Administrator Provisioning
        if ($action === 'step3_admin') {
            $appName     = trim((string)($_POST['app_name'] ?? 'AaZa HRMS'));
            $baseUrl     = trim((string)($_POST['base_url'] ?? ''));
            $timezone    = trim((string)($_POST['timezone'] ?? 'Asia/Colombo'));
            $currency    = strtoupper(trim((string)($_POST['currency'] ?? 'LKR')));
            $companyMode = (string)($_POST['company_mode'] ?? ($hasExistingCompanies ? 'use_existing' : 'add_new'));
            $companyName = trim((string)($_POST['company_name'] ?? ''));
            $companyCode = strtoupper(trim((string)($_POST['company_code'] ?? '')));
            $fullName    = trim((string)($_POST['full_name'] ?? ''));
            $username    = trim((string)($_POST['username'] ?? ''));
            $email       = trim((string)($_POST['email'] ?? ''));
            $password    = (string)($_POST['password'] ?? '');
            $confirm     = (string)($_POST['password_confirm'] ?? '');

            $baseUrl = rtrim($baseUrl, '/');

            // Strict parameter validations
            if ($appName === '' || mb_strlen($appName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $appName)) {
                $errors[] = 'Valid application name is required (max 100 characters).';
            }
            if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL) && !preg_match('#^/[A-Za-z0-9._/-]*$#', $baseUrl)) {
                $errors[] = 'Base URL must be a valid HTTP/HTTPS URL or relative web root path (e.g. /aaza_hrms or empty for domain root).';
            }
            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                $errors[] = 'Invalid timezone identifier selected. Must match standard timezone list (e.g. Asia/Colombo, UTC).';
            }
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $errors[] = 'Currency code must be a 3-letter uppercase ISO code (e.g. LKR, USD, EUR).';
            }

            // Company mode validation
            if ($companyMode === 'add_new') {
                if ($companyName === '' || mb_strlen($companyName) > 150) {
                    $errors[] = 'Company name is required (max 150 chars).';
                }
                if (!preg_match('/^[A-Za-z0-9_-]{2,10}$/', $companyCode)) {
                    $errors[] = 'Company code must be 2-10 alphanumeric characters (e.g. AZT).';
                }
            } elseif ($companyMode === 'use_existing') {
                if (!$hasExistingCompanies) {
                    $errors[] = 'No existing companies found. Please add a company.';
                }
            } else {
                $errors[] = 'Invalid company setup mode selected.';
            }

            if ($fullName === '' || mb_strlen($fullName) > 150) $errors[] = 'Administrator full name is required.';
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) $errors[] = 'Username must be 3-60 chars (letters, numbers, dot, dash, underscore).';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
            if (strlen($password) < 10 || !preg_match('/[0-9]/', $password) || !preg_match('/[A-Za-z]/', $password)) {
                $errors[] = 'Password must be at least 10 characters and contain both letters and numbers.';
            } elseif ($password !== $confirm) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                try {
                    require_once $dbConfigFile;
                    $pdo = db();

                    // Check uniqueness BEFORE attempting insertion
                    if ($companyMode === 'add_new') {
                        $chkCode = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE UPPER(code) = ?");
                        $chkCode->execute([$companyCode]);
                        if ((int)$chkCode->fetchColumn() > 0) {
                            $errors[] = 'Code already exists, choose another';
                        }

                        $chkName = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE LOWER(name) = ?");
                        $chkName->execute([mb_strtolower($companyName)]);
                        if ((int)$chkName->fetchColumn() > 0) {
                            $errors[] = 'Company name already exists, choose another';
                        }
                    }

                    // Check admin username or email uniqueness
                    $chkUser = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(username) = ?");
                    $chkUser->execute([mb_strtolower($username)]);
                    if ((int)$chkUser->fetchColumn() > 0) {
                        $errors[] = 'Username is already taken, choose another';
                    }

                    $chkEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(email) = ?");
                    $chkEmail->execute([mb_strtolower($email)]);
                    if ((int)$chkEmail->fetchColumn() > 0) {
                        $errors[] = 'Email address is already registered';
                    }

                    if (!empty($errors)) {
                        throw new InvalidArgumentException(implode("\n", $errors));
                    }

                    // Execute all database modifications in ONE atomic transaction
                    $pdo->beginTransaction();

                    // 1. Company Handling
                    if ($companyMode === 'add_new') {
                        $insComp = $pdo->prepare("INSERT INTO companies (code, name, email, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
                        $insComp->execute([$companyCode, $companyName, $email]);
                        $companyId = (int)$pdo->lastInsertId();

                        // Seed currency code setting for new company
                        $insSetting = $pdo->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, 'general.currency_code', ?)");
                        $insSetting->execute([$companyId, $currency]);
                    } else {
                        // Use existing companies as they are (create NO new company).
                        // Ensure currency code setting is present for all existing companies
                        $stCompIds = $pdo->query("SELECT id FROM companies WHERE is_active = 1");
                        $existingIds = $stCompIds->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($existingIds as $cId) {
                            $chkSetting = $pdo->prepare("SELECT COUNT(*) FROM company_settings WHERE company_id = ? AND setting_key = 'general.currency_code'");
                            $chkSetting->execute([$cId]);
                            if ((int)$chkSetting->fetchColumn() === 0) {
                                $insSetting = $pdo->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value) VALUES (?, 'general.currency_code', ?)");
                                $insSetting->execute([$cId, $currency]);
                            }
                        }
                    }

                    // 2. Create First Group Administrator
                    // Assigned to NO company by default (is_group_admin = 1 has full access across all companies)
                    $pwHash = password_hash($password, PASSWORD_DEFAULT);
                    $insUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, is_group_admin, is_active, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())");
                    $insUser->execute([$username, $email, $pwHash, $fullName]);
                    $userId = (int)$pdo->lastInsertId();

                    // 2b. Optional: Load demo data if requested
                    $demoSeededInfo = null;
                    if (!empty($_POST['load_demo_data'])) {
                        $targetCompanyId = 0;
                        if ($companyMode === 'add_new' && isset($companyId) && $companyId > 0) {
                            $targetCompanyId = (int)$companyId;
                        } else {
                            $firstCompSt = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1");
                            $targetCompanyId = (int)($firstCompSt->fetchColumn() ?: 1);
                        }
                        if ($targetCompanyId > 0) {
                            require_once $rootDir . '/includes/demo_seed.php';
                            $genDemoPass = 'Demo#' . bin2hex(random_bytes(3)) . '!9A';
                            $demoCounts = demo_seed($pdo, $targetCompanyId, $genDemoPass);
                            $demoSeededInfo = [
                                'company_id' => $targetCompanyId,
                                'password'   => $genDemoPass,
                                'counts'     => $demoCounts,
                            ];
                        }
                    }

                    // Commit the database transaction
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    // 3. Write configuration overrides to config/config.php ONLY AFTER COMMIT SUCCEEDS
                    $configFile = $rootDir . '/config/config.php';
                    $vAppName  = var_export($appName, true);
                    $vBaseUrl  = var_export($baseUrl, true);
                    $vTimezone = var_export($timezone, true);

                    $configCode = "<?php\ndeclare(strict_types=1);\n\n"
                        . "/**\n * AaZa HRMS - Application Configuration\n"
                        . " * Configured by Installer on " . date('Y-m-d H:i:s') . "\n */\n\n"
                        . "define('APP_NAME', {$vAppName});\n"
                        . "define('APP_ENV', 'production');\n"
                        . "define('APP_DEBUG', false);\n"
                        . "define('APP_TIMEZONE', {$vTimezone});\n\n"
                        . "define('BASE_URL', {$vBaseUrl});\n\n"
                        . "define('ROOT_PATH', dirname(__DIR__));\n"
                        . "define('STORAGE_PATH', ROOT_PATH . '/storage');\n\n"
                        . "define('SESSION_NAME', 'AAZASESSID');\n"
                        . "define('SESSION_IDLE_TIMEOUT', 1800);\n"
                        . "define('MAX_FAILED_LOGINS', 5);\n"
                        . "define('LOCKOUT_MINUTES', 15);\n"
                        . "define('PASSWORD_MIN_LENGTH', 10);\n"
                        . "define('PER_PAGE', 20);\n\n"
                        . "if (!defined('WEEKLY_OFF_DAYS')) { define('WEEKLY_OFF_DAYS', [6, 7]); }\n"
                        . "if (!defined('ATTENDANCE_SELF_SERVICE')) { define('ATTENDANCE_SELF_SERVICE', false); }\n"
                        . "if (!defined('ATTENDANCE_WORK_START')) { define('ATTENDANCE_WORK_START', '09:00'); }\n"
                        . "if (!defined('LEAVE_MAX_RANGE_DAYS')) { define('LEAVE_MAX_RANGE_DAYS', 90); }\n"
                        . "if (!defined('BACKUP_PATH')) { define('BACKUP_PATH', ROOT_PATH . '/storage/backups'); }\n"
                        . "if (!defined('BACKUP_RETENTION_COUNT')) { define('BACKUP_RETENTION_COUNT', 14); }\n";

                    file_put_contents($configFile, $configCode);
                    @chmod($configFile, 0640);

                    // 4. Create storage/installed.lock ONLY AFTER COMMIT SUCCEEDS
                    file_put_contents($lockFile, json_encode([
                        'installed_at' => date('c'),
                        'installed_by' => $username,
                        'app_name'     => $appName,
                    ], JSON_PRETTY_PRINT));
                    @chmod($lockFile, 0640);

                    // 5. Complete install session and redirect to Finish Screen (DO NOT AUTO-UNLINK)
                    $_SESSION['install_completed'] = true;
                    $_SESSION['install_step'] = 4;
                    if ($demoSeededInfo !== null) {
                        $_SESSION['demo_seeded_info'] = $demoSeededInfo;
                    }

                    header('Location: setup.php?step=finish');
                    exit;

                } catch (InvalidArgumentException) {
                    // Handled via $errors array populated before transaction
                } catch (PDOException $pe) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = map_db_exception($pe);
                } catch (Throwable $e) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[AaZa Installer Step 3 Error] ' . $e->getMessage());
                    $logDir = dirname(__DIR__) . '/storage/logs';
                    if (!is_dir($logDir)) {
                        @mkdir($logDir, 0755, true);
                    }
                    @file_put_contents($logDir . '/app.log', '[' . date('Y-m-d H:i:s') . '] [AaZa Installer Step 3 Error] ' . $e->getMessage() . "\n", FILE_APPEND);
                    $errors[] = 'Failed to provision organization and administrator: ' . $e->getMessage();
                }
            }
        }
    }
}

// -------------------------------------------------------------
// System Requirements Assessment for Step 1
// -------------------------------------------------------------
$reqs = [
    'php_version' => [
        'title'    => 'PHP Version (>= 8.0)',
        'pass'     => version_compare(PHP_VERSION, '8.0.0', '>='),
        'detail'   => 'Current: ' . PHP_VERSION,
        'required' => true,
    ],
    'pdo_mysql' => [
        'title'    => 'PDO MySQL Extension',
        'pass'     => extension_loaded('pdo_mysql'),
        'detail'   => extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing',
        'required' => true,
    ],
    'mbstring' => [
        'title'    => 'Multibyte String (mbstring) Extension',
        'pass'     => extension_loaded('mbstring'),
        'detail'   => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
        'required' => true,
    ],
    'fileinfo' => [
        'title'    => 'File Information (fileinfo) Extension',
        'pass'     => extension_loaded('fileinfo'),
        'detail'   => extension_loaded('fileinfo') ? 'Loaded' : 'Missing',
        'required' => true,
    ],
    'zip' => [
        'title'    => 'Zip Extension (for backups)',
        'pass'     => extension_loaded('zip'),
        'detail'   => extension_loaded('zip') ? 'Loaded' : 'Missing',
        'required' => true,
    ],
    'gd' => [
        'title'    => 'GD Image Library (for logos & photos)',
        'pass'     => extension_loaded('gd'),
        'detail'   => extension_loaded('gd') ? 'Loaded' : 'Optional (Recommended)',
        'required' => false,
    ],
    'storage_writable' => [
        'title'    => 'Directory Permissions: storage/',
        'pass'     => is_dir($rootDir . '/storage') && is_writable($rootDir . '/storage'),
        'detail'   => is_writable($rootDir . '/storage') ? 'Writable' : 'Not Writable (chmod 0755 or 0775 required)',
        'required' => true,
    ],
    'uploads_writable' => [
        'title'    => 'Directory Permissions: assets/uploads/',
        'pass'     => is_dir($rootDir . '/assets/uploads') && is_writable($rootDir . '/assets/uploads'),
        'detail'   => is_writable($rootDir . '/assets/uploads') ? 'Writable' : 'Not Writable (chmod 0755 or 0775 required)',
        'required' => true,
    ],
];

$allReqsPassed = true;
foreach ($reqs as $r) {
    if ($r['required'] && !$r['pass']) {
        $allReqsPassed = false;
        break;
    }
}

// Auto-detect Base URL for Step 3
$detectedBaseUrl = '';
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '') : '';
$appRoot = str_replace('\\', '/', $rootDir);
if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
    $sub = trim(substr($appRoot, strlen($docRoot)), '/');
    $detectedBaseUrl = $sub !== '' ? '/' . $sub : '';
}

// Determine active company mode for form rendering
$companyMode = (string)($_POST['company_mode'] ?? ($hasExistingCompanies ? 'use_existing' : 'add_new'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Wizard · AaZa HRMS</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="stylesheet" href="../assets/vendor/inter/inter.css">
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f8fafc; }
        .setup-card { max-width: 680px; margin: 40px auto; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .step-pill { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
        .step-active { background-color: #0d6efd; color: #fff; }
        .step-done { background-color: #198754; color: #fff; }
        .step-pending { background-color: #e2e8f0; color: #64748b; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="text-center mb-4">
            <img src="../assets/img/aaza-logo.png" alt="AaZa HRMS" style="max-height: 52px; object-fit: contain;">
            <h2 class="h4 fw-bold mt-2 text-dark">Production Setup Wizard</h2>
            <p class="text-muted small">Configure and provision your AaZa HRMS instance in 3 simple steps</p>
        </div>

        <div class="card setup-card border-0 bg-white p-4 p-md-5">
            <?php if ($currentStep < 4): ?>
                <!-- Wizard Step Indicator -->
                <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="step-pill <?= $currentStep === 1 ? 'step-active' : ($currentStep > 1 ? 'step-done' : 'step-pending') ?>">1</span>
                        <span class="small fw-semibold <?= $currentStep === 1 ? 'text-primary' : 'text-secondary' ?>">Requirements</span>
                    </div>
                    <div class="flex-grow-1 border-top mx-2 border-secondary-subtle"></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="step-pill <?= $currentStep === 2 ? 'step-active' : ($currentStep > 2 ? 'step-done' : 'step-pending') ?>">2</span>
                        <span class="small fw-semibold <?= $currentStep === 2 ? 'text-primary' : 'text-secondary' ?>">Database</span>
                    </div>
                    <div class="flex-grow-1 border-top mx-2 border-secondary-subtle"></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="step-pill <?= $currentStep === 3 ? 'step-active' : 'step-pending' ?>">3</span>
                        <span class="small fw-semibold <?= $currentStep === 3 ? 'text-primary' : 'text-secondary' ?>">Organization</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2 small mb-4">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= h($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($currentStep === 1): ?>
                <!-- ================= STEP 1 ================= -->
                <h5 class="fw-bold mb-3 text-dark">System Requirements &amp; Permissions</h5>
                <p class="text-secondary small mb-3">Checking your hosting environment against AaZa HRMS operational requirements.</p>

                <div class="list-group list-group-flush border rounded-3 mb-4">
                    <?php foreach ($reqs as $k => $r): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                            <div>
                                <span class="fw-medium small"><?= h($r['title']) ?></span>
                                <div class="text-muted" style="font-size: 11px;"><?= h($r['detail']) ?></div>
                            </div>
                            <?php if ($r['pass']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">Passed</span>
                            <?php elseif (!$r['required']): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 small">Optional</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 small">Failed</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row g-2 mb-4 bg-light p-3 rounded-3 border">
                    <div class="col-sm-6 small text-secondary">
                        <strong>upload_max_filesize:</strong> <?= h(ini_get('upload_max_filesize')) ?>
                    </div>
                    <div class="col-sm-6 small text-secondary">
                        <strong>post_max_size:</strong> <?= h(ini_get('post_max_size')) ?>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="step1_next">
                    <button type="submit" class="btn btn-primary w-100" <?= !$allReqsPassed ? 'disabled' : '' ?>>
                        Continue to Database Setup &rarr;
                    </button>
                    <?php if (!$allReqsPassed): ?>
                        <p class="text-danger small text-center mt-2 mb-0">Please resolve the failed requirements above before continuing.</p>
                    <?php endif; ?>
                </form>

            <?php elseif ($currentStep === 2): ?>
                <!-- ================= STEP 2 ================= -->
                <h5 class="fw-bold mb-2 text-dark">Database Configuration</h5>
                <p class="text-secondary small mb-4">Enter your MySQL / MariaDB credentials. The installer will test connection and import the complete schema automatically.</p>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="step2_db">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary">Database Host</label>
                            <input type="text" name="db_host" class="form-control" value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Port</label>
                            <input type="number" name="db_port" class="form-control" value="<?= h((string)($_POST['db_port'] ?? '3306')) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Database Name</label>
                        <input type="text" name="db_name" class="form-control" placeholder="e.g. cpaneluser_aazahrms" value="<?= h($_POST['db_name'] ?? '') ?>" required>
                        <div class="form-text small">Ensure the database has been created in your control panel (cPanel, Plesk, or phpMyAdmin).</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Database Username</label>
                            <input type="text" name="db_user" class="form-control" placeholder="e.g. cpaneluser_dbadmin" value="<?= h($_POST['db_user'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Database Password</label>
                            <input type="password" name="db_pass" class="form-control" placeholder="Enter database password" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="form-check mb-4 bg-light p-3 rounded border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="reuse_existing" value="1" id="reuseExisting" <?= !empty($_POST['reuse_existing']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="reuseExisting">
                            <strong>I understand existing data will be reused</strong>
                            <div class="text-muted" style="font-size: 11px;">Tick only if targeting a database with existing tables. Target database must not have registered user accounts.</div>
                        </label>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="setup.php?step=1" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
                        <button type="submit" class="btn btn-primary px-4">
                            Test &amp; Migrate Database &rarr;
                        </button>
                    </div>
                </form>

            <?php elseif ($currentStep === 3): ?>
                <!-- ================= STEP 3 ================= -->
                <h5 class="fw-bold mb-2 text-dark">Organization &amp; Administrator Account</h5>
                <p class="text-secondary small mb-4">Finalize your system settings and provision your first Group Administrator superuser.</p>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="step3_admin">

                    <h6 class="fw-bold text-primary border-bottom pb-1 mb-3">1. System &amp; Regional Settings</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Application Name</label>
                            <input type="text" name="app_name" class="form-control" value="<?= h($_POST['app_name'] ?? 'AaZa HRMS') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Base URL (Subfolder or Domain Root)</label>
                            <input type="text" name="base_url" class="form-control" value="<?= h($_POST['base_url'] ?? $detectedBaseUrl) ?>" placeholder="e.g. /aaza_hrms or empty for root">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">System Timezone</label>
                            <input type="text" name="timezone" class="form-control" list="tzList" value="<?= h($_POST['timezone'] ?? 'Asia/Colombo') ?>" required>
                            <datalist id="tzList">
                                <?php foreach (timezone_identifiers_list() as $tzId): ?>
                                    <option value="<?= h($tzId) ?>"><?= h($tzId) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Default Currency Code</label>
                            <input type="text" name="currency" class="form-control" value="<?= h($_POST['currency'] ?? 'LKR') ?>" maxlength="3" required>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary border-bottom pb-1 mb-3">2. Company Setup</h6>
                    <?php if ($hasExistingCompanies): ?>
                        <div class="border rounded-3 p-3 bg-light mb-3">
                            <div class="small fw-semibold text-secondary mb-2">Detected Seeded Companies (<?= count($existingCompanies) ?>):</div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach ($existingCompanies as $ec): ?>
                                    <span class="badge bg-white text-dark border px-2.5 py-1.5 shadow-xs">
                                        <strong class="text-primary"><?= h($ec['code']) ?></strong> &middot; <?= h($ec['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="company_mode" id="modeExisting" value="use_existing" <?= ($companyMode === 'use_existing') ? 'checked' : '' ?> onchange="toggleCompanyFields()">
                                <label class="form-check-label small fw-semibold text-dark" for="modeExisting">
                                    Use existing companies as they are (recommended)
                                </label>
                                <div class="text-muted" style="font-size: 11px;">Proceeds with existing companies without creating duplicate company entries.</div>
                            </div>

                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="company_mode" id="modeNew" value="add_new" <?= ($companyMode === 'add_new') ? 'checked' : '' ?> onchange="toggleCompanyFields()">
                                <label class="form-check-label small fw-semibold text-dark" for="modeNew">
                                    Add one more company
                                </label>
                                <div class="text-muted" style="font-size: 11px;">Register an additional company subsidiary for your organization.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="company_mode" value="add_new">
                    <?php endif; ?>

                    <div id="newCompanyFields" class="row g-3 mb-4" style="<?= ($companyMode === 'add_new') ? '' : 'display: none;' ?>">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold text-secondary">Company Name</label>
                            <input type="text" name="company_name" id="newCompanyName" class="form-control" placeholder="e.g. AaZa Global" value="<?= h($_POST['company_name'] ?? '') ?>" <?= ($companyMode === 'add_new') ? 'required' : '' ?>>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Code</label>
                            <input type="text" name="company_code" id="newCompanyCode" class="form-control" placeholder="e.g. AZG" value="<?= h($_POST['company_code'] ?? '') ?>" maxlength="10" <?= ($companyMode === 'add_new') ? 'required' : '' ?>>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary border-bottom pb-1 mb-3">3. First Group Administrator</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Full Name</label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. System Administrator" value="<?= h($_POST['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. admin" value="<?= h($_POST['username'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Administrator Email</label>
                        <input type="email" name="email" class="form-control" placeholder="admin@example.com" value="<?= h($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Min. 10 chars, letter + number" autocomplete="new-password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Confirm Password</label>
                            <input type="password" name="password_confirm" class="form-control" placeholder="Confirm password" autocomplete="new-password" required>
                        </div>
                    </div>

                    <div class="card border border-primary-subtle bg-primary-subtle bg-opacity-10 rounded-3 p-3 mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="load_demo_data" value="1" id="loadDemoData" <?= !empty($_POST['load_demo_data']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold text-dark small" for="loadDemoData">
                                Also load demo data (fake)
                            </label>
                            <div class="form-text small text-secondary">
                                Seeds 12 employees, 3 demo user logins (<code>demo_hr</code>, <code>demo_manager</code>, <code>demo_employee</code>), leave requests, vacancies, and attendance for immediate testing. (Default: Off)
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="setup.php?step=2" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
                        <button type="submit" class="btn btn-success px-4 fw-semibold">
                            Complete Installation &rarr;
                        </button>
                    </div>
                </form>

                <script>
                function toggleCompanyFields() {
                    var isNew = !document.getElementById('modeExisting') || (document.getElementById('modeNew') && document.getElementById('modeNew').checked);
                    var container = document.getElementById('newCompanyFields');
                    var nameInput = document.getElementById('newCompanyName');
                    var codeInput = document.getElementById('newCompanyCode');
                    if (container) {
                        container.style.display = isNew ? '' : 'none';
                    }
                    if (nameInput) {
                        nameInput.required = isNew;
                    }
                    if (codeInput) {
                        codeInput.required = isNew;
                    }
                }
                </script>

            <?php elseif ($currentStep === 4): ?>
                <!-- ================= FINISH SCREEN ================= -->
                <div class="text-center mb-4">
                    <div class="text-success mb-2" style="font-size: 3.5rem;">&#10004;</div>
                    <h4 class="fw-bold text-dark">Installation Completed Successfully!</h4>
                    <p class="text-secondary small">Your system database, subsidiary company, and first administrator account have been created.</p>
                </div>

                <?php if (!empty($_SESSION['demo_seeded_info'])): ?>
                <div class="card border border-success-subtle bg-success-subtle bg-opacity-10 rounded-3 p-3 mb-4">
                    <h6 class="fw-bold text-success mb-2">&#10004; Demonstration Data Loaded</h6>
                    <p class="small text-secondary mb-2">Three demo accounts have been created for testing:</p>
                    <div class="small mb-2">
                        <code>demo_hr</code> (HR Manager) &bull; <code>demo_manager</code> (Department Manager) &bull; <code>demo_employee</code> (Staff Employee)
                    </div>
                    <div class="small">
                        <strong>Demo Password:</strong> <code class="bg-white px-2 py-1 rounded border user-select-all"><?= h($_SESSION['demo_seeded_info']['password']) ?></code> (Save this password now)
                    </div>
                </div>
                <?php endif; ?>

                <div class="alert alert-warning border border-warning-subtle rounded-3 p-3 mb-4">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="fs-4 text-warning">&#9888;</div>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Delete public/setup.php from the server now!</h6>
                            <p class="small text-secondary mb-1">For production security, the installer must be removed from your server. Hosting users can delete <code>public/setup.php</code> using their cPanel File Manager or FTP client, or click the button below to delete it now.</p>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-2">
                    <form method="post" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_installer">
                        <button type="submit" class="btn btn-outline-danger px-3 py-2 fw-medium">
                            <i class="bi bi-trash3 me-1"></i> Delete installer now
                        </button>
                    </form>
                    <a href="login.php" class="btn btn-primary px-4 py-2 fw-medium">
                        Go to Login &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
