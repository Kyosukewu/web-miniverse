# 🐳 Docker 配置

本目錄包含 Miniverse 項目的 Docker 相關配置文件。

---

## 📂 文件說明

### 配置文件

| 文件 | 說明 |
|------|------|
| `nginx.conf` | Nginx Web 服務器配置 |
| `nginx-ssl.conf` | Nginx SSL/HTTPS 配置模板 |
| `supervisord.conf` | Supervisor 主配置文件 |
| `supervisord.d/php-fpm.conf` | PHP-FPM 進程管理配置 |
| `supervisord.d/laravel-scheduler.conf` | Laravel 排程任務配置 |
| `entrypoint.sh` | 容器啟動腳本 |

### 文檔文件

| 文件 | 說明 |
|------|------|
| `DATABASE_ACCESS.md` | 資料庫訪問指南（已移至 `docs/`） |
| `DEPLOYMENT_CHECKLIST.md` | 部署檢查清單（已移至 `docs/`） |
| `UPDATE.md` | 更新流程說明（已整合至主 README） |

---

## 🚀 快速開始

### 啟動服務

```bash
# 在項目根目錄執行
docker compose up -d

# 查看狀態
docker compose ps

# 查看日誌
docker compose logs -f app
```

### 進入容器

```bash
# 進入 app 容器
docker compose exec app bash

# 執行 Artisan 指令
docker compose exec app php artisan list
```

---

## 📝 配置說明

### Nginx 配置 (nginx.conf)

主要配置項：

- **FastCGI 緩衝**: 針對 GCS 代理路由禁用緩衝
- **上傳限制**: `client_max_body_size 500M`
- **超時設置**: `fastcgi_read_timeout 600s`
- **GCS 代理**: `/gcs-proxy/` 路由的特殊處理

### Supervisor 配置 (supervisord.conf)

管理兩個主要進程：

1. **PHP-FPM** - PHP 進程管理
   ```ini
   [program:php-fpm]
   command=php-fpm -F
   autostart=true
   autorestart=true
   ```

2. **Laravel Scheduler** - 定時任務調度
   ```ini
   [program:laravel-scheduler]
   command=...
   autostart=true
   autorestart=true
   stopwaitsecs=300  # 優雅關閉，等待 5 分鐘
   ```

### Entrypoint 腳本 (entrypoint.sh)

容器啟動時執行的腳本：

1. 等待 MySQL 啟動
2. 清除 Laravel 快取
3. 檢查排程開關（`SCHEDULER_ENABLED`）
4. 創建必要目錄並設置權限
5. 啟動 Supervisor

---

## 🔧 常見操作

### 重啟服務

```bash
# 重啟 app 容器
docker compose restart app

# 重啟特定進程
docker compose exec app supervisorctl restart laravel-scheduler:*
docker compose exec app supervisorctl restart php-fpm:*
```

### 查看日誌

```bash
# 容器日誌
docker compose logs -f app

# Supervisor 日誌
docker compose exec app tail -f /var/log/supervisor/supervisord.log

# Laravel Scheduler 日誌
docker compose exec app tail -f /var/log/supervisor/laravel-scheduler-stdout.log
```

### 修改配置

修改配置後需要重建容器：

```bash
# 1. 修改配置文件（如 nginx.conf）

# 2. 重建並重啟
docker compose down
docker compose up -d --build
```

---

## 📚 相關文檔

- [主 README](../README.md) - 項目主文檔
- [部署指南](../docs/DEPLOYMENT_CHECKLIST.md) - 完整部署流程
- [資料庫訪問](../docs/DATABASE_ACCESS.md) - 資料庫管理
- [優雅關閉](../docs/GRACEFUL_SHUTDOWN.md) - 安全停止和維護

---

## ⚠️ 注意事項

1. **權限設置**
   - `entrypoint.sh` 會自動設置 `storage/` 和 `bootstrap/cache/` 權限
   - 不要手動修改容器內的權限

2. **環境變數**
   - 所有環境變數通過 `.env` 文件和 `docker-compose.yml` 配置
   - 修改後需要重啟容器

3. **日誌輪換**
   - Supervisor 日誌會自動輪換（保留 10 個，每個 50MB）
   - Laravel 日誌需要定期清理

4. **優雅關閉**
   - Supervisor 配置了 300 秒的優雅關閉時間
   - 確保排程任務可以完成後再停止容器

---

<div align="center">
  <sub>🐳 Docker 配置確保了一致的運行環境</sub>
</div>
