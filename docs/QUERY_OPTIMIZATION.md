# 查询优化和磁盘空间问题解决方案

## 问题分析

从错误信息看，主要有三个问题：

1. **MySQL 临时文件空间不足**：`Error writing file '/tmp/MYfd=42' (OS errno 28 - No space left on device)`
2. **日志写入失败循环**：每次尝试写入日志都失败，产生更多错误
3. **查询性能问题**：`offset 2450` 意味着需要跳过大量记录，导致查询变慢并需要大量临时空间

## 解决方案

### 1. MySQL 配置优化 (`docker/mysql/my.cnf`)

已创建 MySQL 配置文件，包含以下优化：

- **临时文件配置**：可以配置临时目录指向有更多空间的位置
- **查询缓冲区优化**：增加排序和连接缓冲区大小
- **查询超时**：设置最大执行时间为 5 分钟
- **InnoDB 优化**：优化缓冲池和日志配置

**重要**：如果 `/mnt/PushDownloads` 可用，可以取消注释 `tmpdir` 配置：

```ini
tmpdir = /mnt/PushDownloads/mysql-tmp
```

然后创建目录并设置权限：

```bash
sudo mkdir -p /mnt/PushDownloads/mysql-tmp
sudo chown mysql:mysql /mnt/PushDownloads/mysql-tmp
sudo chmod 700 /mnt/PushDownloads/mysql-tmp
```

### 2. 查询优化

#### 限制分页深度

在 `DashboardService::getPaginatedVideos()` 中：

- 限制最大页数为 100（避免 offset 过大）
- 限制每页最大数量为 100
- 设置查询超时（5 分钟）

#### 优化排序查询

在 `VideoRepository::getAllWithAnalysisQuery()` 中：

- 使用 `join` 而不是 `leftJoin`（已完成）
- 添加 `groupBy('videos.id')` 避免重复记录（已完成）
- 使用 `select('videos.*')` 限制返回字段（已完成）

### 3. 日志优化

#### 减少日志保留天数

在 `config/logging.php` 中：

- 将日志保留天数从 14 天减少到 3 天
- 这样可以节省大量磁盘空间

#### 优雅处理日志写入失败

在 `DashboardService::getPaginatedVideos()` 中：

- 当日志写入失败时，使用 `try-catch` 捕获异常
- 避免错误循环（日志写入失败 → 尝试记录错误 → 再次失败 → ...）
- 限制 trace 长度，避免日志过大

### 4. 数据库索引建议

为了进一步优化查询性能，建议添加以下索引：

```sql
-- 如果还没有这些索引，请添加：

-- videos 表
CREATE INDEX idx_analysis_status ON videos(analysis_status);
CREATE INDEX idx_published_at ON videos(published_at);
CREATE INDEX idx_source_name_status ON videos(source_name, analysis_status);

-- analysis_results 表
CREATE INDEX idx_importance_rating ON analysis_results(importance_rating);
CREATE INDEX idx_video_id_importance ON analysis_results(video_id, importance_rating);
```

### 5. 立即执行的清理操作

```bash
# 1. 清理 Docker 镜像（释放 ~3.5GB）
docker image prune -af

# 2. 清理系统日志
sudo journalctl --vacuum-time=3d

# 3. 清理 MySQL 临时文件
sudo find /tmp -name "MY*" -type f -mtime +1 -delete

# 4. 清理应用日志（保留 3 天）
docker compose exec app php artisan cleanup:logs --days=3 --max-size=50
```

## 监控建议

### 1. 磁盘空间监控

定期检查磁盘使用情况：

```bash
df -h /
df -h /tmp
```

### 2. MySQL 临时文件监控

检查 MySQL 临时文件：

```bash
ls -lh /tmp/MY* 2>/dev/null | head -20
```

### 3. 查询性能监控

在 MySQL 中启用慢查询日志：

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 5; -- 记录超过 5 秒的查询
```

## 长期优化建议

1. **使用游标分页**：对于大数据集，考虑使用基于游标的分页（cursor-based pagination）而不是 offset-based
2. **缓存查询结果**：对于常用的查询，使用 Redis 缓存
3. **数据归档**：定期归档旧数据，减少查询数据量
4. **读写分离**：如果数据量继续增长，考虑使用主从复制

## 验证

重启 MySQL 容器以应用新配置：

```bash
docker compose restart db
```

检查 MySQL 配置是否生效：

```bash
docker compose exec db mysql -e "SHOW VARIABLES LIKE 'tmpdir';"
docker compose exec db mysql -e "SHOW VARIABLES LIKE 'max_execution_time';"
```

