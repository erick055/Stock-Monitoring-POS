<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('stock-alerts:check')->everyMinute()->withoutOverlapping();
Schedule::command('stock-alerts:daily-summary')->everyMinute()->withoutOverlapping();
// Process existing and new alert jobs even when no persistent worker is running.
Schedule::command('queue:work stock-alerts --queue=default --stop-when-empty --max-time=45 --timeout=60')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('ml:refresh')->hourly()->withoutOverlapping(60);
