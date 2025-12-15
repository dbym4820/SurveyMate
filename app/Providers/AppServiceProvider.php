<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AiSummaryService;
use App\Services\AiRssGeneratorService;
use App\Services\RssFetcherService;
use App\Services\FullTextFetcherService;
use App\Services\MetadataExtraction\PatternMatcher;
use App\Services\MetadataExtraction\PatternDetector;
use App\Services\MetadataExtraction\MetadataExtractorService;

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

        // メタデータ抽出サービス
        $this->app->singleton(PatternMatcher::class, function ($app) {
            return new PatternMatcher();
        });

        $this->app->singleton(PatternDetector::class, function ($app) {
            return new PatternDetector(
                $app->make(PatternMatcher::class)
            );
        });

        $this->app->singleton(MetadataExtractorService::class, function ($app) {
            return new MetadataExtractorService(
                $app->make(PatternDetector::class),
                $app->make(PatternMatcher::class)
            );
        });

        $this->app->singleton(RssFetcherService::class, function ($app) {
            return new RssFetcherService(
                $app->make(FullTextFetcherService::class),
                $app->make(AiRssGeneratorService::class),
                $app->make(MetadataExtractorService::class)
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
