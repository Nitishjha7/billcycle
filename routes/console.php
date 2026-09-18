<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler container runs `schedule:work`, which fires this daily. For
// demos, run `php artisan billing:run` by hand instead -- that's the point
// of the idempotency moment in docs/DEMO_SCRIPT.md.
Schedule::command('billing:run')->daily();
