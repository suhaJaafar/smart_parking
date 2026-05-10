<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep stale parking-reservation holds every minute. Refunds free_spaces
// for any ACTIVE Reserve whose expires_at has passed.
Schedule::command('reservations:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
