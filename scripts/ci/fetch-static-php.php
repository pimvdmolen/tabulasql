<?php

/**
 * Downloads a portable static-php-cli PHP CLI build (built with pdo_mysql
 * and sodium — the ones nativephp/php-bin's own bundled binary lacks, which
 * breaks both MySQL connections and encrypted connection export/import),
 * verifies the extensions this app actually needs, and repackages it as
 * bin/{os}/{arch}/php-{majorMinor}.zip — the exact layout NativePHP's build
 * tooling (php.js) expects under a custom NATIVEPHP_PHP_BINARY_PATH.
 *
 * Runs on the matching OS in CI (e.g. the Windows job runs this on an
 * actual windows-latest runner), so the downloaded binary can always be
 * executed directly here to verify it — no cross-platform guessing.
 *
 * Usage:
 *   php fetch-static-php.php <archiveUrl> <tar.gz|zip> <linux|mac|win> <x64|arm64> <majorMinorVersion> <destBaseDir>
 */

[, $url, $archiveType, $os, $arch, $version, $destBase] = $argv;

$requiredExtensions = ['pdo_mysql', 'sodium', 'sqlite3', 'mbstring', 'zip', 'openssl'];

$work = sys_get_temp_dir().'/static-php-fetch-'.bin2hex(random_bytes(4));
mkdir($work, recursive: true);

$archivePath = "$work/archive.$archiveType";
$bytes = file_put_contents($archivePath, fopen($url, 'r'));

if ($bytes === false || $bytes === 0) {
    fwrite(STDERR, "Download failed: $url\n");
    exit(1);
}

$extractDir = "$work/extracted";
mkdir($extractDir);

if ($archiveType === 'zip') {
    $zip = new ZipArchive;

    if ($zip->open($archivePath) !== true) {
        fwrite(STDERR, "Failed to open downloaded zip: $archivePath\n");
        exit(1);
    }

    $zip->extractTo($extractDir);
    $zip->close();
} else {
    (new PharData($archivePath))->extractTo($extractDir, overwrite: true);
}

$binName = $os === 'win' ? 'php.exe' : 'php';
$binPath = "$extractDir/$binName";

if (! file_exists($binPath)) {
    fwrite(STDERR, "Expected binary not found after extraction: $binPath\n");
    exit(1);
}

chmod($binPath, 0755);

$quotedBin = escapeshellarg($binPath);
$modules = (string) shell_exec("$quotedBin -m 2>&1");

foreach ($requiredExtensions as $extension) {
    if (! preg_match('/^'.preg_quote($extension, '/').'$/mi', $modules)) {
        fwrite(STDERR, "Missing required PHP extension \"$extension\" in downloaded static PHP build.\n\nModules reported:\n$modules\n");
        exit(1);
    }
}

$pdoCheck = trim((string) shell_exec("$quotedBin -r ".escapeshellarg("new PDO('sqlite::memory:'); echo 'ok';")." 2>&1"));

if ($pdoCheck !== 'ok') {
    fwrite(STDERR, "PDO sqlite driver check failed in downloaded static PHP build: $pdoCheck\n");
    exit(1);
}

echo "Verified extensions: ".implode(', ', $requiredExtensions)." (+ working PDO sqlite driver)\n";

$destDir = "$destBase/bin/$os/$arch";
mkdir($destDir, recursive: true);
$destZip = "$destDir/php-$version.zip";

$outZip = new ZipArchive;
$outZip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$outZip->addFile($binPath, $binName);
$outZip->close();

echo "Wrote $destZip\n";
