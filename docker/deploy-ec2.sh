#!/bin/bash

# EC2 部署腳本（私有 Repository - 使用 Personal Access Token）
# 使用方法: 
#   GITHUB_TOKEN=your_token GITHUB_REPO=https://github.com/username/web-miniverse.git ./deploy-ec2.sh
#   或
#   export GITHUB_TOKEN=your_token
#   export GITHUB_REPO=https://github.com/username/web-miniverse.git
#   ./deploy-ec2.sh

set -e  # 遇到錯誤立即停止

echo "🚀 開始部署 Web Miniverse 到 EC2..."

# 顏色輸出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 檢查是否為 root 或使用 sudo
if [ "$EUID" -ne 0 ]; then 
    echo -e "${YELLOW}⚠️  建議使用 sudo 執行此腳本${NC}"
fi

# 檢查必要的環境變數
if [ -z "$GITHUB_TOKEN" ]; then
    echo -e "${RED}❌ 錯誤: 請設定 GITHUB_TOKEN 環境變數${NC}"
    echo -e "${YELLOW}使用方法:${NC}"
    echo -e "  export GITHUB_TOKEN=your_token"
    echo -e "  export GITHUB_REPO=https://github.com/username/web-miniverse.git"
    echo -e "  ./deploy-ec2.sh"
    exit 1
fi

if [ -z "$GITHUB_REPO" ]; then
    read -p "請輸入 GitHub repository URL (例如: https://github.com/username/web-miniverse.git): " GITHUB_REPO
    if [ -z "$GITHUB_REPO" ]; then
        echo -e "${RED}❌ 錯誤: 必須提供 GITHUB_REPO${NC}"
        exit 1
    fi
fi

# 將 token 嵌入 URL（如果還沒嵌入）
if [[ "$GITHUB_REPO" == *"https://github.com"* ]] && [[ "$GITHUB_REPO" != *"@"* ]]; then
    GITHUB_REPO=$(echo $GITHUB_REPO | sed "s|https://github.com|https://${GITHUB_TOKEN}@github.com|")
fi

# 1. 檢查 Docker 是否安裝
echo -e "\n${GREEN}📦 檢查 Docker 安裝...${NC}"
if ! command -v docker &> /dev/null; then
    echo -e "${YELLOW}Docker 未安裝，開始安裝...${NC}"
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
    systemctl start docker
    systemctl enable docker
    usermod -aG docker $USER
    echo -e "${GREEN}✓ Docker 安裝完成${NC}"
else
    echo -e "${GREEN}✓ Docker 已安裝${NC}"
fi

# 2. 檢查 Docker Compose 是否安裝
echo -e "\n${GREEN}📦 檢查 Docker Compose 安裝...${NC}"
if ! command -v docker-compose &> /dev/null; then
    echo -e "${YELLOW}Docker Compose 未安裝，開始安裝...${NC}"
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
    echo -e "${GREEN}✓ Docker Compose 安裝完成${NC}"
else
    echo -e "${GREEN}✓ Docker Compose 已安裝${NC}"
fi

# 3. 檢查 Git 是否安裝
echo -e "\n${GREEN}📦 檢查 Git 安裝...${NC}"
if ! command -v git &> /dev/null; then
    echo -e "${YELLOW}Git 未安裝，開始安裝...${NC}"
    if [ -f /etc/redhat-release ]; then
        yum install -y git
    else
        apt-get update && apt-get install -y git
    fi
    echo -e "${GREEN}✓ Git 安裝完成${NC}"
else
    echo -e "${GREEN}✓ Git 已安裝${NC}"
fi

# 4. 設定專案目錄
PROJECT_DIR="/var/www/web-miniverse"
echo -e "\n${GREEN}📁 設定專案目錄: ${PROJECT_DIR}${NC}"

if [ ! -d "$PROJECT_DIR" ]; then
    mkdir -p $PROJECT_DIR
    echo -e "${GREEN}✓ 專案目錄已建立${NC}"
else
    echo -e "${YELLOW}專案目錄已存在${NC}"
fi

# 5. 從 GitHub 拉取或更新程式碼
echo -e "\n${GREEN}📥 從 GitHub 拉取程式碼...${NC}"
cd $PROJECT_DIR

# 如果 .git 目錄存在，表示已經 clone 過，執行 pull
if [ -d ".git" ]; then
    echo -e "${YELLOW}專案已存在，更新 remote URL 並執行 git pull...${NC}"
    # 更新 remote URL 以包含 token
    git remote set-url origin "$GITHUB_REPO"
    git pull origin main || git pull origin master
else
    echo -e "${YELLOW}正在 clone 專案...${NC}"
    git clone $GITHUB_REPO .
fi

echo -e "${GREEN}✓ 程式碼更新完成${NC}"

# 6. 檢查 .env 檔案
echo -e "\n${GREEN}⚙️  檢查環境變數設定...${NC}"
ENV_CREATED=false
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo -e "${YELLOW}.env 檔案不存在，從 .env.example 複製...${NC}"
        cp .env.example .env
        ENV_CREATED=true
        echo -e "${GREEN}✓ .env 檔案已建立${NC}"
    else
        echo -e "${RED}❌ .env.example 檔案不存在！${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✓ .env 檔案已存在${NC}"
fi

# 6-1. 驗證關鍵環境變數（僅在初次部署時）
if [ "$ENV_CREATED" = true ]; then
    echo -e "\n${YELLOW}🔍 檢查關鍵環境變數...${NC}"
    
    # 讀取 .env 檔案中的變數值（忽略註解和空行）
    get_env_value() {
        grep -E "^${1}=" .env 2>/dev/null | cut -d '=' -f2- | sed 's/^["'\'']//; s/["'\'']$//' | xargs
    }
    
    # 定義必須設定的變數（排除預設值）
    MISSING_VARS=()
    WARNING_VARS=()
    
    # 檢查資料庫密碼（不應該是預設的 root）
    DB_PASSWORD_VAL=$(get_env_value "DB_PASSWORD")
    if [ -z "$DB_PASSWORD_VAL" ] || [ "$DB_PASSWORD_VAL" = "root" ] || [ "$DB_PASSWORD_VAL" = "password" ]; then
        MISSING_VARS+=("DB_PASSWORD (目前是預設值，請設定安全的密碼)")
    fi
    
    # 檢查必要的 API 金鑰
    GEMINI_KEY=$(get_env_value "GEMINI_API_KEY")
    if [ -z "$GEMINI_KEY" ]; then
        MISSING_VARS+=("GEMINI_API_KEY")
    fi
    
    AWS_KEY=$(get_env_value "AWS_ACCESS_KEY_ID")
    if [ -z "$AWS_KEY" ]; then
        MISSING_VARS+=("AWS_ACCESS_KEY_ID")
    fi
    
    AWS_SECRET=$(get_env_value "AWS_SECRET_ACCESS_KEY")
    if [ -z "$AWS_SECRET" ]; then
        MISSING_VARS+=("AWS_SECRET_ACCESS_KEY")
    fi
    
    CNN_BUCKET=$(get_env_value "CNN_S3_BUCKET")
    if [ -z "$CNN_BUCKET" ]; then
        MISSING_VARS+=("CNN_S3_BUCKET")
    fi
    
    # 如果有缺少的變數，提示用戶設定
    if [ ${#MISSING_VARS[@]} -gt 0 ]; then
        echo -e "${RED}⚠️  發現以下環境變數需要設定：${NC}"
        for var in "${MISSING_VARS[@]}"; do
            echo -e "  - ${var}"
        done
        
        echo -e "\n${YELLOW}請選擇：${NC}"
        echo -e "1. 現在編輯 .env 檔案（推薦）"
        echo -e "2. 稍後手動編輯（腳本會繼續，但容器可能無法正常啟動）"
        read -p "請選擇 (1 或 2，預設 1): " CHOICE
        CHOICE=${CHOICE:-1}
        
        if [ "$CHOICE" = "1" ]; then
            echo -e "\n${YELLOW}正在開啟編輯器...${NC}"
            echo -e "${GREEN}請設定以下變數：${NC}"
            for var in "${MISSING_VARS[@]}"; do
                echo -e "  - ${var}"
            done
            echo -e "\n${YELLOW}按 Enter 繼續開啟編輯器...${NC}"
            read
            
            # 嘗試使用可用的編輯器
            if command -v nano &> /dev/null; then
                nano .env
            elif command -v vi &> /dev/null; then
                vi .env
            elif command -v vim &> /dev/null; then
                vim .env
            else
                echo -e "${RED}未找到可用的編輯器，請手動編輯 .env 檔案${NC}"
                echo -e "${YELLOW}檔案位置: ${PROJECT_DIR}/.env${NC}"
                read -p "編輯完成後按 Enter 繼續..."
            fi
        else
            echo -e "${YELLOW}⚠️  您選擇稍後編輯，請記得在啟動容器前設定 .env 檔案！${NC}"
            echo -e "${YELLOW}檔案位置: ${PROJECT_DIR}/.env${NC}"
        fi
    else
        echo -e "${GREEN}✓ 關鍵環境變數已設定${NC}"
    fi
fi

# 7. 設定檔案權限
echo -e "\n${GREEN}🔐 設定檔案權限...${NC}"
chown -R $USER:$USER $PROJECT_DIR
chmod -R 755 $PROJECT_DIR
chmod -R 775 $PROJECT_DIR/storage
chmod -R 775 $PROJECT_DIR/bootstrap/cache
echo -e "${GREEN}✓ 權限設定完成${NC}"

# 8. 構建 Docker 映像檔
echo -e "\n${GREEN}🔨 構建 Docker 映像檔...${NC}"
docker-compose build --no-cache
echo -e "${GREEN}✓ 構建完成${NC}"

# 9. 最終檢查環境變數（在啟動容器前）
echo -e "\n${GREEN}🔍 最終檢查環境變數...${NC}"

# 讀取 .env 檔案中的變數值
get_env_value() {
    grep -E "^${1}=" .env 2>/dev/null | cut -d '=' -f2- | sed 's/^["'\'']//; s/["'\'']$//' | xargs
}

# 檢查資料庫連線設定
DB_PASSWORD_VAL=$(get_env_value "DB_PASSWORD")
if [ -z "$DB_PASSWORD_VAL" ] || [ "$DB_PASSWORD_VAL" = "root" ] || [ "$DB_PASSWORD_VAL" = "password" ]; then
    echo -e "${RED}⚠️  警告: DB_PASSWORD 仍是預設值，資料庫可能不安全${NC}"
    echo -e "${YELLOW}建議在啟動容器前修改 .env 檔案中的 DB_PASSWORD${NC}"
    read -p "是否繼續啟動容器？(y/N): " CONTINUE
    if [[ ! "$CONTINUE" =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}已取消啟動。請編輯 .env 檔案後重新執行部署。${NC}"
        echo -e "${YELLOW}編輯命令: nano ${PROJECT_DIR}/.env${NC}"
        exit 0
    fi
fi

# 檢查必要的 API 金鑰（警告但不阻止）
GEMINI_KEY=$(get_env_value "GEMINI_API_KEY")
if [ -z "$GEMINI_KEY" ]; then
    echo -e "${YELLOW}⚠️  警告: GEMINI_API_KEY 未設定，相關功能可能無法使用${NC}"
fi

AWS_KEY=$(get_env_value "AWS_ACCESS_KEY_ID")
if [ -z "$AWS_KEY" ]; then
    echo -e "${YELLOW}⚠️  警告: AWS_ACCESS_KEY_ID 未設定，S3 功能可能無法使用${NC}"
fi

# 9. 啟動容器
echo -e "\n${GREEN}🚀 啟動容器...${NC}"
docker-compose up -d
echo -e "${GREEN}✓ 容器啟動完成${NC}"

# 等待容器完全啟動
echo -e "\n${GREEN}⏳ 等待容器啟動...${NC}"
sleep 10

# 10. 執行 Laravel 初始化（如果需要）
echo -e "\n${GREEN}⚙️  初始化 Laravel...${NC}"
docker-compose exec -T app composer install --no-interaction --optimize-autoloader --no-dev || true
docker-compose exec -T app php artisan key:generate --force || true
docker-compose exec -T app php artisan migrate --force || true
docker-compose exec -T app php artisan storage:link || true
echo -e "${GREEN}✓ Laravel 初始化完成${NC}"

# 11. 檢查容器狀態
echo -e "\n${GREEN}📊 檢查容器狀態...${NC}"
docker-compose ps

echo -e "\n${GREEN}✅ 部署完成！${NC}"

# 檢查容器是否正常運行
echo -e "\n${GREEN}🔍 檢查容器狀態...${NC}"
if ! docker-compose ps | grep -q "Up"; then
    echo -e "${RED}⚠️  警告: 部分容器可能未正常啟動${NC}"
    echo -e "${YELLOW}請檢查日誌: docker-compose logs${NC}"
fi

echo -e "\n${YELLOW}📝 後續步驟：${NC}"
if [ "$ENV_CREATED" = true ]; then
    echo -e "${RED}⚠️  重要: 這是初次部署，請確認以下事項：${NC}"
    echo -e "1. 檢查 .env 檔案設定是否正確（特別是資料庫密碼和 API 金鑰）"
    echo -e "2. 如果環境變數不正確，請編輯 .env 後重啟容器："
    echo -e "   nano ${PROJECT_DIR}/.env"
    echo -e "   docker-compose restart"
fi
echo -e "3. 查看日誌: docker-compose logs -f"
echo -e "4. 檢查排程任務: docker-compose exec app supervisorctl status"
echo -e "5. 訪問應用: http://$(curl -s ifconfig.me)"
echo -e "\n${YELLOW}💡 更新程式碼請使用: ./docker/update.sh${NC}"
