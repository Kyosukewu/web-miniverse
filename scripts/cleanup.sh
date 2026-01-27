#!/bin/bash

# ============================================================================
# 統一清理腳本 - Miniverse 專案
# ============================================================================
# 用途: 提供統一的清理功能,支持多種清理模式
# 使用方法:
#   ./scripts/cleanup.sh quick          # 快速清理 (Docker 基本資源)
#   ./scripts/cleanup.sh full           # 完整清理 (Docker + 應用 + 系統)
#   ./scripts/cleanup.sh emergency      # 緊急清理 (強制清理所有資源)
#   ./scripts/cleanup.sh interactive    # 互動式選擇清理項目
#   ./scripts/cleanup.sh auto           # 自動模式 (根據磁碟使用率決定)
# ============================================================================

set -e

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 預設模式
MODE="${1:-interactive}"

# ============================================================================
# 工具函數
# ============================================================================

# 顯示標題
print_header() {
    echo ""
    echo -e "${CYAN}============================================${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}============================================${NC}"
    echo ""
}

# 顯示步驟
print_step() {
    echo -e "${YELLOW}▶ $1${NC}"
}

# 顯示成功訊息
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# 顯示警告訊息
print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# 顯示錯誤訊息
print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# 顯示資訊
print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# 取得磁碟使用率
get_disk_usage() {
    df / | tail -1 | awk '{print $5}' | sed 's/%//' || echo "0"
}

# 顯示磁碟狀態
show_disk_status() {
    print_step "磁碟使用狀況"
    df -h | grep -E '(Filesystem|/$)'
    echo ""

    local usage=$(get_disk_usage)
    if [ "$usage" -gt 90 ]; then
        print_error "磁碟使用率 ${usage}% - 嚴重不足!"
    elif [ "$usage" -gt 80 ]; then
        print_warning "磁碟使用率 ${usage}% - 建議清理"
    else
        print_success "磁碟使用率 ${usage}% - 正常"
    fi
    echo ""
}

# 顯示 Docker 狀態
show_docker_status() {
    if command -v docker &> /dev/null; then
        print_step "Docker 空間使用狀況"
        docker system df 2>/dev/null || print_warning "無法取得 Docker 狀態"
        echo ""
    fi
}

# ============================================================================
# 清理函數
# ============================================================================

# 清理 Docker 構建緩存
clean_docker_build_cache() {
    print_step "清理 Docker 構建緩存"
    if command -v docker &> /dev/null; then
        docker builder prune -af 2>/dev/null || true
        print_success "Docker 構建緩存已清理"
    else
        print_warning "Docker 未安裝或無法訪問"
    fi
}

# 清理 Docker 未使用的映像
clean_docker_images() {
    print_step "清理 Docker 未使用的映像"
    if command -v docker &> /dev/null; then
        docker image prune -af 2>/dev/null || true
        print_success "Docker 映像已清理"
    else
        print_warning "Docker 未安裝或無法訪問"
    fi
}

# 清理 Docker 系統資源
clean_docker_system() {
    print_step "清理 Docker 系統資源 (容器、網絡等)"
    if command -v docker &> /dev/null; then
        docker system prune -af 2>/dev/null || true
        print_success "Docker 系統資源已清理"
    else
        print_warning "Docker 未安裝或無法訪問"
    fi
}

# 清理 Docker 卷 (危險操作)
clean_docker_volumes() {
    print_warning "這將刪除所有未使用的 Docker 卷,可能導致資料遺失!"
    read -p "確認執行? (yes/no): " confirm
    if [ "$confirm" = "yes" ]; then
        print_step "清理 Docker 卷"
        if command -v docker &> /dev/null; then
            docker volume prune -af 2>/dev/null || true
            print_success "Docker 卷已清理"
        else
            print_warning "Docker 未安裝或無法訪問"
        fi
    else
        print_info "已跳過 Docker 卷清理"
    fi
}

# 清理應用臨時檔案
clean_app_temp_files() {
    print_step "清理應用臨時檔案"

    local project_dir="/var/www/html/web-miniverse"

    # 如果在專案目錄外執行,嘗試使用當前目錄
    if [ ! -d "$project_dir" ]; then
        if [ -f "composer.json" ]; then
            project_dir="."
        else
            print_warning "找不到專案目錄,跳過應用清理"
            return
        fi
    fi

    cd "$project_dir" || return

    # 清理 storage/app/temp
    if [ -d "storage/app/temp" ]; then
        local temp_count=$(find storage/app/temp -type f 2>/dev/null | wc -l || echo "0")
        if [ "$temp_count" -gt 0 ]; then
            find storage/app/temp -type f -delete 2>/dev/null || true
            print_success "已清理 ${temp_count} 個臨時檔案"
        else
            print_info "沒有臨時檔案需要清理"
        fi
    fi

    # 清理框架快取
    find storage/framework/cache/data -type f -delete 2>/dev/null || true
    find storage/framework/sessions -type f -mtime +7 -delete 2>/dev/null || true
    find storage/framework/views -type f -delete 2>/dev/null || true
    print_success "框架快取已清理"
}

# 清理舊日誌
clean_old_logs() {
    print_step "清理舊日誌 (保留最近 7 天)"

    local project_dir="/var/www/html/web-miniverse"
    if [ ! -d "$project_dir" ]; then
        if [ -f "composer.json" ]; then
            project_dir="."
        else
            print_warning "找不到專案目錄,跳過日誌清理"
            return
        fi
    fi

    cd "$project_dir" || return

    if [ -d "storage/logs" ]; then
        local log_count=$(find storage/logs -name "*.log" -type f -mtime +7 2>/dev/null | wc -l || echo "0")
        if [ "$log_count" -gt 0 ]; then
            find storage/logs -name "*.log" -type f -mtime +7 -delete 2>/dev/null || true
            print_success "已清理 ${log_count} 個舊日誌檔案"
        else
            print_info "沒有舊日誌需要清理"
        fi
    fi
}

# 清理系統日誌 (需要 sudo)
clean_system_logs() {
    print_step "清理系統日誌 (需要 sudo 權限)"
    if command -v journalctl &> /dev/null; then
        sudo journalctl --vacuum-time=7d 2>&1 | grep -E "(Vacuuming done|Deleted)" || print_warning "無法清理系統日誌 (可能需要 sudo 權限)"
    else
        print_info "系統不支援 journalctl"
    fi
}

# 清理 APT 快取 (Ubuntu/Debian)
clean_apt_cache() {
    if command -v apt-get &> /dev/null; then
        print_step "清理 APT 套件快取"
        sudo apt-get clean 2>/dev/null || true
        sudo apt-get autoclean 2>/dev/null || true
        sudo apt-get autoremove -y 2>/dev/null || true
        print_success "APT 快取已清理"
    fi
}

# ============================================================================
# 清理模式
# ============================================================================

# 快速清理模式
quick_clean() {
    print_header "快速清理模式"

    show_disk_status
    show_docker_status

    print_info "執行基本 Docker 清理..."
    echo ""

    clean_docker_build_cache
    clean_docker_system
    clean_app_temp_files

    echo ""
    print_success "快速清理完成!"
    echo ""

    show_disk_status
}

# 完整清理模式
full_clean() {
    print_header "完整清理模式"

    show_disk_status
    show_docker_status

    print_info "執行完整清理 (Docker + 應用 + 系統)..."
    echo ""

    clean_docker_build_cache
    clean_docker_images
    clean_docker_system
    clean_app_temp_files
    clean_old_logs
    clean_system_logs
    clean_apt_cache

    echo ""
    print_success "完整清理完成!"
    echo ""

    show_disk_status
    show_docker_status
}

# 緊急清理模式
emergency_clean() {
    print_header "緊急清理模式"

    print_error "警告: 這將執行最激進的清理,包括刪除所有未使用的 Docker 資源!"
    echo ""
    show_disk_status
    show_docker_status

    read -p "確認執行緊急清理? (yes/no): " confirm
    if [ "$confirm" != "yes" ]; then
        print_warning "已取消緊急清理"
        exit 0
    fi

    echo ""
    print_info "執行緊急清理..."
    echo ""

    # 停止容器 (可選)
    print_warning "建議停止容器以釋放更多空間"
    read -p "是否停止所有容器? (y/N): " stop_containers
    if [[ "$stop_containers" =~ ^[Yy]$ ]]; then
        docker compose down 2>/dev/null || true
        print_success "容器已停止"
    fi

    clean_docker_build_cache
    clean_docker_images
    clean_docker_system
    clean_docker_volumes
    clean_app_temp_files
    clean_old_logs
    clean_system_logs
    clean_apt_cache

    echo ""
    print_success "緊急清理完成!"
    echo ""

    show_disk_status
    show_docker_status

    echo ""
    print_info "建議重啟容器: docker compose up -d"
}

# 互動式清理模式
interactive_clean() {
    print_header "互動式清理工具"

    show_disk_status
    show_docker_status

    echo "請選擇清理項目:"
    echo "  1. Docker 構建緩存"
    echo "  2. Docker 未使用的映像"
    echo "  3. Docker 系統資源 (容器、網絡)"
    echo "  4. Docker 卷 (危險)"
    echo "  5. 應用臨時檔案"
    echo "  6. 舊日誌檔案"
    echo "  7. 系統日誌"
    echo "  8. APT 套件快取"
    echo "  ---"
    echo "  9. 執行全部清理 (除了 Docker 卷)"
    echo "  0. 取消"
    echo ""
    read -p "請輸入選項 (0-9): " choice

    echo ""

    case $choice in
        1) clean_docker_build_cache ;;
        2) clean_docker_images ;;
        3) clean_docker_system ;;
        4) clean_docker_volumes ;;
        5) clean_app_temp_files ;;
        6) clean_old_logs ;;
        7) clean_system_logs ;;
        8) clean_apt_cache ;;
        9)
            clean_docker_build_cache
            clean_docker_images
            clean_docker_system
            clean_app_temp_files
            clean_old_logs
            clean_system_logs
            clean_apt_cache
            ;;
        0)
            print_info "已取消"
            exit 0
            ;;
        *)
            print_error "無效的選項"
            exit 1
            ;;
    esac

    echo ""
    print_success "清理完成!"
    echo ""

    show_disk_status
}

# 自動清理模式 (根據磁碟使用率)
auto_clean() {
    print_header "自動清理模式"

    local usage=$(get_disk_usage)

    show_disk_status

    if [ "$usage" -gt 90 ]; then
        print_error "磁碟使用率超過 90%,執行緊急清理!"
        echo ""
        clean_docker_build_cache
        clean_docker_images
        clean_docker_system
        clean_app_temp_files
        clean_old_logs
    elif [ "$usage" -gt 80 ]; then
        print_warning "磁碟使用率超過 80%,執行完整清理!"
        echo ""
        clean_docker_build_cache
        clean_docker_images
        clean_docker_system
        clean_app_temp_files
    elif [ "$usage" -gt 70 ]; then
        print_info "磁碟使用率超過 70%,執行快速清理"
        echo ""
        clean_docker_build_cache
        clean_app_temp_files
    else
        print_success "磁碟空間充足,無需清理"
        exit 0
    fi

    echo ""
    print_success "自動清理完成!"
    echo ""

    show_disk_status
}

# ============================================================================
# 主程式
# ============================================================================

# 顯示使用說明
show_usage() {
    echo "使用方法: $0 <mode>"
    echo ""
    echo "可用模式:"
    echo "  quick         快速清理 (Docker 基本資源)"
    echo "  full          完整清理 (Docker + 應用 + 系統)"
    echo "  emergency     緊急清理 (強制清理所有資源,包括卷)"
    echo "  interactive   互動式選擇清理項目"
    echo "  auto          自動模式 (根據磁碟使用率決定)"
    echo ""
    echo "範例:"
    echo "  $0 quick"
    echo "  $0 full"
    echo "  $0 emergency"
    echo "  $0 interactive"
    echo "  $0 auto"
}

# 主函數
main() {
    case $MODE in
        quick)
            quick_clean
            ;;
        full)
            full_clean
            ;;
        emergency)
            emergency_clean
            ;;
        interactive)
            interactive_clean
            ;;
        auto)
            auto_clean
            ;;
        --help|-h|help)
            show_usage
            exit 0
            ;;
        *)
            print_error "未知的模式: $MODE"
            echo ""
            show_usage
            exit 1
            ;;
    esac
}

# 執行主函數
main
