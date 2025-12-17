#!/bin/bash
echo "========================================="
echo "  檢查 GCS Proxy 錯誤"
echo "========================================="
echo ""

echo "1. 檢查 Laravel 錯誤日誌（最後 50 行）："
echo "-----------------------------------"
tail -50 storage/logs/laravel.log 2>/dev/null || echo "⚠️ 日誌文件不存在"
echo ""

echo "2. 檢查 Nginx 錯誤日誌："
echo "-----------------------------------"
docker compose logs --tail=20 nginx 2>/dev/null || echo "⚠️ 無法獲取日誌"
echo ""

echo "3. 測試 GCS 連接："
echo "-----------------------------------"
docker compose exec app php artisan tinker --execute="
echo '測試 GCS 連接...' . PHP_EOL;
try {
    \$disk = Storage::disk('gcs');
    \$testPath = 'cnn/CNNA-ST1-20000000000905FC/BHDN_BU-40WE_OREO WILL SELL SUGAR-_CNNA-ST1-20000000000905fc_174_0.mp4';
    echo '檢查文件: ' . \$testPath . PHP_EOL;
    echo '文件存在: ' . (\$disk->exists(\$testPath) ? '是' : '否') . PHP_EOL;
    if (\$disk->exists(\$testPath)) {
        echo '文件大小: ' . \$disk->size(\$testPath) . ' bytes' . PHP_EOL;
    }
} catch (Exception \$e) {
    echo '錯誤: ' . \$e->getMessage() . PHP_EOL;
}
"
echo ""

echo "4. 檢查容器 storage 權限："
echo "-----------------------------------"
docker compose exec app ls -la /var/www/html/web-miniverse/storage/logs/ 2>/dev/null || echo "⚠️ 無法訪問"
