<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hub webhooks (Plan B Task 19): retries and any event whose after-commit dispatch was lost.
Schedule::command('hub:deliver-webhooks')->everyMinute()->withoutOverlapping();
