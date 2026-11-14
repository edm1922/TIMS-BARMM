<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Calendar-year rollout: prior-year records become inactive at the start of
// the new year. The command re-records sync changes so offline servers follow.
Schedule::command('beneficiaries:deactivate-prior-years --apply')
    ->dailyAt('00:05')
    ->withoutOverlapping();
