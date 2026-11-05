<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Optional Demo Data Management (Group Admin Only)
 * 
 * Provides an administrative interface to seed or clean 100% fake realistic
 * demonstration data for system evaluations, testing, and stakeholder demos.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/demo_seed.php';

require_login();

// Strictly restrict to Group Administrator
$currentUser = current_user();
if (empty($currentUser['is_group_admin'])) {
    abort(403, 'Access denied: Only Group Administrators can manage demonstration data.');
}

$pdo = db();
$pageTitle = 'Demo Data Manager';

// Fetch all companies
$companies = $pdo->query("SELECT id, name, code FROM companies ORDER BY id ASC")->fetchAll();

$actionResult = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = trim((string)($_POST['action'] ?? ''));
    $selectedCompanyId = (int)($_POST['company_id'] ?? 0);

    if ($selectedCompanyId <= 0) {
        $error = 'Please select a valid subsidiary company.';
    } else {
        if ($action === 'seed') {
            $submittedPass = trim((string)($_POST['demo_password'] ?? ''));
            $isAutoPass = false;
            if ($submittedPass === '') {
                $submittedPass = 'Demo#' . bin2hex(random_bytes(3)) . '!9A';
                $isAutoPass = true;
            } elseif (strlen($submittedPass) < 8) {
                $error = 'Demo password must be at least 8 characters long.';
            }

            if ($error === null) {
                try {
                    $counts = demo_seed($pdo, $selectedCompanyId, $submittedPass);
                    $compName = '';
                    foreach ($companies as $c) {
                        if ((int)$c['id'] === $selectedCompanyId) {
                            $compName = "{$c['name']} ({$c['code']})";
                            break;
                        }
                    }

                    $actionResult = [
                        'type'        => 'seeded',
                        'company_id'  => $selectedCompanyId,
                        'company_name'=> $compName,
                        'password'    => $submittedPass,
                        'is_auto'     => $isAutoPass,
                        'counts'      => $counts,
                    ];
                    flash_set('success', "Fake demonstration data loaded successfully for {$compName}.");
                } catch (Throwable $e) {
                    $error = 'Failed to seed demo data: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'unseed') {
            try {
                $removed = demo_unseed($pdo, $selectedCompanyId);
                $compName = '';
                foreach ($companies as $c) {
                    if ((int)$c['id'] === $selectedCompanyId) {
                        $compName = "{$c['name']} ({$c['code']})";
                        break;
                    }
                }

                $actionResult = [
                    'type'        => 'unseeded',
                    'company_id'  => $selectedCompanyId,
                    'company_name'=> $compName,
                    'removed'     => $removed,
                ];
                flash_set('warning', "All demonstration data for {$compName} has been safely removed.");
            } catch (Throwable $e) {
                $error = 'Failed to remove demo data: ' . $e->getMessage();
            }
        }
    }
}

// Compute demo status per company
$companyStatus = [];
foreach ($companies as $c) {
    $cId = (int)$c['id'];
    $companyStatus[$cId] = is_demo_seeded($pdo, $cId);
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4 py-4">
    <!-- Breadcrumb & Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('modules/system/health.php')) ?>">System Health</a></li>
            <li class="breadcrumb-item active" aria-current="page">Demo Data Manager</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div>
            <h1 class="h3 fw-bold mb-1 text-body">
                <i class="bi bi-database-gear me-2 text-primary"></i>Demo Data Manager
            </h1>
            <p class="text-muted small mb-0">Load or purge 100% fake demonstration datasets for feature testing, evaluators, and system tours.</p>
        </div>
        <div>
            <a href="<?= e(url('modules/system/health.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to System Health
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
            <div><?= e($error) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Seeding Success Card with Generated Credentials -->
    <?php if ($actionResult && $actionResult['type'] === 'seeded'): ?>
        <div class="card border-0 shadow-sm mb-4 border-start border-4 border-success">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="p-2 bg-success bg-opacity-10 text-success rounded-circle">
                        <i class="bi bi-check-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1 text-success">Demo Data Successfully Seeded!</h4>
                        <p class="text-muted small mb-0">The demonstration environment has been populated for <strong><?= e($actionResult['company_name']) ?></strong>.</p>
                    </div>
                </div>

                <div class="alert alert-warning border border-warning-subtle rounded-3 p-3 mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="badge bg-warning text-dark me-2">Save Credentials</span>
                            <span class="small fw-semibold text-dark">Demo User Accounts Password:</span>
                            <code class="fs-6 fw-bold user-select-all text-dark ms-2 bg-white px-2 py-1 rounded border"><?= e($actionResult['password']) ?></code>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="navigator.clipboard.writeText('<?= e($actionResult['password']) ?>'); alert('Password copied to clipboard!');">
                            <i class="bi bi-clipboard me-1"></i> Copy Password
                        </button>
                    </div>
                    <div class="small text-muted mt-2">
                        This password has been set for all 3 demo login accounts. Log in with any of these accounts in a private browsing window to inspect role perspectives.
                    </div>
                </div>

                <!-- Demo Accounts Table -->
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Role Perspective</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Linked Employee</th>
                                <th>Capabilities</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge text-bg-primary">HR Manager</span></td>
                                <td><code>demo_hr</code></td>
                                <td>demo_hr@example.com</td>
                                <td>Kusal Perera (DEMO-0001)</td>
                                <td>Full HR operations, recruitment, employee directory, leave reviews</td>
                            </tr>
                            <tr>
                                <td><span class="badge text-bg-info">Department Manager</span></td>
                                <td><code>demo_manager</code></td>
                                <td>demo_manager@example.com</td>
                                <td>Dinesh Chandimal (DEMO-0002)</td>
                                <td>Approves team leave, manages software engineers, conducts interviews</td>
                            </tr>
                            <tr>
                                <td><span class="badge text-bg-secondary">Staff Employee</span></td>
                                <td><code>demo_employee</code></td>
                                <td>demo_employee@example.com</td>
                                <td>Pathum Nissanka (DEMO-0003)</td>
                                <td>Employee self-service, leave requests, attendance history</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Seeded Counts -->
                <h6 class="fw-bold text-secondary mb-2 small text-uppercase">Generated Dataset Statistics</h6>
                <div class="row g-2 text-center small">
                    <?php foreach ($actionResult['counts'] as $k => $c): ?>
                        <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                            <div class="p-2 border rounded bg-body-tertiary">
                                <div class="fw-bold fs-5 text-primary"><?= (int)$c ?></div>
                                <div class="text-muted text-truncate" title="<?= e(ucwords(str_replace('_', ' ', $k))) ?>"><?= e(ucwords(str_replace('_', ' ', $k))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Removal Success Card -->
    <?php if ($actionResult && $actionResult['type'] === 'unseeded'): ?>
        <div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="p-2 bg-warning bg-opacity-10 text-warning rounded-circle">
                        <i class="bi bi-trash3-fill fs-3 text-warning"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1 text-dark">Demo Data Cleared</h4>
                        <p class="text-muted small mb-0">All tagged demonstration rows have been purged for <strong><?= e($actionResult['company_name']) ?></strong>.</p>
                    </div>
                </div>

                <div class="row g-2 text-center small">
                    <?php foreach ($actionResult['removed'] as $k => $c): ?>
                        <?php if ((int)$c > 0): ?>
                            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                <div class="p-2 border rounded bg-body-tertiary">
                                    <div class="fw-bold fs-5 text-danger"><?= (int)$c ?></div>
                                    <div class="text-muted text-truncate"><?= e(ucwords(str_replace('_', ' ', $k))) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Grid: Seed & Unseed Forms -->
    <div class="row g-4">
        <!-- 1. Load Demo Data Form -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-1 text-body">
                        <i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Load Fake Demo Data
                    </h5>
                    <p class="text-muted small mb-0">Populates the selected company with 12 employees, 3 demo logins, attendance, leave requests, vacancies, and candidates.</p>
                </div>
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="seed">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Target Company <span class="text-danger">*</span></label>
                            <select name="company_id" class="form-select" required>
                                <option value="">-- Choose Company --</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                                        <?= !empty($companyStatus[(int)$c['id']]) ? ' [Currently Seeded]' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">Select the subsidiary company to populate with demonstration records.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold text-secondary">Demo Accounts Password</label>
                            <div class="input-group">
                                <input type="text" name="demo_password" id="demoPasswordInput" class="form-control" placeholder="Leave blank to auto-generate secure password">
                                <button type="button" class="btn btn-outline-secondary" id="btnGenPass" onclick="generateDemoPass()">
                                    <i class="bi bi-shuffle me-1"></i> Generate
                                </button>
                            </div>
                            <div class="form-text small">Sets the password for <code>demo_hr</code>, <code>demo_manager</code>, and <code>demo_employee</code>.</div>
                        </div>

                        <div class="alert alert-info border border-info-subtle small mb-4">
                            <div class="fw-bold mb-1"><i class="bi bi-shield-check me-1"></i> 100% Safe &amp; Idempotent:</div>
                            <ul class="mb-0 ps-3">
                                <li>All names, phone numbers (077000000x), and emails (@example.com) are synthetic test data.</li>
                                <li>All records carry the prefix <code>DEMO-</code> or <code>demo_</code> and are logged for atomic cleanup.</li>
                                <li>Re-running seeding will not duplicate records or crash existing entries.</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-database-fill-add me-1"></i> Load Demo Data Now
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 2. Remove Demo Data Form & Status -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-1 text-danger">
                        <i class="bi bi-trash3 me-2"></i>Remove Demo Data
                    </h5>
                    <p class="text-muted small mb-0">Purges only synthetic demo records for the selected company. Genuine records remain untouched.</p>
                </div>
                <div class="card-body">
                    <form method="post" onsubmit="return confirm('Are you sure you want to completely remove all seeded demo data for this company? Real records and admin users will remain safe.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unseed">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary">Target Company <span class="text-danger">*</span></label>
                            <select name="company_id" class="form-select" required>
                                <option value="">-- Choose Company --</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">
                                        <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                                        <?= !empty($companyStatus[(int)$c['id']]) ? ' [Has Demo Data]' : ' [No Demo Data]' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="small text-muted mb-4">
                            This operation deletes only employees matching <code>DEMO-*</code>, candidates with <code>@example.com</code>, vacancies with <code>(Demo)</code>, and demo user accounts (<code>demo_*</code>).
                        </div>

                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash3-fill me-1"></i> Remove Demo Data
                        </button>
                    </form>
                </div>
            </div>

            <!-- Current Company Demo Status -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h6 class="fw-bold mb-0 text-body">
                        <i class="bi bi-building-check me-2 text-primary"></i>Company Seeding Status
                    </h6>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($companies as $c): ?>
                            <?php $isSeeded = !empty($companyStatus[(int)$c['id']]); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <div class="fw-semibold text-body"><?= e($c['name']) ?></div>
                                    <span class="text-muted small"><?= e($c['code']) ?> &bull; ID #<?= (int)$c['id'] ?></span>
                                </div>
                                <div>
                                    <?php if ($isSeeded): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-check2 me-1"></i> Demo Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                            Clean / Empty
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function generateDemoPass() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let pass = 'Demo#';
    for (let i = 0; i < 7; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    pass += '9A';
    document.getElementById('demoPasswordInput').value = pass;
}
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';

