<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule Configuration
|--------------------------------------------------------------------------
|
| Define scheduled tasks here.
|
*/

// Run RSS fetch daily at 6:00 AM (Tokyo time)
Schedule::command('rss:fetch')
    ->dailyAt('06:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/rss-fetch.log'));

// Cleanup expired sessions - Hourly
Schedule::call(function () {
    \App\Models\Session::cleanupExpired();
})->hourly();
