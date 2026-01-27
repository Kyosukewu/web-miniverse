# 部署與清理腳本優化總結

## 📋 優化概要

本次優化整併了專案中分散的部署和清理腳本,實現了一鍵部署和統一清理功能,並移除了重複代碼。

## ✨ 主要改進

### 1. 統一部署腳本 ([scripts/deploy.sh](../scripts/deploy.sh))

#### 新增功能
- ✅ **智能偵測重建**: 自動檢測 Dockerfile、docker-compose.yml、composer.json 變更,決定是否需要重建
- ✅ **自動空間管理**: 當磁碟使用率超過 85% 時自動執行清理
- ✅ **Git Pull 整合**: 支持 `--pull` 參數,先更新程式碼再部署
- ✅ **參數簡化**: 新增 `--quick` 參數取代 `--skip-build`,更直觀
- ✅ **狀態檢查**: 統一的 `--check` 模式檢查系統狀態

#### 使用方式
```bash
# 開發環境 (智能偵測是否重建)
./scripts/deploy.sh

# 快速部署 (跳過重建)
./scripts/deploy.sh --quick

# 強制重建
./scripts/deploy.sh --rebuild

# Git pull + 部署
./scripts/deploy.sh --pull

# 生產環境部署
./scripts/deploy.sh --env=production --pull

# 檢查狀態
./scripts/deploy.sh --check
```

### 2. 統一清理腳本 ([scripts/cleanup.sh](../scripts/cleanup.sh))

#### 整合功能
- ✅ **Quick 模式**: 快速清理 Docker 構建緩存和臨時檔案
- ✅ **Full 模式**: 完整清理 Docker + 應用 + 系統資源
- ✅ **Emergency 模式**: 緊急清理所有資源 (包括 volumes)
- ✅ **Interactive 模式**: 互動式選擇清理項目
- ✅ **Auto 模式**: 根據磁碟使用率自動決定清理程度

#### 自動模式邏輯
- 磁碟使用率 70-80%: 執行快速清理
- 磁碟使用率 80-90%: 執行完整清理
- 磁碟使用率 90%+: 執行緊急清理

#### 使用方式
```bash
# 自動模式 (推薦)
./scripts/cleanup.sh auto

# 快速清理
./scripts/cleanup.sh quick

# 完整清理
./scripts/cleanup.sh full

# 緊急清理
./scripts/cleanup.sh emergency

# 互動式選擇
./scripts/cleanup.sh interactive
```

### 3. 向後兼容

#### 根目錄 deploy.sh
- 保留為向後兼容包裝腳本
- 自動轉發參數到新的統一腳本
- 提供參數對應說明 (`--skip-build` → `--quick`)

#### update-and-deploy.sh
- 簡化為先 git pull 再調用統一部署腳本
- 減少重複代碼

## 🗑️ 移除的重複腳本

以下腳本功能已整合到統一清理腳本中:

1. ~~`scripts/docker/emergency-cleanup.sh`~~ → `scripts/cleanup.sh emergency`
2. ~~`scripts/docker/fix-docker-space.sh`~~ → `scripts/cleanup.sh interactive`
3. ~~`scripts/maintenance/disk-cleanup.sh`~~ → `scripts/cleanup.sh full`

## 📁 腳本結構優化

### 優化前
```
scripts/
├── deployment/
│   ├── update-and-deploy.sh (122 行)
│   ├── fix-permissions.sh
│   └── fix-git-reset-issue.sh
├── maintenance/
│   └── disk-cleanup.sh (134 行)
├── docker/
│   ├── emergency-cleanup.sh (107 行)
│   ├── fix-docker-space.sh (113 行)
│   ├── diagnose-space.sh
│   └── check-php-fpm.sh
└── debugging/
    ├── check-scheduler.sh
    ├── check-supervisor.sh
    └── check-gcs-proxy.sh

deploy.sh (455 行,功能重複)
```

### 優化後
```
scripts/
├── deploy.sh (統一部署腳本 - 新)
├── cleanup.sh (統一清理腳本 - 新)
├── deployment/
│   ├── update-and-deploy.sh (簡化版)
│   ├── fix-permissions.sh
│   └── fix-git-reset-issue.sh
├── docker/
│   ├── diagnose-space.sh
│   └── check-php-fpm.sh
└── debugging/
    ├── check-scheduler.sh
    ├── check-supervisor.sh
    └── check-gcs-proxy.sh

deploy.sh (向後兼容包裝 - 59 行)
```

## 📊 優化效果

### 代碼減少
- **移除重複代碼**: ~354 行 (emergency-cleanup.sh + fix-docker-space.sh + disk-cleanup.sh)
- **簡化 deploy.sh**: 從 455 行減少到 59 行包裝腳本
- **統一功能**: 2 個新的統一腳本取代了 4 個分散的腳本

### 功能增強
- ✅ 智能偵測重建需求
- ✅ 自動空間管理
- ✅ 統一的清理介面
- ✅ 更好的使用者體驗

### 維護性提升
- ✅ 單一職責原則
- ✅ 減少重複代碼
- ✅ 統一的命令介面
- ✅ 更清晰的腳本結構

## 🎯 使用建議

### 日常開發
```bash
# 快速部署 (不重建 Docker)
./scripts/deploy.sh --quick

# 或讓腳本自動判斷
./scripts/deploy.sh
```

### 生產環境部署
```bash
# 完整部署 (git pull + 智能重建)
./scripts/deployment/update-and-deploy.sh --env=production

# 或
./scripts/deploy.sh --env=production --pull
```

### 磁碟空間管理
```bash
# 日常維護
./scripts/cleanup.sh auto

# 緊急情況
./scripts/cleanup.sh emergency
```

### 狀態檢查
```bash
# 檢查系統狀態
./scripts/deploy.sh --check
```

## 📝 遷移指南

### 舊命令 → 新命令對應

| 舊命令 | 新命令 | 說明 |
|--------|--------|------|
| `./deploy.sh --skip-build` | `./scripts/deploy.sh --quick` | 快速部署 |
| `./deploy.sh --rebuild` | `./scripts/deploy.sh --rebuild` | 強制重建 |
| `./deploy.sh --check` | `./scripts/deploy.sh --check` | 檢查狀態 |
| `./scripts/maintenance/disk-cleanup.sh` | `./scripts/cleanup.sh full` | 完整清理 |
| `./scripts/docker/emergency-cleanup.sh` | `./scripts/cleanup.sh emergency` | 緊急清理 |
| `./scripts/docker/fix-docker-space.sh` | `./scripts/cleanup.sh interactive` | 互動式清理 |

### 向後兼容性

根目錄的 `deploy.sh` 仍然可用,會自動轉發到新腳本:
```bash
# 這些命令仍然有效
./deploy.sh --env=production
./deploy.sh --skip-build  # 自動轉換為 --quick
./deploy.sh --check
```

## 🔄 未來優化建議

1. **CI/CD 整合**: 將統一部署腳本整合到 CI/CD pipeline
2. **日誌記錄**: 添加詳細的部署日誌記錄
3. **回滾功能**: 實現一鍵回滾到上一個版本
4. **健康檢查**: 部署後自動執行健康檢查
5. **通知系統**: 部署成功/失敗時發送通知

## 📚 相關文檔

- [CLAUDE.md](../CLAUDE.md) - 專案開發指南 (已更新)
- [README.md](../README.md) - 專案說明文檔 (已更新)
- [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 部署檢查清單
- [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 優雅關閉指南

## ✅ 完成日期

2026-01-27
