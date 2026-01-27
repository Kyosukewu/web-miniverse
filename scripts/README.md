# 🔧 Miniverse 工具腳本

本目錄包含用於部署、維護和除錯的統一工具腳本。

---

## 📋 目錄

- [統一腳本 (推薦)](#統一腳本-推薦)
- [目錄結構](#目錄結構)
- [使用指南](#使用指南)
- [最佳實踐](#最佳實踐)
- [注意事項](#注意事項)

---

## 🎯 統一腳本 (推薦)

### deploy.sh - 統一部署腳本

**用途**: 提供一鍵部署功能,支持智能偵測重建、自動空間管理等功能

**特性**:
- ✅ 智能偵測 Dockerfile/docker-compose.yml/composer.json 變更
- ✅ 自動空間管理 (磁碟使用率 > 85% 時自動清理)
- ✅ 支持快速部署、強制重建、Git pull 等模式
- ✅ 統一的狀態檢查功能

**使用方法**:
```bash
# 開發環境部署 (智能偵測是否重建)
./scripts/deploy.sh

# 快速部署 (跳過 Docker 重建)
./scripts/deploy.sh --quick

# 強制重建 Docker 映像
./scripts/deploy.sh --rebuild

# Git pull + 部署
./scripts/deploy.sh --pull

# 生產環境部署
./scripts/deploy.sh --env=production --pull

# 檢查系統狀態
./scripts/deploy.sh --check

# 查看幫助
./scripts/deploy.sh --help
```

**適用場景**:
- 日常開發部署
- 生產環境完整部署
- 緊急修復快速部署
- 系統狀態檢查

---

### cleanup.sh - 統一清理腳本

**用途**: 提供多種清理模式,自動管理磁碟空間

**特性**:
- ✅ 5 種清理模式 (quick / full / emergency / interactive / auto)
- ✅ 自動模式根據磁碟使用率智能決定清理程度
- ✅ 安全確認機制防止誤刪
- ✅ 詳細的清理報告

**使用方法**:
```bash
# 自動模式 (推薦) - 根據磁碟使用率自動決定
./scripts/cleanup.sh auto

# 快速清理 - Docker 構建緩存 + 臨時檔案
./scripts/cleanup.sh quick

# 完整清理 - Docker + 應用 + 系統
./scripts/cleanup.sh full

# 緊急清理 - 所有資源 (包括 volumes)
./scripts/cleanup.sh emergency

# 互動式選擇清理項目
./scripts/cleanup.sh interactive

# 查看幫助
./scripts/cleanup.sh --help
```

**清理模式說明**:

| 模式 | 清理內容 | 適用場景 |
|------|---------|---------|
| **quick** | Docker 構建緩存、系統資源、應用臨時檔案 | 日常維護 |
| **full** | quick + Docker 映像、舊日誌、系統日誌、APT 快取 | 定期清理 |
| **emergency** | full + Docker volumes (危險) | 磁碟嚴重不足 |
| **interactive** | 手動選擇清理項目 | 精細控制 |
| **auto** | 根據磁碟使用率自動決定 (70%→quick, 80%→full, 90%→emergency) | 自動化維護 |

**適用場景**:
- 磁碟使用率過高
- "No space left on device" 錯誤
- 定期維護清理
- Docker 構建失敗

---

## 📂 目錄結構

```
scripts/
├── deploy.sh              # 統一部署腳本 (新)
├── cleanup.sh             # 統一清理腳本 (新)
├── deployment/            # 部署相關腳本
│   ├── update-and-deploy.sh      # 更新並部署 (簡化版)
│   ├── fix-permissions.sh        # 修復權限問題
│   └── fix-git-reset-issue.sh    # 修復 git reset 問題
├── debugging/             # 除錯相關腳本
│   ├── check-scheduler.sh        # 檢查排程狀態
│   ├── check-supervisor.sh       # 檢查 Supervisor 狀態
│   └── check-gcs-proxy.sh        # 檢查 GCS 代理錯誤
└── docker/                # Docker 相關腳本
    ├── diagnose-space.sh         # 診斷磁碟空間問題
    ├── diagnose-php-fpm.sh       # 診斷 PHP-FPM 問題
    └── check-php-fpm.sh          # 檢查 PHP-FPM 狀態
```

---

## 📖 使用指南

### 部署相關

#### deployment/update-and-deploy.sh

**用途**: 先從 GitHub 拉取最新代碼,然後執行部署

**使用方法**:
```bash
# 開發環境
./scripts/deployment/update-and-deploy.sh

# 生產環境
./scripts/deployment/update-and-deploy.sh --env=production

# 檢查狀態
./scripts/deployment/update-and-deploy.sh --check
```

**功能**:
- 自動暫存本地更改
- 從遠端拉取最新代碼
- 調用統一部署腳本

**適用場景**:
- 需要 git pull 的完整部署
- 團隊協作時同步代碼

---

#### deployment/fix-permissions.sh

**用途**: 修復 Docker 容器與 Git 之間的權限衝突

**使用方法**:
```bash
sudo ./scripts/deployment/fix-permissions.sh
```

**修復的常見錯誤**:
```
error: unable to unlink old 'storage/app/.gitignore': Permission denied
fatal: Could not reset index file to revision 'HEAD'
```

**適用場景**:
- Git 操作出現權限錯誤
- Docker 容器修改了文件權限
- 首次部署環境設置

---

### 除錯相關

#### debugging/check-scheduler.sh

**用途**: 檢查 Laravel Scheduler 運行狀態

**使用方法**:
```bash
./scripts/debugging/check-scheduler.sh
```

**檢查項目**:
1. Supervisor 狀態
2. 已定義的排程任務列表
3. 手動執行一次排程
4. Supervisor 日誌 (最後 50 行)
5. Laravel 日誌 (排程相關)

**適用場景**:
- 排程任務沒有執行
- 驗證排程配置
- 排程運行異常

---

#### debugging/check-supervisor.sh

**用途**: 檢查 Supervisor 服務狀態

**使用方法**:
```bash
./scripts/debugging/check-supervisor.sh
```

**常見錯誤診斷**:
- `unix:///var/run/supervisor.sock no such file` → Supervisor 未啟動
- `Format string ... is badly formatted` → 配置文件語法錯誤

**適用場景**:
- Supervisor 無法啟動
- 進程管理異常

---

#### debugging/check-gcs-proxy.sh

**用途**: 檢查 GCS 代理錯誤和文件訪問

**使用方法**:
```bash
./scripts/debugging/check-gcs-proxy.sh
```

**檢查項目**:
1. Nginx 錯誤日誌 (GCS 相關)
2. Laravel 日誌 (GCS 代理相關)
3. GCS 配置
4. 測試文件訪問

**適用場景**:
- GCS 代理返回 500 錯誤
- 影片播放失敗
- 文件下載問題

---

### Docker 診斷相關

#### docker/diagnose-space.sh

**用途**: 診斷 Docker 磁碟空間使用情況

**使用方法**:
```bash
./scripts/docker/diagnose-space.sh
```

**提供資訊**:
- Docker 映像空間使用
- Docker 容器空間使用
- Docker 卷空間使用
- 構建緩存大小
- 清理建議

---

#### docker/diagnose-php-fpm.sh

**用途**: 診斷 PHP-FPM 相關問題

**使用方法**:
```bash
./scripts/docker/diagnose-php-fpm.sh
```

**檢查項目**:
- PHP-FPM 進程狀態
- 配置檔案
- 錯誤日誌
- 記憶體使用

---

## 📋 最佳實踐

### 1. 日常開發流程

```bash
# 早上開始工作
./scripts/deploy.sh --check              # 檢查系統狀態

# 開發過程中
./scripts/deploy.sh --quick              # 快速部署測試

# 結束前
./scripts/cleanup.sh auto                # 自動清理
```

### 2. 生產環境部署

```bash
# 步驟 1: 檢查當前狀態
./scripts/deploy.sh --check

# 步驟 2: 檢查磁碟空間
df -h

# 步驟 3: 執行部署
./scripts/deployment/update-and-deploy.sh --env=production

# 步驟 4: 驗證部署
./scripts/deploy.sh --check
```

### 3. 故障排查流程

```bash
# 步驟 1: 檢查 Supervisor
./scripts/debugging/check-supervisor.sh

# 步驟 2: 檢查排程
./scripts/debugging/check-scheduler.sh

# 步驟 3: 檢查 GCS (如果相關)
./scripts/debugging/check-gcs-proxy.sh

# 步驟 4: 檢查磁碟空間
df -h

# 步驟 5: 如需清理
./scripts/cleanup.sh auto
```

### 4. 定期維護

```bash
# 每週執行
./scripts/cleanup.sh full                # 完整清理

# 每天執行
./scripts/deploy.sh --check              # 狀態檢查
./scripts/debugging/check-scheduler.sh   # 排程檢查
```

### 5. 緊急情況處理

```bash
# 磁碟空間不足
./scripts/cleanup.sh emergency

# 排程異常
./scripts/debugging/check-scheduler.sh
docker compose exec app supervisorctl restart laravel-scheduler:*

# 部署失敗
./scripts/cleanup.sh quick
./scripts/deploy.sh --rebuild
```

---

## ⚠️ 注意事項

### 1. 權限要求
- 大部分腳本需要在服務器上執行
- `fix-permissions.sh` 需要 `sudo` 權限
- 確保腳本有執行權限 (`chmod +x`)

### 2. 執行環境
- 確保在專案根目錄執行
- 確保 Docker Compose 服務正在運行
- 檢查 `.env` 檔案配置正確

### 3. 備份建議
- 執行清理腳本前建議先備份重要資料
- `emergency` 模式會刪除 Docker volumes
- 生產環境部署前建議先在開發環境測試

### 4. 向後兼容
- 根目錄的 `deploy.sh` 仍然可用 (會轉發到 `scripts/deploy.sh`)
- 舊的 `--skip-build` 參數會自動轉換為 `--quick`
- 舊的腳本已整合到統一腳本中

---

## 🔄 腳本遷移指南

如果您習慣使用舊腳本,請參考以下對應表:

| 舊腳本 | 新腳本 | 說明 |
|--------|--------|------|
| `./deploy.sh --skip-build` | `./scripts/deploy.sh --quick` | 快速部署 |
| `./deploy.sh --rebuild` | `./scripts/deploy.sh --rebuild` | 強制重建 |
| `./deploy.sh --check` | `./scripts/deploy.sh --check` | 檢查狀態 |
| `scripts/maintenance/disk-cleanup.sh` | `./scripts/cleanup.sh full` | 完整清理 |
| `scripts/docker/emergency-cleanup.sh` | `./scripts/cleanup.sh emergency` | 緊急清理 |
| `scripts/docker/fix-docker-space.sh` | `./scripts/cleanup.sh interactive` | 互動式清理 |

---

## 📚 相關文檔

### 內部資源
- [DEPLOYMENT_OPTIMIZATION.md](../docs/DEPLOYMENT_OPTIMIZATION.md) - 部署優化詳細說明
- [DEPLOYMENT_CHECKLIST.md](../docs/DEPLOYMENT_CHECKLIST.md) - 部署檢查清單
- [GRACEFUL_SHUTDOWN.md](../docs/GRACEFUL_SHUTDOWN.md) - 優雅關閉指南
- [主 README](../README.md) - 專案主文檔
- [CLAUDE.md](../CLAUDE.md) - 開發指南

### 腳本文檔
- [deploy.sh 原始碼](deploy.sh) - 查看實現細節
- [cleanup.sh 原始碼](cleanup.sh) - 查看實現細節

---

## 🆘 獲取幫助

### 使用幫助
```bash
# 查看部署腳本幫助
./scripts/deploy.sh --help

# 查看清理腳本幫助
./scripts/cleanup.sh --help
```

### 問題排查
1. 查看腳本輸出的錯誤訊息
2. 查看 `docs/` 目錄下的相關文檔
3. 查看主 README 的常見問題部分
4. 聯絡項目維護團隊

### 腳本改進
如果發現腳本問題或有改進建議:
1. 提交 GitHub Issue
2. 創建 Pull Request
3. 聯絡項目維護團隊

---

## 📊 優化成果

相較於舊版腳本系統:
- ✅ 移除 ~354 行重複代碼
- ✅ 整合 4 個分散的清理腳本到 1 個
- ✅ 新增智能偵測重建功能
- ✅ 新增自動空間管理功能
- ✅ 提供 5 種清理模式
- ✅ 完全向後兼容

詳細優化說明請參閱 [DEPLOYMENT_OPTIMIZATION.md](../docs/DEPLOYMENT_OPTIMIZATION.md)

---

<div align="center">
  <sub>💡 所有腳本都包含詳細的輸出和錯誤提示,並遵循最佳實踐</sub>
</div>
