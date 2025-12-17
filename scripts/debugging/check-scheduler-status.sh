#!/bin/bash

echo "==========================================="
echo "  檢查排程運行狀態"
echo "==========================================="
echo ""

echo "1. 檢查 .env 中的 SCHEDULER_ENABLED 設置："
echo "-----------------------------------"
grep "SCHEDULER_ENABLED" /var/www/html/web-miniverse/.env || echo "❌ 未找到 SCHEDULER_ENABLED 設置"
echo ""

echo "2. 檢查容器中的環境變數："
echo "-----------------------------------"
docker compose exec app env | grep SCHEDULER_ENABLED || echo "❌ 容器中未設置 SCHEDULER_ENABLED"
echo ""

echo "3. 檢查 Supervisor 狀態："
echo "-----------------------------------"
docker compose exec app supervisorctl status
echo ""

echo "4. 檢查 Laravel Scheduler 進程："
echo "-----------------------------------"
docker compose exec app bash -c "ps aux | grep 'schedule:run' | grep -v grep" || echo "✅ 無 schedule:run 進程"
echo ""

echo "5. 檢查最近的排程日誌（最後 20 行）："
echo "-----------------------------------"
docker compose exec app tail -20 /var/log/supervisor/laravel-scheduler-stdout.log 2>/dev/null || echo "無日誌或日誌文件不存在"
echo ""

echo "==========================================="
echo "  🔍 診斷建議"
echo "==========================================="
echo ""
echo "如果排程仍在運行，請執行："
echo "  1. docker compose restart app"
echo "  2. docker compose exec app supervisorctl status"
echo ""
