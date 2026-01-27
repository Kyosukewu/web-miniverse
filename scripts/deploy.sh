#!/bin/bash

# ============================================================================
# 統一部署腳本 - Miniverse 專案
# ============================================================================
# 用途: 提供統一的部署功能,支持多種部署模式
# 使用方法:
#   ./scripts/deploy.sh                     # 開發環境快速部署
#   ./scripts/deploy.sh --env=production    # 生產環境完整部署
#   ./scripts/deploy.sh --quick             # 快速部署 (不重建 Docker)
#   ./scripts/deploy.sh --rebuild           # 強制重建 Docker
#   ./scripts/deploy.sh --check             # 檢查狀態
#   ./scripts/deploy.sh --pull              # 先 git pull 再部署
# ============================================================================

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 預設值
ENVIRONMENT="development"
ACTION="deploy"
SKIP_BUILD=false
FORCE_REBUILD=false
GIT_PULL=false
AUTO_DETECT=true

# ============================================================================
# 工具函數
# ============================================================================

print_header() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}============================================${NC}"
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

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# 取得磁碟使用率
get_disk_usage() {
    df / | tail -1 | awk '{print $5}' | sed 's/%//' || echo "0"
}

# 檢查是否需要重建 Docker
should_rebuild_docker() {
    # 如果強制重建,返回 true
    if [ "$FORCE_REBUILD" = true ]; then
        return 0
    fi

    # 如果明確跳過構建,返回 false
    if [ "$SKIP_BUILD" = true ]; then
        return 1
    fi

    # 自動偵測: 檢查 Dockerfile 和 docker-compose.yml 是否有變更
    if [ "$AUTO_DETECT" = true ] && [ -d ".git" ]; then
        local changed_files=$(git diff --name-only HEAD origin/main 2>/dev/null || echo "")
        if echo "$changed_files" | grep -qE "(Dockerfile|docker-compose\.yml|composer\.json)"; then
            print_info "偵測到 Docker 相關檔案變更,將重建映像"
            return 0
        else
            print_info "未偵測到 Docker 相關檔案變更,跳過重建"
            return 1
        fi
    fi

    # 預設不重建
    return 1
}

# 智能清理空間
smart_cleanup() {
    local usage=$(get_disk_usage)

    if [ "$usage" -gt 85 ]; then
        print_warning "磁碟使用率 ${usage}%,執行自動清理..."

        if [ -f "./scripts/cleanup.sh" ]; then
            chmod +x ./scripts/cleanup.sh
            ./scripts/cleanup.sh auto
        else
            print_warning "找不到清理腳本,跳過"
        fi
    else
        print_info "磁碟使用率 ${usage}%,空間充足"
    fi
}

# ============================================================================
# Git 操作
# ============================================================================

git_pull_latest() {
    print_step "從遠端拉取最新程式碼"

    if [ ! -d ".git" ]; then
        print_warning "當前目錄不是 git 倉庫,跳過 git pull"
        return
    fi

    # 檢查是否有未提交的更改
    if ! git diff-index --quiet HEAD -- 2>/dev/null; then
        print_warning "偵測到本地有未提交的更改"
        echo ""
        git status --short
        echo ""
        read -p "是否要暫存這些更改並繼續? (y/N): " stash_changes
        if [[ "$stash_changes" =~ ^[Yy]$ ]]; then
            git stash push -m "Auto-stash before deploy at $(date)"
            print_success "本地更改已暫存"
        else
            print_error "請先處理本地更改"
            exit 1
        fi
    fi

    # 拉取最新程式碼
    local current_branch=$(git branch --show-current)
    if [ -z "$current_branch" ]; then
        current_branch="main"
    fi

    git fetch origin
    git reset --hard origin/${current_branch} || {
        print_error "無法重置到遠端版本"
        exit 1
    }

    print_success "程式碼已更新到最新版本 (${current_branch})"
}

# ============================================================================
# Docker 操作
# ============================================================================

docker_build() {
    print_step "構建 Docker 映像"

    # 智能清理空間
    smart_cleanup

    docker compose build --pull app
    print_success "Docker 映像構建完成"
}

docker_stop() {
    print_step "停止現有容器"
    docker compose down
    print_success "容器已停止"
}

docker_start() {
    print_step "啟動容器"
    docker compose up -d
    print_success "容器已啟動"

    print_step "等待容器完全啟動"
    sleep 10
}

docker_check_status() {
    print_step "檢查容器狀態"
    docker compose ps
    echo ""

    print_step "檢查 Supervisor 狀態"
    docker compose exec app supervisorctl status || print_warning "Supervisor 狀態異常"
    echo ""
}

# ============================================================================
# Laravel 操作
# ============================================================================

laravel_install_deps() {
    print_step "安裝/更新 Composer 套件"

    if [ "$ENVIRONMENT" = "production" ]; then
        docker compose exec app composer install --no-dev --optimize-autoloader --no-interaction
    else
        docker compose exec app composer install --optimize-autoloader
    fi

    print_success "Composer 套件已安裝"
}

laravel_migrate() {
    print_step "執行資料庫遷移"
    docker compose exec app php artisan migrate --force
    print_success "資料庫遷移完成"
}

laravel_clear_cache() {
    print_step "清除應用快取"
    docker compose exec app php artisan config:clear
    docker compose exec app php artisan route:clear
    docker compose exec app php artisan view:clear
    print_success "快取已清除"
}

laravel_optimize() {
    if [ "$ENVIRONMENT" = "production" ]; then
        print_step "優化應用效能 (生產環境)"
        docker compose exec app php artisan config:cache
        docker compose exec app php artisan route:cache
        docker compose exec app php artisan view:cache
        docker compose exec app composer dump-autoload --optimize --classmap-authoritative
        print_success "效能優化完成"
    else
        print_step "優化自動載入 (開發環境)"
        docker compose exec app composer dump-autoload --optimize
        print_success "自動載入已優化"
    fi
}

# ============================================================================
# 檢查模式
# ============================================================================

check_status() {
    print_header "系統狀態檢查"

    # 1. 容器狀態
    print_step "容器狀態"
    docker compose ps app
    echo ""

    # 2. Supervisor 狀態
    print_step "Supervisor 進程狀態"
    if docker compose exec app supervisorctl status 2>/dev/null; then
        print_success "Supervisor 運行正常"
    else
        print_error "Supervisor 未運行"
    fi
    echo ""

    # 3. 排程配置
    print_step "排程配置"
    local scheduler_status=$(docker compose exec app grep SCHEDULER_ENABLED /var/www/html/web-miniverse/.env 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n' || echo "")
    if [ "$scheduler_status" = "true" ]; then
        print_success "SCHEDULER_ENABLED=true (已啟用)"
    else
        print_warning "SCHEDULER_ENABLED=$scheduler_status (未啟用)"
    fi
    echo ""

    # 4. 排程任務列表
    print_step "排程任務列表"
    docker compose exec app php artisan schedule:list
    echo ""

    # 5. 最近的排程日誌
    print_step "最近的排程執行記錄 (最後 10 行)"
    if docker compose exec app test -f /var/log/supervisor/scheduler.log 2>/dev/null; then
        docker compose exec app tail -10 /var/log/supervisor/scheduler.log
    else
        print_warning "日誌文件尚未生成"
    fi
    echo ""

    # 6. 磁碟狀態
    print_step "磁碟使用狀況"
    df -h | grep -E '(Filesystem|/$)'
    echo ""

    print_header "提示"
    print_info "即時監控排程日誌: docker compose exec app tail -f /var/log/supervisor/scheduler.log"
    print_info "重啟排程服務: docker compose exec app supervisorctl restart laravel-scheduler:*"
    echo ""
}

# ============================================================================
# 部署模式
# ============================================================================

deploy_development() {
    print_header "開發環境部署"

    # Git pull (如果需要)
    if [ "$GIT_PULL" = true ]; then
        git_pull_latest
        echo ""
    fi

    # 停止容器
    docker_stop
    echo ""

    # 構建 (如果需要)
    if should_rebuild_docker; then
        docker_build
        echo ""
    else
        print_info "跳過 Docker 映像重建"
        echo ""
    fi

    # 啟動容器
    docker_start
    echo ""

    # 檢查狀態
    docker_check_status
    echo ""

    # 安裝依賴
    laravel_install_deps
    echo ""

    # 資料庫遷移
    laravel_migrate
    echo ""

    # 清除快取
    laravel_clear_cache
    echo ""

    # 優化
    laravel_optimize
    echo ""

    # 最終檢查
    print_header "部署完成"
    docker_check_status

    print_success "開發環境部署成功!"
    echo ""
    print_info "提示: 使用 ./scripts/deploy.sh --check 檢查狀態"
}

deploy_production() {
    print_header "生產環境部署"

    # 檢查必要的環境變數
    if [ -z "$GITHUB_TOKEN" ] && [ "$GIT_PULL" = true ]; then
        print_error "生產環境需要設定 GITHUB_TOKEN 環境變數"
        echo ""
        print_info "使用方法:"
        echo "  export GITHUB_TOKEN=your_token"
        echo "  ./scripts/deploy.sh --env=production"
        exit 1
    fi

    # Git pull (如果需要)
    if [ "$GIT_PULL" = true ]; then
        git_pull_latest
        echo ""
    fi

    # 智能清理空間
    smart_cleanup
    echo ""

    # 停止容器
    docker_stop
    echo ""

    # 構建 (如果需要)
    if should_rebuild_docker; then
        docker_build
        echo ""
    else
        print_info "跳過 Docker 映像重建"
        echo ""
    fi

    # 啟動容器
    docker_start
    echo ""

    # 等待容器完全啟動
    print_step "等待容器完全啟動"
    sleep 15
    echo ""

    # 安裝依賴 (生產環境)
    laravel_install_deps
    echo ""

    # 資料庫遷移
    laravel_migrate
    echo ""

    # 清除快取
    laravel_clear_cache
    echo ""

    # 優化 (生產環境)
    laravel_optimize
    echo ""

    # 最終檢查
    print_header "部署完成"
    docker_check_status

    print_success "生產環境部署成功!"
    echo ""
    print_info "服務狀態: docker compose ps"
    print_info "排程監控: docker compose exec app tail -f /var/log/supervisor/scheduler.log"
}

# ============================================================================
# 參數解析
# ============================================================================

show_usage() {
    echo "使用方法: $0 [選項]"
    echo ""
    echo "選項:"
    echo "  --env=production      生產環境部署"
    echo "  --env=development     開發環境部署 (預設)"
    echo "  --quick               快速部署 (跳過 Docker 重建)"
    echo "  --rebuild             強制重建 Docker 映像"
    echo "  --pull                先執行 git pull 再部署"
    echo "  --check               檢查系統狀態"
    echo "  --help                顯示此幫助訊息"
    echo ""
    echo "範例:"
    echo "  $0                            # 開發環境部署 (智能偵測是否重建)"
    echo "  $0 --quick                    # 快速部署 (不重建)"
    echo "  $0 --rebuild                  # 強制重建並部署"
    echo "  $0 --pull                     # git pull + 部署"
    echo "  $0 --env=production --pull    # 生產環境完整部署"
    echo "  $0 --check                    # 檢查狀態"
}

# 解析參數
for arg in "$@"; do
    case $arg in
        --env=*)
            ENVIRONMENT="${arg#*=}"
            ;;
        --check)
            ACTION="check"
            ;;
        --quick)
            SKIP_BUILD=true
            AUTO_DETECT=false
            ;;
        --rebuild)
            FORCE_REBUILD=true
            AUTO_DETECT=false
            ;;
        --pull)
            GIT_PULL=true
            ;;
        --help|-h|help)
            show_usage
            exit 0
            ;;
        *)
            print_error "未知參數: $arg"
            echo ""
            show_usage
            exit 1
            ;;
    esac
done

# ============================================================================
# 主程式
# ============================================================================

main() {
    # 檢查是否在專案目錄
    if [ ! -f "docker-compose.yml" ]; then
        print_error "請在專案根目錄執行此腳本"
        exit 1
    fi

    case $ACTION in
        check)
            check_status
            ;;
        deploy)
            if [ "$ENVIRONMENT" = "production" ]; then
                deploy_production
            else
                deploy_development
            fi
            ;;
        *)
            print_error "未知的操作: $ACTION"
            exit 1
            ;;
    esac
}

# 執行主函數
main
