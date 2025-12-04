<?php

use Illuminate\Support\Facades\Schedule;
use App\Services\RssFetcherService;
use App\Models\Session;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// RSS Fetch - Daily at 6:00 AM (Tokyo time)
Schedule::call(function () {
    if (config('services.fetch.enabled', true)) {
        $fetcher = app(RssFetcherService::class);
        $fetcher->fetchAll();
    }
})->dailyAt('06:00')
  ->timezone('Asia/Tokyo')
  ->name('rss-fetch')
  ->withoutOverlapping();

// Cleanup expired sessions - Hourly
Schedule::call(function () {
    Session::cleanupExpired();
})->hourly()
  ->name('cleanup-sessions');
