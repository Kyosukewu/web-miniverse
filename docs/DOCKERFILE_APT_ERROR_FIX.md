# Dockerfile apt-get 错误修复指南

## 🔴 错误：exit code 100

### 错误信息

```
failed to solve: process "/bin/sh -c apt-get update && apt-get install -y ..." 
did not complete successfully: exit code: 100
```

### 可能原因

1. **网络问题**：无法连接到 apt 仓库
2. **包依赖问题**：某些包需要额外的依赖
3. **仓库更新问题**：apt 仓库临时不可用
4. **磁盘空间不足**：虽然已清理，但可能仍然不足
5. **包名错误**：某些包在 PHP 8.4 基础镜像中不可用

## 🔧 解决方案

### 方案 1：使用修复后的 Dockerfile（推荐）

已修复的 Dockerfile 移除了 `--no-install-recommends`，这可能导致某些必需的依赖缺失。

```bash
# 使用修复后的 Dockerfile 重新构建
docker compose build --pull app
```

### 方案 2：分步安装（如果方案 1 失败）

如果仍然失败，可以使用分步安装的健壮版本：

```bash
# 使用健壮版本的 Dockerfile
cp Dockerfile.robust Dockerfile
docker compose build --pull app
```

### 方案 3：检查网络连接

```bash
# 在容器中测试网络连接
docker run --rm php:8.4-fpm apt-get update

# 如果失败，可能是网络问题
# 检查代理设置或 DNS 配置
```

### 方案 4：使用国内镜像源（如果在中国）

如果在中国大陆，可能需要使用国内镜像源加速：

```dockerfile
# 在 Dockerfile 开头添加（在 FROM 之后）
RUN sed -i 's/deb.debian.org/mirrors.aliyun.com/g' /etc/apt/sources.list.d/debian.sources || \
    sed -i 's/deb.debian.org/mirrors.aliyun.com/g' /etc/apt/sources.list || \
    echo "deb https://mirrors.aliyun.com/debian/ bookworm main" > /etc/apt/sources.list
```

### 方案 5：增加构建超时

如果网络较慢，可能需要增加超时时间：

```bash
# 在 docker-compose.yml 中添加构建参数
# 或在构建时设置
DOCKER_BUILDKIT=1 docker compose build --progress=plain app
```

## 🔍 详细诊断步骤

### 步骤 1：检查错误详情

```bash
# 使用详细输出查看具体错误
docker compose build --progress=plain app 2>&1 | tee build.log

# 查看错误信息
grep -i "error\|fail\|unable\|cannot" build.log
```

### 步骤 2：测试单个包安装

```bash
# 创建一个测试 Dockerfile
cat > Dockerfile.test << 'EOF'
FROM php:8.4-fpm
RUN apt-get update && apt-get install -y git
EOF

# 测试构建
docker build -f Dockerfile.test -t test-build .
```

### 步骤 3：检查基础镜像

```bash
# 拉取最新基础镜像
docker pull php:8.4-fpm

# 检查镜像信息
docker inspect php:8.4-fpm | grep -i "architecture\|os"
```

### 步骤 4：在容器中手动测试

```bash
# 启动一个临时容器
docker run -it --rm php:8.4-fpm bash

# 在容器中测试
apt-get update
apt-get install -y git curl
```

## 📝 常见问题

### Q1: 为什么移除 `--no-install-recommends`？

**A:** `--no-install-recommends` 会跳过推荐包，但某些包（如 `supervisor`、`cron`）可能需要推荐包才能正常工作。移除后可以确保所有必需的依赖都被安装。

### Q2: 分步安装有什么好处？

**A:** 
- 更容易定位哪个包安装失败
- 如果某个步骤失败，其他步骤的缓存仍然可用
- 可以单独重试失败的步骤

### Q3: 如何知道是哪个包失败了？

**A:** 查看构建日志的最后几行，通常会显示：
```
E: Unable to locate package <package-name>
E: Package <package-name> has no installation candidate
```

### Q4: 构建很慢怎么办？

**A:** 
1. 使用国内镜像源（如果在中国）
2. 使用构建缓存：`docker compose build app`（不添加 `--no-cache`）
3. 分步构建，利用缓存

## 🚀 快速修复命令

### 方法 1：使用修复后的 Dockerfile

```bash
# 清理缓存并重新构建
docker builder prune -af
docker compose build --pull app
```

### 方法 2：使用健壮版本

```bash
# 备份原 Dockerfile
cp Dockerfile Dockerfile.backup

# 使用健壮版本
cp Dockerfile.robust Dockerfile

# 重新构建
docker compose build --pull app
```

### 方法 3：完全重建

```bash
# 完全清理并重建
docker builder prune -af
docker system prune -f
docker compose build --no-cache --pull app
```

## 📊 修改对比

### 修改前（有问题）

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    ...
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean
```

**问题**：
- `--no-install-recommends` 可能导致依赖缺失
- 所有包一次性安装，难以定位问题

### 修改后（已修复）

```dockerfile
RUN apt-get update && \
    apt-get install -y \
        git \
        curl \
        ...
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean \
    && apt-get autoremove -y
```

**改进**：
- 移除了 `--no-install-recommends`
- 添加了 `apt-get autoremove -y` 清理不需要的包
- 保持了空间优化（清理 apt 缓存）

## ✅ 验证构建

构建成功后，验证安装的包：

```bash
# 启动容器
docker compose up -d app

# 检查已安装的包
docker compose exec app dpkg -l | grep -E "git|curl|supervisor|cron|python3"

# 检查 PHP 扩展
docker compose exec app php -m | grep -E "pdo_mysql|mbstring|gd|zip|intl"
```

## 🆘 如果问题仍然存在

1. **检查系统日志**：
   ```bash
   journalctl -u docker.service | tail -50
   ```

2. **检查 Docker 版本**：
   ```bash
   docker --version
   docker compose version
   ```

3. **尝试不同的基础镜像**：
   ```dockerfile
   # 如果 php:8.4-fpm 有问题，可以尝试
   FROM php:8.3-fpm
   ```

4. **联系系统管理员**：可能需要检查网络配置或代理设置

## 📚 相关文档

- [Dockerfile 最佳实践](https://docs.docker.com/develop/develop-images/dockerfile_best-practices/)
- [apt-get 故障排查](https://wiki.debian.org/Apt)
- [Docker 构建缓存](https://docs.docker.com/build/cache/)

