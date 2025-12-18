# analyze:full 錯誤處理與自動清理機制

## 問題描述

### 修改前的問題

當 `analyze:full` 命令遇到分析錯誤時（例如 API 配額超限），會出現以下問題：

```bash
✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 55)
✗ 分析失敗: ... - Gemini API 影片分析失敗: ... 429 Too Many Requests ...

✓ 檔案大小符合限制: CNNA-ST1-200000000009086A (94.24MB)
→ 建立新記錄: CNNA-ST1-200000000009086A (Video ID: 56)
✗ 分析失敗: ... - Gemini API 影片分析失敗: ... 429 Too Many Requests ...
```

**問題**：
1. 記錄先被建立（`findOrCreate()`）
2. 然後進行分析
3. 分析失敗，但記錄已經存在於資料庫中
4. 狀態被更新為 `VIDEO_ANALYSIS_FAILED`
5. 導致資料庫中累積大量失敗的空記錄

**影響**：
- 資料庫充斥著無用的失敗記錄
- 這些記錄沒有任何分析結果
- 下次執行時會被「條件 2」跳過（已存在記錄）
- 需要手動清理資料庫才能重試

## 解決方案

### 自動刪除失敗記錄

當分析失敗時，自動刪除剛建立的記錄：

```php
// 建立新的影片記錄
$videoId = $this->videoRepository->findOrCreate($createData);
$isNewlyCreated = true; // 標記為新建立的記錄

try {
    // 執行完整分析
    $analysisResult = $this->analyzeService->executeFullAnalysis(...);
    
    // 分析成功，記錄保留
    $processedCount++;
    
} catch (\Exception $e) {
    // 分析失敗
    
    // 如果是剛建立的記錄，刪除它
    if (isset($videoId) && isset($isNewlyCreated) && $isNewlyCreated) {
        $this->videoRepository->delete($videoId);
        $this->line("\n⚠️  已刪除失敗的新記錄 (Video ID: {$videoId})");
    }
    
    $this->error("\n✗ 分析失敗: {$documentFile['file_name']} - {$e->getMessage()}");
}
```

## 實現細節

### 1. AnalyzeFullCommand.php

**修改位置**：第 226-298 行

**變更內容**：
- 添加 `$isNewlyCreated` 標誌來追蹤記錄是否為新建立
- 在 catch 塊中檢查此標誌
- 如果是新記錄且分析失敗，調用 `delete()` 刪除記錄

### 2. VideoRepository.php

**新增方法**：`delete(int $videoId): bool`

```php
/**
 * Delete a video and its related records.
 *
 * @param int $videoId
 * @return bool
 */
public function delete(int $videoId): bool
{
    $video = Video::find($videoId);
    
    if (null === $video) {
        return false;
    }

    // Delete will cascade to analysis_results due to foreign key constraint
    return $video->delete();
}
```

**說明**：
- 刪除 video 記錄
- 由於資料庫外鍵約束，會自動級聯刪除相關的 `analysis_results` 記錄

## 執行流程對比

### 修改前（有問題）

```
建立記錄 (Video ID: 55)
    ↓
執行分析
    ↓ 失敗（API 配額超限）
更新狀態為 VIDEO_ANALYSIS_FAILED
    ↓
記錄保留在資料庫 ❌
```

### 修改後（已修復）

```
建立記錄 (Video ID: 55)
標記為新建立 ($isNewlyCreated = true)
    ↓
執行分析
    ↓ 失敗（API 配額超限）
檢查是否為新記錄
    ↓ 是
刪除記錄 ✅
    ↓
資料庫保持整潔
```

## 使用示例

### 場景：API 配額超限

#### 第一次執行（配額用完）

```bash
$ docker compose exec app php artisan analyze:full --limit=10

✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 55)
✗ 分析失敗: PY-02FR_GAME ON_WALTON GOGGI_CNNA-ST1-2000000000090867_902_1.xml 
   - Gemini API 影片分析失敗: Client error: ... 429 Too Many Requests ...
⚠️  已刪除失敗的新記錄 (Video ID: 55)

✓ 檔案大小符合限制: CNNA-ST1-200000000009086A (94.24MB)
→ 建立新記錄: CNNA-ST1-200000000009086A (Video ID: 56)
✗ 分析失敗: PRE-4AM_PRELIM SCRIPT_CNNA-ST1-200000000009086a_902_0.xml
   - Gemini API 影片分析失敗: Client error: ... 429 Too Many Requests ...
⚠️  已刪除失敗的新記錄 (Video ID: 56)

完整分析完成！
┌────────┬──────┐
│ 狀態   │ 數量 │
├────────┼──────┤
│ 已檢查 │ 10   │
│ 已處理 │ 0    │
│ 已跳過 │ 8    │
│ 錯誤   │ 2    │
└────────┴──────┘
```

#### 第二次執行（配額恢復後）

```bash
$ docker compose exec app php artisan analyze:full --limit=10

✓ 檔案大小符合限制: CNNA-ST1-2000000000090867 (167.23MB)
→ 建立新記錄: CNNA-ST1-2000000000090867 (Video ID: 101)
✓ 完成完整分析: PY-02FR_GAME ON_WALTON GOGGI_CNNA-ST1-2000000000090867_902_1.xml

✓ 檔案大小符合限制: CNNA-ST1-200000000009086A (94.24MB)
→ 建立新記錄: CNNA-ST1-200000000009086A (Video ID: 102)
✓ 完成完整分析: PRE-4AM_PRELIM SCRIPT_CNNA-ST1-200000000009086a_902_0.xml

完整分析完成！
┌────────┬──────┐
│ 狀態   │ 數量 │
├────────┼──────┤
│ 已檢查 │ 10   │
│ 已處理 │ 2    │
│ 已跳過 │ 8    │
│ 錯誤   │ 0    │
└────────┴──────┘
```

**關鍵差異**：
- 由於失敗記錄已被刪除，第二次執行時可以正常處理這些檔案
- 不需要手動清理資料庫
- 自動重試機制正常運作

## 優點

### 1. 自動清理

✅ 無需手動刪除失敗記錄  
✅ 資料庫保持整潔  
✅ 只保留成功分析的有效資料

### 2. 易於重試

✅ 解決錯誤後（例如增加 API 配額）  
✅ 重新執行命令會自動重試之前失敗的檔案  
✅ 因為記錄已刪除，符合「條件 2：不存在記錄」

### 3. 防止資料污染

✅ 避免 `VIDEO_ANALYSIS_FAILED` 記錄累積  
✅ 資料庫中只有兩種狀態：成功或不存在  
✅ 不會有「半成品」記錄

### 4. 清晰的狀態追蹤

✅ 已處理 = 成功分析並保存結果  
✅ 已跳過 = 不符合條件或已存在  
✅ 錯誤 = 臨時失敗，記錄已清理

## 注意事項

### ⚠️ 只刪除新建立的記錄

機制使用 `$isNewlyCreated` 標誌來判斷：

```php
$videoId = $this->videoRepository->findOrCreate($createData);
$isNewlyCreated = true; // 只有在這裡才設定為 true
```

**安全性**：
- 只有在當前執行中新建立的記錄才會被刪除
- 不會影響任何已存在的記錄（雖然條件 2 已經排除了這種情況）

### ⚠️ 級聯刪除

```sql
-- videos 表的外鍵約束
CONSTRAINT `analysis_results_video_id_foreign` 
FOREIGN KEY (`video_id`) 
REFERENCES `videos` (`id`) 
ON DELETE CASCADE
```

**影響**：
- 刪除 `videos` 記錄時，會自動刪除對應的 `analysis_results` 記錄
- 這是預期的行為，因為沒有影片就不應該有分析結果

### ⚠️ 日誌記錄

所有刪除操作都會被記錄：

```php
Log::info('[AnalyzeFullCommand] 已刪除分析失敗的新記錄', [
    'video_id' => $videoId,
    'source_id' => $documentFile['source_id'],
]);
```

可以通過日誌追蹤哪些記錄被刪除了。

## 驗證

### 檢查資料庫狀態

```sql
-- 查看失敗狀態的記錄數量（應該很少或沒有）
SELECT COUNT(*) 
FROM videos 
WHERE analysis_status = 'VIDEO_ANALYSIS_FAILED';

-- 查看最近建立的記錄
SELECT id, source_id, analysis_status, created_at, analyzed_at
FROM videos
ORDER BY created_at DESC
LIMIT 10;

-- 查看有分析結果的記錄數量
SELECT COUNT(*) 
FROM videos v
INNER JOIN analysis_results ar ON v.id = ar.video_id
WHERE ar.error_message IS NULL;
```

### 查看日誌

```bash
# 查看刪除記錄的日誌
docker compose exec app grep "已刪除分析失敗的新記錄" storage/logs/laravel.log

# 查看完整分析失敗的日誌
docker compose exec app grep "完整分析失敗" storage/logs/laravel.log
```

## 與其他命令的對比

| 命令 | 建立記錄時機 | 失敗處理 |
|------|------------|---------|
| `analyze:document` | 分析前 | 更新為 TXT_ANALYSIS_FAILED（保留記錄） |
| `analyze:video` | 已存在 | 更新為 VIDEO_ANALYSIS_FAILED（保留記錄） |
| `analyze:full` | 分析前 | **刪除新建立的記錄**（不保留） ✅ |

**為什麼 `analyze:full` 要刪除記錄？**

1. **`analyze:document`**：
   - 處理已存在的記錄
   - 失敗後保留，因為記錄已有基本資訊
   - 可以單獨重試文本分析

2. **`analyze:video`**：
   - 處理已有文本分析的記錄
   - 失敗後保留，因為文本分析結果仍有價值
   - 可以單獨重試影片分析

3. **`analyze:full`**：
   - 處理全新的記錄
   - 失敗意味著沒有任何有用資訊
   - 刪除後可以完整重試
   - 避免「條件 2」阻擋重試

## 總結

通過自動刪除失敗記錄的機制：

1. ✅ **保持資料庫整潔**：不累積無用的失敗記錄
2. ✅ **簡化重試流程**：解決問題後直接重新執行即可
3. ✅ **清晰的資料狀態**：只有成功或不存在兩種狀態
4. ✅ **防止條件衝突**：不會因為失敗記錄而觸發「條件 2」跳過
5. ✅ **適合批次處理**：遇到臨時錯誤（如 API 限制）時不會中斷整個流程

這個機制使得 `analyze:full` 更適合處理大批量的新影片，特別是在 API 配額有限的情況下。

