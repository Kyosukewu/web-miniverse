<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\SyncStatus;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
use App\Services\SourceVersionChecker;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AnalyzeFullCommand - 執行完整分析（文本+影片）
 * 
 * 此命令將文檔文本和影片一次性發送給 Gemini API 進行完整分析。
 * 與 AnalyzeDocumentCommand 和 AnalyzeVideoCommand 分開執行，
 * 確保不影響現有的分析流程。
 */
class AnalyzeFullCommand extends Command
{
    /**
     * 控制台命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'analyze:full 
                            {--source=CNN : 來源名稱 (CNN, AP, RT 等)}
                            {--storage=gcs : 儲存空間類型 (nas, s3, gcs, storage)}
                            {--path= : 基礎路徑 (可選)}
                            {--folder= : 指定特定資料夾，只處理該資料夾的資料 (相對於 basePath 或完整路徑)}
                            {--limit=50 : 每次處理的文檔數量上限}
                            {--prompt-version=v1 : Prompt 版本 (預設 v1)}
                            {--id= : 指定要分析的視頻 ID（可多個，用逗號分隔，例如：--id=1,2,3）}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '執行完整分析：從文檔提取元數據並分析影片內容（一次性發送給 Gemini API）';

    /**
     * 建立新的命令實例。
     */
    public function __construct(
        private AnalyzeService $analyzeService,
        private StorageService $storageService,
        private VideoRepository $videoRepository,
        private SourceVersionChecker $versionChecker
    ) {
        parent::__construct();
    }

    /**
     * 執行控制台命令。
     *
     * @return int
     */
    public function handle(): int
    {
        // 提高記憶體限制以處理大型影片檔案
        ini_set('memory_limit', '2048M');
        
        $sourceName = strtoupper($this->option('source'));
        $storageType = strtolower($this->option('storage'));
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');
        $specifiedIds = $this->option('id');

        $this->info("開始處理來源: {$sourceName}, 儲存空間: {$storageType}");
        $this->info("模式：完整分析（文本 + 影片一次性發送）");

        // 處理待處理的記錄
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $checkedCount = 0;
        $batchSize = 50; // 每次從資料庫獲取的記錄數

        // 如果指定了 ID，直接處理指定的視頻
        if (null !== $specifiedIds && '' !== $specifiedIds) {
            $this->info("📋 使用指定的視頻 ID 進行分析");
            $videoIds = array_map('intval', array_filter(array_map('trim', explode(',', $specifiedIds))));
            
            if (empty($videoIds)) {
                $this->error('指定的 ID 格式無效，請使用逗號分隔的數字，例如：--id=1,2,3');
                return Command::FAILURE;
            }
            
            $this->info("將處理 " . count($videoIds) . " 個指定的視頻 ID: " . implode(', ', $videoIds));
            
            // 建立進度條
            $progressBar = $this->output->createProgressBar(count($videoIds));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% 已處理: %current% | 已檢查: %message%');
            $progressBar->setMessage('0');
            $progressBar->start();
            
            // 根據 ID 獲取視頻
            $videos = $this->videoRepository->getByIds($videoIds);
            
            if ($videos->isEmpty()) {
                $this->warn("\n未找到任何指定的視頻 ID");
                $progressBar->finish();
                return Command::SUCCESS;
            }
            
            // 處理指定的視頻
            foreach ($videos as $video) {
                $checkedCount++;
                $videoId = $video->id;
                
                $isTempFile = false;
                $videoFilePath = null;

                try {
                    $this->processSingleVideo($video, $sourceName, $storageType, $promptVersion, $isTempFile, $videoFilePath, $processedCount, $skippedCount, $errorCount, $checkedCount, $progressBar);
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->handleVideoError($e, $video, $isTempFile, $videoFilePath, $errorCount, $checkedCount, $progressBar);
                }
                
                $progressBar->setMessage((string)$checkedCount);
            }
            
            $progressBar->finish();
        } else {
            // 原有的邏輯：從資料庫獲取待處理記錄
            $this->info("📊 從資料庫獲取待處理記錄（sync_status = 'updated' 或 'synced'）");

            if ($limit > 0) {
                $this->info("將處理直到成功處理 {$limit} 個記錄為止（會持續查找更多記錄）");
            }

            // 建立進度條（使用動態最大值，基於已處理數量）
            $progressBar = $this->output->createProgressBar($limit > 0 ? $limit : 100);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% 已處理: %current% | 已檢查: %message%');
            $progressBar->setMessage('0');
            $progressBar->start();

            // 持續獲取記錄，直到處理了足夠的記錄或沒有更多記錄
            $checkedVideoIds = []; // 記錄已檢查過的 Video ID，避免重複處理
            
            while (true) {
                // 檢查是否已達到處理限制
                if ($limit > 0 && $processedCount >= $limit) {
                    $this->line("\n已達到處理限制 ({$limit} 個記錄)，停止處理");
                    break;
                }

                // 從資料庫獲取下一批記錄（排除已檢查過的記錄）
                $pendingVideos = $this->videoRepository->getPendingAnalysisVideos($sourceName, $batchSize, $checkedVideoIds);
                
                // 如果沒有更多記錄，停止
                if ($pendingVideos->isEmpty()) {
                    $this->line("\n沒有更多待處理的記錄");
                    break;
                }

                // 處理這批記錄
                foreach ($pendingVideos as $video) {
                    // 檢查是否已達到處理限制（只計算成功處理的）
                    if ($limit > 0 && $processedCount >= $limit) {
                        break 2; // 跳出兩層循環
                    }

                    $checkedCount++;
                    $videoId = $video->id;
                    
                    // 記錄已檢查的 Video ID，避免下次循環時重複獲取
                    $checkedVideoIds[] = $videoId;
                    
                    $isTempFile = false;
                    $videoFilePath = null;

                    try {
                        $this->processSingleVideo($video, $sourceName, $storageType, $promptVersion, $isTempFile, $videoFilePath, $processedCount, $skippedCount, $errorCount, $checkedCount, $progressBar);
                    } catch (\Exception $e) {
                        $errorCount++;
                        $this->handleVideoError($e, $video, $isTempFile, $videoFilePath, $errorCount, $checkedCount, $progressBar);
                    }

                    // 更新進度條消息（顯示已檢查數量）
                    $progressBar->setMessage((string)$checkedCount);
                    // 進度條的 current 基於已處理數量，只在成功處理時更新
                    // 跳過和錯誤時不更新進度條的 current
                }
                // 這批記錄處理完畢，繼續獲取下一批
            }

            $progressBar->finish();
        }

        $this->newLine(2);

        // 摘要
        $this->info("完整分析完成！");
        $this->table(
            ['狀態', '數量'],
            [
                ['已檢查', $checkedCount],
                ['已處理', $processedCount],
                ['已跳過', $skippedCount],
                ['錯誤', $errorCount],
            ]
        );
        
        if ($processedCount > 0) {
            $this->info("✓ 已將 {$processedCount} 個記錄的 sync_status 更新為 'parsed'");
        }

        return Command::SUCCESS;
    }

    /**
     * 將 XML 內容解析為文字（使用與 AnalyzeDocumentCommand 相同的邏輯）。
     *
     * @param string $xmlContent
     * @return string
     */
    private function parseXmlToText(string $xmlContent): string
    {
        try {
            $xml = simplexml_load_string($xmlContent);

            if (false === $xml) {
                return $xmlContent;
            }

            $textParts = [];

            // 提取標題、描述、腳本等
            if (isset($xml->title)) {
                $textParts[] = 'Title: ' . (string) $xml->title;
            }

            if (isset($xml->description)) {
                $textParts[] = 'Description: ' . (string) $xml->description;
            }

            if (isset($xml->script)) {
                $textParts[] = 'Script: ' . (string) $xml->script;
            }

            // 遞迴提取所有文字節點
            $this->extractTextNodes($xml, $textParts);

            if (empty($textParts)) {
                $text = strip_tags($xml->asXML());
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                return $text;
            }

            $text = implode("\n", $textParts);
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
            $text = trim($text);

            return $text;
        } catch (\Exception $e) {
            Log::warning('[AnalyzeFullCommand] XML 解析失敗，使用原始內容', [
                'error' => $e->getMessage(),
            ]);
            return $xmlContent;
        }
    }

    /**
     * 遞迴地從 XML 提取文字節點。
     *
     * @param \SimpleXMLElement $xml
     * @param array<string> $textParts
     * @return void
     */
    private function extractTextNodes(\SimpleXMLElement $xml, array &$textParts): void
    {
        $text = trim((string) $xml);
        if ('' !== $text && strlen($text) > 10) {
            $textParts[] = $text;
        }

        foreach ($xml->children() as $child) {
            $this->extractTextNodes($child, $textParts);
        }
    }

    /**
     * 將 TXT 檔案內容解析為文字。
     *
     * @param string $txtContent
     * @return string
     */
    private function parseTxtToText(string $txtContent): string
    {
        try {
            $text = preg_replace('/[ \t]+/', ' ', $txtContent);
            $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
            $text = trim($text);

            return $text;
        } catch (\Exception $e) {
            Log::warning('[AnalyzeFullCommand] TXT 解析失敗，使用原始內容', [
                'error' => $e->getMessage(),
            ]);
            return $txtContent;
        }
    }

    /**
     * 從 CNN XML objPaths 提取 MP4 檔案名稱。
     *
     * @param string $xmlContent
     * @param array<string, mixed> $xmlFile
     * @return array<string, string>
     */
    private function extractMp4PathsFromXml(string $xmlContent, array $xmlFile): array
    {
        $mp4Paths = [
            'broadcast' => '',
            'proxy' => '',
        ];

        try {
            $xml = simplexml_load_string($xmlContent);

            if (false === $xml) {
                return $mp4Paths;
            }

            if (isset($xml->objPaths)) {
                // 獲取廣播品質檔案
                if (isset($xml->objPaths->objFile)) {
                    foreach ($xml->objPaths->objFile as $objFile) {
                        $fileName = (string) $objFile;
                        $techDesc = (string) $objFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            if (str_contains($techDesc, 'NTSC') || str_contains($techDesc, 'PAL')) {
                                $mp4Paths['broadcast'] = basename($fileName);
                                break;
                            } elseif ('' === $mp4Paths['broadcast']) {
                                $mp4Paths['broadcast'] = basename($fileName);
                            }
                        }
                    }
                }

                // 獲取代理檔案
                if (isset($xml->objPaths->objProxyFile)) {
                    foreach ($xml->objPaths->objProxyFile as $objProxyFile) {
                        $fileName = (string) $objProxyFile;
                        $techDesc = (string) $objProxyFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            if (str_contains($techDesc, 'H264')) {
                                $mp4Paths['proxy'] = basename($fileName);
                                break;
                            } elseif ('' === $mp4Paths['proxy']) {
                                $mp4Paths['proxy'] = basename($fileName);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('[AnalyzeFullCommand] 從 XML 提取 MP4 路徑失敗', [
                'error' => $e->getMessage(),
            ]);
        }

        return $mp4Paths;
    }

    /**
     * 確定影片記錄的 nas_path（使用與 AnalyzeDocumentCommand 相同的邏輯）。
     *
     * @param string $storageType
     * @param array<string, mixed> $documentFile
     * @param array<string, string> $mp4FilePaths
     * @return string|null
     */
    private function determineNasPath(string $storageType, array $documentFile, array $mp4FilePaths): ?string
    {
        // 優先順序 1：在同目錄中尋找最佳 MP4 檔案
        $bestMp4 = $this->findSmallestMp4InSameDirectory(
            $storageType,
            $documentFile['file_path'],
            $documentFile['relative_path'],
            $documentFile
        );
        if (null !== $bestMp4) {
            return $this->normalizeStoragePath($storageType, $bestMp4, $documentFile);
        }

        // 優先順序 2：使用 XML 中的 MP4
        if (!empty($mp4FilePaths['broadcast']) || !empty($mp4FilePaths['proxy'])) {
            $documentDir = dirname($documentFile['file_path']);
            $disk = $this->storageService->getDisk($storageType);
            
            if (!empty($mp4FilePaths['broadcast'])) {
                $xmlMp4FilePath = $documentDir . '/' . $mp4FilePaths['broadcast'];
                if ($disk->exists($xmlMp4FilePath)) {
                    return $this->normalizeStoragePath($storageType, $xmlMp4FilePath, $documentFile);
                }
            }
            
            if (!empty($mp4FilePaths['proxy'])) {
                $xmlMp4FilePath = $documentDir . '/' . $mp4FilePaths['proxy'];
                if ($disk->exists($xmlMp4FilePath)) {
                    return $this->normalizeStoragePath($storageType, $xmlMp4FilePath, $documentFile);
                }
            }
        }

        return null;
    }

    /**
     * 標準化 nas_path 的儲存路徑。
     *
     * @param string $storageType
     * @param string $path
     * @param array<string, mixed> $documentFile
     * @return string
     */
    private function normalizeStoragePath(string $storageType, string $path, array $documentFile): string
    {
        if ('gcs' === $storageType) {
            $cleanPath = ltrim($path, '/');
            $cleanPath = preg_replace('#^storage/app/#', '', $cleanPath);
            return $cleanPath;
        }

        return $path;
    }

    /**
     * 在與給定檔案相同的目錄中尋找最佳 MP4 檔案。
     *
     * @param string $storageType
     * @param string $filePath
     * @param string $relativePath
     * @param array<string, mixed>|null $documentFile
     * @return string|null
     */
    private function findSmallestMp4InSameDirectory(string $storageType, string $filePath, string $relativePath, ?array $documentFile = null): ?string
    {
        try {
            $disk = $this->storageService->getDisk($storageType);
            $fileDir = dirname($filePath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            $targetUniqueId = null;
            if (null !== $documentFile) {
                $targetUniqueId = $this->extractUniqueIdFromFileName($documentFile['file_name'] ?? '');
            }

            $files = $disk->files($fileDir);
            $mp4Files = [];
            $matchingMp4Files = [];
            
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' !== $extension) {
                    continue;
                }

                try {
                    $size = $disk->size($file);
                    $fileName = basename($file);
                    $fileVersion = $this->storageService->extractFileVersion($fileName);
                    $versionNumber = $fileVersion ?? -1;
                    $mp4UniqueId = $this->extractUniqueIdFromFileName($fileName);

                    $mp4Data = [
                        'file' => $file,
                        'size' => $size,
                        'name' => $fileName,
                        'version' => $fileVersion,
                        'version_number' => $versionNumber,
                        'unique_id' => $mp4UniqueId,
                    ];

                    $mp4Files[] = $mp4Data;

                    if (null !== $targetUniqueId && $mp4UniqueId === $targetUniqueId) {
                        $matchingMp4Files[] = $mp4Data;
                    }
                } catch (\Exception $e) {
                    Log::warning('[AnalyzeFullCommand] 無法取得 MP4 檔案大小', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
            
            if (empty($mp4Files)) {
                return null;
            }
            
            $filesToSort = !empty($matchingMp4Files) ? $matchingMp4Files : $mp4Files;

            usort($filesToSort, function ($a, $b) {
                if ($a['version_number'] !== $b['version_number']) {
                    return $b['version_number'] <=> $a['version_number'];
                }
                return $a['size'] <=> $b['size'];
            });
            
            $bestMp4 = $filesToSort[0];
            
            if ('gcs' === $storageType) {
                return ltrim($bestMp4['file'], '/');
            } else {
                $mp4Dir = dirname($relativePath);
                return $mp4Dir . '/' . $bestMp4['name'];
            }
        } catch (\Exception $e) {
            Log::warning('[AnalyzeFullCommand] 在同資料夾中尋找最佳 MP4 檔案失敗', [
                'storage_type' => $storageType,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 過濾文檔檔案以選擇每個目錄中的最佳 XML 檔案。
     *
     * @param array<int, array<string, mixed>> $documentFiles
     * @return array<int, array<string, mixed>>
     */
    private function filterLatestVersionDocuments(array $documentFiles): array
    {
        $groupedByDir = [];
        foreach ($documentFiles as $file) {
            $dirPath = dirname($file['relative_path'] ?? $file['file_path'] ?? '');
            if (!isset($groupedByDir[$dirPath])) {
                $groupedByDir[$dirPath] = [];
            }
            $groupedByDir[$dirPath][] = $file;
        }

        $filtered = [];

        foreach ($groupedByDir as $dirPath => $files) {
            if (1 === count($files)) {
                $filtered[] = $files[0];
                continue;
            }

            $bestMp4UniqueId = $this->findBestMp4UniqueIdInDirectory($dirPath, $files);
            $selectedXml = $this->selectBestXmlForDirectory($files, $bestMp4UniqueId);

            if (null !== $selectedXml) {
                $filtered[] = $selectedXml;
            }
        }

        return $filtered;
    }

    /**
     * 在目錄中尋找最佳 MP4 檔案並返回其唯一識別碼。
     *
     * @param string $dirPath
     * @param array<int, array<string, mixed>> $files
     * @return string|null
     */
    private function findBestMp4UniqueIdInDirectory(string $dirPath, array $files): ?string
    {
        $storageType = strtolower($this->option('storage'));
        $disk = $this->storageService->getDisk($storageType);

        $firstFile = $files[0];
        $actualDirPath = dirname($firstFile['file_path'] ?? $firstFile['relative_path'] ?? '');

        if (!$disk->exists($actualDirPath)) {
            return null;
        }

        $allFiles = $disk->files($actualDirPath);
        $mp4Files = [];

        foreach ($allFiles as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ('mp4' !== $extension) {
                continue;
            }

            try {
                $size = $disk->size($file);
                $fileName = basename($file);
                $fileVersion = $this->storageService->extractFileVersion($fileName);
                $uniqueId = $this->extractUniqueIdFromFileName($fileName);

                if (null !== $uniqueId) {
                    $mp4Files[] = [
                        'unique_id' => $uniqueId,
                        'version' => $fileVersion ?? -1,
                        'size' => $size,
                        'name' => $fileName,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('[AnalyzeFullCommand] 無法取得 MP4 檔案資訊', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (empty($mp4Files)) {
            return null;
        }

        usort($mp4Files, function ($a, $b) {
            if ($a['version'] !== $b['version']) {
                return $b['version'] <=> $a['version'];
            }
            return $a['size'] <=> $b['size'];
        });

        return $mp4Files[0]['unique_id'];
    }

    /**
     * 為目錄選擇最佳 XML 檔案。
     *
     * @param array<int, array<string, mixed>> $files
     * @param string|null $bestMp4UniqueId
     * @return array<string, mixed>|null
     */
    private function selectBestXmlForDirectory(array $files, ?string $bestMp4UniqueId): ?array
    {
        $xmlFiles = [];
        foreach ($files as $file) {
            $extension = strtolower($file['extension'] ?? pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION));
            if ('xml' === $extension) {
                $xmlFiles[] = $file;
            }
        }

        if (empty($xmlFiles)) {
            return $files[0] ?? null;
        }

        if (null !== $bestMp4UniqueId) {
            $matchingXmls = [];
            foreach ($xmlFiles as $xmlFile) {
                $xmlUniqueId = $this->extractUniqueIdFromFileName($xmlFile['file_name'] ?? '');
                if ($xmlUniqueId === $bestMp4UniqueId) {
                    $matchingXmls[] = $xmlFile;
                }
            }

            if (!empty($matchingXmls)) {
                usort($matchingXmls, function ($a, $b) {
                    $versionA = $a['file_version'] ?? -1;
                    $versionB = $b['file_version'] ?? -1;
                    return $versionB <=> $versionA;
                });
                return $matchingXmls[0];
            }
        }

        usort($xmlFiles, function ($a, $b) {
            $versionA = $a['file_version'] ?? -1;
            $versionB = $b['file_version'] ?? -1;
            return $versionB <=> $versionA;
        });

        return $xmlFiles[0];
    }

    /**
     * 從檔案名提取唯一識別碼。
     *
     * @param string $fileName
     * @return string|null
     */
    private function extractUniqueIdFromFileName(string $fileName): ?string
    {
        if (preg_match('/CNNA-ST1-([a-f0-9]{16})/i', $fileName, $matches)) {
            return 'CNNA-ST1-' . strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * 根據指定的資料夾過濾文檔檔案。
     *
     * @param array<int, array<string, mixed>> $documentFiles
     * @param string $targetFolder 目標資料夾路徑（相對於 basePath 或完整路徑）
     * @param string $storageType 儲存空間類型
     * @return array<int, array<string, mixed>>
     */
    private function filterByFolder(array $documentFiles, string $targetFolder, string $storageType): array
    {
        // 標準化目標資料夾路徑
        $normalizedTargetFolder = $this->normalizeFolderPath($targetFolder, $storageType);
        
        $filtered = [];
        
        foreach ($documentFiles as $file) {
            // 從 relative_path 或 file_path 提取目錄路徑
            $fileDir = dirname($file['relative_path'] ?? $file['file_path'] ?? '');
            
            // 標準化檔案目錄路徑
            $normalizedFileDir = $this->normalizeFolderPath($fileDir, $storageType);
            
            // 檢查是否匹配（支援完整匹配或部分匹配）
            // 例如：targetFolder = "cnn/CNNA-ST1-1234567890abcdef" 或 "CNNA-ST1-1234567890abcdef"
            if ($this->isFolderMatch($normalizedFileDir, $normalizedTargetFolder)) {
                $filtered[] = $file;
            }
        }
        
        return $filtered;
    }

    /**
     * 標準化資料夾路徑。
     *
     * @param string $folderPath
     * @param string $storageType
     * @return string
     */
    private function normalizeFolderPath(string $folderPath, string $storageType): string
    {
        // 移除前導和尾隨斜線
        $normalized = trim($folderPath, '/');
        
        // 移除 storage/app 前綴（如果存在）
        $normalized = preg_replace('#^storage/app/#', '', $normalized);
        $normalized = preg_replace('#^storage/app$#', '', $normalized);
        
        // 統一使用小寫（用於比較）
        $normalized = strtolower($normalized);
        
        return $normalized;
    }

    /**
     * 檢查檔案目錄是否匹配目標資料夾。
     * 支援完整路徑匹配或資料夾名稱匹配。
     *
     * @param string $fileDir 檔案所在目錄（已標準化）
     * @param string $targetFolder 目標資料夾（已標準化）
     * @return bool
     */
    private function isFolderMatch(string $fileDir, string $targetFolder): bool
    {
        // 完全匹配
        if ($fileDir === $targetFolder) {
            return true;
        }
        
        // 將路徑分割為部分
        $fileDirParts = explode('/', $fileDir);
        $targetFolderParts = explode('/', $targetFolder);
        
        // 檢查目標資料夾是否為檔案目錄的結尾部分
        // 例如：fileDir = "cnn/cnna-st1-1234567890abcdef"
        //      targetFolder = "cnna-st1-1234567890abcdef"
        if (count($targetFolderParts) <= count($fileDirParts)) {
            $fileDirEnd = array_slice($fileDirParts, -count($targetFolderParts));
            if ($fileDirEnd === $targetFolderParts) {
                return true;
            }
        }
        
        // 檢查檔案目錄的任何部分是否完全匹配目標資料夾
        // 例如：fileDir = "cnn/cnna-st1-1234567890abcdef"
        //      targetFolder = "cnna-st1-1234567890abcdef"
        foreach ($fileDirParts as $part) {
            if ($part === $targetFolder) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 處理單個視頻的分析。
     *
     * @param \App\Models\Video $video
     * @param string $sourceName
     * @param string $storageType
     * @param string $promptVersion
     * @param bool $isTempFile
     * @param string|null $videoFilePath
     * @param int $processedCount
     * @param int $skippedCount
     * @param int $errorCount
     * @param int $checkedCount
     * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar
     * @return void
     * @throws \Exception
     */
    private function processSingleVideo(
        \App\Models\Video $video,
        string $sourceName,
        string $storageType,
        string $promptVersion,
        bool &$isTempFile,
        ?string &$videoFilePath,
        int &$processedCount,
        int &$skippedCount,
        int &$errorCount,
        int &$checkedCount,
        \Symfony\Component\Console\Helper\ProgressBar $progressBar
    ): void {
        $videoId = $video->id;
        $sourceId = $video->source_id;
        $gcsBasePath = strtolower($sourceName) . '/' . $sourceId;
        
        $this->line("\n處理記錄: {$sourceId} (Video ID: {$videoId})");
        
        // 掃描該資料夾中的 XML 和 MP4 檔案
        $disk = $this->storageService->getDisk($storageType);
        
        // 檢查目錄是否存在
        if (!$disk->exists($gcsBasePath)) {
            $this->warn("\n⊘ 跳過（GCS 目錄不存在）: {$sourceId} (路徑: {$gcsBasePath})");
            Log::warning('[AnalyzeFullCommand] GCS 目錄不存在', [
                'source_id' => $sourceId,
                'gcs_path' => $gcsBasePath,
            ]);
            $skippedCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }
        
        // 使用 allFiles 遞歸查找，或 files 查找直接子文件
        $files = $disk->files($gcsBasePath);
        
        // 如果直接子目錄沒有文件，嘗試遞歸查找
        if (empty($files)) {
            try {
                $allFiles = $disk->allFiles($gcsBasePath);
                $files = $allFiles;
            } catch (\Exception $e) {
                Log::debug('[AnalyzeFullCommand] allFiles 不可用，使用 files', [
                    'source_id' => $sourceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 記錄掃描到的文件（用於調試）
        if (empty($files)) {
            Log::warning('[AnalyzeFullCommand] GCS 目錄中沒有文件', [
                'source_id' => $sourceId,
                'gcs_path' => $gcsBasePath,
            ]);
        } else {
            Log::debug('[AnalyzeFullCommand] 掃描到的文件', [
                'source_id' => $sourceId,
                'gcs_path' => $gcsBasePath,
                'file_count' => count($files),
                'files' => array_slice($files, 0, 10),
            ]);
        }
        
        $xmlFile = null;
        $mp4File = null;
        
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ('xml' === $extension) {
                $xmlFile = $file;
            } elseif ('mp4' === $extension) {
                if (null === $mp4File) {
                    $mp4File = $file;
                } else {
                    // 選擇較小的 MP4 檔案
                    try {
                        $currentSize = $disk->size($file);
                        $existingSize = $disk->size($mp4File);
                        if ($currentSize < $existingSize) {
                            $mp4File = $file;
                        }
                    } catch (\Exception $e) {
                        Log::warning('[AnalyzeFullCommand] 無法取得 MP4 檔案大小', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        
        // 檢查是否同時存在 XML 和 MP4
        if (null === $xmlFile) {
            $this->warn("\n⊘ 跳過（找不到 XML 檔案）: {$sourceId} (GCS 路徑: {$gcsBasePath}, 找到 " . count($files) . " 個檔案)");
            Log::warning('[AnalyzeFullCommand] 找不到 XML 檔案', [
                'source_id' => $sourceId,
                'gcs_path' => $gcsBasePath,
                'files_found' => count($files),
                'file_list' => array_slice($files, 0, 5),
            ]);
            $skippedCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }
        
        if (null === $mp4File) {
            $this->warn("\n⊘ 跳過（找不到 MP4 檔案）: {$sourceId} (GCS 路徑: {$gcsBasePath}, 找到 " . count($files) . " 個檔案)");
            Log::warning('[AnalyzeFullCommand] 找不到 MP4 檔案', [
                'source_id' => $sourceId,
                'gcs_path' => $gcsBasePath,
                'files_found' => count($files),
                'file_list' => array_slice($files, 0, 5),
                'xml_file' => $xmlFile,
            ]);
            $skippedCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }
        
        // 讀取 XML 檔案內容
        $fileContent = $this->storageService->readFile($storageType, $xmlFile);

        if (null === $fileContent) {
            $this->warn("\n無法讀取 XML 檔案: {$xmlFile}");
            $errorCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }

        // 解析 XML 為文字內容
        $textContent = $this->parseXmlToText($fileContent);

        if ('' === trim($textContent)) {
            $this->warn("\nXML 檔案內容為空: {$xmlFile}");
            $errorCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }

        // 檢查影片檔案大小
        $fileSizeMB = null;
        $maxFileSizeMB = 300; // Gemini API 最多支援 300MB
        
        try {
            $fileSize = $disk->size($mp4File);
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
            
            if ($fileSizeMB > $maxFileSizeMB) {
                // 更新檔案大小和狀態為 file_too_large，之後不再處理此記錄
                try {
                    $this->videoRepository->update($videoId, [
                        'file_size_mb' => $fileSizeMB,
                        'analysis_status' => AnalysisStatus::FILE_TOO_LARGE->value,
                    ]);
                    $this->warn("\n⚠️  跳過（檔案過大，已標記為 file_too_large）: {$sourceId} (檔案大小: {$fileSizeMB}MB > {$maxFileSizeMB}MB)");
                    Log::info('[AnalyzeFullCommand] 檔案過大，已標記為 file_too_large', [
                        'source_id' => $sourceId,
                        'video_id' => $videoId,
                        'file_size_mb' => $fileSizeMB,
                        'mp4_file' => $mp4File,
                    ]);
                } catch (\Exception $updateException) {
                    $this->error("\n✗ 更新狀態失敗: {$sourceId} - {$updateException->getMessage()}");
                    Log::error('[AnalyzeFullCommand] 更新 file_too_large 狀態失敗', [
                        'source_id' => $sourceId,
                        'video_id' => $videoId,
                        'file_size_mb' => $fileSizeMB,
                        'error' => $updateException->getMessage(),
                    ]);
                    // 即使更新失敗，也跳過此記錄（因為檔案確實過大）
                }
                $skippedCount++;
                $progressBar->setMessage((string)$checkedCount);
                return;
            }
            
            $this->line("\n✓ 檔案大小符合限制: {$sourceId} ({$fileSizeMB}MB)");
        } catch (\Exception $e) {
            $this->warn("\n⊘ 跳過（無法取得檔案大小）: {$sourceId} - {$e->getMessage()}");
            Log::warning('[AnalyzeFullCommand] 無法取得 GCS 檔案大小', [
                'source_id' => $sourceId,
                'mp4_file' => $mp4File ?? null,
                'error' => $e->getMessage(),
            ]);
            $skippedCount++;
            $progressBar->setMessage((string)$checkedCount);
            return;
        }

        // 更新 nas_path 和 file_size_mb（如果尚未設定）
        $updateData = [];
        if ($video->nas_path !== $mp4File) {
            $updateData['nas_path'] = $mp4File;
        }
        if (null === $video->file_size_mb) {
            $updateData['file_size_mb'] = $fileSizeMB;
        }
        if (!empty($updateData)) {
            $this->videoRepository->update($videoId, $updateData);
        }

        // 將狀態更新為處理中
        $this->videoRepository->updateAnalysisStatus(
            $videoId,
            AnalysisStatus::PROCESSING,
            new \DateTime()
        );

        // 下載影片檔案到臨時位置
        $this->line("→ 開始下載影片檔案...");
        $videoFilePath = $this->storageService->getVideoFilePath($storageType, $mp4File);
        if (null === $videoFilePath) {
            throw new \Exception("無法下載影片檔案: {$mp4File}");
        }
        $isTempFile = true; // 標記為臨時檔案，需要清理
        $this->line("→ 已下載影片檔案到臨時位置: " . basename($videoFilePath));

        // 執行完整分析（文本 + 影片）- 這裡會發送 Gemini API 請求
        $analysisResult = $this->analyzeService->executeFullAnalysis(
            $videoId,
            $textContent,
            $promptVersion,
            $videoFilePath
        );

        // ========== Gemini API 速率限制（無論成功或失敗都需要延遲）==========
        $this->line("⏱  等待 1 秒以符合 API 速率限制...");
        sleep(1);
        // ========================================

        // 處理後釋放記憶體
        unset($analysisResult);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // ========== 立即清理臨時檔案（分析成功後立即刪除，釋放空間）==========
        if ($isTempFile && isset($videoFilePath) && file_exists($videoFilePath)) {
            try {
                $tempFileSize = filesize($videoFilePath);
                if (@unlink($videoFilePath)) {
                    $this->line("🗑️  已清理臨時檔案: " . basename($videoFilePath) . " (" . round($tempFileSize / 1024 / 1024, 2) . "MB)");
                    Log::debug('[AnalyzeFullCommand] 分析成功後已清理臨時檔案', [
                        'temp_path' => $videoFilePath,
                        'size_mb' => round($tempFileSize / 1024 / 1024, 2),
                    ]);
                }
            } catch (\Exception $cleanupException) {
                Log::warning('[AnalyzeFullCommand] 清理臨時檔案失敗', [
                    'temp_path' => $videoFilePath,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }
        // ================================================================

        // 更新 sync_status 為 'parsed'（已解析）
        $this->videoRepository->update($videoId, [
            'sync_status' => SyncStatus::PARSED->value,
        ]);

        $this->line("\n✓ 完成完整分析: {$sourceId}");
        $processedCount++;
        $progressBar->setMessage((string)$checkedCount);
        $progressBar->setProgress($processedCount);
    }

    /**
     * 處理視頻分析錯誤。
     *
     * @param \Exception $e
     * @param \App\Models\Video $video
     * @param bool $isTempFile
     * @param string|null $videoFilePath
     * @param int $errorCount
     * @param int $checkedCount
     * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar
     * @return void
     */
    private function handleVideoError(
        \Exception $e,
        \App\Models\Video $video,
        bool $isTempFile,
        ?string $videoFilePath,
        int &$errorCount,
        int &$checkedCount,
        \Symfony\Component\Console\Helper\ProgressBar $progressBar
    ): void {
        $sourceId = $video->source_id ?? 'unknown';
        $videoId = $video->id ?? null;
        
        // ========== 清理臨時檔案（如果下載失敗或分析失敗）==========
        if ($isTempFile && isset($videoFilePath) && file_exists($videoFilePath)) {
            try {
                $tempFileSize = filesize($videoFilePath);
                if (@unlink($videoFilePath)) {
                    $this->line("\n🗑️  已清理臨時檔案: " . basename($videoFilePath) . " (" . round($tempFileSize / 1024 / 1024, 2) . "MB)");
                    Log::info('[AnalyzeFullCommand] 已清理失敗的臨時檔案', [
                        'temp_path' => $videoFilePath,
                        'size_mb' => round($tempFileSize / 1024 / 1024, 2),
                    ]);
                }
            } catch (\Exception $cleanupException) {
                Log::warning('[AnalyzeFullCommand] 清理臨時檔案失敗', [
                    'temp_path' => $videoFilePath ?? null,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }
        // ================================================================

        Log::error('[AnalyzeFullCommand] 完整分析失敗', [
            'source_id' => $sourceId,
            'video_id' => $videoId,
            'error' => $e->getMessage(),
        ]);

        // ========== 如果已發送 API 請求但失敗，也需要延遲 ==========
        if (isset($videoId)) {
            $this->line("⏱  等待 1 秒以符合 API 速率限制（失敗後延遲）...");
            sleep(1);
        }
        // ========================================

        // ========== 處理 429 錯誤（配額超限）==========
        if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'quota')) {
            $this->warn("\n⚠️  Gemini API 配額已超限，建議停止處理或檢查配額狀態");
            Log::warning('[AnalyzeFullCommand] 檢測到 API 配額超限', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
        // ================================================================

        $this->error("\n✗ 分析失敗: {$sourceId} - {$e->getMessage()}");
        $progressBar->setMessage((string)$checkedCount);
    }
}

