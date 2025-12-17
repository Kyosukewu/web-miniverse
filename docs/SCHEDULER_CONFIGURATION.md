# ⏰ 排程配置指南

本文檔說明如何使用環境變數控制 Laravel Scheduler 中的各個排程任務。

---

## 🎛️ 環境變數說明

### 主開關

| 環境變數 | 預設值 | 說明 |
|---------|--------|------|
| `SCHEDULER_ENABLED` | `false` | 排程總開關，控制是否啟用所有排程任務 |

### 個別任務開關

| 環境變數 | 預設值 | 說明 | 依賴 |
|---------|--------|------|------|
| `ANALYZE_DOCUMENT_ENABLED` | `true` | 控制 XML 文檔分析任務 | 需要 `SCHEDULER_ENABLED=true` |
| `ANALYZE_VIDEO_ENABLED` | `true` | 控制 MP4 影片分析任務 | 需要 `SCHEDULER_ENABLED=true` |
| `CLEANUP_OLD_VIDEOS_ENABLED` | `true` | 控制清理過期影片資料任務 | 需要 `SCHEDULER_ENABLED=true` |

---

## 📋 排程任務列表

當 `SCHEDULER_ENABLED=true` 時，以下任務會被排程：

| 任務 | 指令 | 頻率 | 開關控制 | 說明 |
|------|------|------|----------|------|
| **CNN 資源抓取** | `fetch:cnn` | 每 30 分鐘 | 無獨立開關 | 從 CNN 抓取資源到 GCS |
| **XML 文檔分析** | `analyze:document` | 每 10 分鐘 | `ANALYZE_DOCUMENT_ENABLED` | 分析 XML 文檔內容 |
| **MP4 影片分析** | `analyze:video` | 每 15 分鐘 | `ANALYZE_VIDEO_ENABLED` | 使用 Gemini AI 分析影片 |
| **恢復卡住任務** | `analysis:recover` | 每 10 分鐘 | 無獨立開關 | 自動恢復超時的分析任務 |
| **清理臨時檔案** | 匿名閉包 | 每小時 | 無獨立開關 | 刪除 1 小時前的臨時檔案 |
| **清理過期資料** | `cleanup:old-videos` | 每日 02:00 | `CLEANUP_OLD_VIDEOS_ENABLED` | 刪除 14 天前的影片資料 |

---

## 🔧 使用場景

### 場景 1: 完全關閉所有排程

**適用於**: 維護期間、除錯、測試環境

```bash
# .env 配置
SCHEDULER_ENABLED=false
```

**結果**: 所有排程任務都不會執行

---

### 場景 2: 啟用所有排程任務

**適用於**: 正常生產運行

```bash
# .env 配置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=true
CLEANUP_OLD_VIDEOS_ENABLED=true
```

**結果**: 所有排程任務都會執行

---

### 場景 3: 只抓取資源，不分析

**適用於**: API 配額不足、Gemini API 暫時不可用

```bash
# .env 配置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=false
```

**結果**: 
- ✅ CNN 資源會被抓取到 GCS
- ✅ 臨時檔案會被清理
- ✅ 卡住的任務會被恢復
- ❌ 不會執行 XML 文檔分析
- ❌ 不會執行影片分析（節省 Gemini API 配額）

---

### 場景 4: 只分析文檔，不分析影片

**適用於**: Gemini API 配額有限，優先處理文檔

```bash
# .env 配置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=false
```

**結果**:
- ✅ XML 文檔會被分析
- ❌ 影片不會被分析（節省較多配額）

---

### 場景 5: 只分析影片，不分析文檔

**適用於**: 已經有足夠的文檔資料，只需要影片分析

```bash
# .env 配置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=true
```

**結果**:
- ❌ 不會分析新的 XML 文檔
- ✅ 會分析影片（基於現有的文檔資料）

---

### 場景 6: 保留所有資料，不自動清理

**適用於**: 測試環境、需要保留歷史資料、調查問題

```bash
# .env 配置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=true
CLEANUP_OLD_VIDEOS_ENABLED=false
```

**結果**:
- ✅ 所有分析任務正常執行
- ❌ 不會自動刪除過期影片資料（需要手動清理）

⚠️ **注意**: 長期關閉自動清理可能導致資料庫和儲存空間持續增長

---

## 🚀 配置步驟

### 步驟 1: 修改 `.env` 文件

```bash
cd /var/www/html/web-miniverse

# 編輯 .env
nano .env

# 添加或修改以下設置
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=false
```

---

### 步驟 2: 清除快取並重啟

```bash
# 清除配置快取
docker compose exec app php artisan config:clear

# 重啟容器
docker compose restart app

# 等待啟動
sleep 10
```

---

### 步驟 3: 驗證配置

```bash
# 查看已啟用的排程任務
docker compose exec app php artisan schedule:list

# 應該只顯示已啟用的任務
```

---

## ✅ 驗證方法

### 方法 1: 查看排程列表

```bash
docker compose exec app php artisan schedule:list
```

**預期結果**:
- 如果 `ANALYZE_DOCUMENT_ENABLED=false`，列表中不會顯示 `analyze:document` 任務
- 如果 `ANALYZE_VIDEO_ENABLED=false`，列表中不會顯示 `analyze:video` 任務

---

### 方法 2: 手動執行測試

```bash
# 手動執行排程
docker compose exec app php artisan schedule:run --verbose
```

**預期結果**:
- 只會執行已啟用的任務
- 被禁用的任務不會出現在輸出中

---

### 方法 3: 監控日誌

```bash
# 實時監控排程執行
docker compose logs -f app | grep -E "analyze:document|analyze:video"
```

**預期結果**:
- 被禁用的任務不應該出現在日誌中

---

### 方法 4: 檢查環境變數

```bash
# 檢查容器內的環境變數
docker compose exec app env | grep -E "SCHEDULER_ENABLED|ANALYZE_"

# 應該顯示：
# SCHEDULER_ENABLED=true
# ANALYZE_DOCUMENT_ENABLED=true
# ANALYZE_VIDEO_ENABLED=false
```

---

## 📊 開關邏輯說明

### 雙層控制機制

```
SCHEDULER_ENABLED (主開關)
    ↓
    ├── fetch:cnn (無獨立開關)
    ├── analyze:document ← ANALYZE_DOCUMENT_ENABLED (子開關)
    ├── analyze:video ← ANALYZE_VIDEO_ENABLED (子開關)
    ├── analysis:recover (無獨立開關)
    ├── cleanup:temp-files (無獨立開關)
    └── cleanup:old-videos ← CLEANUP_OLD_VIDEOS_ENABLED (子開關)
```

**邏輯規則**:
1. 如果 `SCHEDULER_ENABLED=false`，所有任務都不執行（子開關無效）
2. 如果 `SCHEDULER_ENABLED=true`：
   - 沒有子開關的任務會執行
   - 有子開關的任務根據子開關決定是否執行

---

## 💡 最佳實踐

### 1. 生產環境建議配置

```bash
# 正常運行
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=true

# 如果 Gemini API 配額緊張
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=true
ANALYZE_VIDEO_ENABLED=false  # 影片分析消耗較多配額
```

---

### 2. 維護期間配置

```bash
# 完全停止分析，但保留資源抓取
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=false
```

---

### 3. API 故障期間配置

```bash
# 如果 Gemini API 暫時不可用
SCHEDULER_ENABLED=true
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=false

# 資源會繼續抓取，等 API 恢復後再啟用分析
```

---

## 🆘 常見問題

### Q1: 修改 .env 後需要重啟容器嗎？

**是的**。環境變數在容器啟動時載入，必須重啟容器才能生效。

```bash
docker compose restart app
```

---

### Q2: 可以即時生效嗎？

**不行**。必須執行以下步驟：
1. 修改 `.env`
2. 清除快取: `docker compose exec app php artisan config:clear`
3. 重啟容器: `docker compose restart app`

---

### Q3: 如何確認配置已生效？

執行 `schedule:list` 查看任務列表：

```bash
docker compose exec app php artisan schedule:list
```

被禁用的任務不會出現在列表中。

---

### Q4: 子開關的預設值是什麼？

如果 `.env` 中沒有設置子開關，預設值為 `true`（啟用）。

這意味著：
- `ANALYZE_DOCUMENT_ENABLED` 預設為 `true`
- `ANALYZE_VIDEO_ENABLED` 預設為 `true`
- `CLEANUP_OLD_VIDEOS_ENABLED` 預設為 `true`

---

### Q5: 可以臨時禁用某個任務嗎？

**可以**。修改 `.env` 並重啟即可：

```bash
# 臨時禁用影片分析
echo "ANALYZE_VIDEO_ENABLED=false" >> .env
docker compose restart app

# 恢復時改回 true
nano .env  # 修改為 ANALYZE_VIDEO_ENABLED=true
docker compose restart app
```

---

### Q6: 關閉自動清理任務有什麼影響？

**影響**：
- ✅ 資料會被永久保留，方便歷史查詢
- ⚠️ 資料庫會持續增長
- ⚠️ GCS 儲存空間會持續增長
- ⚠️ 可能影響系統性能

**建議**：
- 測試環境可以關閉
- 生產環境建議保持開啟
- 如需保留資料，定期手動備份後再清理

```bash
# 關閉自動清理（不建議長期使用）
CLEANUP_OLD_VIDEOS_ENABLED=false

# 手動清理（當需要時）
docker compose exec app php artisan cleanup:old-videos --days=30 --dry-run
docker compose exec app php artisan cleanup:old-videos --days=30 --force
```

---

## 📝 配置模板

將以下內容添加到 `.env` 文件中：

```bash
# ========================================
# Laravel Scheduler 排程配置
# ========================================

# 排程總開關（必須設為 true 才會執行任何排程任務）
SCHEDULER_ENABLED=true

# 個別任務開關（僅在 SCHEDULER_ENABLED=true 時有效）
# 預設值為 true，不設置則啟用

# XML 文檔分析任務（每 10 分鐘執行）
ANALYZE_DOCUMENT_ENABLED=true

# MP4 影片分析任務（每 15 分鐘執行，消耗 Gemini API 配額較多）
ANALYZE_VIDEO_ENABLED=true

# 清理過期影片資料任務（每天凌晨 2 點執行，刪除 14 天前的資料）
CLEANUP_OLD_VIDEOS_ENABLED=true

# ========================================
# 說明：
# - SCHEDULER_ENABLED=false: 關閉所有排程
# - SCHEDULER_ENABLED=true: 啟用排程，個別任務根據子開關決定
# - 修改後需重啟容器: docker compose restart app
# - 長期關閉 CLEANUP_OLD_VIDEOS 可能導致資料庫和儲存空間持續增長
# ========================================
```

---

## 🔗 相關文檔

- [主 README](../README.md) - 項目概覽
- [優雅關閉指南](GRACEFUL_SHUTDOWN.md) - 排程安全停止機制
- [部署檢查清單](DEPLOYMENT_CHECKLIST.md) - 部署時的排程配置檢查

---

<div align="center">
  <sub>⏰ 靈活的排程控制讓你更好地管理系統資源</sub>
</div>

