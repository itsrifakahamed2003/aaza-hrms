<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_group_admin();

$pdo     = db();
$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

$canManageLogo = is_group_admin() && user_can('company.logo');

$company = [
    'code'      => '',
    'name'      => '',
    'email'     => '',
    'phone'     => '',
    'address'   => '',
    'logo_path' => '',
];

if ($editing) {
    $st = $pdo->prepare('SELECT id, code, name, email, phone, address, logo_path FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        abort(404, 'Company not found.');
    }
    $company = array_map(fn($v) => (string)($v ?? ''), $row);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // The code is fixed once created: later it prefixes employee IDs and reports.
    $code    = $editing ? $company['code'] : strtoupper(trim((string)($_POST['code'] ?? '')));
    $name    = trim((string)($_POST['name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if (!$editing && !preg_match('/^[A-Z0-9]{2,20}$/', $code)) {
        $errors[] = 'Company code must be 2-20 letters or numbers (for example AZT).';
    }
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Company name is required (max 150 characters).';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
        $errors[] = 'Enter a valid company email or leave it blank.';
    }
    if (!preg_match('/^[0-9+()\s-]{0,30}$/', $phone)) {
        $errors[] = 'Phone may contain only digits, spaces, + ( ) and - (max 30 characters).';
    }
    if (mb_strlen($address) > 1000) {
        $errors[] = 'Address is too long (max 1000 characters).';
    }

    // Logo upload handling
    $hasLogoUpload = isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;
    $hasRemoveLogo = !empty($_POST['remove_logo']);
    $validLogoData = null;

    if (($hasLogoUpload || $hasRemoveLogo) && !$canManageLogo) {
        $errors[] = 'Permission denied: only Group Administrators can update company logos.';
    } elseif ($hasLogoUpload && $canManageLogo) {
        [$ok, $res] = validate_company_logo($_FILES['logo']);
        if (!$ok) {
            $errors[] = (string)$res;
        } else {
            $validLogoData = $res;
        }
    }

    $company = [
        'code'      => $code,
        'name'      => $name,
        'email'     => $email,
        'phone'     => $phone,
        'address'   => $address,
        'logo_path' => $company['logo_path'],
    ];

    if (!$errors) {
        try {
            $currentLogo = $company['logo_path'] !== '' ? $company['logo_path'] : null;
            $newLogoPath = $currentLogo;
            $logoChanged = false;
            $logoAction  = '';

            if ($canManageLogo) {
                if ($hasRemoveLogo && !empty($currentLogo)) {
                    remove_company_logo($currentLogo);
                    $newLogoPath = null;
                    $logoChanged = true;
                    $logoAction  = 'remove';
                } elseif ($validLogoData !== null) {
                    $newLogoPath = save_company_logo($validLogoData, $currentLogo);
                    $logoChanged = true;
                    $logoAction  = $currentLogo !== null ? 'replace' : 'upload';
                }
            }

            if ($editing) {
                $pdo->prepare('UPDATE companies SET name = ?, email = ?, phone = ?, address = ?, logo_path = ? WHERE id = ?')
                    ->execute([$name, $email ?: null, $phone ?: null, $address ?: null, $newLogoPath, $id]);
                $cid = $id;
            } else {
                $pdo->prepare('INSERT INTO companies (code, name, email, phone, address, logo_path) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$code, $name, $email ?: null, $phone ?: null, $address ?: null, $newLogoPath]);
                $cid = (int)$pdo->lastInsertId();
            }

            log_activity(
                $editing ? 'company.update' : 'company.create',
                'company',
                $cid,
                ['code' => $code, 'name' => $name],
                $cid
            );

            if ($logoChanged) {
                log_activity(
                    'company.logo_update',
                    'company',
                    $cid,
                    [
                        'code'      => $code,
                        'name'      => $name,
                        'action'    => $logoAction,
                        'logo_path' => $newLogoPath,
                    ],
                    $cid
                );
            }

            if ($editing) {
                flash_set('success', 'Company updated.');
                redirect('modules/companies/index.php');
            }
            flash_set('success', 'Company created. Add its departments and designations next.');
            redirect('modules/organization/departments.php?company_id=' . $cid);
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            $errors[] = 'That company code is already in use.';
        }
    }
}

$pageTitle = $editing ? 'Edit company' : 'Add company';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb breadcrumb-clean mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">AaZa HRMS</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('modules/companies/index.php')) ?>">Companies</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="page-header-title mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('modules/companies/index.php')) ?>">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0" style="max-width:680px">
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data" novalidate autocomplete="off">
            <?= csrf_field() ?>

            <!-- Company Logo (Group Admin only) -->
            <?php if ($canManageLogo): ?>
                <div class="mb-4 pb-3 border-bottom">
                    <label class="form-label fw-semibold" for="logo">Company Logo</label>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="flex-shrink-0">
                            <?= company_logo_html($company, $company['name'] ?: 'AaZa', 'lg') ?>
                        </div>
                        <div class="flex-grow-1">
                            <?php if (!empty($company['logo_path'])): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo" value="1">
                                    <label class="form-check-label text-danger small fw-semibold" for="remove_logo">
                                        <i class="bi bi-trash me-1"></i>Remove current logo
                                    </label>
                                </div>
                            <?php endif; ?>
                            <input class="form-control form-control-sm" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                            <div class="form-text small">
                                PNG, JPG, or WebP (max 2 MB). Recommended square or rectangular aspect ratio.
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="code">Company Code</label>
                <input class="form-control text-uppercase font-monospace" id="code" name="code" maxlength="20" required
                       value="<?= e($company['code']) ?>"<?= $editing ? ' readonly' : '' ?>>
                <div class="form-text"><?= $editing ? 'The code cannot be changed after creation.' : 'Short unique code, for example AZT. Cannot be changed later.' ?></div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="name">Company Name</label>
                <input class="form-control" id="name" name="name" maxlength="150" required value="<?= e($company['name']) ?>">
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?= e($company['email']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold" for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" maxlength="30" value="<?= e($company['phone']) ?>">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" for="address">Address</label>
                <textarea class="form-control" id="address" name="address" rows="3" maxlength="1000"><?= e($company['address']) ?></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-2">
                <a class="btn btn-outline-secondary" href="<?= e(url('modules/companies/index.php')) ?>">Cancel</a>
                <button class="btn btn-primary px-4" type="submit"><?= $editing ? 'Save Changes' : 'Create Company' ?></button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
