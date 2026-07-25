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
# Usage:
#   pwsh scripts/ci/build-windows-static-php.ps1 <majorMinorVersion> <destBaseDir>
# Example:
#   pwsh scripts/ci/build-windows-static-php.ps1 8.4 $env:RUNNER_TEMP/php-bin-custom

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

$Work = Join-Path ([System.IO.Path]::GetTempPath()) ("spc-win-build-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $Work | Out-Null
Set-Location $Work

$SpcUrl = "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-windows-x64.exe"
$SpcExe = Join-Path $Work "spc.exe"

Write-Host "Downloading static-php-cli (spc.exe)..."
Invoke-WebRequest -Uri $SpcUrl -OutFile $SpcExe -UseBasicParsing

Write-Host "Running spc doctor --auto-fix..."
& $SpcExe doctor --auto-fix
if ($LASTEXITCODE -ne 0) { throw "spc doctor failed with exit code $LASTEXITCODE" }

Write-Host "Building PHP $PhpVersion CLI with extensions: $BuildExtensions"
& $SpcExe build --build-cli $BuildExtensions --dl-with-php=$PhpVersion --dl-retry=5
if ($LASTEXITCODE -ne 0) { throw "spc build failed with exit code $LASTEXITCODE" }

$BinPath = Join-Path $Work "buildroot\bin\php.exe"
if (-not (Test-Path $BinPath)) {
    throw "Expected PHP binary not found after build: $BinPath"
}

Write-Host "Verifying required extensions..."
$Modules = & $BinPath -m 2>&1 | Out-String
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
Compress-Archive -Path $BinPath -DestinationPath $DestZip -Force

Write-Host "Wrote $DestZip"
