#!/bin/bash
set -e

echo "========================================="
echo "  Laravel Application Starting..."
echo "========================================="

# 等待數據庫準備就緒（如果需要）
if [ -n "$DB_HOST" ]; then
    echo "等待數據庫連接..."
    until php artisan db:show 2>/dev/null; do
        echo "數據庫尚未就緒，等待中..."
        sleep 2
    done
    echo "✅ 數據庫連接成功"
fi

# 清除快取
echo "清除應用快取..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 檢查 SCHEDULER_ENABLED 環境變數
if [ "$SCHEDULER_ENABLED" = "true" ] || [ "$SCHEDULER_ENABLED" = "1" ]; then
    echo "✅ Laravel 排程已啟用 (SCHEDULER_ENABLED=$SCHEDULER_ENABLED)"
else
    echo "⚠️  Laravel 排程未啟用 (SCHEDULER_ENABLED=$SCHEDULER_ENABLED)"
    echo "   設置 SCHEDULER_ENABLED=true 以啟用排程"
fi

# 創建日誌目錄
mkdir -p /var/log/supervisor
chown -R www-data:www-data /var/log/supervisor

echo "========================================="
echo "  啟動 Supervisor..."
echo "========================================="

# 啟動 Supervisor（前台運行，管理 PHP-FPM 和 Laravel Scheduler）
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf

