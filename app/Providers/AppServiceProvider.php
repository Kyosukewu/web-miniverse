<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use Google\Cloud\Storage\StorageClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFour();

        // 註冊 GCS 驅動
        Storage::extend('gcs', function ($app, $config) {
            // 構建 StorageClient 配置
            $clientConfig = [];
            
            // 如果提供了 project_id，則使用它；否則使用默認認證
            if (!empty($config['project_id'])) {
                $clientConfig['projectId'] = $config['project_id'];
            }
            
            // 如果提供了 key_file 且檔案存在，則使用它；否則使用默認認證
            if (!empty($config['key_file']) && file_exists($config['key_file'])) {
                $clientConfig['keyFilePath'] = $config['key_file'];
            }
            
            // 如果沒有提供任何認證資訊，StorageClient 會使用默認認證
            // (例如：GOOGLE_APPLICATION_CREDENTIALS 環境變數或 gcloud auth application-default login)
            $storageClient = new StorageClient($clientConfig);

            $bucket = $storageClient->bucket($config['bucket']);
            $adapter = new GoogleCloudStorageAdapter($bucket, $config['path_prefix'] ?? '');

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}
