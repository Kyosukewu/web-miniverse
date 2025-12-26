#!/bin/bash

# 自動更新並部署腳本
# 此腳本會先從 GitHub 拉取最新代碼（包括 deploy.sh），然後執行部署
# 使用方法:
#   ./update-and-deploy.sh                    # 開發環境
#   ./update-and-deploy.sh --env=production   # 生產環境
#   ./update-and-deploy.sh --check            # 檢查狀態
#   ./update-and-deploy.sh --skip-build       # 跳過構建

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "========================================="
echo -e "${BLUE}  自動更新並部署${NC}"
echo "========================================="
echo ""

# 檢查是否在 git 倉庫中
if [ ! -d ".git" ]; then
    echo -e "${RED}❌ 錯誤: 當前目錄不是 git 倉庫${NC}"
    echo "請確保在 /var/www/html/web-miniverse 目錄下執行"
    exit 1
fi

# 步驟 1: 檢查本地是否有未提交的更改
echo -e "${YELLOW}步驟 1: 檢查本地更改...${NC}"
if ! git diff-index --quiet HEAD -- 2>/dev/null; then
    echo -e "${YELLOW}⚠️  檢測到本地有未提交的更改：${NC}"
    git status --short
    echo ""
    read -p "是否要暫存這些更改並繼續？(y/N): " STASH_CHANGES
    if [[ "$STASH_CHANGES" =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}暫存本地更改...${NC}"
        git stash push -m "Auto-stash before update-and-deploy at $(date)"
        echo -e "${GREEN}✓ 本地更改已暫存${NC}"
    else
        echo -e "${RED}❌ 部署已取消${NC}"
        echo -e "${YELLOW}提示：可以執行以下命令手動處理：${NC}"
        echo "  git add . && git commit -m 'your message'  # 提交更改"
        echo "  git stash                                   # 暫存更改"
        echo "  git reset --hard                            # 丟棄更改（危險）"
        exit 1
    fi
fi
echo ""

# 步驟 2: 獲取當前分支
echo -e "${YELLOW}步驟 2: 檢查當前分支...${NC}"
CURRENT_BRANCH=$(git branch --show-current)
if [ -z "$CURRENT_BRANCH" ]; then
    CURRENT_BRANCH="main"
fi
echo -e "${GREEN}✓ 當前分支: ${CURRENT_BRANCH}${NC}"
echo ""

# 步驟 3: 從遠端拉取最新代碼
echo -e "${YELLOW}步驟 3: 從 GitHub 拉取最新代碼...${NC}"
echo "執行: git fetch origin"
git fetch origin

# 在 reset 之前，先處理可能被占用的文件（如 Docker 掛載的配置文件）
echo "檢查可能被占用的文件..."
if [ -f "docker/mysql/my.cnf" ]; then
    # 嘗試停止可能使用該文件的容器
    if command -v docker &> /dev/null; then
        echo "停止可能使用配置文件的容器..."
        docker compose stop db 2>/dev/null || true
        sleep 1
    fi
    
    # 如果文件仍然無法刪除，嘗試修改權限
    if [ -w "docker/mysql/my.cnf" ]; then
        chmod 644 "docker/mysql/my.cnf" 2>/dev/null || true
    fi
fi

echo "執行: git reset --hard origin/${CURRENT_BRANCH}"
# 使用更安全的方式重置：先清理，再重置
git clean -fd 2>/dev/null || true
git reset --hard origin/${CURRENT_BRANCH} || {
    echo -e "${YELLOW}⚠️  git reset 遇到問題，嘗試修復...${NC}"
    # 如果 reset 失敗，嘗試手動處理
    git checkout HEAD -- docker/mysql/my.cnf 2>/dev/null || true
    git reset --hard origin/${CURRENT_BRANCH} || {
        echo -e "${RED}❌ 無法重置到遠端版本${NC}"
        echo -e "${YELLOW}請手動執行以下命令修復：${NC}"
        echo "  docker compose stop db"
        echo "  rm -f docker/mysql/my.cnf"
        echo "  git reset --hard origin/${CURRENT_BRANCH}"
        exit 1
    }
}
echo -e "${GREEN}✓ 代碼已更新到最新版本${NC}"
echo ""

# 步驟 4: 檢查 deploy.sh 是否存在
echo -e "${YELLOW}步驟 4: 檢查部署腳本...${NC}"
if [ ! -f "./deploy.sh" ]; then
    echo -e "${RED}❌ 錯誤: deploy.sh 不存在${NC}"
    exit 1
fi

# 確保 deploy.sh 可執行
chmod +x ./deploy.sh
echo -e "${GREEN}✓ 部署腳本已準備就緒${NC}"
echo ""

# 步驟 5: 執行部署
echo -e "${YELLOW}步驟 5: 執行部署腳本...${NC}"
echo "========================================="
echo ""

# 將所有參數傳遞給 deploy.sh
exec ./deploy.sh "$@"

