<?php

it('copies maintained patch files over their vendor counterparts', function () {
    $electronDir = base_path('vendor/nativephp/desktop/resources/electron');
    $mainTarget = "$electronDir/src/main/index.js";
    $phpJsTarget = "$electronDir/php.js";
    $builderTarget = "$electronDir/electron-builder.mjs";

    if (! file_exists($mainTarget) || ! file_exists($phpJsTarget) || ! file_exists($builderTarget)) {
        $this->markTestSkipped('nativephp/desktop electron scaffold not installed');
    }

    $originalMain = file_get_contents($mainTarget);
    $originalPhpJs = file_get_contents($phpJsTarget);
    $originalBuilder = file_get_contents($builderTarget);
    file_put_contents($mainTarget, "// tampered\n");
    file_put_contents($phpJsTarget, "// tampered\n");
    file_put_contents($builderTarget, "// tampered\n");

    $this->artisan('native:patch-electron')->assertExitCode(0);

    expect(file_get_contents($mainTarget))
        ->toBe(file_get_contents(resource_path('nativephp-patches/electron-main-index.js')))
        ->toContain('web-contents-created')
        ->toContain('disableHardwareAcceleration');

    expect(file_get_contents($phpJsTarget))
        ->toBe(file_get_contents(resource_path('nativephp-patches/electron-php.js')))
        ->toContain('extractViaUnzipCli');

    expect(file_get_contents($builderTarget))
        ->toBe(file_get_contents(resource_path('nativephp-patches/electron-builder.mjs')))
        ->toContain('execFileSync')
        ->toContain('fileURLToPath')
        ->toContain('cwd: electronDir')
        ->not->toContain("exec(`node php.js");

    file_put_contents($mainTarget, $originalMain);
    file_put_contents($phpJsTarget, $originalPhpJs);
    file_put_contents($builderTarget, $originalBuilder);
});
