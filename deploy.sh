#!/bin/bash

# 統一部署腳本 - 支持生產環境和開發環境
# 使用方法:
#   ./deploy.sh --env=production  # 生產環境完整部署
#   ./deploy.sh --env=development # 開發環境快速重建
#   ./deploy.sh --check           # 檢查排程狀態
#   ./deploy.sh --rebuild         # 只重建容器
#   ./deploy.sh --skip-build      # 跳過映像重建（只重啟容器）

set -e  # 遇到錯誤立即停止

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 預設值
ENVIRONMENT="development"
ACTION="deploy"
SKIP_BUILD=false

# 解析參數
for arg in "$@"; do
    case $arg in
        --env=*)
            ENVIRONMENT="${arg#*=}"
            ;;
        --check)
            ACTION="check"
            ;;
        --rebuild)
            ACTION="rebuild"
            ;;
        --skip-build)
            SKIP_BUILD=true
            ;;
        --help)
            echo "用法: $0 [選項]"
            echo ""
            echo "選項:"
            echo "  --env=production     生產環境完整部署（需要 GITHUB_TOKEN）"
            echo "  --env=development    開發環境快速重建（預設）"
            echo "  --check              檢查排程狀態"
            echo "  --rebuild            只重建容器並測試"
            echo "  --skip-build         跳過 Docker 映像重建（適用於只更新代碼）"
            echo "  --help               顯示此幫助訊息"
            echo ""
            echo "範例:"
            echo "  GITHUB_TOKEN=xxx ./deploy.sh --env=production"
            echo "  ./deploy.sh --env=development"
            echo "  ./deploy.sh --skip-build  # 只更新代碼，不重建映像"
            echo "  ./deploy.sh --check"
            exit 0
            ;;
        *)
            echo -e "${RED}未知參數: $arg${NC}"
            echo "使用 --help 查看幫助"
            exit 1
            ;;
    esac
done

# ==================== 檢查排程狀態 ====================
if [ "$ACTION" = "check" ]; then
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
    SCHEDULER_STATUS=$(docker compose exec app grep SCHEDULER_ENABLED /var/www/html/web-miniverse/.env 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n' || echo "")
    if [ "$SCHEDULER_STATUS" = "true" ]; then
        echo -e "${GREEN}✅ SCHEDULER_ENABLED=true (已啟用)${NC}"
    else
        echo -e "${YELLOW}⚠️  SCHEDULER_ENABLED=$SCHEDULER_STATUS (未啟用)${NC}"
    fi
    echo ""

    # 4. 列出排程任務
    echo -e "${YELLOW}4. 排程任務列表:${NC}"
    docker compose exec app php artisan schedule:list
    echo ""

    # 5. 查看最近的排程日誌
    echo -e "${YELLOW}5. 最近的排程執行記錄 (最後 10 行):${NC}"
    if docker compose exec app test -f /var/log/supervisor/scheduler.log 2>/dev/null; then
        docker compose exec app tail -10 /var/log/supervisor/scheduler.log
    else
        echo -e "${YELLOW}⚠️  日誌文件尚未生成${NC}"
    fi
    echo ""

    echo "========================================="
    echo -e "${BLUE}提示：使用以下命令即時監控排程${NC}"
    echo "========================================="
    echo "docker compose exec app tail -f /var/log/supervisor/scheduler.log"
    
    exit 0
fi

# ==================== 快速重建（開發環境）====================
if [ "$ACTION" = "rebuild" ] || [ "$ENVIRONMENT" = "development" ]; then
    echo "========================================="
    echo -e "${BLUE}  開發環境 - 重建容器並測試排程${NC}"
    echo "========================================="
    echo ""

    # 步驟 1：停止容器
    echo -e "${YELLOW}步驟 1/14: 停止現有容器...${NC}"
    docker compose down
    echo -e "${GREEN}✅ 容器已停止${NC}"
    echo ""

    # 步驟 2：檢查並清理 Docker 空間（避免 "No space left on device" 錯誤）
    if [ "$SKIP_BUILD" = false ]; then
        echo -e "${YELLOW}步驟 2/15: 檢查磁碟空間...${NC}"
        DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
        echo -e "${BLUE}💡 當前磁碟使用率: ${DISK_USAGE}%${NC}"
        
        if [ "$DISK_USAGE" -gt 90 ]; then
            echo -e "${RED}⚠️  警告：磁碟使用率超過 90%，建議清理${NC}"
        fi
        echo ""
        
        echo -e "${YELLOW}步驟 2/15: 清理 Docker 構建緩存和未使用的資源...${NC}"
        echo -e "${BLUE}💡 這可以釋放 1-5GB 空間，避免構建失敗${NC}"
        
        # 清理構建緩存（最重要）
        echo -e "${BLUE}  → 清理構建緩存...${NC}"
        docker builder prune -af 2>/dev/null || true
        
        # 清理未使用的鏡像（不包括正在使用的）
        echo -e "${BLUE}  → 清理未使用的鏡像...${NC}"
        docker image prune -af 2>/dev/null || true
        
        # 清理未使用的容器和網絡
        echo -e "${BLUE}  → 清理未使用的容器和網絡...${NC}"
        docker system prune -f 2>/dev/null || true
        
        # 顯示清理後的空間使用情況
        echo ""
        echo -e "${YELLOW}清理後的 Docker 空間使用：${NC}"
        docker system df 2>/dev/null || true
        
        echo -e "${GREEN}✅ Docker 空間清理完成${NC}"
        echo ""
    fi

    # 步驟 3：重建容器
    if [ "$SKIP_BUILD" = false ]; then
        echo -e "${YELLOW}步驟 3/15: 重建容器（這可能需要幾分鐘）...${NC}"
        echo -e "${BLUE}💡 如果仍然遇到空間不足錯誤，請手動執行：${NC}"
        echo -e "${BLUE}   docker builder prune -af && docker system prune -af${NC}"
        docker compose build --pull app
        echo -e "${GREEN}✅ 容器重建完成${NC}"
        echo ""
    else
        echo -e "${YELLOW}步驟 3/15: 跳過容器重建（使用現有映像）${NC}"
        echo -e "${BLUE}💡 如需重建映像，請移除 --skip-build 參數${NC}"
        echo ""
    fi

    # 步驟 4：啟動容器
    echo -e "${YELLOW}步驟 4/15: 啟動容器...${NC}"
    docker compose up -d
    echo -e "${GREEN}✅ 容器已啟動${NC}"
    echo ""

    # 步驟 5：等待容器完全啟動
    echo -e "${YELLOW}步驟 5/15: 等待容器完全啟動...${NC}"
    sleep 10
    echo -e "${GREEN}✅ 容器啟動完成${NC}"
    echo ""

    # 步驟 6：檢查容器狀態
    echo -e "${YELLOW}步驟 6/15: 檢查容器狀態...${NC}"
    docker compose ps
    echo ""

    # 步驟 7：檢查 Supervisor 狀態
    echo -e "${YELLOW}步驟 7/15: 檢查 Supervisor 狀態...${NC}"
    docker compose exec app supervisorctl status
    echo ""

    # 步驟 8：檢查 SCHEDULER_ENABLED
    echo -e "${YELLOW}步驟 8/15: 檢查排程配置...${NC}"
    docker compose exec app grep SCHEDULER_ENABLED /var/www/html/web-miniverse/.env 2>/dev/null || echo "⚠️  SCHEDULER_ENABLED 未設置"
    echo ""

    # 步驟 9：安裝/更新 Composer 套件
    echo -e "${YELLOW}步驟 9/15: 安裝/更新 Composer 套件...${NC}"
    docker compose exec app composer install --optimize-autoloader
    echo -e "${GREEN}✅ Composer 套件已更新${NC}"
    echo ""

    # 步驟 10：執行資料庫遷移
    echo -e "${YELLOW}步驟 10/15: 執行資料庫遷移...${NC}"
    docker compose exec app php artisan migrate --force
    echo -e "${GREEN}✅ 資料庫遷移完成${NC}"
    echo ""

    # 步驟 11：清除快取
    echo -e "${YELLOW}步驟 11/15: 清除應用快取...${NC}"
    docker compose exec app php artisan config:clear
    docker compose exec app php artisan route:clear
    docker compose exec app php artisan view:clear
    echo -e "${GREEN}✅ 快取已清除${NC}"
    echo ""

    # 步驟 12：優化自動載入
    echo -e "${YELLOW}步驟 12/15: 優化自動載入...${NC}"
    docker compose exec app composer dump-autoload --optimize
    echo -e "${GREEN}✅ 自動載入已優化${NC}"
    echo ""

    # 步驟 13：列出排程任務
    echo -e "${YELLOW}步驟 13/15: 列出所有排程任務...${NC}"
    docker compose exec app php artisan schedule:list
    echo ""

    # 步驟 14：手動執行一次排程測試
    echo -e "${YELLOW}步驟 14/15: 手動執行排程測試...${NC}"
    docker compose exec app php artisan schedule:run --verbose
    echo ""

    # 步驟 15：查看排程日誌
    echo -e "${YELLOW}步驟 14/14: 查看排程日誌（最近 20 行）...${NC}"
    docker compose exec app tail -20 /var/log/supervisor/scheduler.log 2>/dev/null || echo "⚠️  日誌文件尚未生成"
    echo ""

    echo "========================================="
    echo -e "${GREEN}  重建和測試完成！${NC}"
    echo "========================================="
    echo ""
    echo "後續操作："
    echo "  1. 即時監控排程日誌："
    echo "     docker compose exec app tail -f /var/log/supervisor/scheduler.log"
    echo ""
    echo "  2. 檢查 Supervisor 狀態："
    echo "     docker compose exec app supervisorctl status"
    echo ""
    echo "  3. 重啟排程服務："
    echo "     docker compose exec app supervisorctl restart laravel-scheduler:*"
    echo ""
    echo "  4. 查看容器日誌："
    echo "     docker compose logs -f app"
    echo ""
    
    exit 0
fi

# ==================== 生產環境完整部署 ====================
if [ "$ENVIRONMENT" = "production" ]; then
    echo "========================================="
    echo -e "${BLUE}  生產環境 - 完整部署${NC}"
    echo "========================================="
    echo ""

    # 檢查必要的環境變數
    if [ -z "$GITHUB_TOKEN" ]; then
        echo -e "${RED}❌ 錯誤: 請設定 GITHUB_TOKEN 環境變數${NC}"
        echo -e "${YELLOW}使用方法:${NC}"
        echo -e "  export GITHUB_TOKEN=your_token"
        echo -e "  export GITHUB_REPO=https://github.com/username/web-miniverse.git"
        echo -e "  ./deploy.sh --env=production"
        exit 1
    fi

    if [ -z "$GITHUB_REPO" ]; then
        read -p "請輸入 GitHub repository URL: " GITHUB_REPO
        if [ -z "$GITHUB_REPO" ]; then
            echo -e "${RED}❌ 錯誤: 必須提供 GITHUB_REPO${NC}"
            exit 1
        fi
    fi

    # 將 token 嵌入 URL
    if [[ "$GITHUB_REPO" == *"https://github.com"* ]] && [[ "$GITHUB_REPO" != *"@"* ]]; then
        GITHUB_REPO=$(echo $GITHUB_REPO | sed "s|https://github.com|https://${GITHUB_TOKEN}@github.com|")
    fi

    # 設定專案目錄
    PROJECT_DIR="/var/www/html/web-miniverse"
    
    echo -e "${GREEN}📁 專案目錄: ${PROJECT_DIR}${NC}"
    
    # 從 GitHub 拉取或更新程式碼
    echo -e "\n${GREEN}📥 從 GitHub 更新程式碼...${NC}"
    cd $PROJECT_DIR

    if [ -d ".git" ]; then
        echo -e "${YELLOW}更新現有代碼...${NC}"
        git config --global --add safe.directory $PROJECT_DIR 2>/dev/null || true
        git remote set-url origin "$GITHUB_REPO"
        git fetch origin
        git reset --hard origin/main || git reset --hard origin/master
    else
        echo -e "${YELLOW}克隆新代碼...${NC}"
        git clone $GITHUB_REPO .
        git config --global --add safe.directory $PROJECT_DIR 2>/dev/null || true
    fi
    
    echo -e "${GREEN}✓ 程式碼更新完成${NC}"

    # 檢查 .env 檔案
    echo -e "\n${GREEN}⚙️  檢查環境變數設定...${NC}"
    if [ ! -f ".env" ]; then
        if [ -f ".env.example" ]; then
            cp .env.example .env
            echo -e "${YELLOW}⚠️  .env 檔案已從 .env.example 建立，請檢查並更新設定${NC}"
        fi
    fi

    # 確保 SCHEDULER_ENABLED 設置
    if ! grep -q "^SCHEDULER_ENABLED=" .env 2>/dev/null; then
        echo "SCHEDULER_ENABLED=true" >> .env
        echo -e "${GREEN}✓ 已添加 SCHEDULER_ENABLED=true${NC}"
    fi

    # 設定檔案權限
    echo -e "\n${GREEN}🔐 設定檔案權限...${NC}"
    chown -R www-data:www-data $PROJECT_DIR
    chmod -R 755 $PROJECT_DIR
    chmod -R 775 $PROJECT_DIR/storage
    chmod -R 775 $PROJECT_DIR/bootstrap/cache
    echo -e "${GREEN}✓ 權限設定完成${NC}"

    # 清理 Docker 資源
    if [ "$SKIP_BUILD" = false ]; then
        echo -e "\n${GREEN}🧹 檢查磁碟空間...${NC}"
        DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//' || echo "0")
        echo -e "${BLUE}💡 當前磁碟使用率: ${DISK_USAGE}%${NC}"
        
        if [ "$DISK_USAGE" -gt 90 ]; then
            echo -e "${RED}⚠️  警告：磁碟使用率超過 90%，建議清理${NC}"
        fi
        echo ""
        
        echo -e "\n${GREEN}🧹 清理 Docker 構建緩存和未使用的資源（避免空間不足錯誤）...${NC}"
        echo -e "${BLUE}💡 這可以釋放 1-5GB 空間${NC}"
        
        # 清理構建緩存（最重要）
        echo -e "${BLUE}  → 清理構建緩存...${NC}"
        docker builder prune -af 2>/dev/null || true
        
        # 清理未使用的鏡像
        echo -e "${BLUE}  → 清理未使用的鏡像...${NC}"
        docker image prune -af 2>/dev/null || true
        
        # 清理未使用的容器和網絡
        echo -e "${BLUE}  → 清理未使用的容器和網絡...${NC}"
        docker system prune -f 2>/dev/null || true
        
        # 顯示清理後的空間使用情況
        echo ""
        echo -e "${YELLOW}清理後的 Docker 空間使用：${NC}"
        docker system df 2>/dev/null || true
        
        echo -e "${GREEN}✓ Docker 空間清理完成${NC}"
        echo ""

        # 構建 Docker 映像檔
        echo -e "\n${GREEN}🔨 構建 Docker 映像檔...${NC}"
        echo -e "${BLUE}💡 如果仍然遇到空間不足錯誤，請手動執行：${NC}"
        echo -e "${BLUE}   docker builder prune -af && docker system prune -af${NC}"
        docker compose build --pull
        echo -e "${GREEN}✓ 構建完成${NC}"
    else
        echo -e "\n${YELLOW}⊘ 跳過 Docker 資源清理和映像重建${NC}"
        echo -e "${BLUE}💡 如需完整重建，請移除 --skip-build 參數${NC}"
    fi

    # 停止舊容器
    echo -e "\n${GREEN}🛑 停止舊容器...${NC}"
    docker compose down
    echo -e "${GREEN}✓ 舊容器已停止${NC}"

    # 啟動新容器
    echo -e "\n${GREEN}🚀 啟動新容器...${NC}"
    docker compose up -d
    echo -e "${GREEN}✓ 容器已啟動${NC}"

    # 等待容器啟動
    echo -e "\n${GREEN}⏳ 等待容器完全啟動...${NC}"
    sleep 15

    # 安裝/更新 Composer 套件（生產環境）
    echo -e "\n${GREEN}📦 安裝/更新 Composer 套件（生產環境）...${NC}"
    docker compose exec app composer install --no-dev --optimize-autoloader --no-interaction
    echo -e "${GREEN}✓ Composer 套件已安裝${NC}"

    # 執行數據庫遷移
    echo -e "\n${GREEN}🗄️  執行數據庫遷移...${NC}"
    docker compose exec app php artisan migrate --force
    echo -e "${GREEN}✓ 數據庫遷移完成${NC}"

    # 清除快取
    echo -e "\n${GREEN}🧹 清除應用快取...${NC}"
    docker compose exec app php artisan config:clear
    docker compose exec app php artisan route:clear
    docker compose exec app php artisan view:clear
    echo -e "${GREEN}✓ 快取已清除${NC}"

    # 優化應用效能（生產環境）
    echo -e "\n${GREEN}⚡ 優化應用效能...${NC}"
    docker compose exec app php artisan config:cache
    docker compose exec app php artisan route:cache
    docker compose exec app php artisan view:cache
    docker compose exec app composer dump-autoload --optimize --classmap-authoritative
    echo -e "${GREEN}✓ 效能優化完成${NC}"

    # 檢查服務狀態
    echo -e "\n${GREEN}✅ 檢查服務狀態...${NC}"
    docker compose ps
    echo ""
    
    echo -e "\n${GREEN}🔍 檢查 Supervisor 狀態...${NC}"
    docker compose exec app supervisorctl status
    echo ""

    echo -e "\n${GREEN}📅 檢查排程任務...${NC}"
    docker compose exec app php artisan schedule:list
    echo ""

    echo "========================================="
    echo -e "${GREEN}  生產環境部署完成！${NC}"
    echo "========================================="
    echo ""
    echo "服務狀態:"
    echo "  • 應用: http://your-domain"
    echo "  • 排程監控: docker compose exec app tail -f /var/log/supervisor/scheduler.log"
    echo ""
    
    exit 0
fi

