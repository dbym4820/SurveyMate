<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AiSummaryService;
use App\Services\AiRssGeneratorService;
use App\Services\RssFetcherService;
use App\Services\FullTextFetcherService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(AiSummaryService::class, function ($app) {
            return new AiSummaryService();
        });

        $this->app->singleton(FullTextFetcherService::class, function ($app) {
            return new FullTextFetcherService();
        });

        $this->app->singleton(AiRssGeneratorService::class, function ($app) {
            return new AiRssGeneratorService();
        });

        $this->app->singleton(RssFetcherService::class, function ($app) {
            return new RssFetcherService(
                $app->make(FullTextFetcherService::class),
                $app->make(AiRssGeneratorService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
