# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Miniverse 是一個外電 AI 影片分析系統,使用 Google Gemini AI 進行智能內容分析。系統自動從外部新聞源 (CNN、AP、Reuters) 抓取影片資源,透過 Gemini AI 分析內容,並提供可視化的儀表板展示分析結果。

## Technology Stack

- **Backend**: Laravel 12.x, PHP 8.4
- **Database**: MySQL 8.0
- **Cache**: Redis 7.x
- **AI**: Google Gemini 2.0 Flash API
- **Storage**: Google Cloud Storage (GCS), NAS
- **Container**: Docker Compose
- **Frontend**: Vite (minimal, mainly for asset compilation)

## Development Commands

### Local Development

```bash
# 安裝依賴
composer install

# 啟動開發伺服器
php artisan serve

# 前端資源編譯
npm run dev        # 開發模式
npm run build      # 生產模式
```

### Docker Development

```bash
# 啟動所有容器
docker compose up -d

# 查看容器狀態
docker compose ps

# 查看應用日誌
docker compose logs -f app

# 進入應用容器
docker compose exec app bash

# 重啟容器
docker compose restart app

# 停止並移除容器
docker compose down
```

### Testing

```bash
# 執行所有測試
docker compose exec app php artisan test

# 或使用 PHPUnit
docker compose exec app ./vendor/bin/phpunit

# 執行特定測試檔案
docker compose exec app php artisan test --filter=VideoTest
```

### Code Quality

```bash
# 程式碼格式化 (Laravel Pint)
docker compose exec app ./vendor/bin/pint

# 或修復特定檔案
docker compose exec app ./vendor/bin/pint app/Services/AnalyzeService.php
```

### Common Artisan Commands

```bash
# === 資源抓取 ===
# 抓取 CNN 資源
docker compose exec app php artisan fetch:cnn --limit=100 --file-type=mp4

# 抓取 AP 資源
docker compose exec app php artisan fetch:ap --limit=50

# 抓取 Reuters 資源
docker compose exec app php artisan fetch:rt --limit=50

# === 內容分析 ===
# 完整分析 (推薦使用,文本+影片一次完成)
docker compose exec app php artisan analyze:full --source=CNN --storage=gcs --limit=10

# 分析 XML 文檔 (僅文本元數據)
docker compose exec app php artisan analyze:document --source=CNN --storage=gcs --limit=10

# 分析影片內容 (僅影片)
docker compose exec app php artisan analyze:video --source=CNN --storage=gcs --limit=5

# === 資料管理 ===
# 清除影片資料
docker compose exec app php artisan video:clear --all
docker compose exec app php artisan video:clear --id=1,2,3

# 重置分析狀態
docker compose exec app php artisan video:reset-status --id=1,2,3

# 恢復卡住的分析任務
docker compose exec app php artisan analysis:recover

# === 維護指令 ===
# 清理臨時檔案
docker compose exec app php artisan cleanup:temp-files

# 清理舊影片資料
docker compose exec app php artisan cleanup:old-videos --days=30

# 緊急清理 (磁碟空間不足時)
docker compose exec app php artisan cleanup:emergency --force

# 清理日誌檔案
docker compose exec app php artisan cleanup:logs --days=7

# === 快取管理 ===
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan route:clear

# === 資料庫 ===
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:rollback
docker compose exec app php artisan migrate:fresh  # 警告:會清空所有資料
```

## Architecture

### Service Layer Pattern

系統使用分層架構,核心業務邏輯集中在 Service 層:

#### 核心服務

1. **AnalyzeService** ([app/Services/AnalyzeService.php](app/Services/AnalyzeService.php))
   - 核心分析服務,協調 Gemini API 和資料儲存
   - 三種分析模式:
     - `executeTextAnalysis()`: 分析文本內容 (XML/TXT)
     - `executeVideoAnalysis()`: 分析影片內容
     - `executeFullAnalysis()`: 綜合分析 (推薦,一次 API 呼叫完成文本+影片分析)
   - 自動處理臨時檔案清理
   - 錯誤訊息過濾 (移除 API key 等敏感資訊)

2. **GeminiClient** ([app/Services/GeminiClient.php](app/Services/GeminiClient.php))
   - 封裝與 Google Gemini API 的通訊
   - 支援文本分析 (`analyzeText()`) 和影片分析 (`analyzeVideo()`)
   - 使用 v1beta API 版本以支援 JSON response MIME type
   - 設定 300 秒 timeout 以處理大型影片

3. **StorageService** ([app/Services/StorageService.php](app/Services/StorageService.php))
   - 抽象儲存層,統一處理不同儲存類型
   - 支援的儲存類型: `nas`, `gcs`, `s3`, `local`
   - 使用 Laravel Filesystem 抽象層
   - 提供檔案掃描、上傳、下載等功能

4. **PromptService** ([app/Services/PromptService.php](app/Services/PromptService.php))
   - 管理 Gemini API prompt 模板
   - 支援版本控制,可指定使用特定版本的 prompt
   - Prompt 檔案位於 `storage/app/prompts/` 目錄

5. **FetchService Interface** ([app/Services/FetchServiceInterface.php](app/Services/FetchServiceInterface.php))
   - 定義資源抓取服務的統一介面
   - 實作類別:
     - [CnnFetchService](app/Services/Sources/CnnFetchService.php)
     - [ApFetchService](app/Services/Sources/ApFetchService.php)
     - [RtFetchService](app/Services/Sources/RtFetchService.php)
     - [YoutubeFetchService](app/Services/Sources/YoutubeFetchService.php)

### Repository Layer

Repository 負責資料訪問邏輯:

- **VideoRepository** ([app/Repositories/VideoRepository.php](app/Repositories/VideoRepository.php))
  - 影片資料的 CRUD 操作
  - 狀態更新 (`updateAnalysisStatus()`)
  - 查詢方法 (依來源、狀態等條件查詢)

- **AnalysisResultRepository** ([app/Repositories/AnalysisResultRepository.php](app/Repositories/AnalysisResultRepository.php))
  - 分析結果的儲存和查詢
  - 支援 JSON 欄位的自動處理

### Models

- **Video** ([app/Models/Video.php](app/Models/Video.php))
  - 主要欄位: `source_name`, `source_id`, `nas_path`, `analysis_status`, `analyzed_at`
  - 使用 Enum: `AnalysisStatus`
  - 關聯: `hasOne(AnalysisResult::class)`
  - JSON casting: `subjects`, `source_metadata`

- **AnalysisResult** ([app/Models/AnalysisResult.php](app/Models/AnalysisResult.php))
  - 儲存 Gemini 分析結果
  - JSON 欄位: `bites`, `mentioned_locations`, `importance_score`, `topics`, `keywords`
  - 關聯: `belongsTo(Video::class)`

### Enums

- **AnalysisStatus** ([app/Enums/AnalysisStatus.php](app/Enums/AnalysisStatus.php))
  - `PENDING`: 待分析
  - `PROCESSING`: 分析中
  - `COMPLETED`: 已完成
  - `DOCUMENT_ANALYSIS_FAILED`: 文檔分析失敗
  - `VIDEO_ANALYSIS_FAILED`: 影片分析失敗

- **SyncStatus** ([app/Enums/SyncStatus.php](app/Enums/SyncStatus.php))
  - 用於追蹤資料同步狀態

### Service Provider Registration

**SourceServiceProvider** ([app/Providers/SourceServiceProvider.php](app/Providers/SourceServiceProvider.php)) 負責註冊所有資源抓取服務為 singleton,確保整個應用生命週期中只有一個實例。

## Scheduled Tasks (排程系統)

排程任務定義於 [routes/console.php](routes/console.php),透過 Laravel Scheduler 執行。

### 排程開關控制

- `SCHEDULER_ENABLED`: 主開關,控制所有排程是否執行
- `ANALYZE_DOCUMENT_ENABLED`: 控制文檔分析任務
- `ANALYZE_VIDEO_ENABLED`: 控制影片分析任務
- `ANALYZE_FULL_ENABLED`: 控制完整分析任務
- `CLEANUP_OLD_VIDEOS_ENABLED`: 控制舊影片清理任務

### 主要排程任務

1. **資源抓取** (每 30 分鐘)
   ```
   fetch:cnn --group-by=unique-id --limit=500 --file-type=all
   ```

2. **完整分析** (每 2 分鐘)
   ```
   analyze:full --source=CNN --storage=gcs --limit=1
   ```

3. **恢復卡住的任務** (每 10 分鐘)
   ```
   analysis:recover --timeout=3600 --mode=delete
   ```

4. **臨時檔案清理** (每小時)
   - 保留 2 小時內的檔案,避免與分析任務衝突

5. **舊影片資料清理** (每天凌晨 2:00)
   ```
   cleanup:old-videos --days=14 --field=analyzed_at --force
   ```

6. **日誌清理** (每天凌晨 3:00)
   ```
   cleanup:logs --days=3 --max-size=50
   ```

7. **緊急清理檢查** (每 6 小時)
   - 當磁碟使用率超過 85% 時自動執行緊急清理

### Gemini API 配額管理

根據 [Google Gemini API 配額限制](https://docs.cloud.google.com/gemini/docs/quotas):
- **RPS 限制**: 2 requests/second
- **每日請求數**: 960 requests/day

排程策略:
- `analyze:full` 命令內部實作 `sleep(1)` 確保 RPS < 1
- 每 2 分鐘執行 1 個請求,避免超過配額
- 建議每日總請求數控制在 600-700 次以下,留有餘裕

## API Response Format

系統使用統一的 API 回應格式:

### 成功回應

```json
{
    "status": "00000",
    "message": "success",
    "data": []
}
```

### 錯誤回應

```json
{
    "status": "99999",
    "message": "server error.",
    "data": []
}
```

## Routing

### Web Routes ([routes/web.php](routes/web.php))

- `GET /` 或 `/dashboard`: 主儀表板頁面
- `GET /export`: 匯出分析資料
- `GET /status`: 系統狀態頁面
- `GET /gcs-proxy/{path}`: GCS 代理,用於影片串流/下載
- `GET /storage/app/{path}`: 本地儲存檔案服務 (帶有路徑遍歷防護)

### API Routes ([routes/api.php](routes/api.php))

- `GET /api/user`: 取得目前使用者資訊 (需 Sanctum 認證)

## Configuration

### 重要環境變數

```env
# Gemini API
GEMINI_API_KEY=your-api-key
GEMINI_TEXT_MODEL=gemini-2.0-flash-exp
GEMINI_VIDEO_MODEL=gemini-2.0-flash-exp

# GCS 配置
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name

# 排程開關
SCHEDULER_ENABLED=true
ANALYZE_FULL_ENABLED=true
CLEANUP_OLD_VIDEOS_ENABLED=true

# 資料庫
DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=miniverse
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 配置檔案位置

- `config/sources.php`: 各新聞源配置 (CNN, AP, RT, YouTube)
- `config/filesystems.php`: 儲存配置 (NAS, GCS, S3)
- `config/services.php`: 第三方服務配置

## Deployment

### 統一部署腳本 (推薦)

專案已整合為統一的部署腳本 ([scripts/deploy.sh](scripts/deploy.sh)),提供一鍵部署功能:

```bash
# === 開發環境部署 ===
./scripts/deploy.sh                    # 智能偵測是否需要重建 Docker
./scripts/deploy.sh --quick            # 快速部署 (跳過 Docker 重建)
./scripts/deploy.sh --rebuild          # 強制重建 Docker 映像

# === 生產環境部署 ===
./scripts/deploy.sh --env=production   # 生產環境完整部署

# === 帶 Git Pull 的部署 ===
./scripts/deploy.sh --pull             # 先 git pull 再部署
./scripts/deploy.sh --pull --quick     # git pull + 快速部署

# 或使用專用的更新並部署腳本
./scripts/deployment/update-and-deploy.sh

# === 檢查狀態 ===
./scripts/deploy.sh --check            # 檢查系統狀態

# === 向後兼容 ===
./deploy.sh --env=production           # 根目錄的 deploy.sh 會轉發到新腳本
```

### 部署腳本特性

1. **智能偵測重建**: 自動檢測 Dockerfile、docker-compose.yml、composer.json 變更,決定是否需要重建
2. **自動空間管理**: 當磁碟使用率超過 85% 時自動執行清理
3. **參數向後兼容**: 舊的 `--skip-build` 參數會自動轉換為 `--quick`
4. **環境區分**: 開發環境和生產環境使用不同的優化策略

### 清理腳本 (緊急清理功能)

統一清理腳本 ([scripts/cleanup.sh](scripts/cleanup.sh)) 提供多種清理模式:

```bash
# === 清理模式 ===
./scripts/cleanup.sh quick             # 快速清理 (Docker 基本資源)
./scripts/cleanup.sh full              # 完整清理 (Docker + 應用 + 系統)
./scripts/cleanup.sh emergency         # 緊急清理 (強制清理所有,包括 volumes)
./scripts/cleanup.sh interactive       # 互動式選擇清理項目
./scripts/cleanup.sh auto              # 自動模式 (根據磁碟使用率決定)
```

### 清理腳本功能

- **Quick**: 清理 Docker 構建緩存、系統資源、應用臨時檔案
- **Full**: Quick + Docker 映像、舊日誌、系統日誌、APT 快取
- **Emergency**: Full + Docker volumes (危險,需確認)
- **Interactive**: 手動選擇清理項目
- **Auto**: 根據磁碟使用率自動決定清理程度:
  - 70-80%: 快速清理
  - 80-90%: 完整清理
  - 90%+: 緊急清理

### 手動部署步驟 (如不使用腳本)

```bash
# 1. 更新程式碼
git pull origin main

# 2. 停止並重建容器 (如需要)
docker compose down
docker compose build --pull app
docker compose up -d

# 3. 更新依賴
docker compose exec app composer install --no-dev --optimize-autoloader

# 4. 執行資料庫遷移
docker compose exec app php artisan migrate --force

# 5. 清除快取
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# 6. 優化 (生產環境)
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app composer dump-autoload --optimize --classmap-authoritative
```

## Debugging & Maintenance

### 除錯腳本

```bash
# 檢查排程狀態
./scripts/debugging/check-scheduler.sh

# 檢查 Supervisor 狀態
./scripts/debugging/check-supervisor.sh

# 檢查 GCS 代理錯誤
./scripts/debugging/check-gcs-proxy.sh
```

### 維護腳本

```bash
# 統一清理腳本 (推薦)
./scripts/cleanup.sh auto              # 自動判斷並清理
./scripts/cleanup.sh quick             # 快速清理

# 修復 Git 權限問題
./scripts/deployment/fix-permissions.sh

# 診斷腳本
./scripts/docker/diagnose-space.sh     # 診斷磁碟空間問題
./scripts/docker/diagnose-php-fpm.sh   # 診斷 PHP-FPM 問題
```

### 常見問題處理

1. **排程未執行**
   ```bash
   ./scripts/deploy.sh --check        # 檢查狀態
   # 確認 .env 中的 SCHEDULER_ENABLED=true
   # 檢查 Supervisor: docker compose exec app supervisorctl status
   ```

2. **Gemini API 配額超限**
   - 降低 `analyze:full` 的 `--limit` 參數
   - 增加執行間隔 (修改 routes/console.php)
   - 監控每日請求數

3. **磁碟空間不足**
   ```bash
   ./scripts/cleanup.sh emergency     # 緊急清理
   # 或使用自動模式
   ./scripts/cleanup.sh auto
   ```

4. **GCS 訪問問題**
   - 確認 GCS credentials 檔案存在
   - 檢查權限: `ls -la config/`
   - 測試連接: `docker compose exec app php artisan tinker`

5. **影片分析卡住**
   ```bash
   docker compose exec app php artisan analysis:recover
   ```

6. **Docker 構建失敗 (空間不足)**
   ```bash
   ./scripts/cleanup.sh quick         # 快速清理
   ./scripts/deploy.sh --rebuild      # 重新構建
   ```

## Important Notes

### 分析模式選擇

- **優先使用 `analyze:full`**: 這是推薦的分析方式,在單次 Gemini API 呼叫中完成文本和影片分析,效率更高且結果更一致
- **分別使用 `analyze:document` + `analyze:video`**: 僅在需要分開處理時使用

### Gemini API 最佳實踐

- 在命令中加入 rate limiting (如 `sleep(1)`) 避免超過 RPS 限制
- 監控每日請求數,留有緩衝空間
- 處理 API 錯誤時要過濾敏感資訊 (API keys)

### 臨時檔案管理

- GCS/S3 下載的影片會暫存於 `storage/app/temp/`
- AnalyzeService 會在 `finally` block 中自動清理臨時檔案
- 排程系統每小時額外執行清理,保留 2 小時內的檔案

### Docker 容器管理

- 使用 Supervisor 管理 Laravel Scheduler worker
- 優雅關閉策略:參考 [docs/GRACEFUL_SHUTDOWN.md](docs/GRACEFUL_SHUTDOWN.md)
- 部署前檢查清單:參考 [docs/DEPLOYMENT_CHECKLIST.md](docs/DEPLOYMENT_CHECKLIST.md)

## Documentation

完整文檔位於 `docs/` 目錄:

- [CNN_FLOW.md](docs/CNN_FLOW.md): CNN 資源處理流程
- [DATABASE_ACCESS.md](docs/DATABASE_ACCESS.md): 資料庫訪問指南
- [DEPLOYMENT_CHECKLIST.md](docs/DEPLOYMENT_CHECKLIST.md): 部署檢查清單
- [GRACEFUL_SHUTDOWN.md](docs/GRACEFUL_SHUTDOWN.md): 優雅關閉指南
