#!/bin/bash

# 緊急磁碟清理腳本
# 當磁碟空間不足時使用

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "========================================="
echo -e "${RED}  緊急磁碟清理工具${NC}"
echo "========================================="
echo ""

# 1. 檢查當前磁碟使用
echo -e "${YELLOW}1. 檢查當前磁碟使用狀況...${NC}"
df -h | grep -E '(Filesystem|/$)'
echo ""

USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$USAGE" -gt 90 ]; then
    echo -e "${RED}⚠️  警告：磁碟使用率 ${USAGE}%，嚴重不足！${NC}"
else
    echo -e "${GREEN}✓ 磁碟使用率 ${USAGE}%${NC}"
fi
echo ""

# 2. 清理 Docker 資源
echo -e "${YELLOW}2. 清理 Docker 未使用的資源...${NC}"
BEFORE_DOCKER=$(docker system df --format "{{.Size}}" | head -1)
echo "   清理前 Docker 使用: $BEFORE_DOCKER"

docker system prune -a -f --volumes 2>&1 | grep -E "(Total reclaimed space|deleted)" || true

AFTER_DOCKER=$(docker system df --format "{{.Size}}" | head -1)
echo -e "${GREEN}   ✓ Docker 清理完成${NC}"
echo ""

# 3. 清理應用臨時檔案
echo -e "${YELLOW}3. 清理 Laravel 臨時檔案...${NC}"
cd /var/www/html/web-miniverse

# 清理 storage/app/temp
if [ -d "storage/app/temp" ]; then
    TEMP_SIZE=$(du -sh storage/app/temp 2>/dev/null | cut -f1 || echo "0")
    echo "   臨時檔案大小: $TEMP_SIZE"
    find storage/app/temp -type f -delete 2>/dev/null || true
    echo -e "${GREEN}   ✓ 臨時檔案已清理${NC}"
fi

# 清理舊日誌（保留最近 3 天）
if [ -d "storage/logs" ]; then
    LOG_SIZE=$(du -sh storage/logs 2>/dev/null | cut -f1 || echo "0")
    echo "   日誌大小: $LOG_SIZE"
    find storage/logs -name "*.log" -type f -mtime +3 -delete 2>/dev/null || true
    echo -e "${GREEN}   ✓ 舊日誌已清理${NC}"
fi

# 清理框架快取
echo "   清理框架快取..."
find storage/framework/cache/data -type f -delete 2>/dev/null || true
find storage/framework/sessions -type f -delete 2>/dev/null || true
find storage/framework/views -type f -delete 2>/dev/null || true
echo -e "${GREEN}   ✓ 框架快取已清理${NC}"
echo ""

# 4. 清理 Nginx 快取
echo -e "${YELLOW}4. 清理 Nginx 快取...${NC}"
docker compose exec nginx rm -rf /var/cache/nginx/* 2>/dev/null || true
echo -e "${GREEN}   ✓ Nginx 快取已清理${NC}"
echo ""

# 5. 清理系統日誌
echo -e "${YELLOW}5. 清理系統日誌（保留最近 3 天）...${NC}"
sudo journalctl --vacuum-time=3d 2>&1 | grep -E "(Vacuuming done|Deleted)" || echo "   無法清理（可能需要 sudo 權限）"
echo ""

# 6. 清理 APT 快取
if command -v apt-get &> /dev/null; then
    echo -e "${YELLOW}6. 清理 APT 套件快取...${NC}"
    sudo apt-get clean 2>/dev/null || true
    sudo apt-get autoclean 2>/dev/null || true
    sudo apt-get autoremove -y 2>/dev/null || true
    echo -e "${GREEN}   ✓ APT 快取已清理${NC}"
    echo ""
fi

# 7. 查找大型檔案
echo -e "${YELLOW}7. 查找最大的 10 個目錄...${NC}"
echo "   （這可能需要一些時間）"
du -h /var/www/html/web-miniverse 2>/dev/null | sort -h | tail -10 || echo "   無法掃描"
echo ""

# 8. 最終檢查
echo "========================================="
echo -e "${BLUE}  清理後的磁碟使用狀況${NC}"
echo "========================================="
df -h | grep -E '(Filesystem|/$)'
echo ""

FINAL_USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
FREED=$((USAGE - FINAL_USAGE))

echo -e "${GREEN}✅ 清理完成！${NC}"
echo ""
echo "清理統計："
echo "  • 清理前使用率: ${USAGE}%"
echo "  • 清理後使用率: ${FINAL_USAGE}%"
echo "  • 釋放空間: ${FREED}%"
echo ""

if [ "$FINAL_USAGE" -gt 85 ]; then
    echo -e "${RED}⚠️  警告：磁碟使用率仍然很高（${FINAL_USAGE}%）${NC}"
    echo ""
    echo "建議："
    echo "1. 檢查是否有大型檔案可以刪除"
    echo "2. 考慮擴展 EBS 卷大小"
    echo "3. 清理 storage/app/cnn 中的本地檔案（如果已上傳到 GCS）"
    echo ""
    echo "執行以下命令查看最大的檔案："
    echo "  find /var/www/html/web-miniverse/storage -type f -size +100M -exec ls -lh {} \;"
else
    echo -e "${GREEN}✓ 磁碟空間充足！${NC}"
fi

echo ""
echo "========================================="

