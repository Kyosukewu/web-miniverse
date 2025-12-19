#!/bin/bash

# ============================================================================
# Docker 紧急清理脚本
# ============================================================================
# 用途：当遇到 "No space left on device" 错误时，执行彻底清理
# 使用方法: ./scripts/docker/emergency-cleanup.sh
# ============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${RED}=====================================${NC}"
echo -e "${RED}  Docker 紧急清理工具${NC}"
echo -e "${RED}=====================================${NC}"
echo ""
echo -e "${YELLOW}⚠️  警告：此脚本将清理所有未使用的 Docker 资源${NC}"
echo -e "${YELLOW}包括：构建缓存、未使用的镜像、容器、网络等${NC}"
echo ""

# 检查磁盘空间
echo -e "${BLUE}1. 检查当前磁盘空间...${NC}"
df -h / | head -2
echo ""

# 检查 Docker 空间使用
echo -e "${BLUE}2. 检查 Docker 空间使用...${NC}"
docker system df
echo ""

# 确认执行
read -p "确认执行彻底清理？(yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo -e "${YELLOW}已取消${NC}"
    exit 0
fi

echo ""
echo -e "${YELLOW}开始清理...${NC}"
echo ""

# 步骤 1: 停止所有容器（可选）
echo -e "${BLUE}步骤 1: 停止所有容器（可选）...${NC}"
read -p "是否停止所有容器？(y/N): " stop_containers
if [[ "$stop_containers" =~ ^[Yy]$ ]]; then
    docker compose down 2>/dev/null || true
    echo -e "${GREEN}✓ 容器已停止${NC}"
else
    echo -e "${YELLOW}⊘ 跳过停止容器${NC}"
fi
echo ""

# 步骤 2: 清理构建缓存（最重要）
echo -e "${BLUE}步骤 2: 清理构建缓存...${NC}"
BEFORE_CACHE=$(docker system df | grep "Build Cache" | awk '{print $4}' || echo "0")
docker builder prune -af
AFTER_CACHE=$(docker system df | grep "Build Cache" | awk '{print $4}' || echo "0")
echo -e "${GREEN}✓ 构建缓存已清理${NC}"
echo ""

# 步骤 3: 清理未使用的镜像
echo -e "${BLUE}步骤 3: 清理未使用的镜像...${NC}"
docker image prune -af
echo -e "${GREEN}✓ 未使用的镜像已清理${NC}"
echo ""

# 步骤 4: 清理未使用的容器和网络
echo -e "${BLUE}步骤 4: 清理未使用的容器和网络...${NC}"
docker system prune -af
echo -e "${GREEN}✓ 未使用的容器和网络已清理${NC}"
echo ""

# 步骤 5: 清理未使用的卷（可选，危险）
echo -e "${BLUE}步骤 5: 清理未使用的卷（可选，危险）...${NC}"
echo -e "${RED}⚠️  警告：这可能删除未使用的数据卷${NC}"
read -p "是否清理未使用的卷？(y/N): " clean_volumes
if [[ "$clean_volumes" =~ ^[Yy]$ ]]; then
    docker volume prune -af
    echo -e "${GREEN}✓ 未使用的卷已清理${NC}"
else
    echo -e "${YELLOW}⊘ 跳过清理卷${NC}"
fi
echo ""

# 显示清理后的空间使用
echo -e "${BLUE}清理后的磁盘空间：${NC}"
df -h / | head -2
echo ""

echo -e "${BLUE}清理后的 Docker 空间使用：${NC}"
docker system df
echo ""

echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}  紧急清理完成！${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo -e "${YELLOW}现在可以重新执行部署：${NC}"
echo -e "${BLUE}  ./scripts/deployment/update-and-deploy.sh${NC}"

