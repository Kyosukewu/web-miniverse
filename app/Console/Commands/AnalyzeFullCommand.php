<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
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
                            {--limit=50 : 每次處理的文檔數量上限}
                            {--prompt-version=v1 : Prompt 版本 (預設 v1)}';

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
        $basePath = $this->option('path') ?? '';
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');

        $this->info("開始掃描來源: {$sourceName}, 儲存空間: {$storageType}");
        $this->info("模式：完整分析（文本 + 影片一次性發送）");

        // 掃描文檔檔案 (XML 和 TXT)
        $documentFiles = $this->storageService->scanDocumentFiles($storageType, $sourceName, $basePath);

        if (empty($documentFiles)) {
            $this->warn("未找到任何文檔檔案 (XML 或 TXT)");
            return Command::SUCCESS;
        }

        $this->info("找到 " . count($documentFiles) . " 個文檔檔案");

        // 過濾以保留每個 source_id 的最新版本
        $documentFiles = $this->filterLatestVersionDocuments($documentFiles);
        $this->info("過濾後剩餘 " . count($documentFiles) . " 個文檔檔案（每個 source_id 只保留最新版本）");

        if (null !== $limit) {
            $this->info("將處理直到成功處理 {$limit} 個文檔為止");
        }

        // 處理文檔檔案
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $checkedCount = 0;

        // 使用總檔案數量建立進度條
        $progressBar = $this->output->createProgressBar(count($documentFiles));
        $progressBar->start();

        foreach ($documentFiles as $documentFile) {
            // 檢查是否已達到處理限制（只計算成功處理的）
            if (null !== $limit && $processedCount >= $limit) {
                $this->line("\n已達到處理限制 ({$limit} 個文檔)，停止處理");
                break;
            }

            $checkedCount++;

            try {
                // 讀取文檔檔案內容
                $fileContent = $this->storageService->readFile($storageType, $documentFile['file_path']);

                if (null === $fileContent) {
                    $this->warn("\n無法讀取檔案: {$documentFile['file_path']}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                $fileExtension = strtolower($documentFile['extension'] ?? pathinfo($documentFile['file_path'], PATHINFO_EXTENSION));
                $textContent = '';
                $mp4FilePaths = ['broadcast' => '', 'proxy' => ''];

                // 根據檔案類型解析內容
                if ('xml' === $fileExtension) {
                    // 從 CNN XML (objPaths) 提取 MP4 檔案路徑
                    $mp4FilePaths = $this->extractMp4PathsFromXml($fileContent, $documentFile);
                    
                    // 將 XML 解析為文字內容
                    $textContent = $this->parseXmlToText($fileContent);
                } elseif ('txt' === $fileExtension) {
                    // 解析 TXT 檔案內容
                    $textContent = $this->parseTxtToText($fileContent);
                } else {
                    $this->warn("\n不支援的檔案類型: {$fileExtension}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                if ('' === trim($textContent)) {
                    $this->warn("\n檔案內容為空: {$documentFile['file_path']}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                // ========== 條件 1: 確定 nas_path（必須找到對應的 MP4 檔案）==========
                $nasPath = $this->determineNasPath(
                    $storageType,
                    $documentFile,
                    $mp4FilePaths
                );

                // 如果找不到 MP4 檔案，跳過（條件 1 不符合）
                if (null === $nasPath || str_ends_with(strtolower($nasPath), '.xml') || str_ends_with(strtolower($nasPath), '.txt')) {
                    $this->line("\n⊘ 跳過（找不到對應的 MP4 檔案）: {$documentFile['file_name']}");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // ========== 條件 2: 檢查影片是否已存在於 videos 表中 ==========
                $existingVideo = $this->videoRepository->getBySourceId(
                    $documentFile['source_name'],
                    $documentFile['source_id']
                );

                // 如果已存在記錄，直接跳過（條件 2 不符合）
                // 避免之前只分析一半的單筆指令影響
                if (null !== $existingVideo) {
                    $this->line("\n⊘ 跳過（該 ID 已存在於 videos 表中）: {$documentFile['source_id']} (ID: {$existingVideo->id})");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // ========== 條件 3: 檢查影片檔案大小（在建立記錄前先檢查）==========
                $videoFilePath = $this->storageService->getVideoFilePath($storageType, $nasPath);
                
                if (null === $videoFilePath) {
                    $this->warn("\n⊘ 跳過（無法取得影片檔案路徑）: {$documentFile['source_id']}");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // 檢查檔案是否存在
                if (!file_exists($videoFilePath)) {
                    $this->warn("\n⊘ 跳過（影片檔案不存在）: {$documentFile['source_id']} - {$videoFilePath}");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // 檢查檔案大小限制（條件 3）
                try {
                    $fileSize = filesize($videoFilePath);
                    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                    
                    // Gemini API 最多支援 300MB
                    $maxFileSizeMB = 300;
                    if ($fileSizeMB > $maxFileSizeMB) {
                        $this->warn("\n⚠️  跳過（檔案過大）: {$documentFile['source_id']} (檔案大小: {$fileSizeMB}MB > {$maxFileSizeMB}MB)");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $this->line("\n✓ 檔案大小符合限制: {$documentFile['source_id']} ({$fileSizeMB}MB)");
                } catch (\Exception $e) {
                    $this->warn("\n⊘ 跳過（無法取得檔案大小）: {$documentFile['source_id']} - {$e->getMessage()}");
                    Log::warning('[AnalyzeFullCommand] 無法取得檔案大小', [
                        'source_id' => $documentFile['source_id'],
                        'nas_path' => $nasPath,
                        'video_file_path' => $videoFilePath,
                        'error' => $e->getMessage(),
                    ]);
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // ========== 所有條件都符合，建立新的影片記錄並進行分析 ==========
                $versionCheckEnabled = $this->versionChecker->shouldIncludeCompletedForVersionCheck($documentFile['source_name']);
                $versionCheck = $this->versionChecker->shouldReanalyze(
                    $documentFile['source_name'],
                    null, // 已確認不存在，傳入 null
                    $documentFile['file_version'] ?? null,
                    $documentFile['file_path'] ?? null,
                    'xml'
                );

                // 準備記錄資料
                $createData = [
                    'source_name' => $documentFile['source_name'],
                    'source_id' => $documentFile['source_id'],
                    'nas_path' => $nasPath,
                    'fetched_at' => date('Y-m-d H:i:s', $documentFile['last_modified']),
                    'file_size_mb' => $fileSizeMB, // 儲存已取得的檔案大小
                ];

                // 設定版本欄位
                if ($versionCheckEnabled) {
                    $createData['xml_file_version'] = $versionCheck['new_version'] ?? 0;
                    $createData['mp4_file_version'] = 0;
                } else {
                    $createData['xml_file_version'] = 0;
                    $createData['mp4_file_version'] = 0;
                }

                // 建立新的影片記錄
                $videoId = $this->videoRepository->findOrCreate($createData);
                $isNewlyCreated = true; // 標記為新建立的記錄
                
                $this->line("→ 建立新記錄: {$documentFile['source_id']} (Video ID: {$videoId})");

                // 將狀態更新為處理中
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::PROCESSING,
                    new \DateTime()
                );

                // ========== 重要：所有條件檢查已完成，準備發送 API 請求 ==========
                // 條件 1: ✅ MP4 檔案存在
                // 條件 2: ✅ videos 表中不存在記錄
                // 條件 3: ✅ 檔案大小符合限制（≤ 300MB）
                // ================================================================

                // 執行完整分析（文本 + 影片）- 這裡會發送 Gemini API 請求
                $analysisResult = $this->analyzeService->executeFullAnalysis(
                    $videoId,
                    $textContent,
                    $promptVersion,
                    $videoFilePath
                );

                // ========== Gemini API 速率限制（無論成功或失敗都需要延遲）==========
                // 根據 https://docs.cloud.google.com/gemini/docs/quotas?hl=zh-tw
                // 每秒請求數 (RPS) 限制：2 次/秒
                // 為避免超過限制，每次 API 請求後延遲 1 秒（保守策略）
                // 這樣可確保 RPS < 1，遠低於限制值
                // 注意：延遲必須在 API 請求之後，無論成功或失敗
                $this->line("⏱  等待 1 秒以符合 API 速率限制...");
                sleep(1);
                // ========================================

                // 處理後釋放記憶體
                unset($analysisResult);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }

                $this->line("\n✓ 完成完整分析: {$documentFile['file_name']}");
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeFullCommand] 完整分析失敗', [
                    'source_id' => $documentFile['source_id'],
                    'file_path' => $documentFile['file_path'],
                    'error' => $e->getMessage(),
                    'video_id' => $videoId ?? null,
                ]);

                // ========== 如果已發送 API 請求但失敗，也需要延遲 ==========
                // 確保無論成功或失敗，每次 API 請求後都有延遲
                // 避免連續失敗時快速發送多個請求
                if (isset($videoId)) {
                    // 已建立記錄表示已通過所有條件檢查，可能已發送 API 請求
                    $this->line("⏱  等待 1 秒以符合 API 速率限制（失敗後延遲）...");
                    sleep(1);
                }
                // ========================================

                // 如果是剛建立的記錄且分析失敗，刪除該記錄
                // 避免在資料庫中累積大量失敗的空記錄
                if (isset($videoId) && isset($isNewlyCreated) && $isNewlyCreated) {
                    try {
                        $this->videoRepository->delete($videoId);
                        $this->line("\n⚠️  已刪除失敗的新記錄 (Video ID: {$videoId})");
                        Log::info('[AnalyzeFullCommand] 已刪除分析失敗的新記錄', [
                            'video_id' => $videoId,
                            'source_id' => $documentFile['source_id'],
                        ]);
                    } catch (\Exception $deleteException) {
                        Log::error('[AnalyzeFullCommand] 刪除失敗記錄時發生錯誤', [
                            'video_id' => $videoId,
                            'error' => $deleteException->getMessage(),
                        ]);
                    }
                }

                $this->error("\n✗ 分析失敗: {$documentFile['file_name']} - {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
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
}

