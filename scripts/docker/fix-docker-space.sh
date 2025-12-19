#!/bin/bash

# ============================================================================
# Docker 磁盘空间清理脚本
# ============================================================================
# 用途：清理 Docker 占用的磁盘空间，解决 "No space left on device" 错误
# ============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}  Docker 磁盘空间清理工具${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""

# 检查 Docker 是否运行
if ! command -v docker &> /dev/null; then
    echo -e "${RED}错误: Docker 未安装或不在 PATH 中${NC}"
    exit 1
fi

# 显示当前磁盘使用情况
echo -e "${YELLOW}1. 检查当前磁盘使用情况...${NC}"
df -h | grep -E '^/dev/|Filesystem'
echo ""

# 显示 Docker 磁盘使用情况
echo -e "${YELLOW}2. 检查 Docker 磁盘使用情况...${NC}"
docker system df
echo ""

# 显示菜单
echo "请选择清理操作："
echo "1. 清理未使用的容器、网络、镜像（安全）"
echo "2. 清理构建缓存（推荐，解决构建问题）"
echo "3. 清理所有未使用的资源（包括悬空镜像）"
echo "4. 完全清理（危险：删除所有未使用的资源）"
echo "5. 查看占用空间最大的镜像和容器"
echo "6. 执行所有安全清理（1 + 2 + 3）"
echo "0. 退出"
echo ""
read -p "请输入选项 (0-6): " choice

case $choice in
    1)
        echo -e "\n${YELLOW}清理未使用的容器、网络、镜像...${NC}"
        docker system prune -f
        echo -e "${GREEN}✓ 清理完成${NC}"
        ;;
    2)
        echo -e "\n${YELLOW}清理构建缓存...${NC}"
        docker builder prune -af
        echo -e "${GREEN}✓ 构建缓存清理完成${NC}"
        ;;
    3)
        echo -e "\n${YELLOW}清理所有未使用的资源（包括悬空镜像）...${NC}"
        docker system prune -af
        echo -e "${GREEN}✓ 清理完成${NC}"
        ;;
    4)
        echo -e "\n${RED}警告：这将删除所有未使用的资源，包括未使用的镜像！${NC}"
        read -p "确认继续？(yes/no): " confirm
        if [ "$confirm" = "yes" ]; then
            docker system prune -af --volumes
            echo -e "${GREEN}✓ 完全清理完成${NC}"
        else
            echo -e "${YELLOW}已取消${NC}"
        fi
        ;;
    5)
        echo -e "\n${YELLOW}占用空间最大的镜像：${NC}"
        docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | head -10
        echo ""
        echo -e "${YELLOW}占用空间最大的容器：${NC}"
        docker ps -a --format "table {{.Names}}\t{{.Size}}" | head -10
        ;;
    6)
        echo -e "\n${YELLOW}执行所有安全清理...${NC}"
        echo -e "${BLUE}步骤 1: 清理未使用的容器、网络、镜像...${NC}"
        docker system prune -f
        echo -e "${BLUE}步骤 2: 清理构建缓存...${NC}"
        docker builder prune -af
        echo -e "${BLUE}步骤 3: 清理悬空镜像...${NC}"
        docker image prune -af
        echo -e "${GREEN}✓ 所有安全清理完成${NC}"
        ;;
    0)
        echo -e "${YELLOW}退出${NC}"
        exit 0
        ;;
    *)
        echo -e "${RED}无效的选项${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${YELLOW}清理后的磁盘使用情况：${NC}"
docker system df
echo ""

echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}  清理完成！${NC}"
echo -e "${GREEN}=====================================${NC}"

