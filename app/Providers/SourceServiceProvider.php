<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sources\ApFetchService;
use App\Services\Sources\CnnFetchService;
use App\Services\Sources\RtFetchService;
use App\Services\Sources\YoutubeFetchService;
use App\Services\StorageService;
use Illuminate\Support\ServiceProvider;

class SourceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register StorageService
        $this->app->singleton(StorageService::class);

        // Register CNN Fetch Service
        $this->app->singleton(CnnFetchService::class, function ($app) {
            $config = config('sources.cnn', []);

            return new CnnFetchService(
                $app->make(StorageService::class),
                $config
            );
        });

        // Register AP Fetch Service
        $this->app->singleton(ApFetchService::class, function ($app) {
            $config = config('sources.ap', []);

            return new ApFetchService(
                $app->make(StorageService::class),
                $config
            );
        });

        // Register RT Fetch Service
        $this->app->singleton(RtFetchService::class, function ($app) {
            $config = config('sources.rt', []);

            return new RtFetchService(
                $app->make(StorageService::class),
                $config
            );
        });

        // Register YouTube Fetch Service
        $this->app->singleton(YoutubeFetchService::class, function ($app) {
            $apiKey = config('sources.youtube.api_key', '');
            // Ensure apiKey is always a string, not null
            $apiKey = $apiKey ?? '';

            return new YoutubeFetchService($apiKey);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

