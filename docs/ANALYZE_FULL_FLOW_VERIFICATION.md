# AnalyzeFullCommand 流程驗證

## 📋 驗證目標

1. ✅ **不符合分析條件的資料不會發送 API 請求**
2. ✅ **排程及運作流程符合 Gemini API 限制**

## 🔍 流程檢查

### 條件檢查順序（在發送 API 請求之前）

```
開始處理文檔
    ↓
讀取文檔內容（XML/TXT）
    ↓
[檢查 1] 檔案內容是否為空？
    ↓ 是 → continue（跳過，不發送 API）✅
    ↓ 否
[條件 1] 是否找到對應的 MP4 檔案？
    ↓ 否 → continue（跳過，不發送 API）✅
    ↓ 是
[條件 2] videos 表中是否已存在記錄？
    ↓ 是 → continue（跳過，不發送 API）✅
    ↓ 否
[條件 3] 影片檔案大小是否 ≤ 300MB？
    ↓ 否 → continue（跳過，不發送 API）✅
    ↓ 是
建立新的 video 記錄
    ↓
更新狀態為 PROCESSING
    ↓
【這裡才開始發送 API 請求】← executeFullAnalysis()
    ↓
延遲 1 秒（無論成功或失敗）
```

### 代碼位置驗證

#### ✅ 條件 1：MP4 檔案檢查（第 150-163 行）

```php
// 如果找不到 MP4 檔案，跳過（條件 1 不符合）
if (null === $nasPath || str_ends_with(strtolower($nasPath), '.xml') || str_ends_with(strtolower($nasPath), '.txt')) {
    $this->line("\n⊘ 跳過（找不到對應的 MP4 檔案）: {$documentFile['file_name']}");
    $skippedCount++;
    $progressBar->advance();
    continue;  // ✅ 直接跳過，不會發送 API
}
```

**驗證**：✅ 使用 `continue`，不會執行後續的 API 調用

#### ✅ 條件 2：videos 表檢查（第 165-178 行）

```php
// 如果已存在記錄，直接跳過（條件 2 不符合）
if (null !== $existingVideo) {
    $this->line("\n⊘ 跳過（該 ID 已存在於 videos 表中）: {$documentFile['source_id']} (ID: {$existingVideo->id})");
    $skippedCount++;
    $progressBar->advance();
    continue;  // ✅ 直接跳過，不會發送 API
}
```

**驗證**：✅ 使用 `continue`，不會執行後續的 API 調用

#### ✅ 條件 3：檔案大小檢查（第 180-224 行）

```php
// 檢查檔案大小限制（條件 3）
if ($fileSizeMB > $maxFileSizeMB) {
    $this->warn("\n⚠️  跳過（檔案過大）: {$documentFile['source_id']} (檔案大小: {$fileSizeMB}MB > {$maxFileSizeMB}MB)");
    $skippedCount++;
    $progressBar->advance();
    continue;  // ✅ 直接跳過，不會發送 API
}
```

**驗證**：✅ 使用 `continue`，不會執行後續的 API 調用

#### ✅ API 調用位置（第 268-273 行）

```php
// 執行完整分析（文本 + 影片）- 這裡會發送 Gemini API 請求
$analysisResult = $this->analyzeService->executeFullAnalysis(
    $videoId,
    $textContent,
    $promptVersion,
    $videoFilePath
);
```

**驗證**：✅ 只有在所有條件都通過後才會執行

## 🚦 Gemini API 速率限制驗證

### 限制要求

根據 [Gemini API 配額文檔](https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw)：
- **每秒請求數 (RPS)**：2 次/秒
- **每日請求數**：960 次/天

### 實現方式

#### ✅ 成功情況下的延遲（第 275-279 行）

```php
// 執行完整分析（文本 + 影片）- 這裡會發送 Gemini API 請求
$analysisResult = $this->analyzeService->executeFullAnalysis(...);

// ========== Gemini API 速率限制 ==========
// 每次 API 請求後延遲 1 秒（保守策略）
$this->line("⏱  等待 1 秒以符合 API 速率限制...");
sleep(1);
// ========================================
```

**驗證**：✅ API 請求後立即延遲 1 秒

#### ✅ 失敗情況下的延遲（第 294-302 行）

```php
} catch (\Exception $e) {
    // ========== 如果已發送 API 請求但失敗，也需要延遲 ==========
    if (isset($videoId)) {
        // 已建立記錄表示已通過所有條件檢查，可能已發送 API 請求
        $this->line("⏱  等待 1 秒以符合 API 速率限制（失敗後延遲）...");
        sleep(1);
    }
    // ========================================
    ...
}
```

**驗證**：✅ 即使 API 失敗，也會延遲 1 秒

### 速率計算

**實際 RPS**：
- 每次 API 請求後延遲 1 秒
- 實際 RPS = **1 次/秒**
- **遠低於 2 次/秒的限制** ✅

**每日請求數**：
- 排程：每 1 小時執行一次，每次 `--limit=5`
- 每日執行：24 次
- 每日請求：24 × 5 = **120 次**
- **遠低於 960 次/天的限制** ✅

## 📊 執行流程圖

```
┌─────────────────────────────────────────────────────────┐
│ 開始處理文檔                                             │
└────────────────────┬────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────┐
│ 讀取文檔內容                                             │
└────────────────────┬────────────────────────────────────┘
                     ↓
         ┌───────────┴───────────┐
         │ 檔案內容為空？         │
         └───┬───────────────┬───┘
             │ 是            │ 否
             ↓               ↓
        [continue]    ┌─────────────────┐
        (跳過)        │ 條件 1: MP4？   │
                     └───┬───────────┬──┘
                         │ 否        │ 是
                         ↓           ↓
                    [continue]  ┌─────────────────┐
                    (跳過)      │ 條件 2: 已存在？│
                               └───┬───────────┬──┘
                                   │ 是        │ 否
                                   ↓           ↓
                              [continue]  ┌─────────────────┐
                              (跳過)      │ 條件 3: 大小？  │
                                         └───┬───────────┬──┘
                                             │ 否        │ 是
                                             ↓           ↓
                                        [continue]  ┌──────────────┐
                                        (跳過)      │ 建立記錄     │
                                                   └───┬──────────┘
                                                       ↓
                                              ┌─────────────────┐
                                              │ 發送 API 請求   │ ← 只有這裡才發送
                                              └───┬───────────┬──┘
                                                  │ 成功      │ 失敗
                                                  ↓           ↓
                                         ┌─────────────┐  ┌─────────────┐
                                         │ 延遲 1 秒   │  │ 延遲 1 秒   │
                                         └─────────────┘  └─────────────┘
```

## ✅ 驗證結果

### 1. 不符合條件的資料不會發送 API

| 條件 | 檢查位置 | 跳過方式 | 是否發送 API |
|------|---------|---------|------------|
| 檔案內容為空 | 第 143-148 行 | `continue` | ❌ 否 |
| 找不到 MP4 檔案 | 第 158-163 行 | `continue` | ❌ 否 |
| videos 表中已存在 | 第 173-178 行 | `continue` | ❌ 否 |
| 檔案大小 > 300MB | 第 205-210 行 | `continue` | ❌ 否 |
| 無法取得檔案大小 | 第 213-224 行 | `continue` | ❌ 否 |

**結論**：✅ 所有不符合條件的資料都會在發送 API 請求之前被過濾掉

### 2. 排程符合 Gemini API 限制

| 限制類型 | 限制值 | 實際使用 | 狀態 |
|---------|-------|---------|------|
| 每秒請求數 (RPS) | 2 次/秒 | 1 次/秒 | ✅ 符合 |
| 每日請求數 | 960 次/天 | 120 次/天 | ✅ 符合 |

**結論**：✅ 排程和運作流程完全符合 Gemini API 限制

## 🔍 測試場景

### 場景 1：所有條件都不符合

```bash
# 測試：處理 10 個文檔，但都不符合條件
docker compose exec app php artisan analyze:full --limit=10

# 預期輸出：
⊘ 跳過（找不到對應的 MP4 檔案）: file1.xml
⊘ 跳過（該 ID 已存在於 videos 表中）: CNNA-ST1-001 (ID: 1)
⚠️  跳過（檔案過大）: CNNA-ST1-002 (檔案大小: 350MB > 300MB)
...

# 驗證：不應該有任何 API 請求（檢查日誌）
docker compose exec app grep "executeFullAnalysis" storage/logs/laravel.log | wc -l
# 應該返回 0
```

### 場景 2：部分符合條件

```bash
# 測試：處理 5 個文檔，其中 2 個符合條件
docker compose exec app php artisan analyze:full --limit=5

# 預期輸出：
⊘ 跳過（找不到對應的 MP4 檔案）: file1.xml
✓ 檔案大小符合限制: CNNA-ST1-003 (100MB)
→ 建立新記錄: CNNA-ST1-003 (Video ID: 10)
⏱  等待 1 秒以符合 API 速率限制...
✓ 完成完整分析: file3.xml

⊘ 跳過（該 ID 已存在於 videos 表中）: CNNA-ST1-004 (ID: 2)
✓ 檔案大小符合限制: CNNA-ST1-005 (50MB)
→ 建立新記錄: CNNA-ST1-005 (Video ID: 11)
⏱  等待 1 秒以符合 API 速率限制...
✓ 完成完整分析: file5.xml

# 驗證：應該只有 2 次 API 請求
docker compose exec app grep "executeFullAnalysis" storage/logs/laravel.log | tail -2
```

### 場景 3：API 請求失敗

```bash
# 測試：模擬 API 配額超限
# 預期行為：
✓ 檔案大小符合限制: CNNA-ST1-006 (100MB)
→ 建立新記錄: CNNA-ST1-006 (Video ID: 12)
⏱  等待 1 秒以符合 API 速率限制（失敗後延遲）...
✗ 分析失敗: file6.xml - 429 Too Many Requests
⚠️  已刪除失敗的新記錄 (Video ID: 12)

# 驗證：
# 1. 即使失敗也會延遲 ✅
# 2. 失敗記錄會被刪除 ✅
# 3. 下次執行時可以重試 ✅
```

## 📝 檢查清單

部署前請確認：

- [x] 所有條件檢查都在 API 調用之前
- [x] 不符合條件的資料使用 `continue` 跳過
- [x] API 調用只在所有條件通過後執行
- [x] 成功情況下會延遲 1 秒
- [x] 失敗情況下也會延遲 1 秒
- [x] 實際 RPS = 1 次/秒（低於 2 次/秒限制）
- [x] 每日請求數 = 120 次（低於 960 次限制）
- [x] 排程配置正確（每 1 小時，limit=5）

## 🎯 結論

### ✅ 驗證通過

1. **不符合分析條件的資料不會發送 API 請求**
   - 所有條件檢查都在 API 調用之前
   - 使用 `continue` 直接跳過不符合條件的資料
   - 只有通過所有條件的資料才會調用 `executeFullAnalysis()`

2. **排程及運作流程符合 Gemini API 限制**
   - 每次 API 請求後延遲 1 秒（無論成功或失敗）
   - 實際 RPS = 1 次/秒（遠低於 2 次/秒限制）
   - 每日請求數 = 120 次（僅使用 12.5% 配額）
   - 排程頻率：每 1 小時執行一次
   - 批次大小：每次處理 5 個影片

### 📊 配額使用情況

| 項目 | 值 | 限制 | 使用率 |
|------|---|------|--------|
| 每秒請求數 | 1 次/秒 | 2 次/秒 | 50% |
| 每日請求數 | 120 次/天 | 960 次/天 | 12.5% |

**結論**：系統運行安全，留有充足的配額緩衝空間。

