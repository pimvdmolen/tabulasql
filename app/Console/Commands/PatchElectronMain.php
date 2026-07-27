<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Copies our maintained files under resources/nativephp-patches/ back over
 * their vendor/nativephp/desktop/resources/electron/... counterparts:
 *
 * - src/main/index.js: GPU-crash workaround + opens external links in the
 *   system browser instead of a new Electron window.
 * - php.js: extracts the bundled PHP binary via the system `unzip` CLI
 *   instead of the bundled `yauzl` JS library, which has a reproducible
 *   silent-stall bug when this script runs as a descendant of PHP's Process
 *   runner (proc_open); every `native:build`/`native:run` invocation.
 * - electron-builder.mjs: wait for php.js in beforePack (stock uses async
 *   exec without await, so CI Linux builds often shipped without PHP).
 *
 * Neither vendor file is tracked by git; all get regenerated fresh by
 * `native:install` whenever nativephp/desktop is installed/updated, which
 * would otherwise silently drop these fixes. Run after `native:install`
 * (composer.json's post-install-cmd/post-update-cmd already do this) and
 * before any `native:build`/`native:run`.
 */
class PatchElectronMain extends Command
{
    protected $signature = 'native:patch-electron';

    protected $description = 'Reapply local fixes to NativePHP Electron files';

    /** @var array<string, string> patch resource name => vendor path relative to the electron directory */
    private const FILES = [
        'electron-main-index.js' => 'src/main/index.js',
        'electron-php.js' => 'php.js',
        'electron-builder.mjs' => 'electron-builder.mjs',
    ];

    public function handle(): int
    {
        $electronDir = base_path('vendor/nativephp/desktop/resources/electron');

        if (! is_dir($electronDir)) {
            $this->warn("Electron scaffold not found, skipping (nativephp/desktop isn't installed yet): $electronDir");

            return self::SUCCESS;
        }

        foreach (self::FILES as $patchName => $relativeTarget) {
            $source = resource_path("nativephp-patches/$patchName");
            $target = "$electronDir/$relativeTarget";

            if (! file_exists($source)) {
                $this->error("Patch source not found: $source");

                return self::FAILURE;
            }

            if (! is_dir(dirname($target))) {
                $this->warn("Target directory not found, skipping: $target");

                continue;
            }

            copy($source, $target);
            $this->info("Patched $target");
        }

        return self::SUCCESS;
    }
}
