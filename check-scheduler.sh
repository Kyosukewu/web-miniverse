#!/bin/bash

# 顏色定義
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "========================================="
echo -e "${BLUE}  Laravel 排程狀態檢查${NC}"
echo "========================================="
echo ""

# 1. 檢查容器狀態
echo -e "${YELLOW}1. 容器狀態:${NC}"
docker compose ps app
echo ""

# 2. 檢查 Supervisor 狀態
echo -e "${YELLOW}2. Supervisor 進程狀態:${NC}"
if docker compose exec app supervisorctl status 2>/dev/null; then
    echo -e "${GREEN}✅ Supervisor 運行正常${NC}"
else
    echo -e "${RED}❌ Supervisor 未運行${NC}"
fi
echo ""

# 3. 檢查環境變數
echo -e "${YELLOW}3. 排程配置:${NC}"
SCHEDULER_STATUS=$(docker compose exec app grep SCHEDULER_ENABLED /var/www/html/web-miniverse/.env 2>/dev/null | cut -d'=' -f2)
if [ "$SCHEDULER_STATUS" = "true" ]; then
    echo -e "${GREEN}✅ SCHEDULER_ENABLED=true (已啟用)${NC}"
else
    echo -e "${RED}⚠️  SCHEDULER_ENABLED=$SCHEDULER_STATUS (未啟用)${NC}"
fi
echo ""

# 4. 列出排程任務
echo -e "${YELLOW}4. 排程任務列表:${NC}"
docker compose exec app php artisan schedule:list
echo ""

# 5. 檢查排程進程
echo -e "${YELLOW}5. 排程進程:${NC}"
PROCESS_COUNT=$(docker compose exec app ps aux | grep -c "schedule:run" | grep -v grep)
if [ "$PROCESS_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✅ 找到 $PROCESS_COUNT 個排程進程${NC}"
    docker compose exec app ps aux | grep "schedule:run" | grep -v grep
else
    echo -e "${RED}⚠️  未找到排程進程${NC}"
fi
echo ""

# 6. 查看最近的排程日誌
echo -e "${YELLOW}6. 最近的排程執行記錄 (最後 10 行):${NC}"
if docker compose exec app test -f /var/log/supervisor/scheduler.log 2>/dev/null; then
    docker compose exec app tail -10 /var/log/supervisor/scheduler.log
    echo ""
    
    # 檢查是否有錯誤
    ERROR_COUNT=$(docker compose exec app grep -c -i "error\|exception\|failed" /var/log/supervisor/scheduler.log 2>/dev/null || echo "0")
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo -e "${RED}⚠️  發現 $ERROR_COUNT 個錯誤記錄${NC}"
    else
        echo -e "${GREEN}✅ 沒有發現錯誤${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  日誌文件尚未生成${NC}"
fi
echo ""

# 7. 統計資訊
echo -e "${YELLOW}7. 統計資訊:${NC}"
if docker compose exec app test -f /var/log/supervisor/scheduler.log 2>/dev/null; then
    TOTAL_RUNS=$(docker compose exec app grep -c "Running scheduler" /var/log/supervisor/scheduler.log 2>/dev/null || echo "0")
    echo "  總執行次數: $TOTAL_RUNS"
    
    LAST_RUN=$(docker compose exec app grep "Running scheduler" /var/log/supervisor/scheduler.log 2>/dev/null | tail -1)
    if [ -n "$LAST_RUN" ]; then
        echo "  最後執行: $LAST_RUN"
    fi
else
    echo "  日誌文件尚未生成"
fi
echo ""

echo "========================================="
echo -e "${BLUE}  快速操作指令${NC}"
echo "========================================="
echo ""
echo "  即時監控日誌:"
echo "    docker compose exec app tail -f /var/log/supervisor/scheduler.log"
echo ""
echo "  手動執行排程:"
echo "    docker compose exec app php artisan schedule:run --verbose"
echo ""
echo "  重啟排程服務:"
echo "    docker compose exec app supervisorctl restart laravel-scheduler:*"
echo ""
echo "  啟用排程 (如果未啟用):"
echo "    echo 'SCHEDULER_ENABLED=true' >> .env"
echo "    docker compose restart app"
echo ""

