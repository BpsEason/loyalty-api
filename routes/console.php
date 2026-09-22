<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 排程每天凌晨2點自動過期點數
Schedule::command('points:expire')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();
