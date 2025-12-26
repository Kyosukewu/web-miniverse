# 磁盘空间问题多角度解决方案

## 问题根源分析

从错误信息看，问题不仅仅是根分区空间不足，还包括：

1. **MySQL 临时文件空间不足**：查询需要创建临时表，但 `/tmp` 空间不足
2. **日志写入失败循环**：每次尝试写入日志都失败，产生更多错误
3. **查询性能问题**：`offset 2450` 导致查询变慢并需要大量临时空间

## 解决方案总览

### 1. MySQL 配置优化 ✅

**文件**：`docker/mysql/my.cnf`

**优化内容**：
- 配置临时目录（可指向 `/mnt/PushDownloads`）
- 优化查询缓冲区（排序、连接、读取缓冲区）
- 设置查询超时（5 分钟）
- 优化 InnoDB 配置

**应用方式**：
```bash
docker compose restart db
```

### 2. 查询性能优化 ✅

**文件**：`app/Services/DashboardService.php`

**优化内容**：
- 限制最大页数为 100（避免 offset 过大）
- 限制每页最大数量为 100
- 设置查询超时保护
- 优雅处理日志写入失败（避免错误循环）

**效果**：
- 避免深度分页导致的性能问题
- 防止查询占用过多临时空间

### 3. 日志配置优化 ✅

**文件**：`config/logging.php`

**优化内容**：
- 减少日志保留天数从 14 天到 3 天
- 节省磁盘空间

### 4. 错误处理优化 ✅

**文件**：`app/Services/DashboardService.php`

**优化内容**：
- 当日志写入失败时，使用 `try-catch` 捕获异常
- 避免错误循环（日志写入失败 → 尝试记录错误 → 再次失败）
- 限制 trace 长度，避免日志过大

## 立即执行的命令

### 1. 清理 Docker 资源（释放 ~3.5GB）

```bash
docker image prune -af
docker builder prune -af
```

### 2. 清理系统日志

```bash
sudo journalctl --vacuum-time=3d
# 或限制大小
sudo journalctl --vacuum-size=500M
```

### 3. 清理 MySQL 临时文件

```bash
sudo find /tmp -name "MY*" -type f -mtime +1 -delete
```

### 4. 清理应用日志

```bash
docker compose exec app php artisan cleanup:logs --days=3 --max-size=50
```

### 5. 重启 MySQL 以应用新配置

```bash
docker compose restart db
```

## 验证步骤

### 1. 检查磁盘空间

```bash
df -h /
df -h /tmp
```

### 2. 检查 MySQL 配置

```bash
docker compose exec db mysql -e "SHOW VARIABLES LIKE 'tmpdir';"
docker compose exec db mysql -e "SHOW VARIABLES LIKE 'max_execution_time';"
docker compose exec db mysql -e "SHOW VARIABLES LIKE 'tmp_table_size';"
```

### 3. 测试查询性能

访问 dashboard 并尝试翻页，观察是否还有超时问题。

## 长期优化建议

### 1. 配置 MySQL 临时目录到有更多空间的位置

如果 `/mnt/PushDownloads` 可用（500GB，使用率仅 1%），可以：

1. 编辑 `docker/mysql/my.cnf`，取消注释：
   ```ini
   tmpdir = /mnt/PushDownloads/mysql-tmp
   ```

2. 创建目录并设置权限：
   ```bash
   sudo mkdir -p /mnt/PushDownloads/mysql-tmp
   sudo chown mysql:mysql /mnt/PushDownloads/mysql-tmp
   sudo chmod 700 /mnt/PushDownloads/mysql-tmp
   ```

3. 在 `docker-compose.yml` 中添加 volume 挂载：
   ```yaml
   db:
     volumes:
       - db_data:/var/lib/mysql
       - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf
       - /mnt/PushDownloads/mysql-tmp:/tmp/mysql-tmp  # 添加这行
   ```

### 2. 添加数据库索引

```sql
-- videos 表
CREATE INDEX IF NOT EXISTS idx_analysis_status ON videos(analysis_status);
CREATE INDEX IF NOT EXISTS idx_published_at ON videos(published_at);
CREATE INDEX IF NOT EXISTS idx_source_name_status ON videos(source_name, analysis_status);

-- analysis_results 表
CREATE INDEX IF NOT EXISTS idx_importance_rating ON analysis_results(importance_rating);
CREATE INDEX IF NOT EXISTS idx_video_id_importance ON analysis_results(video_id, importance_rating);
```

### 3. 考虑使用游标分页

对于大数据集，考虑使用基于游标的分页（cursor-based pagination）而不是 offset-based。

### 4. 设置定期清理任务

在 crontab 中添加：

```bash
# 每周清理 Docker 资源
0 2 * * 0 docker image prune -af && docker builder prune -af

# 每天清理 MySQL 临时文件
0 3 * * * find /tmp -name "MY*" -type f -mtime +1 -delete
```

## 预期效果

执行上述优化后：

1. **磁盘空间**：释放 4-7GB 空间（Docker 镜像 + 日志清理）
2. **查询性能**：深度分页查询不再超时（限制最大页数）
3. **临时文件**：MySQL 临时文件可以存储在更大空间的位置
4. **错误处理**：日志写入失败不再导致错误循环

## 监控指标

定期检查以下指标：

1. **磁盘使用率**：`df -h /`（应保持在 80% 以下）
2. **MySQL 临时文件**：`ls -lh /tmp/MY*`（应定期清理）
3. **日志文件大小**：`du -sh storage/logs/*`（单个文件不应超过 50MB）
4. **查询性能**：监控慢查询日志

## 故障排查

如果问题仍然存在：

1. **检查 MySQL 临时目录**：
   ```bash
   docker compose exec db mysql -e "SHOW VARIABLES LIKE 'tmpdir';"
   ```

2. **检查磁盘 inode**：
   ```bash
   df -i /
   ```

3. **检查 MySQL 错误日志**：
   ```bash
   docker compose logs db | grep -i error
   ```

4. **检查应用日志**：
   ```bash
   docker compose exec app tail -100 storage/logs/laravel.log
   ```

