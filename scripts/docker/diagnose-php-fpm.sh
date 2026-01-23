#!/bin/bash
# PHP-FPM 診斷腳本

echo "========================================="
echo "  PHP-FPM 診斷報告"
echo "========================================="

echo ""
echo "=== 1. 檢查當前 PHP-FPM 監聽地址 ==="
docker compose exec app cat /usr/local/etc/php-fpm.d/www.conf | grep "^listen =" || echo "無法讀取配置文件"

echo ""
echo "=== 2. 檢查 PHP-FPM 進程狀態 ==="
docker compose exec app supervisorctl status php-fpm 2>&1

echo ""
echo "=== 3. 檢查 PHP-FPM 是否在監聽 ==="
echo "檢查 9000 端口..."
docker compose exec app sh -c 'netstat -tlnp 2>/dev/null | grep 9000 || ss -tlnp 2>/dev/null | grep 9000 || echo "無法檢查端口（可能需要安裝 net-tools）"' || echo "檢查失敗"

echo ""
echo "=== 4. 從 Nginx 容器測試連接 ==="
docker compose exec nginx nc -zv app 9000 2>&1 || echo "連接失敗"

echo ""
echo "=== 5. 檢查容器網絡 ==="
echo "App 容器 IP:"
docker compose exec app hostname -i 2>&1
echo ""
echo "從 Nginx 容器 ping App 容器:"
docker compose exec nginx ping -c 1 app 2>&1 | head -3

echo ""
echo "=== 6. 檢查 Nginx 配置 ==="
echo "FastCGI 配置:"
docker compose exec nginx grep -A 2 "fastcgi_pass" /etc/nginx/conf.d/default.conf | head -5

echo ""
echo "=== 7. 測試網站連接 ==="
echo "測試 HTTP 連接:"
curl -I http://localhost 2>&1 | head -5

echo ""
echo "========================================="
echo "  診斷完成"
echo "========================================="
