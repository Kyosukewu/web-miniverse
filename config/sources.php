<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | CNN Configuration
     |--------------------------------------------------------------------------
     |
     | CNN resources are fetched from Google Cloud Storage (GCS).
     |
     */

    'cnn' => [
        'storage_type' => env('CNN_STORAGE_TYPE', 'gcs'), // 預設使用 GCS
        // 本地來源路徑
        'source_path' => env('CNN_SOURCE_PATH', '/mnt/PushDownloads'),
        // GCS 配置
        'gcs_bucket' => env('CNN_GCS_BUCKET'),
        'gcs_path' => env('CNN_GCS_PATH', 'cnn/'),
        // S3 配置（備用）
        's3_bucket' => env('CNN_S3_BUCKET'),
        's3_path' => env('CNN_S3_PATH', 'cnn/'),
        // 版本檢查配置
        'version_check_enabled' => true, // CNN 支援版本號檢查 (_0, _1, _2)
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
        // 版本檢查配置
        'version_check_enabled' => false, // AP 目前不支援版本號檢查
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
        // 版本檢查配置
        'version_check_enabled' => false, // RT 目前不支援版本號檢查
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

