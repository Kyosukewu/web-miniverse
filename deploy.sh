#!/bin/bash

# ============================================================================
# 部署腳本 (向後兼容包裝)
# ============================================================================
# 此腳本為向後兼容而保留,實際功能由 scripts/deploy.sh 提供
# 建議直接使用 scripts/deploy.sh 以獲得更好的功能和體驗
# ============================================================================

set -e

# 顏色定義
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 檢查新腳本是否存在
if [ ! -f "./scripts/deploy.sh" ]; then
    echo -e "${YELLOW}錯誤: 找不到統一部署腳本 scripts/deploy.sh${NC}"
    exit 1
fi

# 提示用戶
echo -e "${BLUE}注意: 此腳本已重構,實際功能由 scripts/deploy.sh 提供${NC}"
echo -e "${BLUE}建議直接使用: ./scripts/deploy.sh [選項]${NC}"
echo ""

# 將所有參數轉發到新腳本
if [ "$1" = "--help" ] || [ "$1" = "-h" ] || [ "$1" = "help" ]; then
    # 顯示舊參數到新參數的對應
    echo "參數對應:"
    echo "  舊: --env=production       → 新: --env=production"
    echo "  舊: --env=development      → 新: --env=development (預設)"
    echo "  舊: --skip-build           → 新: --quick"
    echo "  舊: --rebuild              → 新: --rebuild"
    echo "  舊: --check                → 新: --check"
    echo ""
    echo "新增功能:"
    echo "  --pull                     先執行 git pull 再部署"
    echo ""
    echo "建議直接使用: ./scripts/deploy.sh --help"
    echo ""
fi

# 參數轉換: 將舊參數轉換為新參數格式
args=()
for arg in "$@"; do
    case $arg in
        --skip-build)
            args+=(--quick)
            ;;
        *)
            args+=("$arg")
            ;;
    esac
done

# 轉發到新腳本
exec ./scripts/deploy.sh "${args[@]}"
