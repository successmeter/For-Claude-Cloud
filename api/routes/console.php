<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hub webhooks (Plan B Task 19): retries and any event whose after-commit dispatch was lost.
Schedule::command('hub:deliver-webhooks')->everyMinute()->withoutOverlapping();

// Sales uploads (Plan C): expire abandoned previews; delete snapshots past their 90 days.
Schedule::command('ingest:expire-runs')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('ingest:prune-snapshots')->dailyAt('03:00')->withoutOverlapping();

// Square sales (Plan E): nightly, after every venue's trading day has closed; jobs spread over an hour.
Schedule::command('pos:sync')->timezone('Australia/Sydney')->dailyAt('04:40')->withoutOverlapping();
