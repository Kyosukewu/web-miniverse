<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Gemini API integration.
    |
    */

    'api_key' => env('GEMINI_API_KEY'),
    'api_version' => env('GEMINI_API_VERSION', 'v1beta'), // v1 or v1beta
    'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-2.5-flash'),
    'video_model' => env('GEMINI_VIDEO_MODEL', 'gemini-2.5-flash'),
    'nas_video_path' => env('NAS_VIDEO_PATH', storage_path('app/videos')),

    /*
    |--------------------------------------------------------------------------
    | Prompt Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for prompt version management.
    |
    */

    'prompts' => [
        'text_file_analysis' => [
            'current_version' => env('GEMINI_TEXT_PROMPT_VERSION', 'v3'),
            'versions' => [
                'v1' => storage_path('app/prompts/text_analysis/v1.txt'),
                'v2' => storage_path('app/prompts/text_analysis/v2.txt'),
                'v3' => storage_path('app/prompts/text_analysis/v3.txt'),
            ],
        ],
        'video_analysis' => [
            'current_version' => env('GEMINI_VIDEO_PROMPT_VERSION', 'v6'),
            'versions' => [
                'v5' => storage_path('app/prompts/video_analysis/v5.txt'),
                'v6' => storage_path('app/prompts/video_analysis/v6.txt'),
            ],
        ],
    ],
];

