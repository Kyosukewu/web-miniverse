# 📚 Miniverse 項目文檔

本目錄包含 Miniverse 外電 AI 影片分析系統的所有技術文檔。

---

## 📋 文檔列表

### 🎬 [CNN_FLOW.md](CNN_FLOW.md)
**CNN 資源處理流程完整指南**

涵蓋內容：
- CNN 資源抓取流程
- 文件命名規則和解析
- 版本控制機制
- XML 和 MP4 文件配對邏輯
- GCS 上傳和驗證
- 常見問題和解決方案

**適用人員**: 開發人員、系統管理員

---

### 🗄️ [DATABASE_ACCESS.md](DATABASE_ACCESS.md)
**資料庫訪問和管理指南**

涵蓋內容：
- 資料庫連接方式（phpMyAdmin、命令行、Tinker）
- 常用資料庫操作
- 資料表結構說明
- 備份和恢復流程
- 性能優化建議

**適用人員**: 資料庫管理員、開發人員

---

### ✅ [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)
**生產環境部署檢查清單**

涵蓋內容：
- 部署前準備
- 環境變數配置檢查
- 安全性檢查項目
- 性能優化配置
- 部署後驗證步驟
- 回滾方案

**適用人員**: DevOps、系統管理員、技術主管

---

### 🛡️ [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md)
**優雅關閉和安全機制指南**

涵蓋內容：
- Supervisor 優雅關閉配置
- 排程任務安全機制
- Gemini API 調用保護
- 代碼更新期間的任務處理
- 緊急情況處理流程

**適用人員**: 系統管理員、DevOps

---

## 🎯 快速導航

### 按角色查找文檔

#### 開發人員
- 開始：[CNN_FLOW.md](CNN_FLOW.md) - 了解業務流程
- 資料：[DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 訪問和查詢資料
- 部署：[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 部署流程

#### 系統管理員
- 部署：[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 完整部署指南
- 維護：[GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 系統維護和安全
- 資料庫：[DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 資料庫管理

#### DevOps
- 所有文檔都需要閱讀

### 按場景查找文檔

#### 首次部署
1. [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) - 部署檢查清單
2. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 設置資料庫訪問
3. [CNN_FLOW.md](CNN_FLOW.md) - 理解業務流程

#### 系統維護
1. [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 安全停止和重啟
2. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 資料庫維護
3. 主 [README.md](../README.md) - 常見問題處理

#### 問題排查
1. [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md) - 排程任務問題
2. [CNN_FLOW.md](CNN_FLOW.md) - 資源處理問題
3. [DATABASE_ACCESS.md](DATABASE_ACCESS.md) - 資料問題

---

## 📖 閱讀建議

### 新手入門

建議按以下順序閱讀：

1. **主 README** ([../README.md](../README.md))
   - 了解項目概況和快速開始

2. **CNN 流程文檔** ([CNN_FLOW.md](CNN_FLOW.md))
   - 理解核心業務邏輯

3. **資料庫訪問** ([DATABASE_ACCESS.md](DATABASE_ACCESS.md))
   - 學習如何訪問和查詢資料

4. **部署檢查清單** ([DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md))
   - 準備部署和上線

5. **優雅關閉指南** ([GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md))
   - 了解系統維護和安全機制

### 文檔維護

#### 更新文檔

當以下情況發生時，請更新相應文檔：

- **新增功能** → 更新 [CNN_FLOW.md](CNN_FLOW.md)
- **資料表結構變更** → 更新 [DATABASE_ACCESS.md](DATABASE_ACCESS.md)
- **部署流程變更** → 更新 [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)
- **排程任務變更** → 更新 [GRACEFUL_SHUTDOWN.md](GRACEFUL_SHUTDOWN.md)

#### 文檔版本控制

所有文檔都納入 Git 版本控制，更新時請：

1. 在文檔底部註明更新日期
2. 使用清晰的 commit message
3. 重大變更時通知團隊

---

## 🔗 相關資源

### 內部資源

- [主 README](../README.md) - 項目主文檔
- [腳本工具文檔](../scripts/README.md) - 工具腳本說明
- [Docker 配置](../docker/README.md) - Docker 環境配置

### 外部資源

- [Laravel 12 文檔](https://laravel.com/docs/12.x)
- [Google Gemini API](https://ai.google.dev/gemini-api/docs)
- [Google Cloud Storage PHP](https://cloud.google.com/php/docs/reference/cloud-storage/latest)
- [Supervisor 文檔](http://supervisord.org/)

---

## 📝 文檔規範

### Markdown 格式

所有文檔使用 Markdown 格式編寫，遵循以下規範：

1. **標題層級**
   - H1 (`#`) - 文檔標題（每個文檔只有一個）
   - H2 (`##`) - 主要章節
   - H3 (`###`) - 次要章節
   - H4-H6 - 更細的層級

2. **代碼塊**
   - 使用三個反引號包裹
   - 指定語言類型（如 `bash`, `php`, `json`）

3. **連結**
   - 內部連結使用相對路徑
   - 外部連結使用完整 URL

4. **表格**
   - 使用 Markdown 表格語法
   - 保持對齊美觀

### 內容組織

1. **開頭** - 簡短的文檔描述
2. **目錄** - 列出主要章節（可選）
3. **正文** - 詳細內容
4. **範例** - 實際操作範例
5. **注意事項** - 重要提醒
6. **相關連結** - 相關文檔連結

---

## 💡 貢獻文檔

歡迎改進文檔！請遵循以下步驟：

1. Fork 項目
2. 創建文檔分支 (`git checkout -b docs/improve-cnn-flow`)
3. 編輯文檔
4. 提交更改 (`git commit -m 'docs: 改進 CNN 流程說明'`)
5. 推送分支 (`git push origin docs/improve-cnn-flow`)
6. 創建 Pull Request

---

## ❓ 獲取幫助

如果文檔中有不清楚的地方：

1. 查看相關的其他文檔
2. 查看主 README 的常見問題部分
3. 查看代碼註釋
4. 聯絡項目維護團隊
5. 提出文檔改進建議

---

<div align="center">
  <sub>📖 文檔是項目的重要組成部分，請幫助我們保持其準確和最新</sub>
</div>

