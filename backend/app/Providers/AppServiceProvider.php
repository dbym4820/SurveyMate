<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AiSummaryService;
use App\Services\RssFetcherService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiSummaryService::class, function ($app) {
            return new AiSummaryService();
        });

        $this->app->singleton(RssFetcherService::class, function ($app) {
            return new RssFetcherService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
