# Docker 空间不足紧急修复指南

## 🔴 错误：No space left on device

### 错误信息

```
tar: ext/soap/tests/soap12/T11.phpt: Cannot write: No space left on device
tar: Exiting with failure status due to previous errors
failed to solve: process "/bin/sh -c docker-php-ext-install ..." did not complete successfully: exit code: 2
```

## 🚀 快速修复（3 种方法）

### 方法 1：使用紧急清理脚本（推荐）

```bash
# 执行紧急清理脚本
./scripts/docker/emergency-cleanup.sh

# 然后重新部署
./scripts/deployment/update-and-deploy.sh
```

### 方法 2：手动清理（如果脚本不可用）

```bash
# 1. 停止容器
docker compose down

# 2. 清理构建缓存（最重要，释放 1-3GB）
docker builder prune -af

# 3. 清理未使用的镜像
docker image prune -af

# 4. 清理未使用的容器和网络
docker system prune -af

# 5. 检查空间
docker system df
df -h

# 6. 重新部署
./scripts/deployment/update-and-deploy.sh
```

### 方法 3：一行命令快速清理

```bash
# 彻底清理所有未使用的 Docker 资源
docker builder prune -af && docker system prune -af && docker image prune -af

# 然后重新部署
./scripts/deployment/update-and-deploy.sh
```

## 📊 检查空间使用

### 检查系统磁盘空间

```bash
# 查看磁盘使用情况
df -h

# 查看 Docker 使用的空间
docker system df
```

### 检查占用空间最大的资源

```bash
# 查看最大的镜像
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | sort -k3 -h -r | head -10

# 查看最大的容器
docker ps -a --format "table {{.Names}}\t{{.Size}}" | sort -k2 -h -r | head -10
```

## 🔧 部署脚本改进

部署脚本 (`deploy.sh`) 现在已经自动包含清理步骤：

### 开发环境

```bash
# 步骤 2: 检查磁盘空间
# 步骤 3: 清理 Docker 构建缓存和未使用的资源
docker builder prune -af
docker image prune -af
docker system prune -f
```

### 生产环境

```bash
# 清理 Docker 构建缓存和未使用的资源
docker builder prune -af
docker image prune -af
docker system prune -f
```

## ⚠️ 如果自动清理仍然不够

### 情况 1：磁盘空间真的不足

```bash
# 检查实际可用空间
df -h /

# 如果使用率 > 95%，需要：
# 1. 删除其他大文件
# 2. 清理系统日志
# 3. 联系系统管理员增加磁盘空间
```

### 情况 2：Docker 数据目录空间不足

```bash
# 检查 Docker 数据目录
docker info | grep "Docker Root Dir"

# 如果 Docker 数据目录在单独的分区，检查该分区
df -h $(docker info | grep "Docker Root Dir" | awk '{print $4}')
```

### 情况 3：需要更彻底的清理

```bash
# 停止所有容器
docker compose down

# 删除所有未使用的资源（包括卷，危险！）
docker system prune -af --volumes

# 删除所有未使用的镜像（包括有标签的）
docker image prune -a -f

# 重新构建
docker compose build --no-cache --pull app
```

## 📝 预防措施

### 1. 定期清理（建议每周）

```bash
# 添加到 crontab
0 2 * * 0 docker builder prune -af --filter "until=168h"
```

### 2. 监控磁盘使用

```bash
# 创建监控脚本
cat > /usr/local/bin/check-docker-space.sh << 'EOF'
#!/bin/bash
THRESHOLD=85
USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')

if [ "$USAGE" -gt "$THRESHOLD" ]; then
    echo "警告：磁盘使用率 ${USAGE}% 超过阈值 ${THRESHOLD}%"
    docker builder prune -af
fi
EOF

chmod +x /usr/local/bin/check-docker-space.sh
```

### 3. 构建前检查

部署脚本现在会自动：
- ✅ 检查磁盘使用率
- ✅ 清理构建缓存
- ✅ 清理未使用的镜像
- ✅ 显示清理后的空间使用情况

## 🆘 故障排查步骤

### 步骤 1：确认错误类型

```bash
# 查看完整错误日志
docker compose build --progress=plain app 2>&1 | tee build.log

# 查找空间相关错误
grep -i "space\|no space\|device" build.log
```

### 步骤 2：检查实际空间

```bash
# 系统磁盘
df -h /

# Docker 空间
docker system df

# 如果空间充足但仍然失败，可能是：
# - inode 耗尽
df -i /
```

### 步骤 3：执行清理

```bash
# 使用紧急清理脚本
./scripts/docker/emergency-cleanup.sh

# 或手动清理
docker builder prune -af
docker system prune -af
```

### 步骤 4：重新构建

```bash
# 重新执行部署
./scripts/deployment/update-and-deploy.sh
```

## ✅ 验证修复

清理后验证：

```bash
# 1. 检查磁盘空间（应该 < 90%）
df -h /

# 2. 检查 Docker 空间（Build Cache 应该为 0）
docker system df

# 3. 尝试构建
docker compose build --pull app
```

## 📚 相关文档

- [Docker 构建问题排查](./DOCKER_BUILD_TROUBLESHOOTING.md)
- [部署脚本使用说明](./DEPLOYMENT_SCRIPT_USAGE.md)

## 🎯 总结

**遇到 "No space left on device" 错误时**：

1. ✅ **立即执行**：`./scripts/docker/emergency-cleanup.sh`
2. ✅ **或手动清理**：`docker builder prune -af && docker system prune -af`
3. ✅ **然后重新部署**：`./scripts/deployment/update-and-deploy.sh`

**部署脚本已改进**：
- ✅ 自动检查磁盘空间
- ✅ 自动清理构建缓存
- ✅ 自动清理未使用的镜像
- ✅ 显示清理后的空间使用情况

如果问题仍然存在，请检查系统磁盘空间是否真的不足。

