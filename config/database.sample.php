<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Database Configuration Template.
 * Copy this file to database.php and configure with secure credentials.
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_secure_db_password');
define('DB_CHARSET', 'utf8mb4');

function db_create_connection(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES         => false,
        PDO::ATTR_PERSISTENT               => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    // Keep MySQL timestamps in the same timezone as PHP
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    static $reconnected = false;

    if ($pdo === null) {
        $pdo = db_create_connection();
    } else {
        try {
            if (!$reconnected) {
                $pdo->query('SELECT 1');
            }
        } catch (Throwable $e) {
            $errCode = (int)($e->errorInfo[1] ?? 0);
            if (!$reconnected && ($errCode === 2006 || $errCode === 2013)) {
                $reconnected = true;
                $pdo = db_create_connection();
            } else {
                throw $e;
            }
        }
    }
    return $pdo;
}
