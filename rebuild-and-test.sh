#!/bin/bash

echo "========================================="
echo "  重建 Docker 容器並測試排程"
echo "========================================="
echo ""

# 顏色定義
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 步驟 1：停止容器
echo -e "${YELLOW}步驟 1: 停止現有容器...${NC}"
docker compose down
echo -e "${GREEN}✅ 容器已停止${NC}"
echo ""

# 步驟 2：重建容器
echo -e "${YELLOW}步驟 2: 重建容器（這可能需要幾分鐘）...${NC}"
docker compose build --no-cache app
echo -e "${GREEN}✅ 容器重建完成${NC}"
echo ""

# 步驟 3：啟動容器
echo -e "${YELLOW}步驟 3: 啟動容器...${NC}"
docker compose up -d
echo -e "${GREEN}✅ 容器已啟動${NC}"
echo ""

# 步驟 4：等待容器完全啟動
echo -e "${YELLOW}步驟 4: 等待容器完全啟動...${NC}"
sleep 10
echo -e "${GREEN}✅ 容器啟動完成${NC}"
echo ""

# 步驟 5：檢查容器狀態
echo -e "${YELLOW}步驟 5: 檢查容器狀態...${NC}"
docker compose ps
echo ""

# 步驟 6：檢查 Supervisor 狀態
echo -e "${YELLOW}步驟 6: 檢查 Supervisor 狀態...${NC}"
docker compose exec app supervisorctl status
echo ""

# 步驟 7：檢查 SCHEDULER_ENABLED
echo -e "${YELLOW}步驟 7: 檢查排程配置...${NC}"
docker compose exec app grep SCHEDULER_ENABLED /var/www/html/web-miniverse/.env || echo "⚠️  SCHEDULER_ENABLED 未設置"
echo ""

# 步驟 8：列出排程任務
echo -e "${YELLOW}步驟 8: 列出所有排程任務...${NC}"
docker compose exec app php artisan schedule:list
echo ""

# 步驟 9：手動執行一次排程測試
echo -e "${YELLOW}步驟 9: 手動執行排程測試...${NC}"
docker compose exec app php artisan schedule:run --verbose
echo ""

# 步驟 10：查看排程日誌
echo -e "${YELLOW}步驟 10: 查看排程日誌（最近 20 行）...${NC}"
docker compose exec app tail -20 /var/log/supervisor/scheduler.log 2>/dev/null || echo "⚠️  日誌文件尚未生成"
echo ""

echo "========================================="
echo -e "${GREEN}  重建和測試完成！${NC}"
echo "========================================="
echo ""
echo "後續操作："
echo "  1. 即時監控排程日誌："
echo "     docker compose exec app tail -f /var/log/supervisor/scheduler.log"
echo ""
echo "  2. 檢查 Supervisor 狀態："
echo "     docker compose exec app supervisorctl status"
echo ""
echo "  3. 重啟排程服務："
echo "     docker compose exec app supervisorctl restart laravel-scheduler:*"
echo ""
echo "  4. 查看容器日誌："
echo "     docker compose logs -f app"
echo ""

