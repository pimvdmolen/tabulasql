<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Voorkom dat scheduler.log onbeperkt groeit: verwijder het wekelijks (maandag 02:00).
Schedule::call(function () {
    $log = storage_path('logs/scheduler.log');

    if (is_file($log)) {
        @unlink($log);
    }
})->weeklyOn(1, '02:00')
    ->timezone('Europe/Amsterdam')
    ->name('cleanup-scheduler-log');
