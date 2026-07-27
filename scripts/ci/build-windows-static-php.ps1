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
# Uses the same approach as static-php/hosted's Windows workflow: clone
# static-php-cli, composer install, then bin/spc (not the nightly .exe),
# which is what their green CI builds use.
#
# Usage (from repo root, on Windows):
#   pwsh -File scripts/ci/build-windows-static-php.ps1 -PhpVersion 8.4 -DestBaseDir nativephp-php-bin-custom

param(
    [Parameter(Mandatory = $true)][string]$PhpVersion,
    [Parameter(Mandatory = $true)][string]$DestBaseDir
)

$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $false

function Write-ErrorAnnotation {
    param([string]$Message)
    # Surfaces in the Actions "Annotations" API without needing log download rights.
    $oneLine = ($Message -replace '[\r\n]+', ' | ')
    Write-Host "::error::$oneLine"
    Write-Host $Message
}

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

try {
    $RequiredExtensions = @(
        "pdo_mysql",
        "sodium",
        "sqlite3",
        "mbstring",
        "zip",
        "openssl"
    )

    # App-focused set: skip gd/opcache/intl (common Windows static-build
    # footguns; this DB client does not need them). Keep sodium + pdo_mysql.
    # intl fails to build via static-php-cli on Windows (exit 75); UI code
    # must not call Laravel Number::* helpers that require it.
    $BuildExtensions = @(
        "bcmath", "ctype", "curl", "dom", "fileinfo", "filter", "iconv",
        "mbstring", "mbregex", "openssl", "pdo", "pdo_mysql", "pdo_sqlite",
        "phar", "session", "simplexml", "sockets", "sodium", "sqlite3",
        "tokenizer", "xml", "zip", "zlib"
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

    # Pin to the v3 branch used by static-php/hosted Windows builds.
    Write-Host "Cloning crazywhalecc/static-php-cli (v3)..."
    Invoke-Native -FilePath git.exe -Arguments @(
        "clone", "--depth", "1", "--branch", "v3",
        "https://github.com/crazywhalecc/static-php-cli.git", "spc-src"
    )
    Set-Location (Join-Path $Work "spc-src")

    Write-Host "composer install (static-php-cli)..."
    Invoke-Native -FilePath composer -Arguments @(
        "update", "-q", "--no-ansi", "--no-interaction", "--no-scripts",
        "--no-progress", "--prefer-dist", "--no-dev"
    )

    $SpcPhp = Join-Path (Get-Location) "bin\spc"
    if (-not (Test-Path $SpcPhp)) {
        throw "bin/spc not found after composer install"
    }
    Invoke-Native -FilePath php -Arguments @($SpcPhp, "--version")
    Invoke-Native -FilePath php -Arguments @($SpcPhp, "doctor", "--auto-fix")

    Write-Host "Building PHP $PhpVersion CLI with extensions: $BuildExtensions"
    Invoke-Native -FilePath php -Arguments @(
        $SpcPhp,
        "build",
        "--build-cli", $BuildExtensions,
        "--dl-with-php=$PhpVersion",
        "--dl-retry=5"
    )

    $BinPath = Join-Path (Get-Location) "buildroot\bin\php.exe"
    if (-not (Test-Path $BinPath)) {
        $found = Get-ChildItem -Path (Get-Location) -Recurse -Filter "php.exe" -ErrorAction SilentlyContinue |
            Select-Object -First 5
        if ($found) {
            Write-Host "php.exe candidates:"
            $found | ForEach-Object { Write-Host $_.FullName }
            $BinPath = $found[0].FullName
        } else {
            throw "Expected PHP binary not found under $(Get-Location)"
        }
    }
    Write-Host "Using PHP binary: $BinPath"

    Write-Host "Verifying required extensions..."
    # Windows php.exe emits CRLF; (?m)^name$ otherwise fails on the trailing CR
    # even when the extension is present (exactly how the previous CI run died).
    $Modules = ((& $BinPath -m 2>&1 | Out-String) -replace "`r", "")
    Write-Host $Modules
    foreach ($Extension in $RequiredExtensions) {
        if ($Modules -notmatch "(?m)^$([regex]::Escape($Extension))$") {
            throw "Missing required PHP extension `"$Extension`" in built static PHP.`n`nModules reported:`n$Modules"
        }
    }

    $PdoCheck = ((& $BinPath -r "new PDO('sqlite::memory:'); echo 'ok';" 2>&1 | Out-String) -replace "`r", "").Trim()
    if ($PdoCheck -ne "ok") {
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
}
catch {
    Write-ErrorAnnotation -Message $_.Exception.Message
    if ($_.ScriptStackTrace) { Write-Host $_.ScriptStackTrace }
    exit 1
}
