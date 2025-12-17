<?php

// Test GCS Proxy functionality
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== 測試 GCS Proxy 功能 ===" . PHP_EOL . PHP_EOL;

// 1. 測試 GCS 連接
echo "1. 測試 GCS 連接..." . PHP_EOL;
try {
    $disk = Storage::disk('gcs');
    echo "   ✓ GCS disk 已載入" . PHP_EOL;
} catch (Exception $e) {
    echo "   ✗ GCS disk 載入失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 2. 取得測試影片
echo PHP_EOL . "2. 取得測試影片資料..." . PHP_EOL;
$video = DB::table('videos')
    ->where('source_name', 'CNN')
    ->whereNotNull('nas_path')
    ->first();

if (!$video) {
    echo "   ✗ 找不到測試影片" . PHP_EOL;
    exit(1);
}

echo "   ✓ 找到影片: {$video->source_id}" . PHP_EOL;
echo "   NAS Path: {$video->nas_path}" . PHP_EOL;

// 3. 測試檔案是否存在
echo PHP_EOL . "3. 測試檔案是否存在於 GCS..." . PHP_EOL;
try {
    $exists = $disk->exists($video->nas_path);
    if ($exists) {
        echo "   ✓ 檔案存在" . PHP_EOL;
        
        // 取得檔案資訊
        $size = $disk->size($video->nas_path);
        $mimeType = $disk->mimeType($video->nas_path);
        echo "   檔案大小: " . number_format($size / 1024 / 1024, 2) . " MB" . PHP_EOL;
        echo "   MIME Type: " . $mimeType . PHP_EOL;
    } else {
        echo "   ✗ 檔案不存在" . PHP_EOL;
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ 檢查檔案失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 4. 測試讀取檔案（只讀前 1KB）
echo PHP_EOL . "4. 測試讀取檔案內容..." . PHP_EOL;
try {
    $stream = $disk->readStream($video->nas_path);
    if ($stream) {
        $firstBytes = fread($stream, 1024);
        fclose($stream);
        echo "   ✓ 成功讀取檔案內容 (前 1KB)" . PHP_EOL;
    } else {
        echo "   ✗ 無法開啟檔案串流" . PHP_EOL;
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ 讀取檔案失敗: " . $e->getMessage() . PHP_EOL;
    echo "   錯誤詳情: " . $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

// 5. 測試 URL 生成
echo PHP_EOL . "5. 測試 URL 生成..." . PHP_EOL;
$proxyUrl = route('gcs.proxy', ['path' => $video->nas_path]);
echo "   Proxy URL: {$proxyUrl}" . PHP_EOL;

// 6. 檢查路由
echo PHP_EOL . "6. 檢查路由註冊..." . PHP_EOL;
$routes = Route::getRoutes();
$gcsProxyRoute = $routes->getByName('gcs.proxy');
if ($gcsProxyRoute) {
    echo "   ✓ gcs.proxy 路由已註冊" . PHP_EOL;
    echo "   URI: " . $gcsProxyRoute->uri() . PHP_EOL;
    echo "   Action: " . $gcsProxyRoute->getActionName() . PHP_EOL;
} else {
    echo "   ✗ gcs.proxy 路由未註冊" . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "=== 所有測試通過 ===" . PHP_EOL;
echo PHP_EOL . "請在瀏覽器中測試以下 URL:" . PHP_EOL;
echo $proxyUrl . PHP_EOL;

