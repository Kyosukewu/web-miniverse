# 部署檢查清單與設定指南

## 📋 主機前置需求

### 1. 必須安裝的軟體

#### Docker（必要）
```bash
# Ubuntu/Debian
sudo apt-get update
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo systemctl start docker
sudo systemctl enable docker
sudo usermod -aG docker $USER
newgrp docker

# 驗證安裝
docker --version
```

#### Docker Compose（必要）
```bash
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
docker-compose --version
```

### 2. 可選但建議安裝的軟體

#### Git（用於程式碼部署）
```bash
# Ubuntu/Debian
sudo apt-get install git -y

# Amazon Linux
sudo yum install git -y
```

#### 基本工具
```bash
# Ubuntu/Debian
sudo apt-get install -y curl wget nano vim
```

## 🔧 主機系統需求

### 最低需求
- **CPU**: 2 核心
- **記憶體**: 4GB RAM
- **硬碟**: 20GB 可用空間
- **作業系統**: Ubuntu 22.04 LTS 或 Amazon Linux 2023

### 建議需求
- **CPU**: 4 核心
- **記憶體**: 8GB RAM
- **硬碟**: 50GB 可用空間（包含資料庫和日誌）

### 網路需求
- 開放端口：
  - `80` (HTTP)
  - `443` (HTTPS)
  - `22` (SSH)
  - `3306` (MySQL，可選，僅內部使用)
  - `8080` (phpMyAdmin，可選)

## 📁 CNN 資源路徑映射評估

### 當前情況
- CNN 資源位於主機的 `/mnt/PushDownloads`
- `CnnFetchService` 需要讀取此路徑的檔案
- 檔案需要移動到 GCS（或 S3）

### 方案評估

#### 方案 A：直接映射到容器（推薦）✅

**優點**：
- ✅ 簡單直接，無需額外配置
- ✅ 容器可以直接讀取主機檔案
- ✅ 檔案移動操作在容器內完成，邏輯統一
- ✅ 不影響主機檔案系統結構

**缺點**：
- ⚠️ 需要確保容器有讀取權限
- ⚠️ 需要確保容器有刪除權限（移動後刪除本地檔案）

**實作方式**：
```yaml
# docker-compose.yml
services:
  app:
    volumes:
      - /mnt/PushDownloads:/mnt/PushDownloads:ro  # 只讀映射（如果只讀取）
      # 或
      - /mnt/PushDownloads:/mnt/PushDownloads:rw  # 讀寫映射（如果需要刪除）
```

**建議**：使用 `rw`（讀寫）映射，因為 `CnnFetchService` 在移動檔案到 GCS 後會刪除本地檔案。

#### 方案 B：使用符號連結

**優點**：
- ✅ 保持專案目錄結構
- ✅ 可以靈活切換來源路徑

**缺點**：
- ⚠️ 需要額外設定
- ⚠️ 容器內路徑可能不一致

**實作方式**：
```bash
# 在主機上建立符號連結
ln -s /mnt/PushDownloads /var/www/web-miniverse/storage/cnn-source
```

#### 方案 C：複製檔案到專案目錄（不推薦）❌

**缺點**：
- ❌ 浪費硬碟空間（重複檔案）
- ❌ 需要額外的同步機制
- ❌ 增加維護複雜度

### 推薦方案：方案 A（直接映射）

**理由**：
1. **簡單直接**：最少的配置，最少的維護
2. **效能最佳**：直接存取，無需複製
3. **符合現有邏輯**：`CnnFetchService` 已經設計為直接讀取 `/mnt/PushDownloads`
4. **安全性可控**：可以設定只讀或讀寫權限

## 🚀 完整部署步驟

### 步驟 0: 準備 GitHub Personal Access Token（如果從 GitHub 部署）

如果您需要從 GitHub 私有 repository 部署，需要先建立 Personal Access Token：

#### 建立步驟：

1. **前往 GitHub 設定頁面**
   - 點擊右上角頭像 → **Settings**
   - 或直接前往：https://github.com/settings/profile

2. **進入 Developer settings**
   - 左側選單最下方 → **Developer settings**

3. **建立 Personal Access Token**
   - 點擊 **Personal access tokens** → **Tokens (classic)**
   - 點擊 **Generate new token** → **Generate new token (classic)**

4. **設定 Token 資訊**
   - **Note**（備註）：例如 "EC2 Deployment Token"
   - **Expiration**（過期時間）：建議設定較長時間（如 90 天或 1 年）
   - **Select scopes**（權限範圍）：
     - ✅ **repo** - 完整 repository 存取權限（必須勾選）

5. **生成並複製 Token**
   - 點擊 **Generate token**
   - ⚠️ **重要**：Token 只會顯示一次，請立即複製並妥善保存！
   - 格式類似：`ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

#### 使用方式：

```bash
# 在 EC2 上設定環境變數
export GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# 使用 token clone 專案
git clone https://${GITHUB_TOKEN}@github.com/username/web-miniverse.git
```

**安全提醒**：
- 不要將 Token 提交到 Git
- 不要公開分享 Token
- 定期更新 Token（建議每 90 天）

### 步驟 1: 安裝 Docker 和 Docker Compose

參考上方「必須安裝的軟體」章節。

### 步驟 2: Clone 專案

#### 方法 A：從 GitHub Clone（需要 Token）

```bash
# 建立專案目錄
sudo mkdir -p /var/www/html/web-miniverse
sudo chown $USER:$USER /var/www/html/web-miniverse
cd /var/www/html/web-miniverse

# Clone 專案（使用 GitHub Token）
export GITHUB_TOKEN=your_token_here
git clone https://${GITHUB_TOKEN}@github.com/username/web-miniverse.git .
```

#### 方法 B：使用自動化部署腳本（EC2 推薦）

```bash
# 1. 連接到 EC2
ssh -i your-key.pem ec2-user@your-ec2-ip

# 2. Clone 專案並執行部署腳本
export GITHUB_TOKEN=your_token_here
git clone https://${GITHUB_TOKEN}@github.com/username/web-miniverse.git /tmp/web-miniverse
cp /tmp/web-miniverse/docker/deploy-ec2.sh ./
chmod +x deploy-ec2.sh
export GITHUB_REPO=https://github.com/username/web-miniverse.git
sudo ./deploy-ec2.sh
```

部署腳本會自動完成所有步驟，包括安裝 Docker、構建容器、初始化 Laravel。

### 步驟 3: 更新 docker-compose.yml

添加 CNN 資源路徑映射：

```yaml
services:
  app:
    volumes:
      - ./:/var/www/html/web-miniverse
      - ./storage:/var/www/html/web-miniverse/storage
      - ./bootstrap/cache:/var/www/html/web-miniverse/bootstrap/cache
      # CNN 資源路徑映射（讀寫，因為需要刪除檔案）
      - /mnt/PushDownloads:/mnt/PushDownloads:rw
```

### 步驟 4: 設定環境變數

```bash
cp .env.example .env
nano .env
```

**重要環境變數**：
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://miniverse.com.tw  # 或 http://miniverse.com.tw（如果暫時不使用 HTTPS）

# 資料庫設定
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=web_miniverse
DB_USERNAME=root
DB_PASSWORD=your_secure_password

# CNN 設定（暫時跳過 GCS，使用本地路徑）
CNN_STORAGE_TYPE=local
CNN_SOURCE_PATH=/mnt/PushDownloads

# Gemini API
GEMINI_API_KEY=your_gemini_api_key

# GCS 設定（如果需要使用 Google Cloud Storage）
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name
GOOGLE_CLOUD_KEY_FILE=/var/www/html/web-miniverse/storage/app/gcs-key.json
```

### 步驟 4.5: 設定 GCS（Google Cloud Storage）

如果您需要使用 Google Cloud Storage 來儲存 CNN 資源，需要完成以下設定：

#### 4.5.1 建立 GCS Service Account

1. 前往 [Google Cloud Console](https://console.cloud.google.com/)
2. 選擇專案或建立新專案
3. 啟用 Cloud Storage API
4. 建立 Service Account：
   - IAM & Admin → Service Accounts
   - Create Service Account
   - 設定名稱和描述
   - 授予角色：`Storage Object Viewer`（讀取）或 `Storage Object Admin`（讀寫）
5. 建立金鑰：
   - 點擊 Service Account
   - Keys → Add Key → Create new key
   - 選擇 JSON 格式
   - 下載金鑰檔案

#### 4.5.2 放置 GCS 金鑰檔案

將下載的 JSON 金鑰檔案放到指定位置：

```bash
# 在主機上建立 storage/app 目錄（如果不存在）
mkdir -p /var/www/html/web-miniverse/storage/app

# 將 GCS 金鑰檔案複製到指定位置
# 方法 1: 使用 scp（從其他機器複製）
# scp gcs-key.json user@server:/var/www/html/web-miniverse/storage/app/

# 方法 2: 使用 SFTP 或其他檔案傳輸工具上傳

# 設定適當的權限（確保容器可以讀取）
chmod 600 /var/www/html/web-miniverse/storage/app/gcs-key.json
chown $USER:$USER /var/www/html/web-miniverse/storage/app/gcs-key.json
```

**重要提醒**：
- 金鑰檔案是敏感資訊，請確保：
  - 不要提交到 Git（已在 `.gitignore` 中排除）
  - 使用安全的傳輸方式（scp、sftp 等）
  - 設定適當的檔案權限（建議 600）
- 如果暫時不使用 GCS，可以跳過此步驟
- 金鑰檔案格式請參考 `config/gcs-key.json.example`

#### 4.5.3 更新環境變數

確保 `.env` 檔案中包含 GCS 相關設定（已在步驟 4 中設定）：
```env
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name
GOOGLE_CLOUD_KEY_FILE=/var/www/html/web-miniverse/storage/app/gcs-key.json
CNN_STORAGE_TYPE=gcs
CNN_GCS_BUCKET=your-bucket-name
CNN_GCS_PATH=cnn/
```

### 步驟 5: 設定檔案權限

```bash
# 確保容器可以讀寫 /mnt/PushDownloads
# 檢查當前權限
ls -la /mnt/PushDownloads

# 如果需要，調整權限（謹慎操作）
# sudo chmod -R 755 /mnt/PushDownloads
# sudo chown -R www-data:www-data /mnt/PushDownloads
```

**注意**：如果 `/mnt/PushDownloads` 是掛載的網路磁碟或特殊權限，可能需要：
1. 將執行 Docker 的用戶加入適當的群組
2. 或使用 `sudo` 執行 Docker 命令（不推薦）

### 步驟 6: 構建並啟動容器

```bash
# 構建映像
docker-compose build

# 啟動容器
docker-compose up -d

# 查看狀態
docker-compose ps
```

### 步驟 7: 初始化 Laravel

```bash
# 安裝依賴
docker-compose exec app composer install --no-dev --optimize-autoloader

# 產生應用程式金鑰
docker-compose exec app php artisan key:generate

# 執行資料庫遷移
docker-compose exec app php artisan migrate --force

# 建立儲存連結
docker-compose exec app php artisan storage:link

# 清除快取
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
```

### 步驟 8: 驗證部署

```bash
# 檢查容器狀態
docker-compose ps

# 檢查排程任務
docker-compose exec app supervisorctl status

# 測試 CNN 資源讀取
docker-compose exec app ls -la /mnt/PushDownloads

# 測試 CNN 抓取命令
docker-compose exec app php artisan fetch:cnn
```

## 🔒 安全建議

### 1. 檔案權限設定

```bash
# 確保專案目錄權限正確
sudo chown -R $USER:$USER /var/www/web-miniverse
chmod -R 755 /var/www/web-miniverse
chmod -R 775 /var/www/web-miniverse/storage
chmod -R 775 /var/www/web-miniverse/bootstrap/cache
```

### 2. 防火牆設定

```bash
# Ubuntu/Debian
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 3. 環境變數安全

- 不要將敏感資訊提交到 Git
- 使用 `.env` 檔案管理環境變數
- 確保 `.env` 檔案權限：`chmod 600 .env`

## 📊 監控與維護

### 查看日誌

```bash
# 所有服務日誌
docker-compose logs -f

# 特定服務日誌
docker-compose logs -f app
docker-compose logs -f db

# Laravel 日誌
docker-compose exec app tail -f storage/logs/laravel.log
```

### 定期維護

```bash
# 清理未使用的 Docker 資源
docker system prune -a

# 備份資料庫
docker-compose exec db mysqldump -u root -p web_miniverse > backup_$(date +%Y%m%d).sql
```

## 🐛 常見問題

### 問題 1: 無法讀取 /mnt/PushDownloads

**解決方案**：
```bash
# 檢查權限
ls -la /mnt/PushDownloads

# 檢查容器內路徑
docker-compose exec app ls -la /mnt/PushDownloads

# 如果權限不足，調整映射方式或權限
```

### 問題 2: 容器無法刪除檔案

**解決方案**：
- 確保使用 `rw`（讀寫）映射而非 `ro`（只讀）
- 檢查主機檔案權限
- 檢查 SELinux 設定（如果啟用）

### 問題 3: 排程任務未執行

**解決方案**：
```bash
# 檢查 Supervisord
docker-compose exec app supervisorctl status

# 手動執行排程
docker-compose exec app php artisan schedule:run

# 查看排程日誌
docker-compose exec app tail -f /var/log/supervisor/scheduler.log
```

## 📝 部署檢查清單

### 部署前
- [ ] Docker 已安裝並運行
- [ ] Docker Compose 已安裝
- [ ] 專案已 Clone 到主機
- [ ] `.env` 檔案已設定
- [ ] `/mnt/PushDownloads` 路徑存在且可存取
- [ ] 資料庫密碼已設定
- [ ] Gemini API Key 已準備

### 部署後
- [ ] 所有容器正常運行 (`docker-compose ps`)
- [ ] 資料庫連線正常
- [ ] 排程任務正常執行 (`supervisorctl status`)
- [ ] CNN 資源路徑可讀取 (`ls /mnt/PushDownloads`)
- [ ] 測試命令可執行 (`php artisan fetch:cnn`)
- [ ] 網站可正常訪問

