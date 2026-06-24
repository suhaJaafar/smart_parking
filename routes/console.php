<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep stale parking reservations every minute. Refunds free_spaces for any
// START hold whose expires_at has passed, and force-closes ACTIVE stays left
// unpaid more than 24h after creation (auto-exiting the car).
Schedule::command('reservations:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
