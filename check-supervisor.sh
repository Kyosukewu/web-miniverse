#!/bin/bash
echo "========================================="
echo "  檢查 Supervisor 問題"
echo "========================================="
echo ""

echo "1. 檢查容器日誌（最後 50 行）："
echo "-----------------------------------"
docker compose logs --tail=50 app
echo ""

echo "2. 檢查容器內的進程："
echo "-----------------------------------"
docker compose exec app ps aux
echo ""

echo "3. 檢查 supervisord 配置文件："
echo "-----------------------------------"
docker compose exec app ls -la /etc/supervisor/
echo ""

echo "4. 檢查 entrypoint.sh 是否存在："
echo "-----------------------------------"
docker compose exec app ls -la /usr/local/bin/entrypoint.sh
echo ""

echo "5. 測試手動啟動 supervisord："
echo "-----------------------------------"
docker compose exec app supervisord -c /etc/supervisor/supervisord.conf || echo "啟動失敗"
echo ""

echo "6. 再次檢查狀態："
echo "-----------------------------------"
docker compose exec app supervisorctl status || echo "supervisorctl 失敗"
