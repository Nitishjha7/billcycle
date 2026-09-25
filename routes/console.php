<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler container runs `schedule:work`, which fires this daily.
// Running it by hand instead shows the idempotency guarantee directly:
// run it twice and the second run bills nothing.
Schedule::command('billing:run')->daily();

// Retry intervals are +1/+3/+5 days, not daily, but checking hourly for
// invoices whose next_retry_at has passed is what makes those exact
// intervals land on time without a bespoke per-invoice scheduled job.
Schedule::command('dunning:retry')->hourly();
