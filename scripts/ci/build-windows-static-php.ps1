# Builds a portable Windows PHP CLI via static-php-cli with the extensions
# this app needs (notably pdo_mysql + sodium), then packs it into the
# bin/win/x64/php-{version}.zip layout NativePHP expects under
# NATIVEPHP_PHP_BINARY_PATH.
#
# Why not download dl.static-php.dev's prebuilt "spc-max"? That set
# deliberately omits sodium (see its README.txt / upstream
# v3-php-bin-windows.yml EXTENSIONS list). Without sodium, encrypted
# .dbmconn export/import (ConnectionPorter) cannot work. Linux/macOS "bulk"
# prebuilds include sodium; Windows has to be built here instead.
#
# Usage (from repo root, on Windows):
#   pwsh -File scripts/ci/build-windows-static-php.ps1 -PhpVersion 8.4 -DestBaseDir nativephp-php-bin-custom

param(
    [Parameter(Mandatory = $true)][string]$PhpVersion,
    [Parameter(Mandatory = $true)][string]$DestBaseDir
)

$ErrorActionPreference = "Stop"

$RequiredExtensions = @(
    "pdo_mysql",
    "sodium",
    "sqlite3",
    "mbstring",
    "zip",
    "openssl"
)

# Lean but complete set for Laravel + this app (MySQL + sodium crypto).
# Keep in sync with nativephp-php-bin-custom/README.md where practical.
$BuildExtensions = @(
    "bcmath", "bz2", "ctype", "curl", "dom", "fileinfo", "filter", "gd",
    "iconv", "mbstring", "mbregex", "opcache", "openssl", "pdo", "pdo_mysql",
    "pdo_sqlite", "phar", "session", "simplexml", "sockets", "sodium",
    "sqlite3", "tokenizer", "xml", "zip", "zlib"
) -join ","

$Work = Join-Path $env:RUNNER_TEMP ("spc-win-build-" + [guid]::NewGuid().ToString("N"))
if (-not $env:RUNNER_TEMP) {
    $Work = Join-Path ([System.IO.Path]::GetTempPath()) ("spc-win-build-" + [guid]::NewGuid().ToString("N"))
}
New-Item -ItemType Directory -Force -Path $Work | Out-Null
Set-Location $Work
Write-Host "Working directory: $Work"

# Official docs use curl.exe (follows redirects cleanly; avoids MotW quirks
# that Invoke-WebRequest can leave on the downloaded .exe).
$SpcUrl = "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-windows-x64.exe"
$SpcExe = Join-Path $Work "spc.exe"

Write-Host "Downloading static-php-cli from $SpcUrl ..."
& curl.exe -fsSL -o $SpcExe $SpcUrl
if ($LASTEXITCODE -ne 0) { throw "curl failed downloading spc.exe (exit $LASTEXITCODE)" }
if (-not (Test-Path $SpcExe) -or (Get-Item $SpcExe).Length -lt 1MB) {
    throw "spc.exe download looks wrong (missing or too small)"
}
Unblock-File -Path $SpcExe -ErrorAction SilentlyContinue

Write-Host "spc.exe size: $((Get-Item $SpcExe).Length) bytes"
& $SpcExe --version
if ($LASTEXITCODE -ne 0) { throw "spc --version failed (exit $LASTEXITCODE)" }

Write-Host "Running spc doctor --auto-fix..."
& $SpcExe doctor --auto-fix
if ($LASTEXITCODE -ne 0) { throw "spc doctor failed with exit code $LASTEXITCODE" }

# Same shape as static-php/hosted's Windows workflow: download+build in one
# `spc build` invocation via --dl-with-php (avoids flag drift between spc versions).
Write-Host "Building PHP $PhpVersion CLI with extensions: $BuildExtensions"
& $SpcExe build --build-cli $BuildExtensions --dl-with-php=$PhpVersion --dl-retry=5 --debug
if ($LASTEXITCODE -ne 0) { throw "spc build failed with exit code $LASTEXITCODE" }

$BinPath = Join-Path $Work "buildroot\bin\php.exe"
if (-not (Test-Path $BinPath)) {
    Write-Host "buildroot tree:"
    if (Test-Path (Join-Path $Work "buildroot")) {
        Get-ChildItem -Recurse (Join-Path $Work "buildroot") | Select-Object -First 50 FullName
    }
    throw "Expected PHP binary not found after build: $BinPath"
}

Write-Host "Verifying required extensions..."
$Modules = & $BinPath -m 2>&1 | Out-String
Write-Host $Modules
foreach ($Extension in $RequiredExtensions) {
    if ($Modules -notmatch "(?m)^$([regex]::Escape($Extension))$") {
        throw "Missing required PHP extension `"$Extension`" in built static PHP.`n`nModules reported:`n$Modules"
    }
}

$PdoCheck = & $BinPath -r "new PDO('sqlite::memory:'); echo 'ok';" 2>&1 | Out-String
if ($PdoCheck.Trim() -ne "ok") {
    throw "PDO sqlite driver check failed in built static PHP: $PdoCheck"
}

Write-Host "Verified extensions: $($RequiredExtensions -join ', ') (+ working PDO sqlite driver)"

$DestDir = Join-Path $DestBaseDir "bin\win\x64"
New-Item -ItemType Directory -Force -Path $DestDir | Out-Null
$DestZip = Join-Path $DestDir "php-$PhpVersion.zip"

if (Test-Path $DestZip) { Remove-Item -Force $DestZip }

# Zip only the bare php.exe at the archive root (NativePHP extracts a single entry).
$Stage = Join-Path $Work "zip-stage"
New-Item -ItemType Directory -Force -Path $Stage | Out-Null
Copy-Item $BinPath (Join-Path $Stage "php.exe")
Compress-Archive -Path (Join-Path $Stage "php.exe") -DestinationPath $DestZip -Force

Write-Host "Wrote $DestZip ($((Get-Item $DestZip).Length) bytes)"
