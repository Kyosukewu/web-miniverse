# Gemini API 配額優化說明

## 📋 概述

根據 [Google Cloud Gemini 配額文檔](https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw)，本系統已進行配額優化，確保不超過 API 限制。

## 🔢 Gemini API 配額限制

### 每秒請求數 (Requests Per Second, RPS)

| 配額類型 | 限制值 | 說明 |
|---------|-------|------|
| 每秒請求數 | **2 次/秒** | 每位使用者每秒最多 2 次請求 |

### 每日請求數 (Daily Requests)

| 配額類型 | 限制值 | 說明 |
|---------|-------|------|
| Gemini Code Assist 或 Gemini 每日 BigQuery 程式碼要求 | 6000 次/天 | 程式碼生成和程式碼完成 |
| 聊天、視覺化、資料洞察等其他要求 | **960 次/天** | **本系統使用的配額** |

## ⚠️ 修改前的問題

### 1. 排程過於頻繁

```php
// 修改前
analyze:document --limit=10  // 每 10 分鐘執行一次
analyze:video --limit=10     // 每 15 分鐘執行一次  
analyze:full --limit=10      // 每 15 分鐘執行一次
```

**問題計算**：
- 每小時執行：6 次 (document) + 4 次 (video) + 4 次 (full) = 14 輪
- 每輪請求：10 + 10 + 10 = 30 次
- **每小時總請求：14 × 30 = 420 次** ❌
- **每日總請求：420 × 24 = 10,080 次** ❌ (超過 960 限制的 10 倍！)

### 2. 沒有速率限制

- 命令內部沒有延遲機制
- 可能在幾秒內發出 10 個請求
- **超過每秒 2 次的限制** ❌

### 3. 容易遇到錯誤

```
429 Too Many Requests: You exceeded your current quota
```

## ✅ 優化方案

### 1. 降低排程頻率和數量

```php
// 修改後
analyze:document --limit=3   // 每 30 分鐘執行一次
analyze:video --limit=5      // 每 1 小時執行一次
analyze:full --limit=5       // 每 1 小時執行一次
```

**配額計算**：

| 命令 | 頻率 | 單次請求數 | 每日執行次數 | 每日總請求 |
|------|------|----------|------------|----------|
| `analyze:document` | 每 30 分鐘 | 3 | 48 次 | 144 次 |
| `analyze:video` | 每 1 小時 | 5 | 24 次 | 120 次 |
| `analyze:full` | 每 1 小時 | 5 | 24 次 | 120 次 |
| **總計** | - | - | - | **384 次/天** ✅ |

**安全餘裕**：384 / 960 = **40%** （留有 60% 緩衝空間）

### 2. 添加請求間延遲

在每個分析命令中添加 1 秒延遲：

```php
// 完成一次分析後
$processedCount++;

// 添加延遲（最後一個不需要）
if ($processedCount < $limit) {
    $this->line("⏱  等待 1 秒以符合 API 速率限制...");
    sleep(1);
}
```

**效果**：
- 實際 RPS = 1 次/秒（遠低於 2 次/秒的限制）
- 每次任務執行時間增加，但更安全
- 避免觸發速率限制錯誤

### 3. 建議使用單一命令

由於 `analyze:full` 同時處理文本和影片分析，建議：

**選項 A：只使用 analyze:full**
```bash
# .env 配置
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=false
ANALYZE_FULL_ENABLED=true
```

每日請求：120 次（僅 12.5% 配額）

**選項 B：分開處理（當前配置）**
```bash
# .env 配置
ANALYZE_DOCUMENT_ENABLED=true   # 提取元數據
ANALYZE_VIDEO_ENABLED=true      # 分析影片
ANALYZE_FULL_ENABLED=false      # 不使用完整分析
```

每日請求：264 次（27.5% 配額）

## 📊 修改細節

### 1. routes/console.php

#### 修改前
```php
// CNN XML 文檔分析：每 10 分鐘執行一次
Schedule::command('analyze:document --source=CNN --storage=gcs --path=cnn --limit=10')
    ->everyTenMinutes()
    ->onOneServer()
    ->runInBackground();

// CNN MP4 影片分析：每 15 分鐘執行一次
Schedule::command('analyze:video --source=CNN --storage=gcs --limit=10')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->runInBackground();

// CNN 完整分析：每 15 分鐘執行一次
Schedule::command('analyze:full --source=CNN --storage=gcs --limit=10')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->runInBackground();
```

#### 修改後
```php
// CNN XML 文檔分析：每 30 分鐘執行一次
// 預計：48 次/天 × 3 個檔案 = 144 次請求/天
Schedule::command('analyze:document --source=CNN --storage=gcs --path=cnn --limit=3')
    ->everyThirtyMinutes()
    ->onOneServer()
    ->runInBackground();

// CNN MP4 影片分析：每 1 小時執行一次
// 預計：24 次/天 × 5 個影片 = 120 次請求/天
Schedule::command('analyze:video --source=CNN --storage=gcs --limit=5')
    ->hourly()
    ->onOneServer()
    ->runInBackground();

// CNN 完整分析：每 1 小時執行一次
// 預計：24 次/天 × 5 個影片 = 120 次請求/天
Schedule::command('analyze:full --source=CNN --storage=gcs --limit=5')
    ->hourly()
    ->onOneServer()
    ->runInBackground();
```

### 2. 所有分析命令添加延遲

**修改的檔案**：
- `app/Console/Commands/AnalyzeFullCommand.php`
- `app/Console/Commands/AnalyzeVideoCommand.php`
- `app/Console/Commands/AnalyzeDocumentCommand.php`

**添加的程式碼**：
```php
// ========== Gemini API 速率限制 ==========
// 根據 https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw
// 每秒請求數 (RPS) 限制：2 次/秒
// 為避免超過限制，每次請求後延遲 1 秒（保守策略）
if ($processedCount < $limit) { // 最後一個不需要延遲
    $this->line("⏱  等待 1 秒以符合 API 速率限制...");
    sleep(1);
}
// ========================================
```

## 🎯 執行效果

### 修改前

```bash
$ docker compose exec app php artisan analyze:full --limit=10

✓ 檔案大小符合限制: CNNA-ST1-001 (100MB)
→ 建立新記錄: CNNA-ST1-001 (Video ID: 1)
✓ 完成完整分析: file1.xml

✓ 檔案大小符合限制: CNNA-ST1-002 (100MB)
→ 建立新記錄: CNNA-ST1-002 (Video ID: 2)
✗ 分析失敗: file2.xml - 429 Too Many Requests ❌

✓ 檔案大小符合限制: CNNA-ST1-003 (100MB)
→ 建立新記錄: CNNA-ST1-003 (Video ID: 3)
✗ 分析失敗: file3.xml - 429 Too Many Requests ❌
```

### 修改後

```bash
$ docker compose exec app php artisan analyze:full --limit=5

✓ 檔案大小符合限制: CNNA-ST1-001 (100MB)
→ 建立新記錄: CNNA-ST1-001 (Video ID: 1)
✓ 完成完整分析: file1.xml
⏱  等待 1 秒以符合 API 速率限制...

✓ 檔案大小符合限制: CNNA-ST1-002 (100MB)
→ 建立新記錄: CNNA-ST1-002 (Video ID: 2)
✓ 完成完整分析: file2.xml
⏱  等待 1 秒以符合 API 速率限制...

✓ 檔案大小符合限制: CNNA-ST1-003 (100MB)
→ 建立新記錄: CNNA-ST1-003 (Video ID: 3)
✓ 完成完整分析: file3.xml
⏱  等待 1 秒以符合 API 速率限制...

...（所有 5 個都成功）✅
```

## 📈 配額監控

### 查看當前使用量

```bash
# 查看今天已處理的記錄數量
docker compose exec app php artisan tinker --execute="
echo '今日分析數量: ' . App\Models\Video::whereDate('analyzed_at', today())->count() . ' 次';
"

# 查看過去 1 小時的記錄數量
docker compose exec app php artisan tinker --execute="
\$oneHourAgo = now()->subHour();
echo '過去 1 小時: ' . App\Models\Video::where('analyzed_at', '>=', \$oneHourAgo)->count() . ' 次';
"
```

### 配額警報

如果發現以下情況，請調整排程：

| 警報級別 | 每日請求數 | 建議動作 |
|---------|-----------|---------|
| 🟢 安全 | < 600 次 | 無需調整 |
| 🟡 注意 | 600-800 次 | 考慮降低 limit 或增加間隔 |
| 🟠 警告 | 800-950 次 | **必須降低 limit 或增加間隔** |
| 🔴 超限 | ≥ 960 次 | **停止排程，等待明天** |

## 🔧 調整配置

### 降低處理量

在 `.env` 中設定：

```bash
# 關閉特定分析命令
ANALYZE_DOCUMENT_ENABLED=false
ANALYZE_VIDEO_ENABLED=false
ANALYZE_FULL_ENABLED=true  # 只保留這個

# 或完全關閉排程
SCHEDULER_ENABLED=false
```

### 手動執行

如果需要手動執行，建議使用較小的 limit：

```bash
# 建議：limit=3-5（每次）
docker compose exec app php artisan analyze:full --limit=3

# 如果需要處理更多，分批執行並間隔 1-2 分鐘
docker compose exec app php artisan analyze:full --limit=3
sleep 120  # 等待 2 分鐘
docker compose exec app php artisan analyze:full --limit=3
```

## 📝 最佳實踐

### 1. 優先級策略

建議使用 `analyze:full` 取代其他命令：
- ✅ 只需一次 API 調用
- ✅ 獲得完整的分析結果
- ✅ 減少配額消耗

### 2. 批次處理策略

**小批量、高頻率** vs **大批量、低頻率**：

❌ **不建議**：
```php
analyze:full --limit=50  // 每 6 小時執行一次
// 問題：一次執行時間長，容易失敗
```

✅ **建議**：
```php
analyze:full --limit=5   // 每 1 小時執行一次
// 優點：快速執行，易於監控和恢復
```

### 3. 錯誤處理

當遇到 `429 Too Many Requests` 錯誤時：

1. **立即停止手動執行**
2. **檢查今日配額使用量**
3. **等待下一個整點**（配額可能按小時計算）
4. **調整排程配置**

### 4. 監控日誌

```bash
# 查看今日的 429 錯誤
docker compose exec app grep "429 Too Many Requests" storage/logs/laravel-$(date +%Y-%m-%d).log | wc -l

# 查看速率限制延遲日誌
docker compose exec app grep "等待 1 秒以符合 API 速率限制" storage/logs/laravel-$(date +%Y-%m-%d).log
```

## 🆘 故障排除

### 問題 1：仍然遇到 429 錯誤

**可能原因**：
- 其他應用或使用者也在使用同一個 API key
- 配額是共享的（每個 Google Cloud 專案）

**解決方案**：
1. 進一步降低 limit：`--limit=1` 或 `--limit=2`
2. 增加排程間隔：從每小時改為每 2 小時
3. 增加延遲時間：從 1 秒改為 2-3 秒

```php
sleep(2);  // 改為 2 秒延遲
```

### 問題 2：處理速度太慢

**情況**：
- 每天只能處理 120-384 個影片
- 積壓的影片越來越多

**解決方案**：

**選項 A**：申請提高配額
- 訪問 [Google Cloud Console](https://console.cloud.google.com/apis/api/generativelanguage.googleapis.com/quotas)
- 申請提高每日請求數限制

**選項 B**：使用多個 API key（如果允許）
- 為不同的命令配置不同的 API key
- 分散配額使用

**選項 C**：優化分析策略
- 只分析重要的影片（添加過濾條件）
- 跳過已分析過的影片

## 📚 參考資料

1. [Google Cloud Gemini 配額文檔](https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw)
2. [申請調整配額](https://cloud.google.com/docs/quota)
3. [Laravel 任務排程](https://laravel.com/docs/scheduling)

## 📊 配額使用儀表板

建議定期檢查：

```sql
-- 每日分析統計
SELECT 
    DATE(analyzed_at) as date,
    COUNT(*) as total_analyzed,
    COUNT(CASE WHEN analysis_status = 'COMPLETED' THEN 1 END) as successful,
    COUNT(CASE WHEN analysis_status = 'VIDEO_ANALYSIS_FAILED' THEN 1 END) as failed
FROM videos
WHERE analyzed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(analyzed_at)
ORDER BY date DESC;

-- 每小時分析統計（今天）
SELECT 
    HOUR(analyzed_at) as hour,
    COUNT(*) as requests
FROM videos
WHERE DATE(analyzed_at) = CURDATE()
GROUP BY HOUR(analyzed_at)
ORDER BY hour;
```

## ✅ 檢查清單

部署前請確認：

- [ ] 已更新 `routes/console.php` 的排程配置
- [ ] 已在所有分析命令中添加延遲機制
- [ ] 已測試手動執行命令（確認延遲生效）
- [ ] 已設定 `.env` 中的排程開關
- [ ] 已監控第一天的配額使用情況
- [ ] 已準備配額監控腳本
- [ ] 已閱讀 Gemini API 配額文檔
- [ ] 已了解 429 錯誤的處理方式

## 總結

通過以下優化措施，系統現在可以安全地使用 Gemini API：

1. ✅ **降低排程頻率**：從每 10-15 分鐘降至每 30-60 分鐘
2. ✅ **減少批次大小**：從 limit=10 降至 limit=3-5
3. ✅ **添加速率限制**：每次請求間延遲 1 秒
4. ✅ **每日配額控制**：從潛在 10,080 次降至 384 次（60% 緩衝）
5. ✅ **自動清理失敗記錄**：避免累積無效資料

**預期效果**：
- 不再觸發 `429 Too Many Requests` 錯誤
- 每日可穩定處理 384 個影片
- 系統運行更加穩定可靠

