<?php
declare(strict_types=1);

/**
 * AaZa HRMS - Production Release Packager (CLI)
 * 
 * Packages a clean, deployment-ready release zip: dist/aaza_hrms_release.zip
 * Excludes:
 *   - .git, tests/, docs/, tools/, scratch/, .user_uploaded/, dist/
 *   - verification scripts (verify_*.py, test_*.py)
 *   - active config/config.php and config/database.php (sample configs are preserved)
 *   - storage/installed.lock
 *   - storage content & uploaded files (empty folders preserved with .gitkeep / .htaccess / index.html)
 * Includes:
 *   - database/aaza_hrms_full.sql
 *   - public/setup.php
 *   - all .htaccess and configuration templates
 * 
 * Usage:
 *   php tools/make_release.php
 */

$rootDir = dirname(__DIR__);
$distDir = $rootDir . '/dist';

if (!extension_loaded('zip')) {
    fwrite(STDERR, "[ERROR] The PHP 'zip' extension is required to build the release archive.\n");
    exit(1);
}

if (!is_dir($distDir)) {
    if (!@mkdir($distDir, 0755, true) && !is_dir($distDir)) {
        fwrite(STDERR, "[ERROR] Failed to create dist directory: {$distDir}\n");
        exit(1);
    }
}

$zipFile = $distDir . '/aaza_hrms_release.zip';

if (is_file($zipFile)) {
    @unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[ERROR] Failed to create zip archive: {$zipFile}\n");
    exit(1);
}

// Directories to exclude entirely
$excludeDirs = [
    '.git',
    'tests',
    'docs',
    'tools',
    'scratch',
    '.user_uploaded',
    '.gemini',
    'dist',
    'temp_test_installer',
    'screenshots',
];

// Patterns or paths to exclude
$excludeFileRegex = [
    '~\\.git.*~i',
    '~verify_.*\\.py$~i',
    '~test_.*\\.py$~i',
    '~.*\\.log(\\.[0-9]+)?$~i',
    '~installed\\.lock$~i',
    '~config[/\\\\]config\\.php$~i',
    '~config[/\\\\]database\\.php$~i',
    '~storage[/\\\\]temp.*~i',
    '~storage[/\\\\].+~i',
    '~assets[/\\\\]uploads[/\\\\].+~i',
    '~.*\\.zip$~i',
];

$filesAdded = 0;
$totalRawBytes = 0;
$includedFiles = [];

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iter as $item) {
    $path = $item->getPathname();
    $relPath = str_replace('\\', '/', substr($path, strlen($rootDir) + 1));

    // Check directory exclusion
    $parts = explode('/', $relPath);
    $skip = false;
    foreach ($parts as $p) {
        if (in_array($p, $excludeDirs, true)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relPath);
        continue;
    }

    // Check file exclusion patterns
    $matchExclude = false;
    foreach ($excludeFileRegex as $pattern) {
        if (preg_match($pattern, $relPath)) {
            // Keep .gitkeep, .htaccess, and index.html in storage / upload / log / backup folders
            $base = basename($relPath);
            if ($base === '.gitkeep' || $base === '.htaccess' || $base === 'index.html') {
                continue;
            }
            $matchExclude = true;
            break;
        }
    }

    if ($matchExclude) {
        continue;
    }

    $fileSize = (int)$item->getSize();
    // If this is public/setup.php, we will inject it from pristine template below
    if ($relPath === 'public/setup.php') {
        continue;
    }
    $zip->addFile($path, $relPath);
    $filesAdded++;
    $totalRawBytes += $fileSize;
    $includedFiles[] = [
        'path' => $relPath,
        'size' => $fileSize,
    ];
}

// CRITICAL: Always inject public/setup.php from tools/templates/setup.php.dist
$pristineTemplate = $rootDir . '/tools/templates/setup.php.dist';
if (is_file($pristineTemplate)) {
    $zip->addFile($pristineTemplate, 'public/setup.php');
    $tplSize = (int)filesize($pristineTemplate);
    $filesAdded++;
    $totalRawBytes += $tplSize;
    $includedFiles[] = [
        'path' => 'public/setup.php',
        'size' => $tplSize,
    ];
} elseif (is_file($rootDir . '/public/setup.php')) {
    $zip->addFile($rootDir . '/public/setup.php', 'public/setup.php');
    $tplSize = (int)filesize($rootDir . '/public/setup.php');
    $filesAdded++;
    $totalRawBytes += $tplSize;
    $includedFiles[] = [
        'path' => 'public/setup.php',
        'size' => $tplSize,
    ];
} else {
    fwrite(STDERR, "[ERROR] Cannot package release: neither tools/templates/setup.php.dist nor public/setup.php exists!\n");
    exit(1);
}

$zip->close();

$zipSize = filesize($zipFile);
$sha256 = hash_file('sha256', $zipFile);

echo "============================================================\n";
echo " AaZa HRMS - Production Release Archive Files ({$filesAdded} files)\n";
echo "============================================================\n";
foreach ($includedFiles as $f) {
    printf("  %-65s %10s\n", $f['path'], number_format($f['size'] / 1024, 1) . ' KB');
}
echo "============================================================\n";
echo " AaZa HRMS - Production Release Archive Built Successfully\n";
echo "============================================================\n";
echo " Archive Path:   {$zipFile}\n";
echo " Files Included: {$filesAdded}\n";
echo " Uncompressed:   " . number_format($totalRawBytes / 1024, 1) . " KB\n";
echo " Compressed:     " . number_format($zipSize / 1024, 1) . " KB\n";
echo " SHA-256 Hash:   {$sha256}\n";
echo "============================================================\n";
exit(0);
