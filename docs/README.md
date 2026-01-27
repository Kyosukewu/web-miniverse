# 📚 Miniverse 項目文檔

本目錄包含 Miniverse 外電 AI 影片分析系統的所有技術文檔,涵蓋核心流程、部署、優化、故障排除等各個方面。

---

## 📋 目錄

- [快速導航](#快速導航)
- [核心文檔](#核心文檔)
- [部署相關](#部署相關)
- [優化相關](#優化相關)
- [Docker 與故障排除](#docker-與故障排除)
- [分析系統](#分析系統)
- [配置與管理](#配置與管理)
- [按角色查找](#按角色查找文檔)
- [按場景查找](#按場景查找文檔)

---

## 🚀 快速導航

### 新手入門 (建議閱讀順序)

1. **[主 README](../README.md)** - 了解項目概況和快速開始
2. **[CNN_FLOW.md](CNN_FLOW.md)** - 理解核心業務邏輯
3. **[DATABASE_ACCESS.md](DATABASE_ACCESS.md)** - 學習資料庫訪問
4. **[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)** - 部署檢查清單
5. **[GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md)** - 系統維護機制

### 常用文檔

| 文檔 | 說明 | 使用頻率 |
|------|------|---------|
| [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) | **最新**部署優化指南 | ⭐⭐⭐⭐⭐ |
| [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) | 生產環境部署清單 | ⭐⭐⭐⭐⭐ |
| [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md) | Gemini API 配額優化 | ⭐⭐⭐⭐ |
| [DISK_SPACE_SOLUTIONS.md](DISK_SPACE_SOLUTIONS.md) | 磁碟空間解決方案 | ⭐⭐⭐⭐ |
| [DATABASE_ACCESS.md](DATABASE_ACCESS.md) | 資料庫操作指南 | ⭐⭐⭐ |

---

## 📖 核心文檔

### 🎬 [CNN_FLOW.md](CNN_FLOW.md)
**CNN 資源處理流程完整指南**

涵蓋內容:
- CNN 資源抓取流程
- 文件命名規則和解析
- 版本控制機制
- XML 和 MP4 文件配對邏輯
- GCS 上傳和驗證
- 常見問題和解決方案

**適用人員**: 開發人員、系統管理員
**重要程度**: ⭐⭐⭐⭐⭐

---

### 🗄️ [DATABASE_ACCESS.md](DATABASE_ACCESS.md)
**資料庫訪問和管理指南**

涵蓋內容:
- 資料庫連接方式 (phpMyAdmin、命令行、Tinker)
- 常用資料庫操作
- 資料表結構說明
- 備份和恢復流程
- 性能優化建議

**適用人員**: 資料庫管理員、開發人員
**重要程度**: ⭐⭐⭐⭐

---

## 🚢 部署相關

### 🎯 [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) ⭐ 最新
**部署與清理腳本優化總結**

涵蓋內容:
- 統一部署腳本 (deploy.sh) 使用指南
- 統一清理腳本 (cleanup.sh) 使用指南
- 優化成果和代碼減少統計
- 腳本遷移指南
- 使用建議和最佳實踐

**適用人員**: 所有開發人員、DevOps
**重要程度**: ⭐⭐⭐⭐⭐
**更新日期**: 2026-01-27

---

### ✅ [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)
**生產環境部署檢查清單**

涵蓋內容:
- 部署前準備
- 環境變數配置檢查
- 安全性檢查項目
- 性能優化配置
- 部署後驗證步驟
- 回滾方案

**適用人員**: DevOps、系統管理員、技術主管
**重要程度**: ⭐⭐⭐⭐⭐

---

### 🛡️ [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md)
**優雅關閉和安全機制指南**

涵蓋內容:
- Supervisor 優雅關閉配置
- 排程任務安全機制
- Gemini API 調用保護
- 代碼更新期間的任務處理
- 緊急情況處理流程

**適用人員**: 系統管理員、DevOps
**重要程度**: ⭐⭐⭐⭐

---

### 📜 [DEPLOYMENT_SCRIPT_USAGE.md](DEPLOYMENT_SCRIPT_USAGE.md)
**部署腳本使用說明** (舊版,建議參考 DEPLOYMENT_OPTIMIZATION.md)

涵蓋內容:
- 舊版部署腳本使用方法
- 常見部署場景

**適用人員**: 開發人員
**重要程度**: ⭐⭐
**備註**: 已被 DEPLOYMENT_OPTIMIZATION.md 取代

---

### 💡 [DEPLOYMENT_IMPROVEMENTS.md](DEPLOYMENT_IMPROVEMENTS.md)
**部署流程改進建議**

涵蓋內容:
- 部署流程優化建議
- 自動化改進方向
- 監控和日誌改進

**適用人員**: DevOps、技術主管
**重要程度**: ⭐⭐⭐

---

## 🔧 優化相關

### 🚀 [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md)
**Gemini API 配額優化指南**

涵蓋內容:
- Gemini API 配額限制說明
- RPS 和每日請求數控制策略
- 排程任務優化建議
- 配額監控和警報設置
- 最佳實踐和使用技巧

**適用人員**: 開發人員、系統管理員
**重要程度**: ⭐⭐⭐⭐⭐

---

### 💰 [GCS_COST_OPTIMIZATION.md](GCS_COST_OPTIMIZATION.md)
**Google Cloud Storage 成本優化**

涵蓋內容:
- GCS 存儲成本分析
- 成本優化策略
- 生命週期管理規則
- 存儲類別選擇建議

**適用人員**: DevOps、技術主管
**重要程度**: ⭐⭐⭐⭐

---

### 📊 [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md)
**資料庫查詢優化指南**

涵蓋內容:
- 常見查詢性能問題
- 索引優化策略
- 查詢重寫技巧
- 分頁優化方案

**適用人員**: 開發人員、資料庫管理員
**重要程度**: ⭐⭐⭐

---

### 📦 [FILE_SIZE_OPTIMIZATION.md](FILE_SIZE_OPTIMIZATION.md)
**文件大小優化策略**

涵蓋內容:
- 影片文件大小管理
- 壓縮策略
- 儲存空間優化
- 傳輸優化

**適用人員**: 開發人員、系統管理員
**重要程度**: ⭐⭐⭐

---

## 🐳 Docker 與故障排除

### 💾 [DISK_SPACE_SOLUTIONS.md](DISK_SPACE_SOLUTIONS.md)
**磁碟空間問題解決方案彙總**

涵蓋內容:
- 磁碟空間不足的常見原因
- 快速清理步驟
- 預防措施
- 監控和警報設置

**適用人員**: 系統管理員、DevOps
**重要程度**: ⭐⭐⭐⭐⭐

---

### 🔧 [DOCKER_BUILD_TROUBLESHOOTING.md](DOCKER_BUILD_TROUBLESHOOTING.md)
**Docker 構建問題排查指南**

涵蓋內容:
- 常見 Docker 構建錯誤
- "No space left on device" 錯誤處理
- 網絡問題排查
- 依賴安裝問題

**適用人員**: 開發人員、DevOps
**重要程度**: ⭐⭐⭐⭐

---

### 📦 [DOCKERFILE_APT_ERROR_FIX.md](DOCKERFILE_APT_ERROR_FIX.md)
**Dockerfile APT 錯誤修復**

涵蓋內容:
- APT 套件安裝錯誤
- 鏡像源配置
- 依賴衝突解決

**適用人員**: 開發人員、DevOps
**重要程度**: ⭐⭐

---

### 🔍 [DOCKER_SPACE_DIAGNOSIS.md](DOCKER_SPACE_DIAGNOSIS.md)
**Docker 空間使用診斷** (舊版,建議使用統一清理腳本)

涵蓋內容:
- Docker 空間使用分析
- 診斷工具使用
- 空間佔用識別

**適用人員**: 系統管理員、DevOps
**重要程度**: ⭐⭐
**備註**: 已整合到 cleanup.sh 腳本中

---

### 🆘 [DOCKER_SPACE_EMERGENCY_FIX.md](DOCKER_SPACE_EMERGENCY_FIX.md)
**Docker 空間緊急修復** (舊版,建議使用統一清理腳本)

涵蓋內容:
- 緊急空間清理步驟
- 強制清理命令

**適用人員**: 系統管理員、DevOps
**重要程度**: ⭐⭐
**備註**: 已整合到 `cleanup.sh emergency` 模式中

---

## 🤖 分析系統

### 📝 [ANALYZE_FULL_CONDITIONS.md](ANALYZE_FULL_CONDITIONS.md)
**完整分析條件和邏輯**

涵蓋內容:
- analyze:full 命令的執行條件
- 文件配對邏輯
- 版本檢查機制
- 跳過已分析影片的邏輯

**適用人員**: 開發人員
**重要程度**: ⭐⭐⭐

---

### 🛠️ [ANALYZE_FULL_ERROR_HANDLING.md](ANALYZE_FULL_ERROR_HANDLING.md)
**完整分析錯誤處理**

涵蓋內容:
- 錯誤處理機制
- 重試策略
- 錯誤日誌記錄
- 恢復流程

**適用人員**: 開發人員、系統管理員
**重要程度**: ⭐⭐⭐

---

## ⚙️ 配置與管理

### ⏰ [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md)
**排程任務配置指南**

涵蓋內容:
- Laravel Scheduler 配置
- 排程任務定義
- Supervisor 配置
- 排程開關控制
- 監控和日誌

**適用人員**: 開發人員、系統管理員
**重要程度**: ⭐⭐⭐⭐

---

## 👥 按角色查找文檔

### 開發人員

**必讀**:
1. [CNN_FLOW.md](CNN_FLOW.md) - 了解業務流程
2. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 資料庫操作
3. [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) - 部署腳本使用
4. [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md) - API 配額管理

**推薦**:
- [ANALYZE_FULL_CONDITIONS.md](ANALYZE_FULL_CONDITIONS.md) - 分析邏輯
- [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md) - 查詢優化
- [DOCKER_BUILD_TROUBLESHOOTING.md](DOCKER_BUILD_TROUBLESHOOTING.md) - 構建問題

---

### 系統管理員 / DevOps

**必讀**:
1. [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) - 統一部署和清理
2. [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 部署清單
3. [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 優雅關閉
4. [DISK_SPACE_SOLUTIONS.md](DISK_SPACE_SOLUTIONS.md) - 空間管理
5. [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md) - 排程配置

**推薦**:
- [GCS_COST_OPTIMIZATION.md](GCS_COST_OPTIMIZATION.md) - 成本優化
- [DOCKER_BUILD_TROUBLESHOOTING.md](DOCKER_BUILD_TROUBLESHOOTING.md) - 故障排除
- [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md) - API 管理

---

### 技術主管

**建議閱讀**:
1. [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) - 最新優化成果
2. [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 部署流程
3. [GCS_COST_OPTIMIZATION.md](GCS_COST_OPTIMIZATION.md) - 成本控制
4. [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md) - API 成本
5. [DEPLOYMENT_IMPROVEMENTS.md](DEPLOYMENT_IMPROVEMENTS.md) - 改進建議

---

## 🎯 按場景查找文檔

### 首次部署

1. [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 完整部署清單
2. [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) - 使用統一部署腳本
3. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 設置資料庫訪問
4. [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md) - 配置排程任務
5. [CNN_FLOW.md](CNN_FLOW.md) - 理解業務流程

---

### 系統維護

1. [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 安全停止和重啟
2. [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) - 使用清理腳本
3. [DISK_SPACE_SOLUTIONS.md](DISK_SPACE_SOLUTIONS.md) - 空間管理
4. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 資料庫維護
5. [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md) - 排程監控

---

### 問題排查

**Docker 問題**:
- [DOCKER_BUILD_TROUBLESHOOTING.md](DOCKER_BUILD_TROUBLESHOOTING.md)
- [DISK_SPACE_SOLUTIONS.md](DISK_SPACE_SOLUTIONS.md)
- [DOCKERFILE_APT_ERROR_FIX.md](DOCKERFILE_APT_ERROR_FIX.md)

**排程問題**:
- [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md)
- [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md)

**資源處理問題**:
- [CNN_FLOW.md](CNN_FLOW.md)
- [ANALYZE_FULL_ERROR_HANDLING.md](ANALYZE_FULL_ERROR_HANDLING.md)

**API 配額問題**:
- [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md)

**性能問題**:
- [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md)
- [FILE_SIZE_OPTIMIZATION.md](FILE_SIZE_OPTIMIZATION.md)

---

### 優化和改進

**成本優化**:
- [GCS_COST_OPTIMIZATION.md](GCS_COST_OPTIMIZATION.md)
- [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md)

**性能優化**:
- [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md)
- [FILE_SIZE_OPTIMIZATION.md](FILE_SIZE_OPTIMIZATION.md)

**流程優化**:
- [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md)
- [DEPLOYMENT_IMPROVEMENTS.md](DEPLOYMENT_IMPROVEMENTS.md)

---

## 📝 文檔維護

### 更新文檔

當以下情況發生時,請更新相應文檔:

| 變更類型 | 需要更新的文檔 |
|---------|---------------|
| 新增功能 | [CNN_FLOW.md](CNN_FLOW.md) |
| 資料表結構變更 | [DATABASE_ACCESS.md](DATABASE_ACCESS.md) |
| 部署流程變更 | [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md), [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) |
| 排程任務變更 | [SCHEDULER_CONFIGURATION.md](SCHEDULER_CONFIGURATION.md), [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) |
| API 配額調整 | [GEMINI_API_QUOTA_OPTIMIZATION.md](GEMINI_API_QUOTA_OPTIMIZATION.md) |
| 腳本優化 | [DEPLOYMENT_OPTIMIZATION.md](DEPLOYMENT_OPTIMIZATION.md) |

### 文檔版本控制

所有文檔都納入 Git 版本控制,更新時請:

1. 在文檔底部註明更新日期
2. 使用清晰的 commit message (如: `docs: 更新部署優化說明`)
3. 重大變更時通知團隊
4. 保持文檔與代碼同步

---

## 🔗 相關資源

### 內部資源

- [主 README](../README.md) - 項目主文檔
- [腳本工具文檔](../scripts/README.md) - 工具腳本詳細說明
- [CLAUDE.md](../CLAUDE.md) - Claude Code 開發指南
- [Docker 配置](../docker/README.md) - Docker 環境配置

### 外部資源

- [Laravel 12 文檔](https://laravel.com/docs/12.x)
- [Google Gemini API](https://ai.google.dev/gemini-api/docs)
- [Google Cloud Storage PHP](https://cloud.google.com/php/docs/reference/cloud-storage/latest)
- [Supervisor 文檔](http://supervisord.org/)
- [Docker 文檔](https://docs.docker.com/)

---

## 📋 文檔規範

### Markdown 格式

所有文檔使用 Markdown 格式編寫,遵循以下規範:

1. **標題層級**
   - H1 (`#`) - 文檔標題 (每個文檔只有一個)
   - H2 (`##`) - 主要章節
   - H3 (`###`) - 次要章節
   - H4-H6 - 更細的層級

2. **代碼塊**
   - 使用三個反引號包裹
   - 指定語言類型 (如 `bash`, `php`, `json`)

3. **連結**
   - 內部連結使用相對路徑
   - 外部連結使用完整 URL

4. **表格**
   - 使用 Markdown 表格語法
   - 保持對齊美觀

### 內容組織

1. **開頭** - 簡短的文檔描述
2. **目錄** - 列出主要章節 (可選)
3. **正文** - 詳細內容
4. **範例** - 實際操作範例
5. **注意事項** - 重要提醒
6. **相關連結** - 相關文檔連結
7. **更新日期** - 最後更新時間

---

## 💡 貢獻文檔

歡迎改進文檔! 請遵循以下步驟:

1. Fork 項目
2. 創建文檔分支 (`git checkout -b docs/improve-xxx`)
3. 編輯文檔
4. 提交更改 (`git commit -m 'docs: 改進 XXX 文檔說明'`)
5. 推送分支 (`git push origin docs/improve-xxx`)
6. 創建 Pull Request

---

## ❓ 獲取幫助

如果文檔中有不清楚的地方:

1. 查看相關的其他文檔
2. 查看主 README 的常見問題部分
3. 查看代碼註釋
4. 使用腳本的 `--help` 參數
5. 聯絡項目維護團隊
6. 提出文檔改進建議

---

## 📊 文檔統計

- **總文檔數**: 20
- **核心文檔**: 2
- **部署相關**: 5
- **優化相關**: 4
- **Docker 相關**: 5
- **分析系統**: 2
- **配置管理**: 2

**最後更新**: 2026-01-27

---

<div align="center">
  <sub>📖 文檔是項目的重要組成部分,請幫助我們保持其準確和最新</sub>
</div>
