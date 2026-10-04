<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('stock-alerts:check')->everyMinute()->withoutOverlapping();
Schedule::command('stock-alerts:daily-summary')->everyMinute()->withoutOverlapping();
Schedule::command('ml:refresh')->hourly()->withoutOverlapping(60);
