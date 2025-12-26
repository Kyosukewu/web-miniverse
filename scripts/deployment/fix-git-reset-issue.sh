#!/bin/bash

# 修复 git reset --hard 权限问题的脚本

set -e

echo "========================================="
echo "  修复 Git Reset 权限问题"
echo "========================================="
echo ""

# 1. 停止可能使用配置文件的容器
echo "步骤 1: 停止可能使用配置文件的容器..."
if command -v docker &> /dev/null; then
    docker compose stop db 2>/dev/null || echo "  ℹ️  未找到 db 容器或已停止"
    sleep 2
else
    echo "  ℹ️  Docker 不可用，跳过"
fi
echo ""

# 2. 检查并修复文件权限
echo "步骤 2: 检查文件权限..."
if [ -f "docker/mysql/my.cnf" ]; then
    echo "  文件存在: docker/mysql/my.cnf"
    ls -la "docker/mysql/my.cnf"
    
    # 尝试修改权限
    echo "  修改文件权限..."
    chmod 644 "docker/mysql/my.cnf" 2>/dev/null || {
        echo "  ⚠️  无法修改权限，尝试使用 sudo..."
        sudo chmod 644 "docker/mysql/my.cnf" 2>/dev/null || echo "  ⚠️  仍然无法修改权限"
    }
    
    # 尝试删除文件（如果 Git 需要）
    echo "  尝试删除文件（如果 Git 需要）..."
    rm -f "docker/mysql/my.cnf" 2>/dev/null || {
        echo "  ⚠️  无法删除文件，尝试使用 sudo..."
        sudo rm -f "docker/mysql/my.cnf" 2>/dev/null || echo "  ⚠️  仍然无法删除文件"
    }
else
    echo "  ℹ️  文件不存在"
fi
echo ""

# 3. 清理 Git 索引
echo "步骤 3: 清理 Git 索引..."
git rm --cached "docker/mysql/my.cnf" 2>/dev/null || echo "  ℹ️  文件不在索引中或已清理"
echo ""

# 4. 尝试重置
echo "步骤 4: 尝试 Git Reset..."
CURRENT_BRANCH=$(git branch --show-current)
if [ -z "$CURRENT_BRANCH" ]; then
    CURRENT_BRANCH="main"
fi

echo "  当前分支: $CURRENT_BRANCH"
echo "  执行: git fetch origin"
git fetch origin

echo "  执行: git reset --hard origin/${CURRENT_BRANCH}"
if git reset --hard "origin/${CURRENT_BRANCH}"; then
    echo "  ✓ Git Reset 成功"
else
    echo "  ❌ Git Reset 失败"
    echo ""
    echo "  请手动执行以下命令："
    echo "    1. docker compose stop db"
    echo "    2. sudo rm -f docker/mysql/my.cnf"
    echo "    3. git reset --hard origin/${CURRENT_BRANCH}"
    exit 1
fi
echo ""

# 5. 恢复 stash（如果有）
echo "步骤 5: 检查是否有暂存的更改..."
if git stash list | grep -q "Auto-stash before update-and-deploy"; then
    echo "  发现自动暂存的更改"
    read -p "  是否要恢复暂存的更改？(y/N): " RESTORE_STASH
    if [[ "$RESTORE_STASH" =~ ^[Yy]$ ]]; then
        git stash pop
        echo "  ✓ 已恢复暂存的更改"
    else
        echo "  ℹ️  暂存的更改保留在 stash 中"
        echo "  使用 'git stash list' 查看，'git stash pop' 恢复"
    fi
else
    echo "  ℹ️  没有找到自动暂存的更改"
fi
echo ""

echo "========================================="
echo "  ✓ 修复完成"
echo "========================================="

