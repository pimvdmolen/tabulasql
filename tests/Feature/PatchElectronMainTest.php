<?php

it('copies both maintained patch files over their vendor counterparts', function () {
    $mainTarget = base_path('vendor/nativephp/desktop/resources/electron/src/main/index.js');
    $phpJsTarget = base_path('vendor/nativephp/desktop/resources/electron/php.js');

    if (! file_exists($mainTarget) || ! file_exists($phpJsTarget)) {
        $this->markTestSkipped('nativephp/desktop electron scaffold not installed');
    }

    $originalMain = file_get_contents($mainTarget);
    $originalPhpJs = file_get_contents($phpJsTarget);
    file_put_contents($mainTarget, "// tampered\n");
    file_put_contents($phpJsTarget, "// tampered\n");

    $this->artisan('native:patch-electron')->assertExitCode(0);

    expect(file_get_contents($mainTarget))
        ->toBe(file_get_contents(resource_path('nativephp-patches/electron-main-index.js')))
        ->toContain('web-contents-created')
        ->toContain('disableHardwareAcceleration');

    expect(file_get_contents($phpJsTarget))
        ->toBe(file_get_contents(resource_path('nativephp-patches/electron-php.js')))
        ->toContain('extractViaUnzipCli');

    file_put_contents($mainTarget, $originalMain);
    file_put_contents($phpJsTarget, $originalPhpJs);
});
