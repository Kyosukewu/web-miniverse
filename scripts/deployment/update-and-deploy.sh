#!/bin/bash

# ============================================================================
# 更新並部署腳本 (簡化版)
# ============================================================================
# 此腳本會先從 GitHub 拉取最新代碼,然後執行部署
# 使用方法:
#   ./update-and-deploy.sh                    # 開發環境
#   ./update-and-deploy.sh --env=production   # 生產環境
#   ./update-and-deploy.sh --check            # 檢查狀態
# ============================================================================

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    echo ""
    echo -e "${BLUE}============================================${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}============================================${NC}"
    echo ""
}

print_step() {
    echo -e "${YELLOW}▶ $1${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_header "自動更新並部署"

# 檢查是否在 git 倉庫中
if [ ! -d ".git" ]; then
    print_error "當前目錄不是 git 倉庫"
    echo "請確保在專案根目錄下執行"
    exit 1
fi

# 檢查本地是否有未提交的更改
print_step "檢查本地更改"
if ! git diff-index --quiet HEAD -- 2>/dev/null; then
    print_warning "偵測到本地有未提交的更改"
    echo ""
    git status --short
    echo ""
    read -p "是否要暫存這些更改並繼續? (y/N): " stash_changes
    if [[ "$stash_changes" =~ ^[Yy]$ ]]; then
        git stash push -m "Auto-stash before update-and-deploy at $(date)"
        print_success "本地更改已暫存"
    else
        print_error "部署已取消"
        exit 1
    fi
fi

# 獲取當前分支
print_step "檢查當前分支"
CURRENT_BRANCH=$(git branch --show-current)
if [ -z "$CURRENT_BRANCH" ]; then
    CURRENT_BRANCH="main"
fi
print_success "當前分支: ${CURRENT_BRANCH}"

# 從遠端拉取最新代碼
print_step "從 GitHub 拉取最新代碼"
git fetch origin
git reset --hard origin/${CURRENT_BRANCH} || {
    print_error "無法重置到遠端版本"
    exit 1
}
print_success "程式碼已更新到最新版本"

# 檢查統一部署腳本是否存在
if [ ! -f "./scripts/deploy.sh" ]; then
    print_error "找不到統一部署腳本 scripts/deploy.sh"
    exit 1
fi

# 確保腳本可執行
chmod +x ./scripts/deploy.sh

# 執行部署 (添加 --pull 參數已經沒有必要,因為已經 pull 了)
print_header "執行部署"

# 將所有參數傳遞給統一部署腳本
exec ./scripts/deploy.sh "$@"
