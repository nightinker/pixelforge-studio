<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for lost webhooks and lost queue jobs. Needs `schedule:run` every minute.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();
