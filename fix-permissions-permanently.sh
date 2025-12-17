#!/bin/bash

#######################################
# 永久解決 Git 權限問題
#######################################

set -e

echo "==========================================="
echo "  永久解決 Git 權限問題"
echo "==========================================="
echo ""

cd /var/www/html/web-miniverse

echo "步驟 1/5: 修正所有權..."
sudo chown -R $(whoami):$(whoami) .
echo "✅ 所有權已修正"
echo ""

echo "步驟 2/5: 配置 Git 忽略權限變更..."
git config core.fileMode false
git config --global core.fileMode false
echo "✅ Git 已配置為忽略權限變更"
echo ""

echo "步驟 3/5: 重置本地更改..."
git reset --hard HEAD
git clean -fd
echo "✅ 本地更改已重置"
echo ""

echo "步驟 4/5: 同步遠端代碼..."
git fetch origin
git reset --hard origin/main
echo "✅ 已同步遠端代碼"
echo ""

echo "步驟 5/5: 設置腳本可執行權限..."
chmod +x *.sh
echo "✅ 腳本權限已設置"
echo ""

echo "==========================================="
echo "  ✅ 權限問題已永久解決！"
echo "==========================================="
echo ""
echo "🔹 已應用的修復："
echo "  1. ✅ Git 忽略權限變更 (core.fileMode = false)"
echo "  2. ✅ Entrypoint 腳本不再修改 .gitignore 文件"
echo "  3. ✅ 主機文件所有權已修正"
echo ""
echo "🔹 下次更新時直接執行："
echo "  ./update-and-deploy.sh --skip-build"
echo ""

