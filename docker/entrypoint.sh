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

# 設置 Laravel 目錄權限
# 注意：Git 已配置 core.fileMode=false，所以權限變更不會被追蹤
chown -R www-data:www-data /var/www/html/web-miniverse/storage
chown -R www-data:www-data /var/www/html/web-miniverse/bootstrap/cache
chmod -R 775 /var/www/html/web-miniverse/storage
chmod -R 775 /var/www/html/web-miniverse/bootstrap/cache

# 設置 Supervisor 日誌權限
chown -R www-data:www-data /var/log/supervisor
chmod 755 /var/log/supervisor

# 修復 PHP-FPM 配置：確保監聽在 0.0.0.0:9000（允許其他容器連接）
echo "修復 PHP-FPM 配置..."

# 修復 docker.conf（優先級較高，會覆蓋 www.conf）
DOCKER_CONF="/usr/local/etc/php-fpm.d/docker.conf"
if [ -f "$DOCKER_CONF" ]; then
    # 備份原始配置
    if [ ! -f "${DOCKER_CONF}.bak" ]; then
        cp "$DOCKER_CONF" "${DOCKER_CONF}.bak"
    fi
    
    # 修改 listen = 9000 為 listen = 0.0.0.0:9000
    if grep -q "^listen = 9000" "$DOCKER_CONF"; then
        sed -i 's/^listen = 9000/listen = 0.0.0.0:9000/' "$DOCKER_CONF"
        echo "✅ 已將 docker.conf 中的監聽地址改為 0.0.0.0:9000"
    elif grep -q "^listen = 127.0.0.1:9000" "$DOCKER_CONF"; then
        sed -i 's/^listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' "$DOCKER_CONF"
        echo "✅ 已將 docker.conf 中的監聽地址改為 0.0.0.0:9000"
    elif ! grep -q "^listen = 0.0.0.0:9000" "$DOCKER_CONF"; then
        # 如果沒有找到 0.0.0.0:9000，則添加或修改
        sed -i 's/^listen = .*/listen = 0.0.0.0:9000/' "$DOCKER_CONF" || \
        sed -i '/^\[www\]/a listen = 0.0.0.0:9000' "$DOCKER_CONF"
        echo "✅ 已設置 docker.conf 中的監聽地址為 0.0.0.0:9000"
    fi
fi

# 修復 www.conf（備用）
PHP_FPM_CONF="/usr/local/etc/php-fpm.d/www.conf"
if [ -f "$PHP_FPM_CONF" ]; then
    # 備份原始配置
    if [ ! -f "${PHP_FPM_CONF}.bak" ]; then
        cp "$PHP_FPM_CONF" "${PHP_FPM_CONF}.bak"
    fi
    
    # 修改 listen 地址為 0.0.0.0:9000（允許容器間通信）
    if grep -q "^listen = 127.0.0.1:9000" "$PHP_FPM_CONF"; then
        sed -i 's/^listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' "$PHP_FPM_CONF"
        echo "✅ 已將 www.conf 中的監聽地址改為 0.0.0.0:9000"
    elif grep -q "^listen = 9000" "$PHP_FPM_CONF"; then
        sed -i 's/^listen = 9000/listen = 0.0.0.0:9000/' "$PHP_FPM_CONF"
        echo "✅ 已將 www.conf 中的監聽地址改為 0.0.0.0:9000"
    elif ! grep -q "^listen = 0.0.0.0:9000" "$PHP_FPM_CONF"; then
        # 如果沒有找到 0.0.0.0:9000，則添加或修改
        sed -i 's/^listen = .*/listen = 0.0.0.0:9000/' "$PHP_FPM_CONF" || \
        sed -i '/^\[www\]/a listen = 0.0.0.0:9000' "$PHP_FPM_CONF"
        echo "✅ 已設置 www.conf 中的監聽地址為 0.0.0.0:9000"
    fi
fi

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

