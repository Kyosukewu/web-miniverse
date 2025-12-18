# analyze:full 命令執行條件說明

## 概述

`analyze:full` 命令現在有更嚴格的執行條件，確保只有完全符合要求的影片才會被分析。

## 執行條件（必須全部符合）

### 條件 1：必須同時存在 XML 和 MP4 檔案

- **檢查位置**：第 150-163 行
- **檢查內容**：
  - 確認能找到對應的 MP4 檔案路徑（nas_path）
  - MP4 檔案必須實際存在於儲存空間中
  
**不符合時的行為**：
```
⊘ 跳過（找不到對應的 MP4 檔案）: [檔案名稱]
```

### 條件 2：videos 表中不存在該筆記錄

- **檢查位置**：第 165-175 行
- **檢查內容**：
  - 查詢 `videos` 表中是否已存在相同 `source_name` 和 `source_id` 的記錄
  - 如果存在任何記錄（無論狀態），都會跳過
  
**目的**：
- 避免之前只分析一半的單筆指令（`analyze:document` 或 `analyze:video`）影響
- 確保 `analyze:full` 只處理全新的影片
- 如果需要重新分析，請先手動刪除 `videos` 表中的記錄

**不符合時的行為**：
```
⊘ 跳過（該 ID 已存在於 videos 表中）: [source_id] (ID: [video_id])
```

### 條件 3：影片大小不超過限制

- **檢查位置**：第 177-214 行
- **檢查內容**：
  - 取得影片檔案的實際大小
  - 確認大小不超過 Gemini API 限制（300MB）
  
**不符合時的行為**：
```
⚠️  跳過（檔案過大）: [source_id] (檔案大小: [實際大小]MB > 300MB)
```

**符合時的行為**：
```
✓ 檔案大小符合限制: [source_id] ([實際大小]MB)
```

## 執行流程

```
開始處理文檔
    ↓
讀取文檔內容（XML/TXT）
    ↓
[條件 1] 檢查是否找到對應的 MP4 檔案
    ↓ 否：跳過
    ↓ 是
[條件 2] 檢查 videos 表是否已存在記錄
    ↓ 是：跳過
    ↓ 否
[條件 3] 檢查影片檔案大小是否符合限制
    ↓ 否：跳過
    ↓ 是
建立新的 video 記錄（標記為新建）
    ↓
執行完整分析（文本 + 影片）
    ↓ 成功
保存分析結果
    ↓ 失敗
刪除剛建立的記錄（避免累積空記錄）
```

## 條件檢查順序說明

條件檢查順序是經過優化的，從**成本最低到成本最高**：

1. **條件 1（MP4 路徑檢查）**：只需要檔案系統查詢，成本最低
2. **條件 2（資料庫檢查）**：需要資料庫查詢，成本中等
3. **條件 3（檔案大小檢查）**：需要讀取檔案元數據，成本相對較高

## 使用範例

### 正常執行

```bash
docker compose exec app php artisan analyze:full --limit=10
```

**輸出範例**：
```
開始掃描來源: CNN, 儲存空間: gcs
模式：完整分析（文本 + 影片一次性發送）
找到 100 個文檔檔案
過濾後剩餘 50 個文檔檔案（每個 source_id 只保留最新版本）

⊘ 跳過（找不到對應的 MP4 檔案）: NA-001.xml
⊘ 跳過（該 ID 已存在於 videos 表中）: CNNA-ST1-xxx (ID: 123)
✓ 檔案大小符合限制: CNNA-ST1-yyy (45.3MB)
→ 建立新記錄: CNNA-ST1-yyy (Video ID: 456)
✓ 完成完整分析: NA-002.xml

完整分析完成！
┌────────┬──────┐
│ 狀態   │ 數量 │
├────────┼──────┤
│ 已檢查 │ 50   │
│ 已處理 │ 5    │
│ 已跳過 │ 43   │
│ 錯誤   │ 2    │
└────────┴──────┘
```

## 與其他命令的比較

| 命令 | 條件 1 (XML+MP4) | 條件 2 (不存在記錄) | 條件 3 (大小限制) |
|------|-----------------|-------------------|------------------|
| `analyze:document` | 只需要 XML | ❌ 可處理已存在記錄 | ❌ 不檢查 |
| `analyze:video` | 只需要 MP4 | ❌ 可處理已存在記錄 | ✅ 檢查 |
| `analyze:full` | ✅ 需要 XML+MP4 | ✅ **不可處理已存在記錄** | ✅ 檢查 |

## 常見問題

### Q1: 為什麼我的影片被跳過？

**A:** 請檢查以下三個條件：
1. 確認 XML 和 MP4 檔案都存在且在同一目錄
2. 檢查 `videos` 表中是否已有該 `source_id` 的記錄
3. 確認影片檔案大小不超過 300MB

### Q2: 如果我想重新分析已存在的影片怎麼辦？

**A:** 有兩個選擇：

1. **使用分開的命令**（推薦）：
```bash
# 重新分析文檔元數據
docker compose exec app php artisan analyze:document --source=CNN

# 重新分析影片內容
docker compose exec app php artisan analyze:video --source=CNN
```

2. **刪除記錄後重新分析**（慎用）：
```sql
-- 先備份
SELECT * FROM videos WHERE source_id = 'CNNA-ST1-xxx';
SELECT * FROM analysis_results WHERE video_id = 123;

-- 刪除記錄
DELETE FROM analysis_results WHERE video_id = 123;
DELETE FROM videos WHERE id = 123;

-- 重新執行 analyze:full
```

### Q3: 為什麼要限制不能處理已存在的記錄？

**A:** 這是為了避免以下問題：
- 如果之前使用 `analyze:document` 已建立記錄並提取了元數據
- 但尚未使用 `analyze:video` 分析影片內容
- 此時如果 `analyze:full` 處理該記錄，會導致：
  - 重複分析文本（浪費 API 配額）
  - 可能覆蓋已有的正確元數據
  - 造成分析流程混亂

**解決方案**：
- `analyze:full` 專門處理**全新的影片**
- 已存在的記錄請使用 `analyze:document` 或 `analyze:video` 個別處理

### Q4: 檔案大小限制為什麼是 300MB？

**A:** 這是 Gemini API 的官方限制。如果您的影片超過此大小：
1. 考慮壓縮影片
2. 降低影片解析度或位元率
3. 聯繫 Google 確認是否有企業版配額可以提高限制

## 監控與日誌

### 查看跳過的原因

```bash
# 查看所有跳過的記錄
docker compose exec app grep "跳過" storage/logs/laravel.log

# 查看特定 source_id 的處理記錄
docker compose exec app grep "CNNA-ST1-xxx" storage/logs/laravel.log
```

### 檢查 videos 表中的記錄

```sql
-- 查看特定來源的記錄數量
SELECT COUNT(*) FROM videos WHERE source_name = 'CNN';

-- 查看最近建立的記錄
SELECT id, source_id, analysis_status, created_at 
FROM videos 
WHERE source_name = 'CNN'
ORDER BY created_at DESC 
LIMIT 10;
```

## 錯誤處理機制

### 自動清理失敗記錄

當分析失敗時（例如 API 配額超限、網路錯誤等），命令會自動清理剛建立的記錄：

```php
// 如果是剛建立的記錄且分析失敗，刪除該記錄
if (isset($videoId) && isset($isNewlyCreated) && $isNewlyCreated) {
    $this->videoRepository->delete($videoId);
    $this->line("\n⚠️  已刪除失敗的新記錄 (Video ID: {$videoId})");
}
```

### 為什麼要刪除失敗記錄？

**問題場景**：
- 當遇到 API 配額超限（429 Too Many Requests）時
- 每次嘗試都會先建立記錄，然後分析失敗
- 導致資料庫中累積大量狀態為 `VIDEO_ANALYSIS_FAILED` 的空記錄

**解決方案**：
- ✅ 分析成功：記錄保留，保存分析結果
- ❌ 分析失敗：刪除剛建立的記錄，避免累積垃圾資料

### 輸出示例

#### 成功的情況
```
✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 55)
✓ 完成完整分析: PY-02FR_GAME ON_WALTON GOGGI_CNNA-ST1-2000000000090867_902_1.xml
```

#### 失敗的情況（舊行為）
```
✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 55)
✗ 分析失敗: ... - Gemini API 影片分析失敗: ... 429 Too Many Requests ...
# 記錄保留在資料庫中，狀態為 VIDEO_ANALYSIS_FAILED
```

#### 失敗的情況（新行為）
```
✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 55)
✗ 分析失敗: ... - Gemini API 影片分析失敗: ... 429 Too Many Requests ...
⚠️  已刪除失敗的新記錄 (Video ID: 55)
# 記錄已從資料庫中刪除，不會累積
```

### 優點

1. **保持資料庫整潔**：
   - 不會累積大量失敗的空記錄
   - 只保留成功分析的有效資料

2. **易於重試**：
   - 解決問題後（例如增加 API 配額）
   - 重新執行命令會自動處理之前失敗的檔案
   - 因為記錄已刪除，符合「條件 2：不存在記錄」

3. **清晰的狀態**：
   - `videos` 表中的記錄要麼是成功的，要麼不存在
   - 不會有「半成品」記錄混淆視線

### 注意事項

⚠️ **只刪除新建立的記錄**

如果記錄是之前已經存在的（不應該發生，因為條件 2 會跳過），則不會刪除。這個機制只針對在當前執行中新建立的記錄。

⚠️ **級聯刪除**

由於資料庫外鍵約束，刪除 `video` 記錄時會自動刪除相關的 `analysis_results` 記錄：

```sql
-- 在 VideoRepository::delete() 中
$video->delete();  // 會級聯刪除 analysis_results
```

## 總結

新的執行條件和錯誤處理機制確保了：
1. ✅ 只處理完整的影片資料（XML + MP4）
2. ✅ 避免與其他分析命令衝突
3. ✅ 不浪費 API 配額在超大檔案上
4. ✅ 保持分析流程的一致性和可追蹤性
5. ✅ **自動清理失敗記錄，避免資料庫垃圾累積**

這些限制和機制使得 `analyze:full` 成為處理**全新影片**的最佳選擇，而不會干擾現有的分析工作流程或污染資料庫。

