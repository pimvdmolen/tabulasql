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

# Native command stderr/exit must NOT become terminating errors — we check
# $LASTEXITCODE ourselves. (Default PS 7 + ErrorAction Stop can otherwise
# abort before our checks, with a useless generic exit code 1.)
$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $false

function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $false)][string[]]$Arguments = @()
    )
    Write-Host "::group::Running: $FilePath $($Arguments -join ' ')"
    & $FilePath @Arguments
    $code = $LASTEXITCODE
    Write-Host "::endgroup::"
    if ($null -eq $code) {
        throw "Command did not set an exit code: $FilePath $($Arguments -join ' ')"
    }
    if ($code -ne 0) {
        throw "Command failed (exit $code): $FilePath $($Arguments -join ' ')"
    }
}

$RequiredExtensions = @(
    "pdo_mysql",
    "sodium",
    "sqlite3",
    "mbstring",
    "zip",
    "openssl"
)

# Lean but complete set for Laravel + this app (MySQL + sodium crypto).
$BuildExtensions = @(
    "bcmath", "bz2", "ctype", "curl", "dom", "fileinfo", "filter", "gd",
    "iconv", "mbstring", "mbregex", "opcache", "openssl", "pdo", "pdo_mysql",
    "pdo_sqlite", "phar", "session", "simplexml", "sockets", "sodium",
    "sqlite3", "tokenizer", "xml", "zip", "zlib"
) -join ","

$TempRoot = $env:RUNNER_TEMP
if ([string]::IsNullOrWhiteSpace($TempRoot)) {
    $TempRoot = [System.IO.Path]::GetTempPath()
}
$Work = Join-Path $TempRoot ("spc-win-build-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $Work | Out-Null
Set-Location $Work
Write-Host "Working directory: $Work"
Write-Host "PhpVersion=$PhpVersion DestBaseDir=$DestBaseDir"

# v3 nightly matches current static-php.dev docs / hosted Windows builds.
$SpcUrl = "https://dl.static-php.dev/v3/spc-bin/nightly/spc-windows-x64.exe"
$SpcExe = Join-Path $Work "spc.exe"

Write-Host "Downloading static-php-cli from $SpcUrl ..."
Invoke-Native -FilePath curl.exe -Arguments @("-fsSL", "-o", $SpcExe, $SpcUrl)
$spcSize = (Get-Item $SpcExe).Length
Write-Host "spc.exe size: $spcSize bytes"
if ($spcSize -lt 1MB) {
    throw "spc.exe download looks wrong (too small: $spcSize bytes)"
}
Unblock-File -Path $SpcExe -ErrorAction SilentlyContinue

Invoke-Native -FilePath $SpcExe -Arguments @("--version")
Invoke-Native -FilePath $SpcExe -Arguments @("doctor", "--auto-fix")

Write-Host "Building PHP $PhpVersion CLI with extensions: $BuildExtensions"
Invoke-Native -FilePath $SpcExe -Arguments @(
    "build",
    "--build-cli", $BuildExtensions,
    "--dl-with-php=$PhpVersion",
    "--dl-retry=5",
    "--debug"
)

$BinPath = Join-Path $Work "buildroot\bin\php.exe"
if (-not (Test-Path $BinPath)) {
    Write-Host "buildroot tree:"
    if (Test-Path (Join-Path $Work "buildroot")) {
        Get-ChildItem -Recurse (Join-Path $Work "buildroot") | Select-Object -First 80 | ForEach-Object { $_.FullName }
    } else {
        Write-Host "(no buildroot directory at all)"
        Get-ChildItem $Work | ForEach-Object { $_.FullName }
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

$Stage = Join-Path $Work "zip-stage"
New-Item -ItemType Directory -Force -Path $Stage | Out-Null
Copy-Item $BinPath (Join-Path $Stage "php.exe")
Compress-Archive -Path (Join-Path $Stage "php.exe") -DestinationPath $DestZip -Force

Write-Host "Wrote $DestZip ($((Get-Item $DestZip).Length) bytes)"
