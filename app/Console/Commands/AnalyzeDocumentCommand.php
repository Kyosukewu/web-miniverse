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

class AnalyzeDocumentCommand extends Command
{
    /**
     * 控制台命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'analyze:document 
                            {--source=CNN : 來源名稱 (CNN, AP, RT 等)}
                            {--storage=gcs : 儲存空間類型 (nas, s3, gcs, storage)}
                            {--path= : 基礎路徑 (可選)}
                            {--limit=50 : 每次處理的文檔數量上限}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '從指定儲存空間撈取 XML 或 TXT 文檔並進行分析';

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
        $sourceName = strtoupper($this->option('source'));
        $storageType = strtolower($this->option('storage'));
        $basePath = $this->option('path') ?? '';
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');

        $this->info("開始掃描來源: {$sourceName}, 儲存空間: {$storageType}");

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
        $checkedCount = 0; // 已檢查的檔案數量

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
                    // 在解析前從 CNN XML (objPaths) 提取 MP4 檔案路徑
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

                // 確定 nas_path（優先使用與 XML 唯一識別碼匹配的同目錄 MP4，然後使用 XML 中的 MP4）
                $nasPath = $this->determineNasPath(
                    $storageType,
                    $documentFile,
                    $mp4FilePaths
                );

                // 如果找不到 MP4 檔案（nas_path 為 null 或指向 XML），跳過此文檔
                if (null === $nasPath || str_ends_with(strtolower($nasPath), '.xml') || str_ends_with(strtolower($nasPath), '.txt')) {
                    $this->line("\n⊘ 跳過（找不到對應的 MP4 檔案）: {$documentFile['file_name']}");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // 檢查影片是否已存在
                $existingVideo = $this->videoRepository->getBySourceId(
                    $documentFile['source_name'],
                    $documentFile['source_id']
                );

                // 檢查版本並確定是否需要重新分析（針對 XML 檔案）
                // 僅在來源支援時執行版本檢查（例如 CNN）
                $versionCheckEnabled = $this->versionChecker->shouldIncludeCompletedForVersionCheck($documentFile['source_name']);
                $versionCheck = $this->versionChecker->shouldReanalyze(
                    $documentFile['source_name'],
                    $existingVideo,
                    $documentFile['file_version'] ?? null,
                    $documentFile['file_path'] ?? null,
                    'xml'
                );

                // 處理現有影片
                if (null !== $existingVideo) {
                    // 如果已完成且版本未變更則跳過（僅適用於支援版本檢查的來源）
                    if ($versionCheckEnabled && AnalysisStatus::COMPLETED === $existingVideo->analysis_status && !$versionCheck['should_reanalyze']) {
                        $this->line("\n跳過已完成分析的文檔: {$documentFile['source_id']}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $videoId = $existingVideo->id;

                    // 僅在此來源啟用版本檢查時更新 xml_file_version
                    $updateData = [];
                    if ($versionCheckEnabled && null !== $versionCheck['new_version']) {
                        $updateData['xml_file_version'] = $versionCheck['new_version'];
                    }

                    // 如果版本變更或路徑不同，則更新 nas_path
                    if ($versionCheck['should_reanalyze'] || $existingVideo->nas_path !== $nasPath) {
                        $updateData['nas_path'] = $nasPath;
                    }

                    if (!empty($updateData)) {
                        $this->videoRepository->update($videoId, $updateData);

                        if ($versionCheck['should_reanalyze']) {
                            $this->line("\n{$versionCheck['reason']}: {$documentFile['source_id']}，將重新分析");
                        }
                    }
                } else {
                    // 建立新的影片記錄
                    $createData = [
                        'source_name' => $documentFile['source_name'],
                        'source_id' => $documentFile['source_id'],
                        'nas_path' => $nasPath,
                        'fetched_at' => date('Y-m-d H:i:s', $documentFile['last_modified']),
                    ];

                    // 僅在此來源啟用版本檢查時設定版本欄位
                    if ($versionCheckEnabled) {
                        $createData['xml_file_version'] = $versionCheck['new_version'] ?? 0;
                        $createData['mp4_file_version'] = 0; // 新記錄預設為 0
                    } else {
                        // 對於不支援版本檢查的來源，設為 0（預設值）
                        $createData['xml_file_version'] = 0;
                        $createData['mp4_file_version'] = 0;
                    }

                    // 嘗試取得 MP4 檔案大小（如果檔案存在）
                    try {
                        $videoFilePath = $this->storageService->getVideoFilePath($storageType, $nasPath);
                        if (null !== $videoFilePath && file_exists($videoFilePath)) {
                            $fileSize = filesize($videoFilePath);
                            $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                            $createData['file_size_mb'] = $fileSizeMB;
                        }
                    } catch (\Exception $e) {
                        // 如果無法取得檔案大小，不影響建立記錄，稍後會在 analyze:video 時補上
                        Log::debug('[AnalyzeDocumentCommand] 無法取得檔案大小', [
                            'nas_path' => $nasPath,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $videoId = $this->videoRepository->findOrCreate($createData);
                }

                // 將狀態更新為 metadata_extracting
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::METADATA_EXTRACTING,
                    new \DateTime()
                );

                // 執行文字分析
                $analysisResult = $this->analyzeService->executeTextAnalysis($textContent, $promptVersion);

                if (empty($analysisResult)) {
                    throw new \Exception('文本分析結果為空');
                }

                // 處理陣列回應（AI 可能返回包含單一物件的陣列）
                // 檢查結果是否為具有數字鍵的陣列（索引陣列）
                if (is_array($analysisResult) && isset($analysisResult[0]) && is_array($analysisResult[0])) {
                    // 在覆寫前提取提示版本（它可能在根層級）
                    $promptVersionFromResult = $analysisResult['_prompt_version'] ?? null;
                    // 如果結果是陣列，使用第一個元素
                    $analysisResult = $analysisResult[0];
                    // 如果提示版本在原始陣列中，則還原它
                    if (null !== $promptVersionFromResult) {
                        $analysisResult['_prompt_version'] = $promptVersionFromResult;
                    }
                }

                Log::info('[AnalyzeDocumentCommand] 解析後的分析結果結構', [
                    'is_array' => is_array($analysisResult),
                    'has_title' => isset($analysisResult['title']),
                    'has_creation_date' => isset($analysisResult['creation_date']),
                    'has_subjects' => isset($analysisResult['subjects']),
                    'keys' => is_array($analysisResult) ? array_keys($analysisResult) : [],
                ]);

                // 使用分析結果更新影片元數據
                $updateData = [];
                if (isset($analysisResult['title'])) {
                    $updateData['title'] = $analysisResult['title'];
                }
                if (isset($analysisResult['creation_date'])) {
                    $updateData['published_at'] = $this->parseDateTime($analysisResult['creation_date']);
                }
                if (isset($analysisResult['duration_seconds'])) {
                    $updateData['duration_secs'] = (int) $analysisResult['duration_seconds'];
                }
                if (isset($analysisResult['subjects'])) {
                    $updateData['subjects'] = json_encode($analysisResult['subjects']);
                }
                if (isset($analysisResult['location'])) {
                    $updateData['location'] = $analysisResult['location'];
                }
                if (isset($analysisResult['restrictions'])) {
                    $updateData['restrictions'] = $analysisResult['restrictions'];
                }
                if (isset($analysisResult['tran_restrictions'])) {
                    $updateData['tran_restrictions'] = $analysisResult['tran_restrictions'];
                }
                if (isset($analysisResult['shotlist_content'])) {
                    $updateData['shotlist_content'] = $analysisResult['shotlist_content'];
                }

                // 更新提示版本
                $updateData['prompt_version'] = $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v3';

                // 更新影片元數據
                if (!empty($updateData)) {
                    Log::info('[AnalyzeDocumentCommand] 準備更新影片 metadata', [
                        'video_id' => $videoId,
                        'source_id' => $documentFile['source_id'],
                        'update_data_keys' => array_keys($updateData),
                    ]);

                    $updated = $this->videoRepository->update($videoId, $updateData);
                    
                    if (!$updated) {
                        throw new \Exception('更新影片 metadata 失敗: ' . $videoId);
                    }

                    Log::info('[AnalyzeDocumentCommand] 影片 metadata 更新成功', [
                        'video_id' => $videoId,
                        'source_id' => $documentFile['source_id'],
                    ]);
                } else {
                    Log::warning('[AnalyzeDocumentCommand] 沒有資料需要更新', [
                        'video_id' => $videoId,
                        'source_id' => $documentFile['source_id'],
                    ]);
                }

                // 將狀態更新為 metadata_extracted
                $statusUpdated = $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::METADATA_EXTRACTED,
                    new \DateTime()
                );

                if (!$statusUpdated) {
                    throw new \Exception('更新影片狀態失敗: ' . $videoId);
                }

                Log::info('[AnalyzeDocumentCommand] 影片分析完成', [
                    'video_id' => $videoId,
                    'source_id' => $documentFile['source_id'],
                    'file_name' => $documentFile['file_name'],
                ]);

                $this->line("\n✓ 完成分析: {$documentFile['file_name']}");
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeDocumentCommand] 分析文檔失敗', [
                    'source_id' => $documentFile['source_id'],
                    'file_path' => $documentFile['file_path'],
                    'error' => $e->getMessage(),
                ]);

                // 將狀態更新為失敗
                if (isset($videoId)) {
                    $this->videoRepository->updateAnalysisStatus(
                        $videoId,
                        AnalysisStatus::TXT_ANALYSIS_FAILED,
                        new \DateTime()
                    );
                }

                $this->error("\n✗ 分析失敗: {$documentFile['file_name']} - {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // 摘要
        $this->info("分析完成！");
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
     * 將 XML 內容解析為文字（CNN 格式）。
     *
     * @param string $xmlContent
     * @return string
     */
    private function parseXmlToText(string $xmlContent): string
    {
        try {
            $xml = simplexml_load_string($xmlContent);

            if (false === $xml) {
                // 如果 XML 解析失敗，返回原始內容
                return $xmlContent;
            }

            // 對於 CNN XML 格式，提取所有文字內容，包括腳本資訊
            // 將 XML 轉換為字串，同時保留結構
            $textParts = [];

            // 如果存在則提取標題
            if (isset($xml->title)) {
                $textParts[] = 'Title: ' . (string) $xml->title;
            }

            // 如果存在則提取描述
            if (isset($xml->description)) {
                $textParts[] = 'Description: ' . (string) $xml->description;
            }

            // 提取腳本內容（CNN XML 可能有腳本標籤）
            if (isset($xml->script)) {
                $textParts[] = 'Script: ' . (string) $xml->script;
            }

            // 遞迴提取所有文字節點
            $this->extractTextNodes($xml, $textParts);

            // 如果未找到特定內容，使用所有文字內容
            if (empty($textParts)) {
                $text = strip_tags($xml->asXML());
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                return $text;
            }

            // 合併所有文字部分
            $text = implode("\n", $textParts);

            // 清理空白字元但保留換行
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
            $text = trim($text);

            return $text;
        } catch (\Exception $e) {
            Log::warning('[AnalyzeDocumentCommand] XML 解析失敗，使用原始內容', [
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
        // 獲取直接文字內容
        $text = trim((string) $xml);
        if ('' !== $text && strlen($text) > 10) {
            // 僅添加實質文字內容
            $textParts[] = $text;
        }

        // 遞迴處理子節點
        foreach ($xml->children() as $child) {
            $this->extractTextNodes($child, $textParts);
        }
    }

    /**
     * 將日期時間字串解析為資料庫格式。
     *
     * @param string|null $dateTimeString
     * @return string|null
     */
    private function parseDateTime(?string $dateTimeString): ?string
    {
        if (null === $dateTimeString || '' === trim($dateTimeString)) {
            return null;
        }

        try {
            $dateTime = new \DateTime($dateTimeString);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning('[AnalyzeDocumentCommand] 日期時間解析失敗', [
                'date_string' => $dateTimeString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 從 CNN XML objPaths 提取 MP4 檔案名稱。
     * 僅返回檔案名稱，不返回完整路徑。
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

            // 尋找 objPaths 標籤
            if (isset($xml->objPaths)) {
                // 獲取廣播品質檔案（帶有 MP4 的 objFile）
                if (isset($xml->objPaths->objFile)) {
                    foreach ($xml->objPaths->objFile as $objFile) {
                        $fileName = (string) $objFile;
                        $techDesc = (string) $objFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            // 優先使用 NTSC 或 PAL 廣播品質
                            if (str_contains($techDesc, 'NTSC') || str_contains($techDesc, 'PAL')) {
                                $mp4Paths['broadcast'] = basename($fileName);
                                break;
                            } elseif ('' === $mp4Paths['broadcast']) {
                                $mp4Paths['broadcast'] = basename($fileName);
                            }
                        }
                    }
                }

                // 獲取代理檔案（帶有 MP4 的 objProxyFile）
                if (isset($xml->objPaths->objProxyFile)) {
                    foreach ($xml->objPaths->objProxyFile as $objProxyFile) {
                        $fileName = (string) $objProxyFile;
                        $techDesc = (string) $objProxyFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            // 優先使用 H264 代理格式
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
            Log::warning('[AnalyzeDocumentCommand] 從 XML 提取 MP4 路徑失敗', [
                'error' => $e->getMessage(),
            ]);
        }

        return $mp4Paths;
    }

    /**
     * 確定影片記錄的 nas_path。
     * 優先順序：1. 同目錄中的 MP4，2. XML 中的 MP4（如果存在）。
     * 如果找不到 MP4 檔案，返回 null。
     * 對於 GCS 儲存，確保路徑相對於 bucket 根目錄。
     *
     * @param string $storageType
     * @param array<string, mixed> $documentFile
     * @param array<string, string> $mp4FilePaths
     * @return string|null 返回 MP4 檔案路徑，如果找不到則返回 null
     */
    private function determineNasPath(string $storageType, array $documentFile, array $mp4FilePaths): ?string
    {
        // 優先順序 1：在同目錄中尋找最佳 MP4 檔案（如果可能，匹配 XML 的唯一識別碼）
        $bestMp4 = $this->findSmallestMp4InSameDirectory(
            $storageType,
            $documentFile['file_path'],
            $documentFile['relative_path'],
            $documentFile
        );
        if (null !== $bestMp4) {
            return $this->normalizeStoragePath($storageType, $bestMp4, $documentFile);
        }

        // 優先順序 2：嘗試使用 XML 中的 MP4（如果檔案存在於同目錄中）
        if (!empty($mp4FilePaths['broadcast']) || !empty($mp4FilePaths['proxy'])) {
            $documentDir = dirname($documentFile['file_path']);
            $disk = $this->storageService->getDisk($storageType);
            
            // 首先嘗試廣播
            if (!empty($mp4FilePaths['broadcast'])) {
                $xmlMp4FilePath = $documentDir . '/' . $mp4FilePaths['broadcast'];
                if ($disk->exists($xmlMp4FilePath)) {
                    // 使用 file_path 格式（完整 GCS 路徑）作為 nas_path
                    $xmlMp4Path = $this->normalizeStoragePath($storageType, $xmlMp4FilePath, $documentFile);
                    return $xmlMp4Path;
                }
            }
            
            // 嘗試代理
            if (!empty($mp4FilePaths['proxy'])) {
                $xmlMp4FilePath = $documentDir . '/' . $mp4FilePaths['proxy'];
                if ($disk->exists($xmlMp4FilePath)) {
                    // 使用 file_path 格式（完整 GCS 路徑）作為 nas_path
                    $xmlMp4Path = $this->normalizeStoragePath($storageType, $xmlMp4FilePath, $documentFile);
                    return $xmlMp4Path;
                }
            }
        }

        // 找不到任何 MP4 檔案，返回 null
        return null;
    }

    /**
     * 標準化 nas_path 的儲存路徑。
     * 對於 GCS，確保路徑相對於 bucket 根目錄（例如：cnn/CNNA-ST1-xxx/file.mp4）。
     * 對於其他儲存類型，使用 relative_path。
     *
     * @param string $storageType
     * @param string $path
     * @param array<string, mixed> $documentFile
     * @return string
     */
    private function normalizeStoragePath(string $storageType, string $path, array $documentFile): string
    {
        // 對於 GCS，確保路徑格式為：cnn/CNNA-ST1-xxx/file.mp4
        if ('gcs' === $storageType) {
            // 移除可能的前導斜線和 storage/app 前綴
            $cleanPath = ltrim($path, '/');
            $cleanPath = preg_replace('#^storage/app/#', '', $cleanPath);
            
            return $cleanPath;
        }

        // 對於其他儲存類型（nas、s3、local），使用 relative_path 格式
        return $path;
    }

    /**
     * 在與給定檔案相同的目錄中尋找最佳 MP4 檔案。
     * 優先順序：1. 匹配唯一識別碼的 MP4（如果提供了 XML 檔案），2. 最新版本，3. 最小檔案大小。
     *
     * @param string $storageType
     * @param string $filePath
     * @param string $relativePath
     * @param array<string, mixed>|null $documentFile 可選的文檔檔案以匹配唯一識別碼
     * @return string|null
     */
    private function findSmallestMp4InSameDirectory(string $storageType, string $filePath, string $relativePath, ?array $documentFile = null): ?string
    {
        try {
            $disk = $this->storageService->getDisk($storageType);
            
            // 從檔案路徑獲取目錄路徑
            $fileDir = dirname($filePath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            // 如果提供了文檔檔案，則從中提取唯一識別碼
            $targetUniqueId = null;
            if (null !== $documentFile) {
                $targetUniqueId = $this->extractUniqueIdFromFileName($documentFile['file_name'] ?? '');
            }

            // 列出同目錄中的所有檔案
            $files = $disk->files($fileDir);
            
            $mp4Files = [];
            $matchingMp4Files = [];
            
            // 收集所有 MP4 檔案及其大小和版本
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' !== $extension) {
                    continue;
                }

                    try {
                        $size = $disk->size($file);
                    $fileName = basename($file);
                    $fileVersion = $this->storageService->extractFileVersion($fileName);

                    // 提取版本號以進行排序（extractFileVersion 現在直接返回 int）
                    $versionNumber = $fileVersion ?? -1;

                    // 從 MP4 檔案名提取唯一識別碼
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

                    // 如果我們有目標唯一識別碼且此 MP4 匹配，則添加到匹配列表
                    if (null !== $targetUniqueId && $mp4UniqueId === $targetUniqueId) {
                        $matchingMp4Files[] = $mp4Data;
                    }
                    } catch (\Exception $e) {
                    // 跳過無法讀取的檔案
                        Log::warning('[AnalyzeDocumentCommand] 無法取得 MP4 檔案大小', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                }
            }
            
            // 如果未找到 MP4 檔案，返回 null
            if (empty($mp4Files)) {
                return null;
            }
            
            // 如果我們有匹配的 MP4 檔案，優先處理它們
            $filesToSort = !empty($matchingMp4Files) ? $matchingMp4Files : $mp4Files;

            // 排序方式：1. 版本號（降序 - 最新版本優先），2. 大小（升序 - 最小優先）
            usort($filesToSort, function ($a, $b) {
                // 首先按版本號比較（較高版本優先）
                if ($a['version_number'] !== $b['version_number']) {
                    return $b['version_number'] <=> $a['version_number'];
                }
                // 如果版本相等（或兩者都是 -1），按大小排序（較小優先）
                return $a['size'] <=> $b['size'];
            });
            
            $bestMp4 = $filesToSort[0];
            
            // 根據儲存類型構建路徑
            // 對於 GCS，使用 file_path 格式（相對於 bucket 根目錄的完整路徑）
            // 對於其他儲存類型，使用 relative_path 格式
            if ('gcs' === $storageType) {
                // 對於 GCS，$bestMp4['file'] 已經是完整的 GCS 路徑（相對於 bucket 根目錄）
                // 例如：cnn/CNNA-ST1-xxx/檔案.mp4
                return ltrim($bestMp4['file'], '/');
            } else {
                // 使用相對路徑格式
            $mp4Dir = dirname($relativePath);
                return $mp4Dir . '/' . $bestMp4['name'];
            }
        } catch (\Exception $e) {
            Log::warning('[AnalyzeDocumentCommand] 在同資料夾中尋找最佳 MP4 檔案失敗', [
                'storage_type' => $storageType,
                'file_path' => $filePath,
                'relative_path' => $relativePath,
                'error' => $e->getMessage(),
            ]);
            return null;
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
            // 清理空白字元但保留換行
            $text = preg_replace('/[ \t]+/', ' ', $txtContent);
            $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
            $text = trim($text);

            return $text;
        } catch (\Exception $e) {
            Log::warning('[AnalyzeDocumentCommand] TXT 解析失敗，使用原始內容', [
                'error' => $e->getMessage(),
            ]);
            return $txtContent;
        }
    }

    /**
     * 過濾文檔檔案以選擇每個目錄中的最佳 XML 檔案。
     * 優先順序：尋找最佳 MP4（按版本 > 大小），然後選擇匹配唯一識別碼的 XML。
     * 如果未找到匹配的 XML，選擇版本最高的 XML。
     *
     * @param array<int, array<string, mixed>> $documentFiles
     * @return array<int, array<string, mixed>>
     */
    private function filterLatestVersionDocuments(array $documentFiles): array
    {
        // 按目錄（資料夾）分組
        $groupedByDir = [];
        foreach ($documentFiles as $file) {
            // 從 relative_path 或 file_path 提取目錄
            $dirPath = dirname($file['relative_path'] ?? $file['file_path'] ?? '');
            if (!isset($groupedByDir[$dirPath])) {
                $groupedByDir[$dirPath] = [];
            }
            $groupedByDir[$dirPath][] = $file;
        }

        $filtered = [];

        foreach ($groupedByDir as $dirPath => $files) {
            // 如果只有一個檔案，保留它
            if (1 === count($files)) {
                $filtered[] = $files[0];
                continue;
            }

            // 步驟 1：在此目錄中尋找最佳 MP4 檔案
            $bestMp4UniqueId = $this->findBestMp4UniqueIdInDirectory($dirPath, $files);

            // 步驟 2：選擇匹配最佳 MP4 唯一識別碼的 XML 檔案
            // 如果沒有匹配的 XML，選擇版本最高的 XML
            $selectedXml = $this->selectBestXmlForDirectory($files, $bestMp4UniqueId);

            if (null !== $selectedXml) {
                $filtered[] = $selectedXml;
            }
        }

        return $filtered;
    }

    /**
     * 在目錄中尋找最佳 MP4 檔案並返回其唯一識別碼。
     * 優先順序：1. 最高版本號，2. 最小檔案大小。
     *
     * @param string $dirPath
     * @param array<int, array<string, mixed>> $files
     * @return string|null
     */
    private function findBestMp4UniqueIdInDirectory(string $dirPath, array $files): ?string
    {
        $storageType = strtolower($this->option('storage'));
        $disk = $this->storageService->getDisk($storageType);

        // 從第一個檔案獲取實際目錄路徑
        $firstFile = $files[0];
        $actualDirPath = dirname($firstFile['file_path'] ?? $firstFile['relative_path'] ?? '');

        if (!$disk->exists($actualDirPath)) {
            return null;
        }

        // 列出目錄中的所有 MP4 檔案
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

                // 從檔案名提取唯一識別碼
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
                Log::warning('[AnalyzeDocumentCommand] 無法取得 MP4 檔案資訊', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (empty($mp4Files)) {
            return null;
        }

        // 排序方式：1. 版本（降序），2. 大小（升序）
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
     * 優先順序：1. 匹配最佳 MP4 唯一識別碼的 XML（最高版本），2. 版本最高的 XML。
     *
     * @param array<int, array<string, mixed>> $files
     * @param string|null $bestMp4UniqueId
     * @return array<string, mixed>|null
     */
    private function selectBestXmlForDirectory(array $files, ?string $bestMp4UniqueId): ?array
    {
        // 分離 XML 檔案
        $xmlFiles = [];
        foreach ($files as $file) {
            $extension = strtolower($file['extension'] ?? pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION));
            if ('xml' === $extension) {
                $xmlFiles[] = $file;
            }
        }

        if (empty($xmlFiles)) {
            // 沒有 XML 檔案，返回第一個檔案（不應該發生，但優雅處理）
            return $files[0] ?? null;
        }

        // 如果我們有最佳 MP4 唯一識別碼，嘗試尋找匹配的 XML
        if (null !== $bestMp4UniqueId) {
            $matchingXmls = [];
            foreach ($xmlFiles as $xmlFile) {
                $xmlUniqueId = $this->extractUniqueIdFromFileName($xmlFile['file_name'] ?? '');
                if ($xmlUniqueId === $bestMp4UniqueId) {
                    $matchingXmls[] = $xmlFile;
                }
            }

            if (!empty($matchingXmls)) {
                // 在匹配的 XML 中選擇最高版本
                usort($matchingXmls, function ($a, $b) {
                    $versionA = $a['file_version'] ?? -1;
                    $versionB = $b['file_version'] ?? -1;
                    return $versionB <=> $versionA;
                });
                return $matchingXmls[0];
            }
        }

        // 未找到匹配的 XML，選擇版本最高的 XML
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
        // 模式：CNNA-ST1-xxxxxxxxxxxxxxxx（16 個十六進位數字）
        if (preg_match('/CNNA-ST1-([a-f0-9]{16})/i', $fileName, $matches)) {
            return 'CNNA-ST1-' . strtoupper($matches[1]);
        }

        return null;
    }
}
