#!/bin/bash
# PHP-FPM 快速诊断脚本

echo "=== 1. 检查当前 PHP-FPM 监听地址 ==="
docker compose exec app cat /usr/local/etc/php-fpm.d/www.conf | grep "^listen ="

echo ""
echo "=== 2. 检查 PHP-FPM 进程状态 ==="
docker compose exec app supervisorctl status php-fpm

echo ""
echo "=== 3. 检查 PHP-FPM 是否在监听 ==="
docker compose exec app netstat -tlnp 2>/dev/null | grep 9000 || \
docker compose exec app ss -tlnp 2>/dev/null | grep 9000 || \
echo "无法检查端口（可能需要安装 net-tools）"

echo ""
echo "=== 4. 从 Nginx 容器测试连接 ==="
docker compose exec nginx nc -zv app 9000 2>&1 || echo "连接失败"

echo ""
echo "=== 5. 检查容器网络 ==="
echo "App 容器 IP:"
docker compose exec app hostname -i
echo ""
echo "从 Nginx 容器 ping App 容器:"
docker compose exec nginx ping -c 1 app 2>&1 | head -3
