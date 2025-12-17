# 📺 Miniverse - 外電 AI 影片分析系統

自動化的外電影片抓取、分析和管理平台，使用 Google Gemini AI 進行智能內容分析。

[![PHP](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-blue.svg)](https://www.mysql.com/)

---

## 📋 目錄

- [快速開始](#快速開始)
- [系統架構](#系統架構)
- [部署指南](#部署指南)
- [開發規範](#開發規範)
- [文檔索引](#文檔索引)
- [腳本工具](#腳本工具)
- [常見問題](#常見問題)

---

## 🚀 快速開始

### 本地開發環境

```bash
# 1. 克隆項目
git clone https://github.com/Kyosukewu/web-miniverse.git
cd web-miniverse

# 2. 環境配置
cp .env.example .env
php artisan key:generate

# 3. 安裝依賴
composer install

# 4. 資料庫遷移
php artisan migrate

# 5. 啟動服務
php artisan serve
```

### Docker 部署（推薦）

```bash
# 啟動所有容器
docker compose up -d

# 查看狀態
docker compose ps

# 查看日誌
docker compose logs -f app
```

---

## 🏗️ 系統架構

### 技術棧

| 組件 | 技術 | 版本 |
|------|------|------|
| **後端框架** | Laravel | 12.x |
| **語言** | PHP | 8.4 |
| **資料庫** | MySQL | 8.0 |
| **快取** | Redis | 7.x |
| **Web 伺服器** | Nginx | Alpine |
| **容器化** | Docker Compose | - |
| **AI 分析** | Google Gemini | 2.0 Flash |
| **儲存** | Google Cloud Storage | - |

### 核心功能

1. **🔄 自動資源抓取** - 從外部源（CNN、AP、Reuters 等）自動抓取影片資源
2. **🤖 AI 智能分析** - 使用 Gemini AI 分析影片內容、提取關鍵信息
3. **📊 可視化儀表板** - 提供友好的 Web 界面展示分析結果
4. **☁️ 雲端儲存** - 整合 GCS 進行大規模媒體文件管理
5. **⏰ 自動化排程** - Laravel Scheduler + Supervisor 管理定時任務

### 目錄結構

```
web-miniverse/
├── app/                          # Laravel 應用核心
│   ├── Console/Commands/        # Artisan 指令
│   ├── Http/Controllers/        # HTTP 控制器
│   ├── Services/                # 業務邏輯服務
│   │   └── Sources/            # 資源抓取服務
│   ├── Repositories/            # 資料訪問層
│   └── Models/                  # Eloquent 模型
├── docker/                       # Docker 相關配置
│   ├── nginx.conf               # Nginx 配置
│   ├── supervisord.conf         # Supervisor 配置
│   └── entrypoint.sh            # 容器啟動腳本
├── docs/                         # 📚 項目文檔
│   ├── CNN_FLOW.md              # CNN 資源處理流程
│   ├── DATABASE_ACCESS.md       # 資料庫訪問指南
│   ├── DEPLOYMENT_CHECKLIST.md # 部署檢查清單
│   └── GRACEFUL_SHUTDOWN.md     # 優雅關閉指南
├── scripts/                      # 🔧 工具腳本
│   ├── deployment/              # 部署相關腳本
│   ├── maintenance/             # 維護相關腳本
│   └── debugging/               # 除錯相關腳本
├── routes/                       # 路由定義
│   ├── web.php                  # Web 路由
│   ├── api.php                  # API 路由
│   └── console.php              # 排程任務
├── deploy.sh                     # 🚀 主部署腳本
└── docker-compose.yml            # Docker Compose 配置
```

---

## 🚢 部署指南

### 生產環境部署

```bash
# 在 EC2 或其他服務器上執行
./deploy.sh --env=production

# 或者只更新代碼（不重建 Docker 鏡像）
./scripts/deployment/update-and-deploy.sh --skip-build
```

### 開發環境測試

```bash
# 重建容器並測試排程
./deploy.sh --env=development

# 只檢查排程狀態
./deploy.sh --check
```

### 環境變數配置

關鍵環境變數（`.env` 文件）：

```env
# 應用基本配置
APP_ENV=production
APP_DEBUG=false
APP_URL=https://miniverse.tvbs-internal.com.tw

# 資料庫配置
DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=miniverse
DB_USERNAME=root
DB_PASSWORD=your_password

# GCS 配置
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name

# Gemini API
GEMINI_API_KEY=your-gemini-api-key

# 排程開關
SCHEDULER_ENABLED=true
```

---

## 📝 開發規範

### 命名規則

1. **Controllers**: `{名稱}Controller`
   - 範例: `DashboardController`

2. **Services**: `{名稱}Service`
   - 範例: `AnalyzeService`, `StorageService`

3. **Repositories**: `{名稱}Repository`
   - 範例: `VideoRepository`

4. **Models**: `{名稱}`（單數形式）
   - 範例: `Video`, `AnalysisResult`

### API 回傳格式

#### 成功回應

```json
{
    "status": "00000",
    "message": "success",
    "data": []
}
```

#### 錯誤回應

```json
{
    "status": "99999",
    "message": "server error.",
    "data": []
}
```

### Git 分支策略

- **main**: 生產環境分支
- **develop**: 開發分支
- **feature/***: 功能分支

### 代碼提交規範

```bash
# 功能開發
git commit -m "feat: 新增影片下載功能"

# Bug 修復
git commit -m "fix: 修正 GCS 權限問題"

# 文檔更新
git commit -m "docs: 更新部署指南"

# 性能優化
git commit -m "perf: 優化影片分析性能"
```

---

## 📚 文檔索引

### 核心文檔

| 文檔 | 說明 | 路徑 |
|------|------|------|
| 📺 **CNN 處理流程** | CNN 資源抓取和處理的完整流程 | [`docs/CNN_FLOW.md`](docs/CNN_FLOW.md) |
| 🗄️ **資料庫訪問指南** | 如何連接和管理資料庫 | [`docs/DATABASE_ACCESS.md`](docs/DATABASE_ACCESS.md) |
| ✅ **部署檢查清單** | 生產環境部署前的檢查項目 | [`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md) |
| 🛡️ **優雅關閉指南** | 確保排程任務安全停止的指南 | [`docs/GRACEFUL_SHUTDOWN.md`](docs/GRACEFUL_SHUTDOWN.md) |

### Docker 相關文檔

- [`docker/README.md`](docker/README.md) - Docker 環境配置說明

---

## 🔧 腳本工具

### 部署腳本

| 腳本 | 用途 | 使用方法 |
|------|------|----------|
| **deploy.sh** | 主部署腳本（生產/開發） | `./deploy.sh --env=production` |
| **update-and-deploy.sh** | 更新代碼並部署 | `./scripts/deployment/update-and-deploy.sh` |
| **fix-permissions.sh** | 修復 Git 權限問題 | `./scripts/deployment/fix-permissions.sh` |

### 維護腳本

| 腳本 | 用途 | 使用方法 |
|------|------|----------|
| **disk-cleanup.sh** | 清理磁盤空間 | `./scripts/maintenance/disk-cleanup.sh` |

### 除錯腳本

| 腳本 | 用途 | 使用方法 |
|------|------|----------|
| **check-scheduler.sh** | 檢查排程狀態 | `./scripts/debugging/check-scheduler.sh` |
| **check-supervisor.sh** | 檢查 Supervisor 狀態 | `./scripts/debugging/check-supervisor.sh` |
| **check-gcs-proxy.sh** | 檢查 GCS 代理錯誤 | `./scripts/debugging/check-gcs-proxy.sh` |

---

## 🎯 常用 Artisan 指令

### 資源抓取

```bash
# 抓取 CNN 資源
docker compose exec app php artisan fetch:cnn --limit=100 --file-type=mp4

# 抓取 AP 資源
docker compose exec app php artisan fetch:ap --limit=50

# 抓取 Reuters 資源
docker compose exec app php artisan fetch:rt --limit=50
```

### 內容分析

```bash
# 分析 XML 文檔
docker compose exec app php artisan analyze:document --source=CNN --storage=gcs --limit=10

# 分析影片內容
docker compose exec app php artisan analyze:video --source=CNN --storage=gcs --limit=5
```

### 資料管理

```bash
# 清除影片資料
docker compose exec app php artisan video:clear --all
docker compose exec app php artisan video:clear --id=1,2,3

# 重置分析狀態
docker compose exec app php artisan video:reset-status --id=1,2,3

# 恢復卡住的分析任務
docker compose exec app php artisan analysis:recover
```

### 維護指令

```bash
# 清理臨時文件
docker compose exec app php artisan cleanup:temp-files

# 清理舊影片資料（30 天前）
docker compose exec app php artisan cleanup:old-videos --days=30

# 清除快取
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
```

---

## ❓ 常見問題

### Q1: 容器啟動失敗？

```bash
# 檢查容器狀態
docker compose ps

# 查看詳細日誌
docker compose logs -f app

# 重啟容器
docker compose restart app
```

### Q2: 權限問題？

```bash
# 執行權限修復腳本
./scripts/deployment/fix-permissions.sh

# 或手動修復
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### Q3: 磁盤空間不足？

```bash
# 執行磁盤清理
./scripts/maintenance/disk-cleanup.sh

# 或手動清理
docker system prune -af --volumes
docker compose exec app php artisan cleanup:temp-files
```

### Q4: 排程未運行？

```bash
# 檢查排程狀態
./scripts/debugging/check-scheduler.sh

# 檢查 Supervisor 狀態
./scripts/debugging/check-supervisor.sh

# 確保環境變數已設置
# .env 中: SCHEDULER_ENABLED=true
```

### Q5: GCS 訪問問題？

```bash
# 檢查 GCS 配置
docker compose exec app php artisan tinker --execute="
echo 'GCS Bucket: ' . config('filesystems.disks.gcs.bucket') . PHP_EOL;
echo 'Project ID: ' . config('filesystems.disks.gcs.project_id') . PHP_EOL;
"

# 檢查 GCS 代理錯誤
./scripts/debugging/check-gcs-proxy.sh
```

---

## 🤝 貢獻指南

1. Fork 本項目
2. 創建特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'feat: 添加某個功能'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 開啟 Pull Request

---

## 📄 授權

本項目僅供 TVBS 內部使用。

---

## 📮 聯絡方式

- **項目維護**: TVBS 技術團隊
- **問題回報**: 請使用 GitHub Issues

---

## 🔄 版本歷史

- **v2.0.0** (2025-12) - 整合 Gemini 2.0 Flash，優化磁盤管理
- **v1.5.0** (2025-11) - 添加多源支持（AP、Reuters）
- **v1.0.0** (2025-10) - 初始版本，支持 CNN 資源處理

---

<div align="center">
  <sub>Built with ❤️ by TVBS Tech Team</sub>
</div>
