# GCS 費用優化說明

## 📊 優化目標

減少 `analyze:full` 命令執行時的 GCS 操作費用和網路傳輸費用。

## ✅ 已實現的優化

### 1. **提前檢查條件 2（批量查詢）**

**優化前**：
```php
// 每個檔案都查詢一次資料庫
foreach ($documentFiles as $documentFile) {
    $existingVideo = $this->videoRepository->getBySourceId(...);
    if ($existingVideo) {
        continue; // 但 XML 已經讀取了
    }
    $fileContent = $this->storageService->readFile(...); // ❌ 不必要的 GCS 讀取
}
```

**優化後**：
```php
// 批量查詢所有 source_id，一次性完成
$sourceIds = array_unique(array_column($documentFiles, 'source_id'));
$existingVideos = $this->videoRepository->getBySourceIds($sourceName, $sourceIds);
$existingVideoMap = []; // 建立映射表

foreach ($documentFiles as $documentFile) {
    // 先檢查映射表，如果已存在直接跳過
    if (isset($existingVideoMap[$documentFile['source_id']])) {
        continue; // ✅ 避免讀取 XML
    }
    $fileContent = $this->storageService->readFile(...); // 只在需要時讀取
}
```

**節省**：
- 減少資料庫查詢：從 N 次降至 1 次
- 避免不必要的 XML 讀取：如果已存在記錄，不讀取 XML

### 2. **使用 GCS 元數據檢查檔案大小**

**優化前**：
```php
// 下載整個影片檔案（可能 100-300MB）
$videoFilePath = $this->storageService->getVideoFilePath($storageType, $nasPath);
$fileSize = filesize($videoFilePath); // ❌ 已下載整個檔案

if ($fileSizeMB > 300) {
    continue; // 但檔案已經下載了，浪費流量
}
```

**優化後**：
```php
// 只讀取元數據，不下載檔案
if ('gcs' === $storageType) {
    $disk = $this->storageService->getDisk($storageType);
    $fileSize = $disk->size($nasPath); // ✅ 只讀取元數據
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
    
    if ($fileSizeMB > 300) {
        continue; // ✅ 未下載檔案，節省流量
    }
}
```

**節省**：
- 避免下載不符合條件的影片檔案
- 假設 100 個檔案中有 20 個超過 300MB，節省約 2-6GB 的下載流量

### 3. **延遲下載影片檔案**

**優化前**：
```php
// 在檢查檔案大小時就下載
$videoFilePath = $this->storageService->getVideoFilePath(...); // 下載
// 檢查大小...
// 檢查其他條件...
// 如果不符合條件，檔案已經下載了
```

**優化後**：
```php
// 先檢查所有條件（使用元數據）
if ($fileSizeMB > 300) {
    continue; // ✅ 未下載
}
if ($existingVideo) {
    continue; // ✅ 未下載
}

// 所有條件都通過後才下載
$videoFilePath = $this->storageService->getVideoFilePath(...); // ✅ 只在需要時下載
```

**節省**：
- 避免下載不符合條件的檔案
- 減少不必要的網路傳輸

## 📊 費用節省估算

### 優化前（處理 100 個影片）

| 操作 | 次數 | 費用 |
|------|------|------|
| 掃描檔案（List） | 100 | $0.005 |
| 讀取 XML | 100 | $0.004 |
| 下載影片檢查大小 | 100 | $0.004 + 10GB 流量 ≈ $1.20 |
| **總計** | - | **約 $1.21** |

### 優化後（處理 100 個影片，假設 20 個不符合條件）

| 操作 | 次數 | 費用 |
|------|------|------|
| 掃描檔案（List） | 100 | $0.005 |
| 讀取 XML | 80（跳過 20 個已存在） | $0.0032 |
| 檢查影片元數據 | 80 | $0.0032 |
| 下載影片（只下載符合條件的） | 60（跳過 20 個過大） | $0.0024 + 6GB 流量 ≈ $0.72 |
| **總計** | - | **約 $0.73** |

**節省**：約 **40%** 的費用（$1.21 → $0.73）

## 🔧 技術實現

### 1. 批量查詢方法

**VideoRepository.php**：
```php
public function getBySourceIds(string $sourceName, array $sourceIds): Collection
{
    if (empty($sourceIds)) {
        return collect();
    }

    return Video::where('source_name', $sourceName)
        ->whereIn('source_id', $sourceIds)
        ->get();
}
```

### 2. GCS 元數據檢查

**AnalyzeFullCommand.php**：
```php
if ('gcs' === $storageType) {
    $disk = $this->storageService->getDisk($storageType);
    // 只讀取元數據，不下載檔案
    $fileSize = $disk->size($nasPath);
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
}
```

### 3. 延遲下載

**AnalyzeFullCommand.php**：
```php
// 所有條件檢查完成後
if ('gcs' === $storageType) {
    // 現在才下載影片檔案
    $videoFilePath = $this->storageService->getVideoFilePath($storageType, $nasPath);
}
```

## 📈 優化效果

### 執行流程對比

**優化前**：
```
掃描檔案 → 讀取 XML → 下載影片 → 檢查大小 → 檢查條件 2 → 分析
                                    ↑
                              如果不符合條件，已經下載了
```

**優化後**：
```
掃描檔案 → 批量檢查條件 2 → 讀取 XML（只在需要時）→ 檢查元數據（不下載）→ 下載影片（只在需要時）→ 分析
                              ↑                    ↑
                        已存在則跳過          過大則跳過，未下載
```

## 🎯 進一步優化建議

### 1. 快取掃描結果

如果短時間內多次執行，可以快取掃描結果：

```php
// 使用 Redis 或檔案快取
$cacheKey = "scan_documents_{$sourceName}_{$basePath}";
$documentFiles = Cache::remember($cacheKey, 300, function() {
    return $this->storageService->scanDocumentFiles(...);
});
```

### 2. 並行處理（需謹慎）

對於大量檔案，可以考慮並行處理，但需要注意：
- API 速率限制（每秒 2 次）
- 記憶體使用
- 錯誤處理

### 3. 增量掃描

只掃描新增或更新的檔案，而不是每次都掃描全部。

## ✅ 總結

**已實現的優化**：
1. ✅ 批量查詢 videos 表（減少資料庫查詢）
2. ✅ 提前檢查條件 2（避免不必要的 XML 讀取）
3. ✅ 使用 GCS 元數據檢查檔案大小（避免下載大檔案）
4. ✅ 延遲下載影片檔案（只在確認需要時下載）

**預期效果**：
- 減少 GCS 操作費用：約 30-40%
- 減少網路傳輸費用：約 40-60%（避免下載不符合條件的檔案）
- 提升執行效率：減少不必要的操作

