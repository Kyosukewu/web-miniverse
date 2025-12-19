#!/bin/bash

# ============================================================================
# Docker 空间诊断脚本
# ============================================================================
# 检查是主机空间不足还是 Docker 空间不足
# 使用方法: ./scripts/docker/diagnose-space.sh
# ============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}  Docker 空间诊断工具${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""

# 检查 Docker 是否运行
if ! command -v docker &> /dev/null; then
    echo -e "${RED}错误: Docker 未安装或不在 PATH 中${NC}"
    exit 1
fi

# ============================================================================
# 1. 检查主机磁盘空间
# ============================================================================
echo -e "${YELLOW}1. 主机磁盘空间检查${NC}"
echo "----------------------------------------"
df -h
echo ""

# 检查根分区使用率
ROOT_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//' || echo "0")
ROOT_AVAIL=$(df -h / | awk 'NR==2 {print $4}' || echo "0")

if [ "$ROOT_USAGE" -gt 95 ]; then
    echo -e "${RED}⚠️  严重警告：主机根分区空间严重不足！${NC}"
    echo -e "${RED}   使用率: ${ROOT_USAGE}% | 可用空间: ${ROOT_AVAIL}${NC}"
    HOST_SPACE_ISSUE=true
elif [ "$ROOT_USAGE" -gt 90 ]; then
    echo -e "${RED}⚠️  警告：主机根分区空间不足${NC}"
    echo -e "${YELLOW}   使用率: ${ROOT_USAGE}% | 可用空间: ${ROOT_AVAIL}${NC}"
    HOST_SPACE_ISSUE=true
elif [ "$ROOT_USAGE" -gt 80 ]; then
    echo -e "${YELLOW}⚠️  注意：主机根分区空间紧张${NC}"
    echo -e "${YELLOW}   使用率: ${ROOT_USAGE}% | 可用空间: ${ROOT_AVAIL}${NC}"
    HOST_SPACE_ISSUE=false
else
    echo -e "${GREEN}✅ 主机根分区空间充足${NC}"
    echo -e "${GREEN}   使用率: ${ROOT_USAGE}% | 可用空间: ${ROOT_AVAIL}${NC}"
    HOST_SPACE_ISSUE=false
fi
echo ""

# ============================================================================
# 2. 检查 Docker 数据目录所在分区
# ============================================================================
echo -e "${YELLOW}2. Docker 数据目录空间检查${NC}"
echo "----------------------------------------"

DOCKER_ROOT=$(docker info 2>/dev/null | grep "Docker Root Dir" | awk '{print $4}' || echo "")
if [ -z "$DOCKER_ROOT" ]; then
    echo -e "${RED}无法获取 Docker Root Dir（Docker 可能未运行）${NC}"
    DOCKER_SPACE_ISSUE=true
else
    echo "Docker Root Dir: $DOCKER_ROOT"
    echo ""
    
    # 检查 Docker 数据目录所在分区的空间
    DOCKER_PARTITION=$(df -h "$DOCKER_ROOT" 2>/dev/null | tail -1)
    if [ -n "$DOCKER_PARTITION" ]; then
        DOCKER_USAGE=$(echo "$DOCKER_PARTITION" | awk '{print $5}' | sed 's/%//')
        DOCKER_AVAIL=$(echo "$DOCKER_PARTITION" | awk '{print $4}')
        DOCKER_MOUNT=$(echo "$DOCKER_PARTITION" | awk '{print $6}')
        
        echo "Docker 数据目录所在分区: $DOCKER_MOUNT"
        echo "使用率: ${DOCKER_USAGE}% | 可用空间: ${DOCKER_AVAIL}"
        echo ""
        
        if [ "$DOCKER_USAGE" -gt 95 ]; then
            echo -e "${RED}⚠️  严重警告：Docker 数据分区空间严重不足！${NC}"
            DOCKER_SPACE_ISSUE=true
        elif [ "$DOCKER_USAGE" -gt 90 ]; then
            echo -e "${RED}⚠️  警告：Docker 数据分区空间不足${NC}"
            DOCKER_SPACE_ISSUE=true
        elif [ "$DOCKER_USAGE" -gt 80 ]; then
            echo -e "${YELLOW}⚠️  注意：Docker 数据分区空间紧张${NC}"
            DOCKER_SPACE_ISSUE=false
        else
            echo -e "${GREEN}✅ Docker 数据分区空间充足${NC}"
            DOCKER_SPACE_ISSUE=false
        fi
    else
        echo -e "${YELLOW}无法确定 Docker 数据目录所在分区${NC}"
        DOCKER_SPACE_ISSUE=false
    fi
fi
echo ""

# ============================================================================
# 3. 检查 Docker 空间使用情况
# ============================================================================
echo -e "${YELLOW}3. Docker 资源使用情况${NC}"
echo "----------------------------------------"
docker system df 2>/dev/null || echo -e "${RED}Docker 未运行或无法访问${NC}"
echo ""

# 检查构建缓存大小
BUILD_CACHE=$(docker system df 2>/dev/null | grep "Build Cache" | awk '{print $3}' || echo "0")
if [ "$BUILD_CACHE" != "0" ] && [ -n "$BUILD_CACHE" ]; then
    echo -e "${YELLOW}💡 构建缓存占用: ${BUILD_CACHE}${NC}"
    echo -e "${BLUE}   可以执行 'docker builder prune -af' 清理${NC}"
fi
echo ""

# ============================================================================
# 4. 检查 inode 使用情况
# ============================================================================
echo -e "${YELLOW}4. Inode 使用情况检查${NC}"
echo "----------------------------------------"
echo "（inode 耗尽也会导致 'No space left on device' 错误）"
df -i
echo ""

INODE_USAGE=$(df -i / | awk 'NR==2 {print $5}' | sed 's/%//' || echo "0")
if [ "$INODE_USAGE" -gt 90 ]; then
    echo -e "${RED}⚠️  警告：inode 使用率过高 (${INODE_USAGE}%)${NC}"
    echo -e "${YELLOW}   建议：删除大量小文件${NC}"
    INODE_ISSUE=true
else
    echo -e "${GREEN}✅ Inode 使用率正常 (${INODE_USAGE}%)${NC}"
    INODE_ISSUE=false
fi
echo ""

# ============================================================================
# 5. 检查 Docker Desktop 磁盘镜像大小（如果适用）
# ============================================================================
echo -e "${YELLOW}5. Docker Desktop 配置检查${NC}"
echo "----------------------------------------"
if docker info 2>/dev/null | grep -q "Operating System.*Docker Desktop\|Docker Desktop"; then
    echo -e "${BLUE}检测到 Docker Desktop${NC}"
    echo -e "${YELLOW}请检查 Docker Desktop Settings → Resources → Advanced → Disk image size${NC}"
    echo -e "${YELLOW}当前限制可能不足，建议至少 64GB${NC}"
    echo ""
    echo -e "${BLUE}如何增加 Docker Desktop 磁盘空间：${NC}"
    echo "1. 打开 Docker Desktop"
    echo "2. 进入 Settings → Resources → Advanced"
    echo "3. 增加 'Disk image size'（例如：从 32GB 增加到 64GB 或 128GB）"
    echo "4. 点击 'Apply & Restart'"
    DOCKER_DESKTOP=true
else
    echo -e "${GREEN}未检测到 Docker Desktop（Linux 上的 Docker）${NC}"
    echo -e "${BLUE}Docker 使用主机文件系统，空间限制取决于主机磁盘${NC}"
    DOCKER_DESKTOP=false
fi
echo ""

# ============================================================================
# 诊断结果总结
# ============================================================================
echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}  诊断结果总结${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""

if [ "$HOST_SPACE_ISSUE" = true ]; then
    echo -e "${RED}❌ 问题：主机磁盘空间不足${NC}"
    echo -e "${YELLOW}解决方案：${NC}"
    echo "  1. 清理主机磁盘空间"
    echo "  2. 删除不需要的文件"
    echo "  3. 清理系统日志: sudo journalctl --vacuum-time=7d"
    echo "  4. 清理 apt 缓存: sudo apt-get clean"
    echo "  5. 联系系统管理员增加磁盘空间"
    echo ""
fi

if [ "$DOCKER_SPACE_ISSUE" = true ]; then
    echo -e "${RED}❌ 问题：Docker 数据分区空间不足${NC}"
    echo -e "${YELLOW}解决方案：${NC}"
    echo "  1. 清理 Docker 构建缓存: docker builder prune -af"
    echo "  2. 清理未使用的镜像: docker image prune -af"
    echo "  3. 清理未使用的容器: docker system prune -af"
    echo "  4. 执行紧急清理: ./scripts/docker/emergency-cleanup.sh"
    echo ""
    
    if [ "$DOCKER_DESKTOP" = true ]; then
        echo -e "${YELLOW}  5. 增加 Docker Desktop 磁盘镜像大小${NC}"
        echo "     Settings → Resources → Advanced → Disk image size"
        echo ""
    fi
fi

if [ "$INODE_ISSUE" = true ]; then
    echo -e "${RED}❌ 问题：inode 耗尽${NC}"
    echo -e "${YELLOW}解决方案：${NC}"
    echo "  1. 查找并删除大量小文件"
    echo "  2. 清理临时文件: find /tmp -type f -delete"
    echo "  3. 清理 Docker 日志: docker system prune -af"
    echo ""
fi

if [ "$HOST_SPACE_ISSUE" != true ] && [ "$DOCKER_SPACE_ISSUE" != true ] && [ "$INODE_ISSUE" != true ]; then
    echo -e "${GREEN}✅ 未发现明显的空间问题${NC}"
    echo -e "${YELLOW}如果仍然遇到 'No space left on device' 错误：${NC}"
    echo "  1. 可能是临时空间不足（构建过程中的临时文件）"
    echo "  2. 执行紧急清理: ./scripts/docker/emergency-cleanup.sh"
    echo "  3. 检查 /tmp 目录空间: df -h /tmp"
    echo ""
fi

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}  快速修复命令${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""
echo -e "${GREEN}如果确认是 Docker 空间问题：${NC}"
echo "  ./scripts/docker/emergency-cleanup.sh"
echo ""
echo -e "${GREEN}如果确认是主机空间问题：${NC}"
echo "  # 清理系统日志"
echo "  sudo journalctl --vacuum-time=7d"
echo ""
echo "  # 清理 apt 缓存"
echo "  sudo apt-get clean && sudo apt-get autoremove -y"
echo ""
echo -e "${GREEN}如果使用 Docker Desktop：${NC}"
echo "  增加磁盘镜像大小: Settings → Resources → Advanced → Disk image size"

