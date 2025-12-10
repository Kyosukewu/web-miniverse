# 程式碼更新/更版指南

## 🔄 更新流程

當 GitHub 上的程式碼有更新時，可以使用以下方式更新 EC2 上的部署。

## 📋 完整更新流程

### 階段一：開發與提交（本地）

1. **開發新功能或修復**
   ```bash
   git checkout -b feature/new-feature  # 建立新分支（可選）
   # ... 進行開發 ...
   ```

2. **本地測試**
   ```bash
   php artisan test  # 執行測試（如果有）
   # 手動測試功能
   ```

3. **提交變更**
   ```bash
   git add .
   git commit -m "feat: 新增新功能或修復問題"
   git push origin main
   ```

### 階段二：部署到 EC2

#### 方法一：使用更新腳本（推薦）

```bash
# 1. 連接到 EC2
ssh -i your-key.pem ec2-user@your-ec2-ip

# 2. 進入專案目錄
cd /var/www/html/web-miniverse

# 3. 執行更新腳本
GITHUB_TOKEN=your_token ./docker/update.sh

# 或強制重新構建
GITHUB_TOKEN=your_token ./docker/update.sh --rebuild
```

更新腳本會自動：
- ✅ 備份當前版本
- ✅ 拉取最新程式碼
- ✅ 檢查 Docker 相關變更
- ✅ 重新構建容器（如有需要）
- ✅ 重啟容器
- ✅ 執行 Laravel 維護任務（migrate、cache 等）
- ✅ 檢查容器和排程任務狀態

#### 方法二：手動更新

```bash
# 1. 連接到 EC2
ssh -i your-key.pem ec2-user@your-ec2-ip
cd /var/www/web-miniverse

# 2. 拉取最新程式碼
git pull origin main  # 或 master

# 或重置到最新版本
git fetch origin
git reset --hard origin/main
git clean -fd

# 3. 重新構建容器（如有 Docker 相關變更）
docker compose build --no-cache

# 4. 重啟容器
docker compose restart
# 或完全重啟
docker compose down
docker compose up -d

# 5. 執行 Laravel 維護任務
docker compose exec -T app composer install --no-interaction --optimize-autoloader --no-dev
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
```

### 階段三：驗證部署

```bash
# 檢查容器狀態
docker compose ps

# 檢查應用日誌
docker compose logs -f app

# 檢查排程任務
docker compose exec -T app ps aux | grep schedule

# 測試網站功能
# 開啟瀏覽器訪問網站，測試主要功能
```

## 📋 更新檢查清單

更新前：
- [ ] 確認程式碼已測試
- [ ] 確認資料庫遷移檔案已準備
- [ ] 確認環境變數是否需要更新
- [ ] 備份資料庫（建議）

更新後：
- [ ] 檢查容器狀態：`docker compose ps`
- [ ] 檢查應用日誌：`docker compose logs -f app`
- [ ] 檢查排程任務：`docker compose exec -T app ps aux | grep schedule`
- [ ] 測試網站功能
- [ ] 檢查資料庫遷移是否成功

## 🔍 常見問題

### 更新後容器無法啟動

```bash
# 查看詳細錯誤
docker compose logs app

# 檢查容器狀態
docker compose ps -a

# 檢查資源使用
docker stats
```

### 資料庫遷移失敗

```bash
# 查看遷移狀態
docker compose exec -T app php artisan migrate:status

# 手動執行遷移
docker compose exec -T app php artisan migrate --force

# 如有問題，可以回滾
docker compose exec -T app php artisan migrate:rollback
```

### 排程任務沒有執行

```bash
# 檢查排程進程
docker compose exec -T app ps aux | grep schedule

# 查看排程日誌
docker compose exec -T app tail -f /var/log/supervisor/scheduler.log

# 手動執行排程
docker compose exec -T app php artisan schedule:run
```

### 需要還原到之前的版本

```bash
# 查看備份
ls -lh /var/backups/web-miniverse/

# 還原備份
cd /var/www/html/web-miniverse
docker compose down
tar -xzf /var/backups/web-miniverse/backup_YYYYMMDD_HHMMSS.tar.gz -C /var/www/html/
docker compose up -d
```

或使用 Git 回滾：

```bash
# 停止當前容器
docker compose down

# 還原程式碼
cd /var/www/html/web-miniverse
git reset --hard HEAD~1  # 或指定 commit
git clean -fd

# 重新啟動
docker compose up -d

# 執行維護任務
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
```

## 🚀 最佳實踐

### 1. 使用 Git Tag 管理版本

```bash
# 建立版本標籤
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin v1.0.0

# 更新到特定版本
git fetch origin
git checkout v1.0.0
./docker/update.sh
```

### 2. 在更新前測試

建議在測試環境先測試更新，確認無誤後再更新生產環境。

### 3. 定期備份

```bash
# 備份資料庫
docker compose exec -T db mysqldump -u root -p${DB_PASSWORD:-root} miniverse > backup_$(date +%Y%m%d).sql

# 備份整個專案（更新腳本會自動執行）
./docker/update.sh
```

### 4. 監控更新過程

```bash
# 在另一個終端視窗監控日誌
docker compose logs -f

# 監控容器資源
docker stats
```

## ⚠️ 注意事項

1. **更新前務必備份**：更新腳本會自動備份，但建議額外備份資料庫
2. **檢查環境變數**：更新後確認 `.env` 檔案設定正確
3. **測試功能**：更新後測試主要功能是否正常
4. **監控日誌**：更新後持續監控日誌，確認無錯誤
5. **排程任務**：確認排程任務正常執行

## 📝 更新日誌範例

建議記錄每次更新的內容：

```markdown
## 2024-01-15 更新

- 更新 Laravel 依賴
- 新增 YouTube 分析功能
- 修復排程任務問題

執行命令：
```bash
./docker/update.sh
```

結果：✅ 成功
```
