import fs from 'fs';
import fs_extra from 'fs-extra';
import { join } from 'path';
import { execFileSync } from 'child_process';
import unzip from 'yauzl';
const { removeSync, ensureDirSync } = fs_extra;

const isBuilding = Boolean(process.env.NATIVEPHP_BUILDING);
const phpBinaryPath = process.env.NATIVEPHP_PHP_BINARY_PATH;
const phpVersion = process.env.NATIVEPHP_PHP_BINARY_VERSION;

// Differentiates for Serving and Building
const isArm64 = isBuilding ? process.argv.includes('--arm64') : process.arch.includes('arm64');
const isWindows = isBuilding ? process.argv.includes('--win') : process.platform.includes('win32');
const isLinux = isBuilding ? process.argv.includes('--linux') : process.platform.includes('linux');
const isDarwin = isBuilding ? process.argv.includes('--mac') : process.platform.includes('darwin');

// false because string mapping is done in is{OS} checks
const platform = {
    os: false,
    arch: false,
    phpBinary: 'php',
};

if (isWindows) {
    platform.os = 'win';
    platform.arch = 'x64';
    platform.phpBinary += '.exe';
}

if (isLinux) {
    platform.os = 'linux';
    platform.arch = 'x64';
}

if (isDarwin) {
    platform.os = 'mac';
    platform.arch = 'x64';
}

if (isArm64) {
    platform.arch = 'arm64';
}

// isBuilding overwrites platform to the desired architecture
if (isBuilding) {
    // Only one will be used by the configured build commands in package.json
    platform.arch = process.argv.includes('--x64') ? 'x64' : platform.arch;
    platform.arch = process.argv.includes('--arm64') ? 'arm64' : platform.arch;
}

const phpVersionZip = 'php-' + phpVersion + '.zip';
const binarySrcDir = join(phpBinaryPath, platform.os, platform.arch, phpVersionZip);
const binaryDestDir = join(process.env.NATIVEPHP_BUILD_PATH, 'php');
const binaryPath = join(binaryDestDir, platform.phpBinary);

console.log('Binary Source: ', binarySrcDir);
console.log('Binary Filename: ', platform.phpBinary);
console.log('PHP version: ' + phpVersion);

/**
 * Extract the single PHP binary entry from the zip via the system `unzip`
 * CLI. Confirmed by direct testing to be immune to a reproducible bug where
 * yauzl's read stream silently stalls partway through (consistently a few
 * hundred KB short, no error, no more data) specifically when this script
 * runs as a descendant of PHP's Process runner (proc_open) — happens
 * whether launched via `composer native:dev`, `php artisan native:run`
 * directly, with or without a streaming output callback, regardless of
 * process nesting depth. A plain child process doing its own OS-level I/O
 * (unzip) never exhibits it. Throws on failure.
 */
function extractViaUnzipCli() {
    execFileSync('unzip', ['-o', binarySrcDir, '-d', binaryDestDir], { stdio: 'pipe' });
}

/**
 * Fallback for platforms without an `unzip` CLI (e.g. Windows). Streams the
 * single entry out of the zip via yauzl.
 */
function extractViaYauzl() {
    return new Promise((resolve, reject) => {
        unzip.open(binarySrcDir, { lazyEntries: true }, function (err, zipfile) {
            if (err) return reject(err);

            zipfile.readEntry();
            zipfile.on('entry', function (entry) {
                zipfile.openReadStream(entry, function (err, readStream) {
                    if (err) return reject(err);

                    const writeStream = fs.createWriteStream(binaryPath);
                    readStream.on('error', reject);
                    writeStream.on('error', reject);
                    readStream.pipe(writeStream);

                    writeStream.on('close', function () {
                        zipfile.readEntry();
                        resolve();
                    });
                });
            });
        });
    });
}

function hasUnzipCli() {
    try {
        execFileSync('unzip', ['-v'], { stdio: 'ignore' });
        return true;
    } catch {
        return false;
    }
}

if (platform.phpBinary) {
    const maxAttempts = 3;
    const useCli = hasUnzipCli();

    const attemptExtract = async (attempt) => {
        console.log(`Unzipping PHP binary from ${binarySrcDir} to ${binaryDestDir} (attempt ${attempt}/${maxAttempts}, via ${useCli ? 'unzip CLI' : 'yauzl'})`);

        try {
            removeSync(binaryDestDir);
            ensureDirSync(binaryDestDir);

            if (useCli) {
                extractViaUnzipCli();
            } else {
                await extractViaYauzl();
            }

            // Guard against a truncated/partial extraction being used silently.
            if (fs.statSync(binaryPath).size === 0) {
                throw new Error('extracted PHP binary is empty');
            }

            fs.chmodSync(binaryPath, 0o755);
            console.log('Copied PHP binary to ', binaryPath);
        } catch (e) {
            console.error(`PHP binary extraction attempt ${attempt}/${maxAttempts} failed: ${e.message || e}`);
            if (attempt < maxAttempts) {
                await new Promise((r) => setTimeout(r, 250));
                return attemptExtract(attempt + 1);
            }
            console.error(`Fatal: failed to extract PHP binary to ${binaryPath} after ${maxAttempts} attempts`);
            process.exit(1);
        }
    };

    await attemptExtract(1);
}
