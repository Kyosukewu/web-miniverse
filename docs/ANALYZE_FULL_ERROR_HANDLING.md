# AnalyzeFullCommand 錯誤處理說明

## 📋 常見錯誤及解決方案

### 1. **429 Too Many Requests（API 配額超限）**

**錯誤訊息**：
```
Gemini API 影片分析失敗: Client error: `POST ...` resulted in a `429 Too Many Requests` response
```

**原因**：
- Gemini API 配額已用完（每日 960 次請求）
- 或超過每秒請求數限制（2 RPS）

**解決方案**：
1. **檢查配額狀態**：
   - 登入 Google Cloud Console
   - 檢查 Gemini API 配額使用情況
   - 確認是否已達到每日限制

2. **調整處理頻率**：
   - 減少 `--limit` 參數值
   - 增加 `sleep()` 延遲時間（目前為 1 秒）
   - 調整 scheduler 執行頻率

3. **分批處理**：
   ```bash
   # 每次只處理少量檔案
   php artisan analyze:full --source=CNN --storage=gcs --limit=5
   ```

**程式改進**：
- ✅ 已實現 `sleep(1)` 延遲，確保 RPS < 1
- ✅ 檢測到 429 錯誤時會顯示警告訊息
- ✅ 失敗後也會延遲，避免連續快速請求

### 2. **No space left on device（磁碟空間不足）**

**錯誤訊息**：
```
fwrite(): Write of 150726244 bytes failed with errno=28 No space left on device
```

**原因**：
- 臨時目錄（`storage/app/temp`）空間不足
- 下載的影片檔案太大（237-238MB），多個檔案累積導致空間不足
- 臨時檔案沒有及時清理

**解決方案**：

#### 立即處理：
```bash
# 1. 檢查磁碟空間
df -h

# 2. 清理臨時檔案
rm -rf storage/app/temp/*

# 3. 檢查並清理舊的臨時檔案（超過 1 小時）
find storage/app/temp -type f -mtime +0 -delete
```

#### 長期解決方案：

1. **增加磁碟空間**：
   - 擴展 Docker volume 或主機磁碟
   - 清理其他不必要的檔案

2. **自動清理機制**：
   - ✅ Scheduler 已設定每小時清理 1 小時前的臨時檔案
   - ✅ 分析完成後自動清理臨時檔案（`finally` 塊）
   - ✅ 分析失敗時也會清理臨時檔案

3. **監控磁碟空間**：
   ```bash
   # 建立監控腳本
   #!/bin/bash
   DISK_USAGE=$(df -h /var/www/html | awk 'NR==2 {print $5}' | sed 's/%//')
   if [ $DISK_USAGE -gt 80 ]; then
       echo "警告：磁碟使用率超過 80%"
       # 清理臨時檔案
       find storage/app/temp -type f -mtime +0 -delete
   fi
   ```

**程式改進**：
- ✅ 下載前檢查磁碟空間（需要檔案大小 + 100MB 緩衝）
- ✅ 使用流式寫入（chunk by chunk），避免一次性載入到記憶體
- ✅ 下載失敗時自動清理部分檔案
- ✅ 分析失敗時清理臨時檔案
- ✅ 驗證檔案完整性後才返回路徑

### 3. **檔案下載不完整**

**錯誤訊息**：
```
檔案下載不完整：預期 150726244 bytes，實際 0 bytes
```

**原因**：
- 下載過程中網路中斷
- 磁碟空間不足導致寫入失敗
- GCS 連線問題

**解決方案**：
- ✅ 程式已實現檔案完整性驗證
- ✅ 下載不完整時會自動清理並拋出錯誤
- ✅ 檢查網路連線和 GCS 認證

## 🔧 程式改進細節

### 1. **磁碟空間檢查**

```php
// 在 downloadGcsFileToTemp() 中
$requiredSpace = $fileSize + (100 * 1024 * 1024); // 檔案大小 + 100MB 緩衝
$availableSpace = disk_free_space($tempDir);

if ($availableSpace < $requiredSpace) {
    throw new \Exception("磁碟空間不足：需要 {$requiredSpaceMB}MB，但只有 {$availableSpaceMB}MB 可用");
}
```

### 2. **流式寫入（避免記憶體問題）**

```php
// 使用 readStream() 和 fwrite() 分塊寫入
$stream = $disk->readStream($filePath);
$tempHandle = fopen($tempPath, 'wb');

while (!feof($stream)) {
    $chunk = fread($stream, 8192); // 8KB chunks
    fwrite($tempHandle, $chunk);
}
```

### 3. **自動清理機制**

```php
// 在 AnalyzeFullCommand 的 catch 塊中
if (isset($isTempFile) && $isTempFile && isset($videoFilePath) && file_exists($videoFilePath)) {
    @unlink($videoFilePath);
    Log::info('[AnalyzeFullCommand] 已清理失敗的臨時檔案', [...]);
}
```

### 4. **檔案完整性驗證**

```php
// 驗證下載的檔案大小是否正確
if (filesize($tempPath) !== $fileSize) {
    @unlink($tempPath);
    throw new \Exception("檔案下載不完整");
}
```

## 📊 監控建議

### 1. **監控磁碟空間**

```bash
# 定期檢查
watch -n 60 'df -h | grep -E "(Filesystem|/var/www)"'
```

### 2. **監控臨時檔案**

```bash
# 檢查臨時檔案大小
du -sh storage/app/temp/

# 檢查臨時檔案數量
ls -1 storage/app/temp/ | wc -l
```

### 3. **監控 API 配額**

- 在 Google Cloud Console 設定配額告警
- 監控每日請求數和錯誤率

## 🚀 最佳實踐

1. **定期清理**：
   - 確保 scheduler 正常執行
   - 手動清理舊的臨時檔案

2. **監控空間**：
   - 設定磁碟空間告警（建議 > 20% 可用空間）
   - 定期檢查臨時目錄大小

3. **分批處理**：
   - 使用 `--limit` 參數控制每次處理數量
   - 避免一次性處理大量檔案

4. **錯誤處理**：
   - 監控錯誤日誌
   - 及時處理 429 和磁碟空間錯誤

## 📝 相關檔案

- `app/Console/Commands/AnalyzeFullCommand.php` - 主命令檔案
- `app/Services/StorageService.php` - 儲存服務（包含下載邏輯）
- `app/Services/AnalyzeService.php` - 分析服務（包含清理邏輯）
- `routes/console.php` - Scheduler 配置（包含自動清理）

