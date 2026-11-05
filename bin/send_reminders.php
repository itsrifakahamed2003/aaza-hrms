<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Automated Daily Reminders Utility
 *
 * Runs scheduled checks across all tenant companies:
 *  - Upcoming interviews (today and tomorrow)
 *  - Interviews completed or past scheduled date awaiting evaluation
 *  - Candidates selected awaiting job offer / hiring decision
 *  - Vacancy application deadlines (closing_date within 2 days)
 *  - Pending leave requests older than 3 days
 *  - Employee documents expiring within 30 days
 *
 * Designed for daily execution via Windows Task Scheduler or cron.
 *
 * Usage (CLI only):
 *   php bin/send_reminders.php [options]
 *
 * Options:
 *   --dry-run       Calculate and display reminders without inserting notifications
 *   --help          Show help message
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "ERROR: This utility can only be executed via the command-line interface (CLI).\n";
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$options = getopt('', ['dry-run', 'help']);
if (isset($options['help'])) {
    echo "AaZa HRMS Daily Reminders Utility\n";
    echo "Usage: php bin/send_reminders.php [--dry-run]\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);

echo "============================================================\n";
echo " AaZa HRMS - Scheduled Reminders Engine\n";
echo " Executed: " . date('Y-m-d H:i:s') . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo "============================================================\n";

try {
    $pdo = db();
    $stats = send_system_reminders($pdo, $dryRun);

    echo "\nSummary of Reminders Processed:\n";
    echo "  - Upcoming Interviews:           " . $stats['interviews_upcoming'] . "\n";
    echo "  - Interviews Awaiting Eval:      " . $stats['interviews_awaiting'] . "\n";
    echo "  - Candidates Awaiting Decision:  " . $stats['candidates_decision'] . "\n";
    echo "  - Vacancy Deadlines:             " . $stats['vacancies_deadline'] . "\n";
    echo "  - Stale Leave Requests (>3d):    " . $stats['leaves_stale'] . "\n";
    echo "  - Expiring Employee Documents:   " . $stats['documents_expiry'] . "\n";
    echo "------------------------------------------------------------\n";
    echo " Total Reminders " . ($dryRun ? "Identified: " : "Dispatched: ") . $stats['total'] . "\n";
    echo "============================================================\n";
    echo "[SUCCESS] Reminders engine finished cleanly.\n";
    exit(0);
} catch (Throwable $e) {
    echo "\n[ERROR] Reminders engine failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

