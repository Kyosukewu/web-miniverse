<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\AnalysisResultRepository;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
use App\Services\GeminiClient;
use App\Services\PromptService;
use Illuminate\Support\ServiceProvider;

class GeminiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register GeminiClient
        $this->app->singleton(GeminiClient::class, function ($app) {
            $apiKey = config('gemini.api_key');
            $apiVersion = config('gemini.api_version', 'v1beta');
            $textModel = config('gemini.text_model', 'gemini-2.5-flash');
            $videoModel = config('gemini.video_model', 'gemini-2.5-flash');

            if (null === $apiKey || '' === $apiKey) {
                throw new \RuntimeException('Gemini API Key 未在設定中提供！');
            }

            return new GeminiClient($apiKey, $textModel, $videoModel, $apiVersion);
        });

        // Register PromptService
        $this->app->singleton(PromptService::class, function ($app) {
            $promptConfig = config('gemini.prompts', []);

            return new PromptService($promptConfig);
        });

        // Register AnalyzeService
        $this->app->singleton(AnalyzeService::class, function ($app) {
            $nasVideoPath = config('gemini.nas_video_path', storage_path('app/videos'));

            return new AnalyzeService(
                $app->make(GeminiClient::class),
                $app->make(VideoRepository::class),
                $app->make(AnalysisResultRepository::class),
                $app->make(PromptService::class),
                $nasVideoPath
            );
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

