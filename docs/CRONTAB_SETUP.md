# 主機 Crontab 自動重啟配置指南

## 概述

此配置用於在主機上設置定期容器重啟,作為防禦層 3（方案 B）的一部分。定期重啟可以清理容器可寫層累積的資料,防止磁碟空間不足問題。

## 配置說明

### 1. 連接到主機

```bash
ssh miniverse
```

### 2. 編輯 crontab

```bash
crontab -e
```

### 3. 添加以下排程任務

```cron
# Web Miniverse 容器自動重啟（每週日凌晨 3:30）
# 目的：清理容器可寫層累積的資料,防止磁碟空間不足
30 3 * * 0 cd /var/www/html/web-miniverse && docker compose restart app >> /var/log/miniverse-restart.log 2>&1

# 每月第一天凌晨 4:00 執行 Docker 系統清理（可選）
0 4 1 * * docker system prune -af --volumes=false >> /var/log/miniverse-docker-prune.log 2>&1
```

### 4. 驗證 crontab 設置

```bash
# 檢查 crontab 是否正確添加
crontab -l

# 確認 cron 服務運行中
sudo systemctl status cron
```

## 排程時間說明

### 主要重啟排程（每週日凌晨 3:30）

- **時間選擇理由**:
  - 週日凌晨流量最低
  - 避開工作時間
  - 與其他清理任務（凌晨 2:00、3:00）錯開

- **重啟影響**:
  - 停機時間：約 10-30 秒
  - PHP-FPM 和排程器會優雅關閉（stopwaitsecs=300）
  - 正在執行的分析任務會完成後才關閉

### Docker 清理排程（每月第一天凌晨 4:00,可選）

- **清理內容**:
  - 未使用的映像
  - 構建快取
  - 懸空容器
  - **不刪除 volumes**（保留數據庫資料）

- **預期效果**:
  - 釋放 1-3GB 磁碟空間

## 日誌管理

### 查看重啟日誌

```bash
# 查看最近的重啟日誌
tail -f /var/log/miniverse-restart.log

# 查看 Docker 清理日誌
tail -f /var/log/miniverse-docker-prune.log
```

### 日誌輪轉配置（可選）

創建 `/etc/logrotate.d/miniverse-cron`:

```
/var/log/miniverse-*.log {
    weekly
    rotate 4
    compress
    delaycompress
    missingok
    notifempty
}
```

## 手動測試

在正式啟用前,建議先手動測試重啟流程:

```bash
# 進入專案目錄
cd /var/www/html/web-miniverse

# 手動執行重啟命令
docker compose restart app

# 檢查容器狀態
docker compose ps

# 檢查應用日誌
docker compose logs -f app
```

## 監控建議

### 重啟後檢查項目

1. **容器狀態**:
   ```bash
   docker compose ps
   # 確認 app 容器狀態為 Up
   ```

2. **應用健康**:
   ```bash
   curl http://localhost/status
   # 應該返回正常響應
   ```

3. **排程器運行**:
   ```bash
   docker compose exec app supervisorctl status
   # 確認 laravel-scheduler 和 php-fpm 狀態為 RUNNING
   ```

4. **容器大小**:
   ```bash
   docker ps -s
   # 重啟後容器可寫層應該接近 0（只有虛擬大小）
   ```

## 緊急處理

### 如果重啟失敗

1. **檢查 Docker 狀態**:
   ```bash
   sudo systemctl status docker
   ```

2. **手動啟動容器**:
   ```bash
   cd /var/www/html/web-miniverse
   docker compose up -d app
   ```

3. **檢查錯誤日誌**:
   ```bash
   docker compose logs app
   ```

### 如果需要臨時停用

```bash
# 註釋掉 crontab 中的排程
crontab -e
# 在對應行前加上 #

# 或直接移除
crontab -r  # 警告：會刪除所有 crontab 任務
```

## 與其他清理機制的協調

### Laravel 排程清理（容器內）

- **臨時檔案清理**: 每小時執行（保留 1 小時）
- **緊急清理**: 每 6 小時檢查（磁碟使用率 > 80%）
- **容器大小監控**: 每 6 小時檢查（容器 > 5GB 警告）
- **舊影片清理**: 每天凌晨 2:00（刪除 14 天前資料）
- **日誌清理**: 每天凌晨 3:00（保留 3 天）

### 主機 Crontab（本配置）

- **容器重啟**: 每週日凌晨 3:30
- **Docker 清理**: 每月 1 號凌晨 4:00

### 手動部署清理

- **部署前清理**: `./scripts/deploy.sh` 會在部署前執行清理
- **緊急清理**: `./scripts/cleanup.sh emergency`

## 預期效果

### 正常情況下

- 容器可寫層每週重置為接近 0
- 防止長期累積導致的空間問題
- 磁碟使用率穩定在 50-60%

### 異常情況下（API 錯誤等）

- 即使清理機制失效,每週也會強制重置
- 配合容器大小監控,在達到 5GB 時提前預警
- 最壞情況下有 2-3 天緩衝時間處理問題

## 參考資料

- [容器空間管理計劃](/Users/tvbs/.claude/plans/bubbly-knitting-otter.md)
- [部署檢查清單](./DEPLOYMENT_CHECKLIST.md)
- [優雅關閉指南](./GRACEFUL_SHUTDOWN.md)
