#!/bin/bash

# Docker 磁碟空間清理腳本
# 使用方法: ./cleanup-docker.sh

set -e

echo "🧹 開始清理 Docker 資源..."

# 顏色輸出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. 檢查磁碟空間
echo -e "\n${YELLOW}=== 磁碟空間使用情況 ===${NC}"
df -h

# 2. 檢查 Docker 磁碟使用情況
echo -e "\n${YELLOW}=== Docker 磁碟使用情況 ===${NC}"
docker system df

# 3. 詢問是否繼續清理
echo -e "\n${YELLOW}⚠️  即將清理以下 Docker 資源：${NC}"
echo "  - 所有未使用的容器"
echo "  - 所有未使用的映像（包括懸空映像）"
echo "  - 所有未使用的網路"
echo "  - 所有未使用的卷"
echo ""
read -p "是否繼續清理？(y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}❌ 已取消清理${NC}"
    exit 1
fi

# 4. 清理未使用的 Docker 資源
echo -e "\n${GREEN}🧹 清理未使用的 Docker 資源...${NC}"
docker system prune -a --volumes -f

# 5. 清理舊的 Docker 映像（保留最近 3 個版本）
echo -e "\n${GREEN}🧹 清理舊的 Docker 映像...${NC}"
docker images --format "{{.Repository}}:{{.Tag}}" | grep "web-miniverse-app" | tail -n +4 | xargs -r docker rmi -f || true

# 6. 檢查清理後的空間
echo -e "\n${GREEN}✓ 清理完成${NC}"
echo -e "\n${YELLOW}=== 清理後的磁碟空間 ===${NC}"
df -h

echo -e "\n${YELLOW}=== 清理後的 Docker 磁碟使用情況 ===${NC}"
docker system df

echo -e "\n${GREEN}✅ 清理完成！現在可以重新執行更新腳本。${NC}"

