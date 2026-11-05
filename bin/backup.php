<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Automated Database & Uploads Backup Utility
 *
 * Usage (CLI only):
 *   php bin/backup.php [options]
 *
 * Options:
 *   --uploads      Also create a ZIP archive of storage/uploads directory
 *   --keep=N       Number of historical backups to retain (default: 14)
 *   --path=DIR     Custom destination directory for backup archives
 *   --mysqldump=P  Explicit path to mysqldump binary
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "ERROR: This utility can only be executed via the command-line interface (CLI).\n";
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

// Parse CLI arguments
$options = getopt('', ['uploads', 'keep:', 'path:', 'mysqldump:']);
$includeUploads = isset($options['uploads']);
$retentionCount = isset($options['keep']) ? max(1, (int)$options['keep']) : (defined('BACKUP_RETENTION_COUNT') ? BACKUP_RETENTION_COUNT : 14);
$backupDir = isset($options['path']) ? (string)$options['path'] : (defined('BACKUP_PATH') ? BACKUP_PATH : STORAGE_PATH . '/backups');
$customMysqldump = $options['mysqldump'] ?? (defined('BACKUP_MYSQLDUMP_PATH') ? BACKUP_MYSQLDUMP_PATH : null);

echo "============================================================\n";
echo " AaZa HRMS - Backup System (" . date('Y-m-d H:i:s') . ")\n";
echo "============================================================\n";

// Ensure backup destination directory exists
if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0750, true)) {
        $msg = "Failed to create backup directory at: $backupDir";
        error_log("[BACKUP ERROR] $msg");
        echo "ERROR: $msg\n";
        exit(1);
    }
}

// Locate mysqldump executable
function find_mysqldump(?string $customPath): ?string
{
    if ($customPath !== null && is_file($customPath) && is_executable($customPath)) {
        return $customPath;
    }

    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.4\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.11\\bin\\mysqldump.exe',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
    ];

    foreach ($candidates as $cand) {
        if (is_file($cand)) {
            return $cand;
        }
    }

    // Try finding in PATH
    $checkCmd = (PHP_OS_FAMILY === 'Windows') ? 'where mysqldump 2>nul' : 'which mysqldump 2>/dev/null';
    $output = trim((string)shell_exec($checkCmd));
    if ($output !== '') {
        $lines = explode("\n", $output);
        $first = trim($lines[0]);
        if (is_file($first)) {
            return $first;
        }
    }

    return null;
}

$dumpBin = find_mysqldump($customMysqldump);
if ($dumpBin === null) {
    $msg = "mysqldump executable could not be found. Specify path via --mysqldump=... or config.";
    error_log("[BACKUP ERROR] $msg");
    echo "ERROR: $msg\n";
    exit(1);
}

echo "Using mysqldump: {$dumpBin}\n";
echo "Database:        " . DB_NAME . " on " . DB_HOST . "\n";
echo "Backup Path:     {$backupDir}\n";

$timestamp = date('Ymd_His');
$sqlGzFilename = 'aaza_hrms_db_' . $timestamp . '.sql.gz';
$sqlGzPath = $backupDir . '/' . $sqlGzFilename;

// Write temporary MySQL credentials defaults file to prevent exposing password on CLI
$tempCredsFile = tempnam(sys_get_temp_dir(), 'aaza_my_');
if ($tempCredsFile === false) {
    $msg = "Unable to create secure temporary credentials file.";
    error_log("[BACKUP ERROR] $msg");
    echo "ERROR: $msg\n";
    exit(1);
}

$credsContent = "[client]\n"
    . "user=" . DB_USER . "\n"
    . "password=\"" . str_replace(['\\', '"'], ['\\\\', '\"'], DB_PASS) . "\"\n"
    . "host=" . DB_HOST . "\n";

file_put_contents($tempCredsFile, $credsContent);
@chmod($tempCredsFile, 0600);

$dumpSuccess = false;
$sqlOutput = '';

try {
    $cmd = escapeshellarg($dumpBin)
        . ' --defaults-extra-file=' . escapeshellarg($tempCredsFile)
        . ' --single-transaction'
        . ' --routines'
        . ' --triggers'
        . ' --quick'
        . ' ' . escapeshellarg(DB_NAME);

    // Execute mysqldump
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to launch mysqldump process.");
    }

    fclose($pipes[0]);
    $rawDump = stream_get_contents($pipes[1]);
    $dumpErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || strlen($rawDump) < 500) {
        throw new RuntimeException("mysqldump failed (exit code $exitCode): " . trim($dumpErrors));
    }

    // Compress with maximum compression (gzip level 9)
    $compressedSql = gzencode($rawDump, 9);
    if ($compressedSql === false) {
        throw new RuntimeException("Failed to gzip-compress SQL dump stream.");
    }

    if (file_put_contents($sqlGzPath, $compressedSql) === false) {
        throw new RuntimeException("Failed to write database archive to: $sqlGzPath");
    }

    $dbSizeBytes = filesize($sqlGzPath);
    $dbSizeFormatted = number_format($dbSizeBytes / 1024, 1) . ' KB';
    echo "[PASS] Database backup created: {$sqlGzFilename} ({$dbSizeFormatted})\n";
    $dumpSuccess = true;
} catch (Throwable $e) {
    $msg = "Database backup failed: " . $e->getMessage();
    error_log("[BACKUP ERROR] $msg");
    echo "[FAIL] $msg\n";
} finally {
    if (file_exists($tempCredsFile)) {
        @unlink($tempCredsFile);
    }
}

if (!$dumpSuccess) {
    exit(1);
}

// -------------------------------------------------------------
// Optional: Zip storage/uploads directory
// -------------------------------------------------------------
$uploadsArchived = false;
if ($includeUploads) {
    $uploadsDir = STORAGE_PATH . '/uploads';
    $zipFilename = 'aaza_hrms_uploads_' . $timestamp . '.zip';
    $zipPath = $backupDir . '/' . $zipFilename;

    if (!is_dir($uploadsDir)) {
        echo "[SKIP] Uploads directory not found ($uploadsDir).\n";
    } elseif (!class_exists('ZipArchive')) {
        echo "[SKIP] ZipArchive extension not installed in PHP CLI. Uploads zip skipped.\n";
    } else {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $fileCount = 0;
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($uploadsDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                    $fileCount++;
                }
            }
            $zip->close();
            $zipSizeBytes = filesize($zipPath);
            $zipSizeFormatted = number_format($zipSizeBytes / 1024, 1) . ' KB';
            echo "[PASS] Uploads archive created: {$zipFilename} ({$fileCount} files, {$zipSizeFormatted})\n";
            $uploadsArchived = true;
        } else {
            echo "[FAIL] Unable to open ZIP archive at: {$zipPath}\n";
        }
    }
}

// -------------------------------------------------------------
// Pruning: Retain last N backups
// -------------------------------------------------------------
function prune_archives(string $dir, string $prefix, string $ext, int $keep): int
{
    $pattern = $dir . '/' . $prefix . '*.' . $ext;
    $files = glob($pattern);
    if (!$files || count($files) <= $keep) {
        return 0;
    }

    // Sort descending by mtime (newest first)
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

    $deleted = 0;
    $toDelete = array_slice($files, $keep);
    foreach ($toDelete as $oldFile) {
        if (@unlink($oldFile)) {
            $deleted++;
        }
    }
    return $deleted;
}

$prunedDb = prune_archives($backupDir, 'aaza_hrms_db_', 'sql.gz', $retentionCount);
$prunedUploads = prune_archives($backupDir, 'aaza_hrms_uploads_', 'zip', $retentionCount);

if ($prunedDb > 0 || $prunedUploads > 0) {
    echo "[INFO] Pruned {$prunedDb} old DB backup(s) and {$prunedUploads} old uploads archive(s) (Retention: {$retentionCount}).\n";
}

// -------------------------------------------------------------
// Final Audit Log Entry
// -------------------------------------------------------------
$logMsg = "[BACKUP] Successfully created database backup '{$sqlGzFilename}' (" . round($dbSizeBytes / 1024, 1) . " KB)."
    . ($uploadsArchived ? " Uploads archived." : "")
    . " Pruned {$prunedDb} old archives. Retention cap: {$retentionCount}.";

error_log($logMsg);

echo "============================================================\n";
echo " Backup Completed Successfully.\n";
echo "============================================================\n";
exit(0);
