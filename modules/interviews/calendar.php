<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('interview.view');

// Company isolation & scoping
$scopedCompanyIds = scoped_company_ids('interview.view');
if (empty($scopedCompanyIds)) {
    abort(403, 'No accessible companies found for interview viewing.');
}

// Fetch accessible companies for the dropdown filter
$cPlaceholders = implode(',', array_fill(0, count($scopedCompanyIds), '?'));
$cStmt = db()->prepare("SELECT id, code, name FROM companies WHERE id IN ($cPlaceholders) AND is_active = 1 ORDER BY name");
$cStmt->execute($scopedCompanyIds);
$accessibleCompanies = $cStmt->fetchAll();

// GET parameters
$view = (isset($_GET['view']) && $_GET['view'] === 'week') ? 'week' : 'month';
$rawDate = trim((string)($_GET['date'] ?? ''));
$targetDateStr = ($rawDate !== '' ? parse_ymd($rawDate) : null) ?? date('Y-m-d');
$targetDate = new DateTimeImmutable($targetDateStr);

// Optional company filter
$filterCompanyId = null;
if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
    $candCid = (int)$_GET['company_id'];
    if (in_array($candCid, $scopedCompanyIds, true)) {
        $filterCompanyId = $candCid;
    }
}

// Calculate grid date boundaries (7 columns, Mon-first)
if ($view === 'week') {
    $currentDow = (int)$targetDate->format('N'); // 1 (Mon) to 7 (Sun)
    $daysToMon = $currentDow - 1;
    $gridStartDate = $targetDate->modify("-{$daysToMon} days");
    $gridEndDate = $gridStartDate->modify('+6 days');

    $rangeFrom = $gridStartDate->format('Y-m-d 00:00:00');
    $rangeTo = $gridEndDate->modify('+1 day')->format('Y-m-d 00:00:00');

    $prevDateStr = $gridStartDate->modify('-7 days')->format('Y-m-d');
    $nextDateStr = $gridStartDate->modify('+7 days')->format('Y-m-d');
    $todayDateStr = date('Y-m-d');

    $periodTitle = $gridStartDate->format('M j') . ' – ' . $gridEndDate->format('M j, Y');
} else {
    // Month grid: first Monday on or before the 1st to the last Sunday on or after month end
    $firstOfMonth = $targetDate->modify('first day of this month');
    $lastOfMonth = $targetDate->modify('last day of this month');

    $firstDow = (int)$firstOfMonth->format('N');
    $daysBefore = $firstDow - 1;
    $gridStartDate = $firstOfMonth->modify("-{$daysBefore} days");

    $lastDow = (int)$lastOfMonth->format('N');
    $daysAfter = 7 - $lastDow;
    $gridEndDate = $lastOfMonth->modify("+{$daysAfter} days");

    $rangeFrom = $gridStartDate->format('Y-m-d 00:00:00');
    $rangeTo = $gridEndDate->modify('+1 day')->format('Y-m-d 00:00:00');

    $prevDateStr = $firstOfMonth->modify('-1 month')->format('Y-m-01');
    $nextDateStr = $firstOfMonth->modify('+1 month')->format('Y-m-01');
    $todayDateStr = date('Y-m-d');

    $periodTitle = $targetDate->format('F Y');
}

// Query interviews strictly scoped within the visible range (LIMIT 1000)
// Evaluation scores are intentionally excluded
$params = [
    ':from' => $rangeFrom,
    ':to'   => $rangeTo,
];

if ($filterCompanyId !== null) {
    $companyClause = 'i.company_id = :comp_id';
    $params[':comp_id'] = $filterCompanyId;
} else {
    [$cSql, $cArr] = company_in_sql($scopedCompanyIds, 'i.company_id');
    $i = 0;
    $companyClause = preg_replace_callback('/\?/', function () use (&$i, &$params, $cArr) {
        $paramName = ':cid_' . $i;
        $params[$paramName] = $cArr[$i];
        $i++;
        return $paramName;
    }, $cSql);
}

$sql = "SELECT i.id, i.application_id, i.company_id, i.round_no, i.interview_type,
               i.scheduled_at, i.duration_min, i.mode, i.location, i.status,
               ca.candidate_id, ca.vacancy_id,
               c.first_name, c.last_name, c.candidate_code,
               v.title AS vacancy_title, v.vacancy_code,
               comp.name AS company_name, comp.code AS company_code
          FROM interviews i
          JOIN candidate_applications ca ON ca.id = i.application_id
          JOIN candidates c ON c.id = ca.candidate_id
          JOIN job_vacancies v ON v.id = ca.vacancy_id
          JOIN companies comp ON comp.id = i.company_id
         WHERE i.scheduled_at >= :from
           AND i.scheduled_at < :to
           AND $companyClause
         ORDER BY i.scheduled_at ASC
         LIMIT 1000";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$interviews = $stmt->fetchAll();

// Group interviews by date string Y-m-d
$interviewsByDate = [];
foreach ($interviews as $row) {
    $d = substr((string)$row['scheduled_at'], 0, 10);
    $interviewsByDate[$d][] = $row;
}

// Generate all day objects in the grid
$periodDays = [];
$iterDate = $gridStartDate;
while ($iterDate <= $gridEndDate) {
    $periodDays[] = $iterDate;
    $iterDate = $iterDate->modify('+1 day');
}

// Status to CSS chip variant mapping
$statusColorMap = [
    'scheduled'   => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'secondary',
    'no_show'     => 'danger',
    'rescheduled' => 'warning',
];

$weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Helper to preserve filter query parameters
$queryArgs = ['view' => $view];
if ($filterCompanyId !== null) {
    $queryArgs['company_id'] = $filterCompanyId;
}

$pageTitle = 'Interview Calendar';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Breadcrumb & Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= e(url('modules/interviews/index.php')) ?>">Interviews</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Calendar</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-0 text-body">Interview Calendar</h1>
            <p class="text-muted small mb-0">Multi-company recruitment schedule and interview agenda.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <!-- View Toggle Button Group (List / Calendar) -->
            <div class="btn-group btn-group-sm" role="group" aria-label="View Toggle">
                <a href="<?= e(url('modules/interviews/index.php')) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i>List
                </a>
                <a href="<?= e(url('modules/interviews/calendar.php')) ?>" class="btn btn-primary active" aria-current="page">
                    <i class="bi bi-calendar3 me-1"></i>Calendar
                </a>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6">
                <i class="bi bi-calendar-event me-1"></i> Visible Interviews: <strong><?= count($interviews) ?></strong>
            </span>
        </div>
    </div>

    <!-- Calendar Controls Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <!-- Left: Prev / Today / Next Navigation + Period Heading -->
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Calendar Navigation">
                        <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['date' => $prevDateStr])))) ?>"
                           class="btn btn-outline-secondary" title="Previous <?= e(ucfirst($view)) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['date' => $todayDateStr])))) ?>"
                           class="btn btn-outline-secondary">
                            Today
                        </a>
                        <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['date' => $nextDateStr])))) ?>"
                           class="btn btn-outline-secondary" title="Next <?= e(ucfirst($view)) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                    <h2 class="h5 mb-0 fw-bold text-body ms-1"><?= e($periodTitle) ?></h2>
                </div>

                <!-- Right: Month/Week Toggle & Company Filter -->
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <!-- Month / Week Toggle -->
                    <div class="btn-group btn-group-sm" role="group" aria-label="Calendar View Mode">
                        <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['view' => 'month', 'date' => $targetDateStr])))) ?>"
                           class="btn btn-<?= $view === 'month' ? 'primary active' : 'outline-secondary' ?>">
                            Month
                        </a>
                        <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['view' => 'week', 'date' => $targetDateStr])))) ?>"
                           class="btn btn-<?= $view === 'week' ? 'primary active' : 'outline-secondary' ?>">
                            Week
                        </a>
                    </div>

                    <!-- Company Filter (if multiple accessible companies or group admin) -->
                    <?php if (count($accessibleCompanies) > 1 || is_group_admin()): ?>
                    <form method="get" action="<?= e(url('modules/interviews/calendar.php')) ?>" class="d-flex align-items-center">
                        <input type="hidden" name="view" value="<?= e($view) ?>">
                        <input type="hidden" name="date" value="<?= e($targetDateStr) ?>">
                        <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by company">
                            <option value="">All Accessible Companies</option>
                            <?php foreach ($accessibleCompanies as $comp): ?>
                                <option value="<?= (int)$comp['id'] ?>" <?= $filterCompanyId === (int)$comp['id'] ? 'selected' : '' ?>>
                                    <?= e($comp['code'] . ' - ' . $comp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-primary ms-1">Filter</button></noscript>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- DESKTOP CALENDAR GRID (>= 768px): Server-side CSS Grid (7 columns, Mon-first) -->
    <div class="interview-calendar-wrap d-none d-md-block <?= $view === 'week' ? 'calendar-view-week' : 'calendar-view-month' ?>">
        <!-- 7-Column Days of Week Header (Mon-first) -->
        <div class="calendar-grid-header">
            <?php foreach ($weekdayNames as $wIdx => $wName): ?>
                <?php
                $colHeaderClass = 'calendar-header-col';
                if ($wIdx >= 5) $colHeaderClass .= ' text-secondary';
                ?>
                <div class="<?= $colHeaderClass ?>"><?= e($wName) ?></div>
            <?php endforeach; ?>
        </div>

        <!-- Calendar Days Grid Body -->
        <div class="calendar-grid-body">
            <?php foreach ($periodDays as $dayDate): ?>
                <?php
                $dayStr = $dayDate->format('Y-m-d');
                $isToday = ($dayStr === date('Y-m-d'));
                $isDim = ($view === 'month' && $dayDate->format('Y-m') !== $targetDate->format('Y-m'));
                $dayInterviews = $interviewsByDate[$dayStr] ?? [];
                $cellClasses = ['calendar-day-cell'];
                if ($isDim) $cellClasses[] = 'calendar-day-dim';
                if ($isToday) $cellClasses[] = 'calendar-day-today';

                // Display up to 3 chips in month view, with "+N more" linking to the week view
                $chipsLimit = ($view === 'month') ? 3 : 1000;
                $visibleChips = array_slice($dayInterviews, 0, $chipsLimit);
                $extraCount = count($dayInterviews) - count($visibleChips);
                ?>
                <div class="<?= implode(' ', $cellClasses) ?>">
                    <!-- Day Cell Header -->
                    <div class="calendar-day-top">
                        <span class="calendar-day-number"><?= $dayDate->format('j') ?></span>
                        <?php if ($view === 'week'): ?>
                            <span class="text-muted small"><?= $dayDate->format('M') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($dayInterviews)): ?>
                            <span class="badge rounded-pill bg-body-secondary text-body-secondary" style="font-size: 0.68rem;">
                                <?= count($dayInterviews) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Interview Chips List -->
                    <div class="calendar-chips-list">
                        <?php foreach ($visibleChips as $int): ?>
                            <?php
                            $st = $int['status'] ?? 'scheduled';
                            $chipColor = $statusColorMap[$st] ?? 'primary';
                            $timeStr = date('H:i', strtotime($int['scheduled_at']));
                            $candName = $int['first_name'] . ' ' . $int['last_name'];
                            $tooltip = "{$timeStr} - {$candName} (R{$int['round_no']}) · " . ucfirst(str_replace('_', ' ', $st));
                            ?>
                            <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$int['id'])) ?>"
                               class="calendar-chip chip-<?= e($chipColor) ?>"
                               title="<?= e($tooltip) ?>">
                                <span class="calendar-chip-time"><?= e($timeStr) ?></span>
                                <span class="calendar-chip-name"><?= e($candName) ?></span>
                                <span class="calendar-chip-round">R<?= (int)$int['round_no'] ?></span>
                            </a>
                        <?php endforeach; ?>

                        <?php if ($extraCount > 0): ?>
                            <a href="<?= e(url('modules/interviews/calendar.php?' . http_build_query(array_merge($queryArgs, ['view' => 'week', 'date' => $dayStr])))) ?>"
                               class="calendar-more-link"
                               title="View all <?= count($dayInterviews) ?> interviews in week view">
                                +<?= (int)$extraCount ?> more
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- MOBILE AGENDA LIST (< 768px): Grouped by Day -->
    <div class="interview-agenda-wrap d-block d-md-none">
        <?php if (empty($interviews)): ?>
            <div class="card border-0 shadow-sm text-center py-5">
                <div class="card-body">
                    <i class="bi bi-calendar-x fs-1 text-muted d-block mb-2"></i>
                    <p class="text-muted mb-0">No interviews scheduled for this <?= e($view) ?>.</p>
                </div>
            </div>
        <?php else: ?>
            <?php
            // Group and render days with interviews in order
            foreach ($periodDays as $dayDate):
                $dayStr = $dayDate->format('Y-m-d');
                $dayInterviews = $interviewsByDate[$dayStr] ?? [];
                if (empty($dayInterviews)) continue;
                $isDayToday = ($dayStr === date('Y-m-d'));
            ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center py-2 px-3">
                        <span class="fw-bold small <?= $isDayToday ? 'text-primary' : 'text-body' ?>">
                            <?= $dayDate->format('D, j M Y') ?>
                        </span>
                        <?php if ($isDayToday): ?>
                            <span class="badge bg-primary">Today</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                <?= count($dayInterviews) ?> interview<?= count($dayInterviews) > 1 ? 's' : '' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($dayInterviews as $int): ?>
                            <?php
                            $st = $int['status'] ?? 'scheduled';
                            $chipColor = $statusColorMap[$st] ?? 'primary';
                            $timeStr = date('H:i', strtotime($int['scheduled_at']));
                            $candName = $int['first_name'] . ' ' . $int['last_name'];
                            ?>
                            <a href="<?= e(url('modules/interviews/view.php?id=' . (int)$int['id'])) ?>"
                               class="list-group-item list-group-item-action p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-body small">
                                        <i class="bi bi-clock me-1 text-muted"></i><?= e($timeStr) ?> &bull; <?= e($candName) ?>
                                    </span>
                                    <span class="badge chip-<?= e($chipColor) ?> text-capitalize px-2 py-1" style="font-size: 0.72rem;">
                                        <?= e(str_replace('_', ' ', $st)) ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center text-muted small">
                                    <span>
                                        <span class="badge bg-secondary-subtle text-secondary me-1">R<?= (int)$int['round_no'] ?></span>
                                        <?= e($int['vacancy_title']) ?>
                                    </span>
                                    <span class="badge bg-body-secondary text-body-secondary"><?= e($int['company_code']) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Status Legend -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center gap-3 text-muted small">
                <span class="fw-bold text-body"><i class="bi bi-info-circle me-1"></i>Status Legend:</span>
                <span class="d-flex align-items-center gap-1">
                    <span class="calendar-chip chip-primary px-2 py-0">&bull;</span> Scheduled
                </span>
                <span class="d-flex align-items-center gap-1">
                    <span class="calendar-chip chip-success px-2 py-0">&bull;</span> Completed
                </span>
                <span class="d-flex align-items-center gap-1">
                    <span class="calendar-chip chip-warning px-2 py-0">&bull;</span> Rescheduled
                </span>
                <span class="d-flex align-items-center gap-1">
                    <span class="calendar-chip chip-danger px-2 py-0">&bull;</span> No Show
                </span>
                <span class="d-flex align-items-center gap-1">
                    <span class="calendar-chip chip-secondary px-2 py-0">&bull;</span> Cancelled
                </span>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
