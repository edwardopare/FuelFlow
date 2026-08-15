<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('emails:send-license-expiry-alerts')
    ->dailyAt('07:00')
    ->timezone('Africa/Accra')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reports:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
