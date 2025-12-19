# 部署脚本使用说明

## 📋 概述

系统提供两个部署脚本：

1. **`scripts/deployment/update-and-deploy.sh`** - 自动更新代码并部署
2. **`deploy.sh`** - 执行实际部署操作

## 🚀 快速使用

### 方法 1：一键部署（推荐）

```bash
# 开发环境（默认）
./scripts/deployment/update-and-deploy.sh

# 生产环境
./scripts/deployment/update-and-deploy.sh --env=production
```

### 方法 2：直接使用 deploy.sh

```bash
# 开发环境
./deploy.sh --env=development

# 生产环境
./deploy.sh --env=production
```

## 📝 脚本功能

### update-and-deploy.sh

**功能**：
1. ✅ 检查本地未提交的更改
2. ✅ 从 GitHub 拉取最新代码
3. ✅ 调用 `deploy.sh` 执行部署

**使用场景**：
- 需要从远程仓库更新代码
- 确保使用最新版本的部署脚本

### deploy.sh

**功能**：
1. ✅ **清理 Docker 构建缓存**（新增，避免空间不足）
2. ✅ 构建 Docker 镜像
3. ✅ 启动容器
4. ✅ 安装/更新 Composer 套件
5. ✅ 执行数据库迁移
6. ✅ 清除应用缓存
7. ✅ 优化自动加载
8. ✅ 检查排程状态

## 🔧 新增功能

### Docker 空间清理（重要）

**问题**：之前可能遇到 "No space left on device" 错误

**解决方案**：部署脚本现在会自动清理 Docker 构建缓存

**开发环境**：
```bash
# 步骤 2: 清理 Docker 构建缓存
docker builder prune -af
```

**生产环境**：
```bash
# 清理 Docker 构建缓存
docker builder prune -af
```

**效果**：
- 释放 1-3GB 磁盘空间
- 避免构建失败
- 确保构建过程顺利进行

## 📊 部署流程

### 开发环境流程

```
1. 停止现有容器
2. 清理 Docker 构建缓存 ← 新增
3. 重建容器（使用 --pull 获取最新基础镜像）
4. 启动容器
5. 等待容器启动
6. 检查容器状态
7. 检查 Supervisor 状态
8. 检查排程配置
9. 安装/更新 Composer 套件
10. 执行数据库迁移
11. 清除应用缓存
12. 优化自动加载
13. 列出排程任务
14. 手动执行排程测试
15. 查看排程日志
```

### 生产环境流程

```
1. 检查 GitHub Token
2. 拉取最新代码
3. 设置文件权限
4. 清理 Docker 构建缓存 ← 改进（更精确）
5. 构建 Docker 镜像
6. 停止旧容器
7. 启动新容器
8. 等待容器启动
9. 安装/更新 Composer 套件
10. 执行数据库迁移
11. 清除应用缓存
12. 优化自动加载
13. 缓存配置（生产环境）
14. 检查排程状态
```

## 🎯 使用选项

### update-and-deploy.sh 选项

```bash
# 开发环境（默认）
./scripts/deployment/update-and-deploy.sh

# 生产环境
./scripts/deployment/update-and-deploy.sh --env=production

# 检查状态
./scripts/deployment/update-and-deploy.sh --check

# 跳过构建（只更新代码）
./scripts/deployment/update-and-deploy.sh --skip-build
```

### deploy.sh 选项

```bash
# 开发环境（默认）
./deploy.sh --env=development

# 生产环境
./deploy.sh --env=production

# 只重建容器
./deploy.sh --rebuild

# 跳过构建（只重启容器）
./deploy.sh --skip-build

# 检查排程状态
./deploy.sh --check

# 显示帮助
./deploy.sh --help
```

## ⚠️ 注意事项

### 1. 本地未提交的更改

如果本地有未提交的更改，脚本会询问：

```
⚠️  检测到本地有未提交的更改：
 M app/Console/Commands/AnalyzeFullCommand.php
是否要暂存这些更改并继续？(y/N):
```

**选项**：
- `y` - 暂存更改并继续（推荐）
- `N` - 取消部署，手动处理更改

### 2. Docker 空间不足

如果遇到 "No space left on device" 错误：

```bash
# 手动清理（如果自动清理不够）
docker builder prune -af
docker system prune -f

# 然后重新执行部署
./scripts/deployment/update-and-deploy.sh
```

### 3. 构建失败

如果 Docker 构建失败：

```bash
# 查看详细错误
docker compose build --progress=plain app 2>&1 | tee build.log

# 检查错误
grep -i "error\|fail" build.log
```

## 📋 部署检查清单

部署前请确认：

- [ ] 已备份数据库（生产环境）
- [ ] 已检查 `.env` 配置
- [ ] 已确认 GitHub Token（生产环境）
- [ ] 已检查磁盘空间（`df -h`）
- [ ] 已检查 Docker 空间（`docker system df`）

部署后请验证：

- [ ] 容器正常运行（`docker compose ps`）
- [ ] Supervisor 正常运行（`docker compose exec app supervisorctl status`）
- [ ] 排程已启用（`docker compose exec app php artisan schedule:list`）
- [ ] 应用可以访问
- [ ] 数据库连接正常

## 🔍 故障排查

### 问题 1：构建失败（No space left on device）

**解决方案**：
```bash
# 脚本已自动清理，如果仍然失败，手动清理
docker builder prune -af
docker system prune -f

# 检查空间
df -h
docker system df
```

### 问题 2：容器启动失败

**检查**：
```bash
# 查看容器日志
docker compose logs app

# 检查容器状态
docker compose ps
```

### 问题 3：数据库迁移失败

**检查**：
```bash
# 查看迁移错误
docker compose exec app php artisan migrate --force

# 检查数据库连接
docker compose exec app php artisan tinker --execute="DB::connection()->getPdo();"
```

### 问题 4：排程未运行

**检查**：
```bash
# 检查排程配置
docker compose exec app grep SCHEDULER_ENABLED .env

# 检查 Supervisor 状态
docker compose exec app supervisorctl status

# 手动测试排程
docker compose exec app php artisan schedule:run --verbose
```

## 📚 相关文档

- [Docker 构建问题排查](./DOCKER_BUILD_TROUBLESHOOTING.md)
- [Dockerfile apt-get 错误修复](./DOCKERFILE_APT_ERROR_FIX.md)
- [Dockerfile PHP 扩展错误修复](./DOCKERFILE_PHP_EXT_ERROR_FIX.md)

## ✅ 总结

**是的，您可以直接执行 `./scripts/deployment/update-and-deploy.sh` 完成部署！**

**改进点**：
1. ✅ 自动清理 Docker 构建缓存（避免空间不足）
2. ✅ 使用 `--pull` 获取最新基础镜像
3. ✅ 完整的部署流程（代码更新 → 构建 → 迁移 → 缓存清理）
4. ✅ 详细的步骤提示和错误处理

**使用建议**：
- **开发环境**：直接执行 `./scripts/deployment/update-and-deploy.sh`
- **生产环境**：执行 `./scripts/deployment/update-and-deploy.sh --env=production`
- **快速更新**：使用 `--skip-build` 跳过构建（只更新代码）

