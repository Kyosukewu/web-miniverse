#!/bin/bash

# 程式碼更新腳本
# 使用方法: ./update.sh
# 或: GITHUB_TOKEN=your_token ./update.sh

set -e  # 遇到錯誤立即停止

echo "🔄 開始更新 Web Miniverse..."

# 顏色輸出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROJECT_DIR="/var/www/web-miniverse"

# 檢查專案目錄是否存在
if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}❌ 錯誤: 專案目錄不存在: ${PROJECT_DIR}${NC}"
    echo -e "${YELLOW}請先執行部署腳本: ./deploy-ec2.sh${NC}"
    exit 1
fi

cd $PROJECT_DIR

# 檢查是否為 git repository
if [ ! -d ".git" ]; then
    echo -e "${RED}❌ 錯誤: 不是 git repository${NC}"
    exit 1
fi

# 檢查是否需要 token（如果 remote URL 包含 token）
CURRENT_REMOTE=$(git remote get-url origin)
if [[ "$CURRENT_REMOTE" == *"@"* ]] && [[ "$CURRENT_REMOTE" == *"https://"* ]]; then
    # Remote URL 已包含 token，直接使用
    echo -e "${GREEN}✓ 使用已設定的認證資訊${NC}"
elif [ -n "$GITHUB_TOKEN" ]; then
    # 更新 remote URL 以包含 token
    GITHUB_REPO=$(echo $CURRENT_REMOTE | sed "s|https://github.com|https://${GITHUB_TOKEN}@github.com|" | sed "s|https://.*@github.com|https://${GITHUB_TOKEN}@github.com|")
    git remote set-url origin "$GITHUB_REPO"
    echo -e "${GREEN}✓ 已更新 remote URL${NC}"
fi

# 1. 備份當前版本（可選）
echo -e "\n${GREEN}📦 備份當前版本...${NC}"
BACKUP_DIR="/var/backups/web-miniverse"
mkdir -p $BACKUP_DIR
BACKUP_FILE="$BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).tar.gz"
tar -czf $BACKUP_FILE --exclude='.git' --exclude='node_modules' --exclude='vendor' $PROJECT_DIR 2>/dev/null || true
echo -e "${GREEN}✓ 備份完成: ${BACKUP_FILE}${NC}"

# 2. 拉取最新程式碼
echo -e "\n${GREEN}📥 拉取最新程式碼...${NC}"
git fetch origin

# 檢查當前分支
CURRENT_BRANCH=$(git branch --show-current)
if [ -z "$CURRENT_BRANCH" ]; then
    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
fi

# 顯示變更
echo -e "${YELLOW}當前分支: ${CURRENT_BRANCH}${NC}"
echo -e "${YELLOW}變更內容:${NC}"
git log HEAD..origin/${CURRENT_BRANCH} --oneline 2>/dev/null || git log HEAD..origin/main --oneline 2>/dev/null || git log HEAD..origin/master --oneline 2>/dev/null || echo "無新變更"

# 確認是否繼續
read -p "是否繼續更新？(y/N): " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}已取消更新${NC}"
    exit 0
fi

# 執行更新
echo -e "\n${GREEN}🔄 執行更新...${NC}"
git reset --hard origin/${CURRENT_BRANCH} 2>/dev/null || \
git reset --hard origin/main 2>/dev/null || \
git reset --hard origin/master
git clean -fd

echo -e "${GREEN}✓ 程式碼更新完成${NC}"

# 3. 檢查 .env 檔案
echo -e "\n${GREEN}⚙️  檢查環境變數設定...${NC}"
if [ ! -f ".env" ]; then
    echo -e "${RED}⚠️  警告: .env 檔案不存在！${NC}"
    if [ -f ".env.example" ]; then
        read -p "是否從 .env.example 複製？(y/N): " COPY_ENV
        if [[ "$COPY_ENV" =~ ^[Yy]$ ]]; then
            cp .env.example .env
            echo -e "${YELLOW}請記得編輯 .env 檔案設定正確的環境變數！${NC}"
        fi
    fi
fi

# 4. 重新構建容器（如果有 Dockerfile 變更）
echo -e "\n${GREEN}🔨 檢查是否需要重新構建容器...${NC}"
if git diff HEAD@{1} HEAD --name-only | grep -qE "(Dockerfile|docker-compose.yml|docker/)" || [ "$1" == "--rebuild" ]; then
    echo -e "${YELLOW}偵測到 Docker 相關變更，重新構建容器...${NC}"
    docker-compose build --no-cache
    echo -e "${GREEN}✓ 構建完成${NC}"
else
    echo -e "${GREEN}✓ 無 Docker 相關變更，跳過構建${NC}"
fi

# 5. 重啟容器
echo -e "\n${GREEN}🔄 重啟容器...${NC}"
docker-compose down
docker-compose up -d
echo -e "${GREEN}✓ 容器重啟完成${NC}"

# 6. 等待容器啟動
echo -e "\n${GREEN}⏳ 等待容器啟動...${NC}"
sleep 10

# 7. 執行 Laravel 維護任務
echo -e "\n${GREEN}⚙️  執行 Laravel 維護任務...${NC}"
docker-compose exec -T app composer install --no-interaction --optimize-autoloader --no-dev || true
docker-compose exec -T app php artisan migrate --force || true
docker-compose exec -T app php artisan config:cache || true
docker-compose exec -T app php artisan route:cache || true
docker-compose exec -T app php artisan view:cache || true
echo -e "${GREEN}✓ 維護任務完成${NC}"

# 8. 檢查容器狀態
echo -e "\n${GREEN}📊 檢查容器狀態...${NC}"
docker-compose ps

# 9. 檢查排程任務
echo -e "\n${GREEN}📅 檢查排程任務狀態...${NC}"
docker-compose exec -T app supervisorctl status || true

echo -e "\n${GREEN}✅ 更新完成！${NC}"
echo -e "\n${YELLOW}📝 後續檢查：${NC}"
echo -e "1. 查看日誌: docker-compose logs -f"
echo -e "2. 檢查應用: http://$(curl -s ifconfig.me)"
echo -e "3. 如有問題可還原備份: ${BACKUP_FILE}"

