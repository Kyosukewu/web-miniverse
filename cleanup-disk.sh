#!/bin/bash

# 磁碟空間清理腳本
# 用於清理 EC2 實例上的 Docker 和應用程式暫存檔案

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "========================================="
echo -e "${BLUE}  磁碟空間清理工具${NC}"
echo "========================================="
echo ""

# 1. 檢查目前磁碟使用狀況
echo -e "${YELLOW}1. 檢查磁碟使用狀況...${NC}"
df -h
echo ""

# 2. 顯示 Docker 佔用的空間
echo -e "${YELLOW}2. Docker 資源使用狀況...${NC}"
docker system df
echo ""

# 3. 清理應用程式暫存檔案
echo -e "${YELLOW}3. 清理應用程式暫存檔案...${NC}"
PROJECT_DIR="/var/www/html/web-miniverse"

if [ -d "$PROJECT_DIR/storage/app/temp" ]; then
    TEMP_SIZE=$(du -sh "$PROJECT_DIR/storage/app/temp" 2>/dev/null | cut -f1 || echo "0")
    echo -e "   暫存目錄大小: ${TEMP_SIZE}"
    
    read -p "是否清理 storage/app/temp 目錄？(y/N): " CLEAN_TEMP
    if [[ "$CLEAN_TEMP" =~ ^[Yy]$ ]]; then
        find "$PROJECT_DIR/storage/app/temp" -type f -name "*.mp4" -delete 2>/dev/null || true
        find "$PROJECT_DIR/storage/app/temp" -type f -name "*.xml" -delete 2>/dev/null || true
        find "$PROJECT_DIR/storage/app/temp" -type f -delete 2>/dev/null || true
        echo -e "${GREEN}   ✅ 暫存檔案已清理${NC}"
    else
        echo -e "${YELLOW}   ⊘ 跳過暫存檔案清理${NC}"
    fi
else
    echo -e "   暫存目錄不存在，跳過"
fi
echo ""

# 4. 清理 Laravel 日誌
echo -e "${YELLOW}4. 清理 Laravel 日誌...${NC}"
if [ -d "$PROJECT_DIR/storage/logs" ]; then
    LOG_SIZE=$(du -sh "$PROJECT_DIR/storage/logs" 2>/dev/null | cut -f1 || echo "0")
    echo -e "   日誌目錄大小: ${LOG_SIZE}"
    
    read -p "是否清理舊的日誌檔案（保留最近 7 天）？(y/N): " CLEAN_LOGS
    if [[ "$CLEAN_LOGS" =~ ^[Yy]$ ]]; then
        find "$PROJECT_DIR/storage/logs" -type f -name "*.log" -mtime +7 -delete 2>/dev/null || true
        echo -e "${GREEN}   ✅ 舊日誌已清理${NC}"
    else
        echo -e "${YELLOW}   ⊘ 跳過日誌清理${NC}"
    fi
else
    echo -e "   日誌目錄不存在，跳過"
fi
echo ""

# 5. 清理 Docker 資源
echo -e "${YELLOW}5. 清理 Docker 資源...${NC}"
echo -e "   這將清理："
echo -e "   - 停止的容器"
echo -e "   - 未使用的網路"
echo -e "   - 懸空映像檔（dangling images）"
echo -e "   - 未使用的映像檔"
echo -e "   ${RED}注意：不會清理卷（volumes），資料庫資料安全${NC}"
echo ""

read -p "是否清理 Docker 資源？(y/N): " CLEAN_DOCKER
if [[ "$CLEAN_DOCKER" =~ ^[Yy]$ ]]; then
    echo -e "${BLUE}   清理停止的容器...${NC}"
    docker container prune -f
    
    echo -e "${BLUE}   清理未使用的網路...${NC}"
    docker network prune -f
    
    echo -e "${BLUE}   清理懸空映像檔...${NC}"
    docker image prune -f
    
    echo -e "${BLUE}   清理未使用的映像檔...${NC}"
    docker image prune -a -f --filter "until=24h"
    
    echo -e "${GREEN}   ✅ Docker 資源已清理${NC}"
else
    echo -e "${YELLOW}   ⊘ 跳過 Docker 清理${NC}"
fi
echo ""

# 6. 清理 APT 快取（Ubuntu/Debian）
if command -v apt-get &> /dev/null; then
    echo -e "${YELLOW}6. 清理 APT 快取...${NC}"
    read -p "是否清理 APT 套件快取？(y/N): " CLEAN_APT
    if [[ "$CLEAN_APT" =~ ^[Yy]$ ]]; then
        sudo apt-get clean
        sudo apt-get autoclean
        sudo apt-get autoremove -y
        echo -e "${GREEN}   ✅ APT 快取已清理${NC}"
    else
        echo -e "${YELLOW}   ⊘ 跳過 APT 清理${NC}"
    fi
    echo ""
fi

# 7. 清理 journalctl 日誌（systemd）
if command -v journalctl &> /dev/null; then
    echo -e "${YELLOW}7. 清理系統日誌...${NC}"
    JOURNAL_SIZE=$(sudo journalctl --disk-usage 2>/dev/null | grep -oP 'archived and active journals take up \K[^ ]+' || echo "unknown")
    echo -e "   系統日誌大小: ${JOURNAL_SIZE}"
    
    read -p "是否清理系統日誌（保留最近 7 天）？(y/N): " CLEAN_JOURNAL
    if [[ "$CLEAN_JOURNAL" =~ ^[Yy]$ ]]; then
        sudo journalctl --vacuum-time=7d
        echo -e "${GREEN}   ✅ 系統日誌已清理${NC}"
    else
        echo -e "${YELLOW}   ⊘ 跳過系統日誌清理${NC}"
    fi
    echo ""
fi

# 8. 最終檢查
echo "========================================="
echo -e "${BLUE}  清理後的磁碟使用狀況${NC}"
echo "========================================="
df -h
echo ""

echo -e "${YELLOW}Docker 資源使用狀況:${NC}"
docker system df
echo ""

echo "========================================="
echo -e "${GREEN}  清理完成！${NC}"
echo "========================================="
echo ""
echo -e "${YELLOW}💡 建議:${NC}"
echo "1. 定期清理暫存檔案和舊日誌"
echo "2. 監控磁碟使用狀況: df -h"
echo "3. 檢查最大的目錄: du -h /var/www/html/web-miniverse/storage | sort -h | tail -10"
echo "4. 如果空間仍不足，考慮擴展 EBS 卷"
echo ""

