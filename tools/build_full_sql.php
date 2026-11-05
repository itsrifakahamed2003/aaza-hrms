<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Database Migration Consolidator
 * 
 * Compiles all migration files in database/migrations/ in sequential order
 * into a single unified SQL installer: database/aaza_hrms_full.sql.
 * 
 * Usage:
 *   php tools/build_full_sql.php           # Regenerates database/aaza_hrms_full.sql
 *   php tools/build_full_sql.php --check   # Returns 0 if committed matches generated, 1 otherwise
 */

$rootDir = dirname(__DIR__);
$migrationsDir = $rootDir . '/database/migrations';
$outputFile = $rootDir . '/database/aaza_hrms_full.sql';

// Documented sequential order of migrations
$migrationSequence = [
    'schema.sql',
    'phase2_roles_seed.sql',
    'phase6_permissions.sql',
    'phase6_fix.sql',
    'phase7_permissions.sql',
    'phase8_leave_attendance.sql',
    'phase9_reports.sql',
    'phase10_final.sql',
    'phase11_link_user.sql',
    'phase12_user_overrides.sql',
    'phase12_company_logo.sql',
    'phase12_login_logo.sql',
    'phase13_user_delete.sql',
    'phase14_delete_permissions.sql',
    'phase15_search_indexes.sql',
    'phase16_notifications.sql',
    'phase17_company_settings.sql',
    'phase18_performance.sql',
    'phase19_holidays.sql',
    'phase20_cleanup_permissions.sql',
    'phase21_demo_seed_log.sql',
];

/**
 * Builds the consolidated SQL string.
 */
function build_full_sql(string $migrationsDir, array $sequence): string {
    $buffer = [];
    $buffer[] = "-- ============================================================";
    $buffer[] = "-- AaZa Group - Multi-Company HR & Recruitment Management System";
    $buffer[] = "-- Unified Single-File Database Schema & Migration Master";
    $buffer[] = "-- Suitable for fresh installation on cPanel, Plesk, VPS & Local Dev";
    $buffer[] = "--";
    $buffer[] = "-- Note: This file contains NO 'CREATE DATABASE' or 'USE' commands.";
    $buffer[] = "-- It is safe for host-prefixed database names (e.g. user_aazahrms).";
    $buffer[] = "-- Target database must be selected/connected before importing.";
    $buffer[] = "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC";
    $buffer[] = "-- ============================================================\n";

    $buffer[] = "SET FOREIGN_KEY_CHECKS = 0;\n";

    foreach ($sequence as $index => $filename) {
        $filePath = $migrationsDir . '/' . $filename;
        if (!is_file($filePath)) {
            throw new RuntimeException("Missing migration file: {$filename}");
        }

        $stepNum = $index + 1;
        $content = (string)file_get_contents($filePath);

        // Strip CREATE DATABASE statement
        $content = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+[`\w]+[^;]*;/is', '', $content);
        $content = preg_replace('/CREATE\s+DATABASE\s+[`\w]+[^;]*;/is', '', $content);

        // Strip USE statement
        $content = preg_replace('/USE\s+[`\w]+\s*;/is', '', $content);

        // Trim leading/trailing whitespace
        $content = trim($content);

        $buffer[] = "-- ------------------------------------------------------------";
        $buffer[] = "-- Step {$stepNum}: {$filename}";
        $buffer[] = "-- ------------------------------------------------------------";
        $buffer[] = $content;
        $buffer[] = ""; // empty line between migrations
    }

    $buffer[] = "SET FOREIGN_KEY_CHECKS = 1;\n";

    // Normalize line endings to LF
    $fullSql = implode("\n", $buffer);
    return preg_replace('/\r\n|\r/', "\n", $fullSql);
}

// Check or Generate
$isCheck = in_array('--check', $argv ?? [], true);
$generated = build_full_sql($migrationsDir, $migrationSequence);

if ($isCheck) {
    if (!is_file($outputFile)) {
        fwrite(STDERR, "[FAIL] Committed {$outputFile} does not exist.\n");
        exit(1);
    }
    $existing = (string)file_get_contents($outputFile);
    // Ignore timestamp comment difference in verification
    $normalize = fn($s) => preg_replace('/-- Generated: [^\n]+/', '', preg_replace('/\r\n|\r/', "\n", trim($s)));
    if ($normalize($existing) !== $normalize($generated)) {
        fwrite(STDERR, "[FAIL] Committed {$outputFile} differs from generated migrations output.\n");
        fwrite(STDERR, "       Run 'php tools/build_full_sql.php' to synchronize.\n");
        exit(1);
    }
    echo "[OK] Single-file installer database/aaza_hrms_full.sql is up to date.\n";
    exit(0);
}

file_put_contents($outputFile, $generated);
echo "Successfully generated {$outputFile} (" . strlen($generated) . " bytes, " . count($migrationSequence) . " migrations).\n";
exit(0);

