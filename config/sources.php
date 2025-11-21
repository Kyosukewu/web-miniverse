<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | CNN Configuration
    |--------------------------------------------------------------------------
    |
    | CNN resources are fetched from Windows Server and stored in S3.
    |
    */

    'cnn' => [
        'storage_type' => env('CNN_STORAGE_TYPE', 's3'),
        's3_bucket' => env('CNN_S3_BUCKET'),
        's3_path' => env('CNN_S3_PATH', 'cnn/'),
        'windows_server_path' => env('CNN_WINDOWS_SERVER_PATH'), // Optional: path on Windows Server
        'sync_from_windows' => env('CNN_SYNC_FROM_WINDOWS', false), // Enable Windows Server sync
    ],

    /*
    |--------------------------------------------------------------------------
    | AP (Associated Press) Configuration
    |--------------------------------------------------------------------------
    |
    | AP resources are fetched via API and stored in S3.
    |
    */

    'ap' => [
        'api_url' => env('AP_API_URL'),
        'api_key' => env('AP_API_KEY'),
        'storage_type' => env('AP_STORAGE_TYPE', 's3'),
        's3_bucket' => env('AP_S3_BUCKET'),
        's3_path' => env('AP_S3_PATH', 'ap/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RT (Reuters) Configuration
    |--------------------------------------------------------------------------
    |
    | RT resources are fetched via API and stored in S3.
    |
    */

    'rt' => [
        'api_url' => env('RT_API_URL'),
        'api_key' => env('RT_API_KEY'),
        'storage_type' => env('RT_STORAGE_TYPE', 's3'),
        's3_bucket' => env('RT_S3_BUCKET'),
        's3_path' => env('RT_S3_PATH', 'rt/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | YouTube Configuration
    |--------------------------------------------------------------------------
    |
    | YouTube videos are analyzed directly without storage.
    |
    */

    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
        'direct_analysis' => true, // Videos are analyzed directly, not stored
    ],
];

