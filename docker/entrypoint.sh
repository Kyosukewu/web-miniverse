#!/bin/bash
set -e

echo "========================================="
echo "  Laravel Application Starting..."
echo "========================================="

# 等待數據庫準備就緒（使用簡單的 MySQL 連接測試）
if [ -n "$DB_HOST" ]; then
    echo "等待數據庫連接 (DB_HOST=$DB_HOST)..."
    MAX_TRIES=30
    COUNT=0
    
    # 使用 mysqladmin 測試連接（更可靠）
    until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null || [ $COUNT -eq $MAX_TRIES ]; do
        COUNT=$((COUNT + 1))
        echo "數據庫尚未就緒，等待中... ($COUNT/$MAX_TRIES)"
        sleep 2
    done
    
    if [ $COUNT -eq $MAX_TRIES ]; then
        echo "⚠️  數據庫連接超時，繼續啟動但某些功能可能無法使用"
    else
        echo "✅ 數據庫連接成功"
    fi
fi

# 清除快取（失敗不中斷）
echo "清除應用快取..."
php artisan config:clear || echo "⚠️ config:clear 失敗"
php artisan route:clear || echo "⚠️ route:clear 失敗"
php artisan view:clear || echo "⚠️ view:clear 失敗"

# 檢查 SCHEDULER_ENABLED 環境變數
if [ "$SCHEDULER_ENABLED" = "true" ] || [ "$SCHEDULER_ENABLED" = "1" ]; then
    echo "✅ Laravel 排程已啟用 (SCHEDULER_ENABLED=$SCHEDULER_ENABLED)"
else
    echo "⚠️  Laravel 排程未啟用 (SCHEDULER_ENABLED=$SCHEDULER_ENABLED)"
    echo "   設置 SCHEDULER_ENABLED=true 以啟用排程"
fi

# 創建必要的目錄並設置權限
echo "創建必要的目錄並設置權限..."
mkdir -p /var/log/supervisor /var/run
mkdir -p /var/www/html/web-miniverse/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/web-miniverse/storage/logs
mkdir -p /var/www/html/web-miniverse/bootstrap/cache

# 設置 Laravel 目錄權限（但不修改 .gitignore 文件）
# 使用 find 來精確控制權限修改範圍
find /var/www/html/web-miniverse/storage -type d -exec chmod 775 {} \;
find /var/www/html/web-miniverse/storage -type f ! -name '.gitignore' -exec chmod 664 {} \;
find /var/www/html/web-miniverse/storage -type f ! -name '.gitignore' -exec chown www-data:www-data {} \;
find /var/www/html/web-miniverse/storage -type d -exec chown www-data:www-data {} \;

find /var/www/html/web-miniverse/bootstrap/cache -type d -exec chmod 775 {} \;
find /var/www/html/web-miniverse/bootstrap/cache -type f ! -name '.gitignore' -exec chmod 664 {} \;
find /var/www/html/web-miniverse/bootstrap/cache -type f ! -name '.gitignore' -exec chown www-data:www-data {} \;
find /var/www/html/web-miniverse/bootstrap/cache -type d -exec chown www-data:www-data {} \;

# 設置 Supervisor 日誌權限
chown -R www-data:www-data /var/log/supervisor
chmod 755 /var/log/supervisor

# 檢查 supervisord 配置
echo "檢查 supervisord 配置..."
if [ ! -f /etc/supervisor/supervisord.conf ]; then
    echo "❌ 錯誤: /etc/supervisor/supervisord.conf 不存在！"
    exit 1
fi

echo "Supervisor 配置文件內容："
cat /etc/supervisor/supervisord.conf

echo ""
echo "========================================="
echo "  啟動 Supervisor..."
echo "========================================="

# 檢查 supervisord 是否存在
if ! command -v supervisord &> /dev/null; then
    echo "❌ 錯誤: supervisord 命令不存在！"
    exit 1
fi

echo "Supervisord 路徑: $(which supervisord)"
echo "Supervisord 版本: $(supervisord --version 2>&1)"

# 啟動 Supervisor（前台運行，管理 PHP-FPM 和 Laravel Scheduler）
echo "執行: supervisord -n -c /etc/supervisor/supervisord.conf"
exec supervisord -n -c /etc/supervisor/supervisord.conf

