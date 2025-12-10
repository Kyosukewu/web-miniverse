# CNN 資源抓取與分析完整流程（更新版）

## 整體流程圖

```
┌─────────────────────────────────────────────────────────────┐
│ 階段 1: 資源準備（外部）                                      │
│ Windows Server 自動程式 → /mnt/PushDownloads                │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 階段 2: 檔案抓取與轉移（每小時）                               │
│ Laravel Scheduler → FetchCnnCommand                         │
│ 1. 從 /mnt/PushDownloads 掃描檔案                           │
│ 2. 依唯一識別碼（CNNA-ST1-xxxxxxxxxxxxxxxx）分類             │
│ 3. 移動到 GCS，資料夾結構：cnn/{唯一識別碼}/檔案名稱          │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 階段 3: XML 文檔分析（每 10 分鐘）                            │
│ Laravel Scheduler → AnalyzeDocumentCommand                   │
│ 從 GCS 掃描 XML 檔案並進行分析                                │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 階段 4: MP4 影片分析（每 15 分鐘）                             │
│ Laravel Scheduler → AnalyzeVideoCommand                     │
│ 從 GCS 掃描 MP4 檔案並進行分析                                │
└─────────────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────────────┐
│ 階段 5: 結果展示                                              │
│ Dashboard → 顯示分析結果                                      │
└─────────────────────────────────────────────────────────────┘
```

## 詳細流程說明

### 階段 1: 資源準備（外部流程）

```
Windows Server (自動程式)
    ↓
/mnt/PushDownloads (本地目錄)
    ↓
檔案命名格式：
[格式前綴]_[分類代碼]_[描述標籤]_[標題Slug]_[唯一識別碼]_[資產類型ID]_[版本號].[副檔名]

範例：
- BHDN_BU-07MO_REPORT_TITLE_CNNA-ST1-2000000000090313_174_0.mp4
- WH16x9N_EN-06FR_FILE_TITLE_CNNA-ST1-2000000000090313_213_0.mp4
- BU-09FR_REPORT_TITLE_CNNA-ST1-2000000000090313_900_0.xml
```

**檔案命名規格說明**：
- **格式前綴**：B9x16N, WH16x9N, WH9x16N, BHDN（MP4 才有）
- **分類代碼**：BU-07MO, EN-06FR, PO-41SU 等
- **唯一識別碼**：`CNNA-ST1-xxxxxxxxxxxxxxxx`（16 位數字）- **關鍵欄位**
- **資產類型 ID**：
  - 702/214: B9x16N/WH9x16N (直式影片 MP4)
  - 174: BHDN (標準高清影片 MP4)
  - 213: WH16x9N (16:9 寬螢幕影片 MP4)
  - 801: JPG (縮圖/預覽圖)
  - 900/902: XML (元數據)

---

### 階段 2: 檔案抓取與轉移（FetchCnnCommand）

#### 2.1 排程觸發
```php
// routes/console.php
// 每 30 分鐘執行一次（優先執行，為後續分析提供資料）
Schedule::command('fetch:cnn')->everyThirtyMinutes()->onOneServer()->runInBackground();
```

#### 2.2 FetchCnnCommand 執行流程

```php
FetchCnnCommand::handle()
    ↓
CnnFetchService::fetchResourceList()
    ↓
1. 掃描本地目錄 /mnt/PushDownloads
   └─> scanLocalFiles()
       └─> 遞迴掃描所有 .xml, .mp4, .jpg 檔案
       └─> 返回檔案列表（包含路徑、檔名、大小、修改時間）
    ↓
2. 依描述標籤分組（使用第一個遇到的唯一識別碼作為資料夾名稱）
   └─> groupFilesByUniqueId($files)
       ├─> extractDescriptionLabel($fileName)
       │   └─> 從檔名提取描述標籤（例如："KPOP DEMON"）
       ├─> extractUniqueId($fileName)
       │   └─> 從檔名提取 CNNA-ST1-xxxxxxxxxxxxxxxx
       └─> 對每個描述標籤，使用第一個遇到的唯一識別碼作為資料夾名稱
       └─> 返回分組後的檔案：{唯一識別碼: [檔案列表]}
    ↓
3. 移動檔案到 GCS
   └─> moveFilesToGcs($groupedFiles, 'cnn/')
       ├─> 對每個唯一識別碼：
       │   ├─> 建立 GCS 路徑：cnn/{唯一識別碼}/
       │   ├─> 上傳檔案到 GCS
       │   └─> 刪除本地檔案
       └─> 返回已移動的檔案列表
    ↓
4. 從 GCS 掃描資源列表
   └─> fetchResourceListFromGcs('cnn/', 'CNN')
       ├─> StorageService::scanXmlFiles('gcs', 'CNN', 'cnn/')
       └─> StorageService::scanVideoFiles('gcs', 'CNN', 'cnn/')
    ↓
5. 返回資源列表
```

**GCS 資料夾結構**：
```
gcs://bucket-name/
└── cnn/
    ├── CNNA-ST1-2000000000090313/
    │   ├── BHDN_BU-07MO_REPORT_TITLE_CNNA-ST1-2000000000090313_174_0.mp4
    │   ├── WH16x9N_EN-06FR_FILE_TITLE_CNNA-ST1-2000000000090313_213_0.mp4
    │   └── BU-09FR_REPORT_TITLE_CNNA-ST1-2000000000090313_900_0.xml
    └── CNNA-ST1-2000000000090314/
        └── ...
```

**關鍵程式碼位置**：
- `app/Console/Commands/FetchCnnCommand.php`
- `app/Services/Sources/CnnFetchService.php` (fetchResourceList, scanLocalFiles, groupFilesByUniqueId, extractDescriptionLabel, moveFilesToGcs)

**命令選項**：
- `--batch-size=50`: 每批處理的檔案數量（預設 50）
- `--dry-run`: 乾跑模式，僅顯示會處理的檔案，不實際上傳
- `--keep-local`: 保留本地檔案，上傳到 GCS 後不刪除

---

### 階段 3: XML 文檔分析（AnalyzeDocumentCommand）

#### 3.1 排程觸發
```php
// routes/console.php
Schedule::command('analyze:document --source=CNN --storage=gcs')
         ->everyTenMinutes()
         ->onOneServer()
         ->runInBackground();
```

#### 3.2 AnalyzeDocumentCommand 執行流程

```php
AnalyzeDocumentCommand::handle()
    ↓
1. 掃描 GCS 中的 XML 檔案
   └─> StorageService::scanXmlFiles('gcs', 'CNN', 'cnn/')
       └─> 掃描 cnn/ 目錄下的所有 .xml 檔案
       └─> 從路徑提取 source_id（唯一識別碼）
       └─> 返回 XML 檔案列表
    ↓
2. 過濾並選擇最佳版本的檔案（針對 CNN 來源）
   └─> filterLatestVersionDocuments($documentFiles)
       ├─> 依資料夾分組檔案
       ├─> 對每個資料夾：
       │   ├─> findBestMp4UniqueIdInDirectory()
       │   │   └─> 選擇最佳 MP4：優先版本號（最高）→ 檔案大小（最小）
       │   └─> selectBestXmlForDirectory()
       │       ├─> 優先選擇與最佳 MP4 相同唯一識別碼的 XML
       │       └─> 若無匹配，選擇版本號最高的 XML
       └─> 返回過濾後的 XML 檔案列表
    ↓
3. 遍歷每個 XML 檔案
   └─> foreach ($xmlFiles as $xmlFile)
       ↓
       3.1 讀取 XML 內容
           └─> StorageService::readFile('gcs', $xmlFile['file_path'])
               └─> 從 GCS 下載 XML 內容
       ↓
       3.2 從 XML 提取 MP4 路徑
           └─> extractMp4PathsFromXml($xmlContent, $xmlFile)
               └─> 解析 <objPaths> 標籤
               └─> 提取 <objFile> (Broadcast Quality)
               └─> 提取 <objProxyFile> (Proxy Format)
       ↓
       3.3 解析 XML 為文字內容
           └─> parseXmlToText($xmlContent)
               └─> 提取所有文字節點（包含腳本資訊）
       ↓
       3.4 檢查/建立 Video Record
           └─> VideoRepository::getBySourceId('CNN', $sourceId)
           └─> 如果不存在 → VideoRepository::findOrCreate()
           └─> source_id = 唯一識別碼（CNNA-ST1-xxxxxxxxxxxxxxxx）
       ↓
       3.5 執行文字分析
           └─> AnalyzeService::executeTextAnalysis($textContent)
               └─> Gemini API 分析
       ↓
       3.6 更新 Video 元數據
           └─> 從分析結果提取：title, published_at, duration_secs, etc.
           └─> 更新 xml_file_version（如果來源啟用版本檢查）
       ↓
       3.7 狀態更新為 metadata_extracted
```

**關鍵程式碼位置**：
- `app/Console/Commands/AnalyzeDocumentCommand.php`
  - `filterLatestVersionDocuments()`: 過濾並選擇最佳版本的檔案
  - `findBestMp4UniqueIdInDirectory()`: 選擇最佳 MP4（版本號優先，檔案大小次之）
  - `selectBestXmlForDirectory()`: 選擇最佳 XML（優先匹配 MP4 的唯一識別碼）
- `app/Services/AnalyzeService.php` (executeTextAnalysis)
- `app/Services/StorageService.php` (scanXmlFiles, readFile)
- `app/Services/SourceVersionChecker.php` (版本檢查邏輯，僅在來源啟用版本檢查時生效)

---

### 階段 4: MP4 影片分析（AnalyzeVideoCommand）

#### 4.1 排程觸發
```php
// routes/console.php
Schedule::command('analyze:video --source=CNN --storage=gcs')
         ->everyFifteenMinutes()
         ->onOneServer()
         ->runInBackground();
```

#### 4.2 AnalyzeVideoCommand 執行流程

```php
AnalyzeVideoCommand::handle()
    ↓
1. 從資料庫查詢未完成分析的影片
   └─> VideoRepository::getIncompleteVideos('CNN', $limit)
       └─> 查詢 analysis_status != 'completed' 的影片
    ↓
2. 遍歷需要分析的影片
   └─> foreach ($videos as $video)
       ↓
       2.1 更新狀態為 processing
       ↓
       2.2 檢查檔案大小（Gemini API 限制）
           └─> 如果檔案 > 300MB → 跳過並記錄錯誤
           └─> 如果檔案 > 200MB → 記錄警告
           └─> 動態調整 PHP 記憶體限制（至少 2GB 或 1.5x 檔案大小）
       ↓
       2.3 取得影片檔案路徑（從 GCS 下載到臨時位置）
           └─> StorageService::getVideoFilePath('gcs', $video->nas_path)
               └─> downloadGcsFileToTemp($filePath)
                   ├─> 從 GCS 下載檔案
                   ├─> 儲存到 storage/app/temp/
                   └─> 返回臨時檔案路徑
       ↓
       2.4 執行影片分析
           └─> AnalyzeService::executeVideoAnalysis($videoId, $prompt, $tempPath)
               └─> Gemini API 分析影片內容
               └─> 分析完成後立即釋放記憶體（unset 大型變數）
       ↓
       2.5 更新 mp4_file_version（如果來源啟用版本檢查）
       ↓
       2.6 儲存分析結果
           └─> AnalyzeService::saveAnalysisResult()
               ├─> 提取 importance_rating (1-5)
               └─> 儲存到 analysis_results 表
       ↓
       2.7 狀態更新為 completed
       ↓
       2.8 (可選) 清理臨時檔案
```

**關鍵程式碼位置**：
- `app/Console/Commands/AnalyzeVideoCommand.php`
  - 檔案大小檢查（> 300MB 跳過，> 200MB 警告）
  - 動態記憶體限制調整
- `app/Services/AnalyzeService.php` (executeVideoAnalysis)
- `app/Services/GeminiClient.php` (analyzeVideo - 包含檔案大小檢查和記憶體優化)
- `app/Services/StorageService.php` (getVideoFilePath, downloadGcsFileToTemp)
- `app/Services/SourceVersionChecker.php` (版本檢查邏輯，僅在來源啟用版本檢查時生效)

---

## 檔案命名解析邏輯

### 描述標籤提取

**格式範例**：`EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_801_0`

1. **從檔名提取描述標籤**：提取最後一個底線（`_`）和 `CNNA-ST1-` 之間的文字
   ```php
   // 範例：EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-...
   // 描述標籤 = "KPOP DEMON"
   extractDescriptionLabel($fileName)
   ```

2. **用途**：用於檔案分類，相同描述標籤的檔案會被歸類到同一個資料夾

### 唯一識別碼提取優先順序

1. **從檔名提取**：`CNNA-ST1-xxxxxxxxxxxxxxxx`（16 位十六進位數字）
   ```php
   preg_match('/CNNA-ST1-([a-f0-9]{16})/i', $fileName, $matches)
   ```

2. **從資料夾名稱提取**：GCS 路徑中的資料夾名稱
   ```
   cnn/CNNA-ST1-2000000000090313/file.xml
   → source_id = CNNA-ST1-2000000000090313
   ```

3. **從舊格式提取**：`MW-006TH` 等（向後相容）

### 分類邏輯

1. **依描述標籤分組**：所有具有相同描述標籤的檔案會被歸類到同一組
2. **資料夾命名**：使用該描述標籤組中**第一個遇到的唯一識別碼**作為資料夾名稱
3. **範例**：
   - 檔案 A: `... KPOP DEMON _CNNA-ST1-20000000000900ca_...` (第一個)
   - 檔案 B: `... KPOP DEMON _CNNA-ST1-20000000000900cc_...` (第二個)
   - 結果：兩個檔案都會被放到 `CNNA-ST1-20000000000900ca/` 資料夾中

### 資料夾分類邏輯

檔案移動到 GCS 時，會依**描述標籤**分組，並使用**第一個遇到的唯一識別碼**作為資料夾名稱：

**分類規則**：
1. 從檔名提取描述標籤（例如：`EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-...` → 描述標籤為 `KPOP DEMON`）
2. 對每個描述標籤，使用第一個遇到的唯一識別碼作為資料夾名稱
3. 所有具有相同描述標籤的檔案（即使唯一識別碼不同）會被歸類到同一個資料夾

**範例**：
```
/mnt/PushDownloads/
├── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_801_0.jpg
├── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900cc_801_0.jpg
└── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_900_0.xml
    ↓
gcs://bucket-name/cnn/
└── CNNA-ST1-20000000000900ca/  (使用第一個遇到的唯一識別碼)
    ├── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_801_0.jpg
    ├── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900cc_801_0.jpg
    └── EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_900_0.xml
```

---

## 資料狀態轉換流程

```
pending (初始狀態)
    ↓
metadata_extracting (XML 分析開始)
    ↓
metadata_extracted (XML 分析完成)
    ↓
processing (MP4 分析開始)
    ↓
completed (MP4 分析完成)
```

**錯誤狀態**：
- `txt_analysis_failed` - XML 分析失敗
- `video_analysis_failed` - MP4 分析失敗
- `failed` - 整體失敗

---

## 排程時間表

| 命令 | 頻率 | 說明 |
|------|------|------|
| `fetch:cnn` | 每 30 分鐘 | 從 /mnt/PushDownloads 抓取檔案並移動到 GCS（優先執行，為後續分析提供資料） |
| `analyze:document --source=CNN --storage=gcs` | 每 10 分鐘 | XML 文檔分析（依賴 fetch:cnn 的結果） |
| `analyze:video --source=CNN --storage=gcs` | 每 15 分鐘 | MP4 影片分析（依賴 analyze:document 的結果） |

---

## 環境變數配置

在 `.env` 檔案中設定：

```env
# CNN 本地來源路徑
CNN_SOURCE_PATH=/mnt/PushDownloads

# CNN GCS 配置
CNN_STORAGE_TYPE=gcs
CNN_GCS_BUCKET=your-bucket-name
CNN_GCS_PATH=cnn/

# GCS 認證
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_KEY_FILE=/var/www/html/web-miniverse/storage/app/gcs-key.json
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name
GOOGLE_CLOUD_STORAGE_PATH_PREFIX=
```

---

## 資料庫記錄範例

**videos 表**:
- `source_name`: 'CNN'
- `source_id`: 'CNNA-ST1-2000000000090313' (唯一識別碼)
- `nas_path`: 'cnn/CNNA-ST1-2000000000090313/BHDN_BU-07MO_REPORT_TITLE_CNNA-ST1-2000000000090313_174_0.mp4'
- `analysis_status`: 'completed'
- `title`: '從 XML 分析提取的標題'
- `published_at`: '從 XML 分析提取的日期'

**analysis_results 表**:
- `video_id`: 對應的 video.id
- `importance_rating`: 5 (從影片分析提取，1-5)
- `transcript`: '影片逐字稿'
- `short_summary`: '短摘要'
- `bulleted_summary`: '列點摘要'
- `importance_score`: JSON (包含 overall_rating, key_factors, etc.)

---

## 錯誤處理

### 檔案移動失敗
- 記錄錯誤訊息到日誌
- 保留本地檔案，不刪除
- 繼續處理下一個檔案

### XML 分析失敗
- 狀態更新為 `txt_analysis_failed`
- 記錄錯誤訊息到日誌
- 繼續處理下一個檔案

### MP4 分析失敗
- 狀態更新為 `video_analysis_failed`
- 儲存錯誤訊息到 `analysis_results.error_message`
- 記錄錯誤訊息到日誌
- 繼續處理下一個檔案

### GCS 檔案不存在
- 記錄警告到日誌
- 跳過該檔案
- 繼續處理下一個檔案

---

## 效能考量

1. **批次處理**: 每次處理最多 50 個檔案（可透過 `--limit` 調整）
2. **GCS 下載**: MP4 檔案會下載到臨時位置，分析完成後可清理
3. **狀態檢查**: 自動跳過已完成分析的檔案
4. **優先順序**: 優先選擇 Broadcast Quality 檔案而非 Proxy Format
5. **檔案去重**: 移動到 GCS 時會檢查檔案是否已存在，避免重複上傳

