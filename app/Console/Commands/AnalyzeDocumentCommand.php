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
     * The name and signature of the console command.
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
     * The console command description.
     *
     * @var string
     */
    protected $description = '從指定儲存空間撈取 XML 或 TXT 文檔並進行分析';

    /**
     * Create a new command instance.
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
     * Execute the console command.
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

        // Scan for document files (XML and TXT)
        $documentFiles = $this->storageService->scanDocumentFiles($storageType, $sourceName, $basePath);

        if (empty($documentFiles)) {
            $this->warn("未找到任何文檔檔案 (XML 或 TXT)");
            return Command::SUCCESS;
        }

        $this->info("找到 " . count($documentFiles) . " 個文檔檔案");

        // Filter to keep only the latest version for each source_id
        $documentFiles = $this->filterLatestVersionDocuments($documentFiles);
        $this->info("過濾後剩餘 " . count($documentFiles) . " 個文檔檔案（每個 source_id 只保留最新版本）");

        // Process document files
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar(count($documentFiles));
        $progressBar->start();

        foreach ($documentFiles as $documentFile) {
            if ($processedCount >= $limit) {
                break;
            }

            try {
                // Read document file content
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

                // Parse content based on file type
                if ('xml' === $fileExtension) {
                // Extract MP4 file paths from CNN XML (objPaths) before parsing
                    $mp4FilePaths = $this->extractMp4PathsFromXml($fileContent, $documentFile);

                // Parse XML to text content
                    $textContent = $this->parseXmlToText($fileContent);
                } elseif ('txt' === $fileExtension) {
                    // Parse TXT file content
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

                // Determine nas_path (prefer MP4 in same directory matching XML's unique ID, then MP4 from XML, fallback to document path)
                $nasPath = $this->determineNasPath(
                    $storageType,
                    $documentFile,
                    $mp4FilePaths
                );

                // Check if video already exists
                $existingVideo = $this->videoRepository->getBySourceId(
                    $documentFile['source_name'],
                    $documentFile['source_id']
                );

                // Check version and determine if reanalysis is needed (for XML files)
                // Only perform version check if source supports it (e.g., CNN)
                $versionCheckEnabled = $this->versionChecker->shouldIncludeCompletedForVersionCheck($documentFile['source_name']);
                $versionCheck = $this->versionChecker->shouldReanalyze(
                    $documentFile['source_name'],
                    $existingVideo,
                    $documentFile['file_version'] ?? null,
                    $documentFile['file_path'] ?? null,
                    'xml'
                );

                // Handle existing video
                if (null !== $existingVideo) {
                    // Skip if completed and version hasn't changed (only for sources with version checking)
                    if ($versionCheckEnabled && AnalysisStatus::COMPLETED === $existingVideo->analysis_status && !$versionCheck['should_reanalyze']) {
                        $this->line("\n跳過已完成分析的文檔: {$documentFile['source_id']}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $videoId = $existingVideo->id;

                    // Only update xml_file_version if version checking is enabled for this source
                    $updateData = [];
                    if ($versionCheckEnabled && null !== $versionCheck['new_version']) {
                        $updateData['xml_file_version'] = $versionCheck['new_version'];
                    }
                    
                    // Update nas_path if version changed or if it's different
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
                    // Create new video record
                    $createData = [
                        'source_name' => $documentFile['source_name'],
                        'source_id' => $documentFile['source_id'],
                        'nas_path' => $nasPath,
                        'fetched_at' => date('Y-m-d H:i:s', $documentFile['last_modified']),
                    ];
                    
                    // Only set version fields if version checking is enabled for this source
                    if ($versionCheckEnabled) {
                        $createData['xml_file_version'] = $versionCheck['new_version'] ?? 0;
                        $createData['mp4_file_version'] = 0; // Default to 0 for new records
                    } else {
                        // For sources without version checking, set to 0 (default)
                        $createData['xml_file_version'] = 0;
                        $createData['mp4_file_version'] = 0;
                    }
                    
                    $videoId = $this->videoRepository->findOrCreate($createData);
                }

                // Update status to metadata_extracting
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::METADATA_EXTRACTING,
                    new \DateTime()
                );

                // Execute text analysis
                $analysisResult = $this->analyzeService->executeTextAnalysis($textContent, $promptVersion);

                if (empty($analysisResult)) {
                    throw new \Exception('文本分析結果為空');
                }

                // Handle array response (AI may return array with single object)
                // Check if result is an array with numeric keys (indexed array)
                if (is_array($analysisResult) && isset($analysisResult[0]) && is_array($analysisResult[0])) {
                    // Extract prompt version before overwriting (it might be at root level)
                    $promptVersionFromResult = $analysisResult['_prompt_version'] ?? null;
                    // Use first element if result is an array
                    $analysisResult = $analysisResult[0];
                    // Restore prompt version if it was in the original array
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

                // Update video with metadata from analysis
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

                // Update prompt version
                $updateData['prompt_version'] = $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v3';

                // Update video metadata
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

                // Update status to metadata_extracted
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

                // Update status to failed
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

        // Summary
        $this->info("分析完成！");
        $this->table(
            ['狀態', '數量'],
            [
                ['已處理', $processedCount],
                ['已跳過', $skippedCount],
                ['錯誤', $errorCount],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Parse XML content to text (CNN format).
     *
     * @param string $xmlContent
     * @return string
     */
    private function parseXmlToText(string $xmlContent): string
    {
        try {
            $xml = simplexml_load_string($xmlContent);

            if (false === $xml) {
                // If XML parsing fails, return raw content
                return $xmlContent;
            }

            // For CNN XML format, extract all text content including script information
            // Convert XML to string while preserving structure
            $textParts = [];

            // Extract title if exists
            if (isset($xml->title)) {
                $textParts[] = 'Title: ' . (string) $xml->title;
            }

            // Extract description if exists
            if (isset($xml->description)) {
                $textParts[] = 'Description: ' . (string) $xml->description;
            }

            // Extract script content (CNN XML may have script tags)
            if (isset($xml->script)) {
                $textParts[] = 'Script: ' . (string) $xml->script;
            }

            // Extract all text nodes recursively
            $this->extractTextNodes($xml, $textParts);

            // If no specific content found, use all text content
            if (empty($textParts)) {
                $text = strip_tags($xml->asXML());
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                return $text;
            }

            // Combine all text parts
            $text = implode("\n", $textParts);

            // Clean up whitespace but preserve line breaks
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
     * Extract text nodes from XML recursively.
     *
     * @param \SimpleXMLElement $xml
     * @param array<string> $textParts
     * @return void
     */
    private function extractTextNodes(\SimpleXMLElement $xml, array &$textParts): void
    {
        // Get direct text content
        $text = trim((string) $xml);
        if ('' !== $text && strlen($text) > 10) {
            // Only add substantial text content
            $textParts[] = $text;
        }

        // Recursively process children
        foreach ($xml->children() as $child) {
            $this->extractTextNodes($child, $textParts);
        }
    }

    /**
     * Parse date time string to database format.
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
     * Extract MP4 file names from CNN XML objPaths.
     * Returns only file names, not full paths.
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

            // Look for objPaths tag
            if (isset($xml->objPaths)) {
                // Get broadcast quality file (objFile with MP4)
                if (isset($xml->objPaths->objFile)) {
                    foreach ($xml->objPaths->objFile as $objFile) {
                        $fileName = (string) $objFile;
                        $techDesc = (string) $objFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            // Prefer NTSC or PAL broadcast quality
                            if (str_contains($techDesc, 'NTSC') || str_contains($techDesc, 'PAL')) {
                                $mp4Paths['broadcast'] = basename($fileName);
                                break;
                            } elseif ('' === $mp4Paths['broadcast']) {
                                $mp4Paths['broadcast'] = basename($fileName);
                            }
                        }
                    }
                }

                // Get proxy file (objProxyFile with MP4)
                if (isset($xml->objPaths->objProxyFile)) {
                    foreach ($xml->objPaths->objProxyFile as $objProxyFile) {
                        $fileName = (string) $objProxyFile;
                        $techDesc = (string) $objProxyFile['techDescription'] ?? '';

                        if (str_ends_with(strtolower($fileName), '.mp4')) {
                            // Prefer H264 proxy format
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
     * Determine nas_path for video record.
     * Priority: 1. MP4 in same directory, 2. MP4 from XML (if exists), 3. Document path.
     *
     * @param string $storageType
     * @param array<string, mixed> $documentFile
     * @param array<string, string> $mp4FilePaths
     * @return string
     */
    private function determineNasPath(string $storageType, array $documentFile, array $mp4FilePaths): string
    {
        // Priority 1: Find the best MP4 file in the same directory (matching XML's unique ID if possible)
        $bestMp4 = $this->findSmallestMp4InSameDirectory(
            $storageType,
            $documentFile['file_path'],
            $documentFile['relative_path'],
            $documentFile
        );
        if (null !== $bestMp4) {
            return $bestMp4;
        }

        // Priority 2: Try MP4 from XML (if file exists in same directory)
        if (!empty($mp4FilePaths['broadcast']) || !empty($mp4FilePaths['proxy'])) {
            $documentDir = dirname($documentFile['relative_path']);
            $disk = $this->storageService->getDisk($storageType);
            
            // Try broadcast first
            if (!empty($mp4FilePaths['broadcast'])) {
                $xmlMp4Path = $documentDir . '/' . $mp4FilePaths['broadcast'];
                $xmlMp4FilePath = dirname($documentFile['file_path']) . '/' . $mp4FilePaths['broadcast'];
                if ($disk->exists($xmlMp4FilePath)) {
                    return $xmlMp4Path;
                }
            }
            
            // Try proxy
            if (!empty($mp4FilePaths['proxy'])) {
                $xmlMp4Path = $documentDir . '/' . $mp4FilePaths['proxy'];
                $xmlMp4FilePath = dirname($documentFile['file_path']) . '/' . $mp4FilePaths['proxy'];
                if ($disk->exists($xmlMp4FilePath)) {
                    return $xmlMp4Path;
                }
            }
        }

        // Priority 3: Use document path as fallback
        return $documentFile['relative_path'];
    }

    /**
     * Find the best MP4 file in the same directory as the given file.
     * Priority: 1. MP4 with matching unique ID (if XML file provided), 2. Latest version, 3. Smallest file size.
     *
     * @param string $storageType
     * @param string $filePath
     * @param string $relativePath
     * @param array<string, mixed>|null $documentFile Optional document file to match unique ID
     * @return string|null
     */
    private function findSmallestMp4InSameDirectory(string $storageType, string $filePath, string $relativePath, ?array $documentFile = null): ?string
    {
        try {
            $disk = $this->storageService->getDisk($storageType);
            
            // Get directory path from file path
            $fileDir = dirname($filePath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            // Extract unique ID from document file if provided
            $targetUniqueId = null;
            if (null !== $documentFile) {
                $targetUniqueId = $this->extractUniqueIdFromFileName($documentFile['file_name'] ?? '');
            }
            
            // List all files in the same directory
            $files = $disk->files($fileDir);
            
            $mp4Files = [];
            $matchingMp4Files = [];
            
            // Collect all MP4 files with their sizes and versions
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' !== $extension) {
                    continue;
                }

                try {
                    $size = $disk->size($file);
                    $fileName = basename($file);
                    $fileVersion = $this->storageService->extractFileVersion($fileName);
                    
                    // Extract version number for sorting (extractFileVersion now returns int directly)
                    $versionNumber = $fileVersion ?? -1;
                    
                    // Extract unique ID from MP4 filename
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
                    
                    // If we have a target unique ID and this MP4 matches, add to matching list
                    if (null !== $targetUniqueId && $mp4UniqueId === $targetUniqueId) {
                        $matchingMp4Files[] = $mp4Data;
                    }
                } catch (\Exception $e) {
                    // Skip files that can't be read
                    Log::warning('[AnalyzeDocumentCommand] 無法取得 MP4 檔案大小', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
            
            // If no MP4 files found, return null
            if (empty($mp4Files)) {
                return null;
            }
            
            // If we have matching MP4 files, prioritize them
            $filesToSort = !empty($matchingMp4Files) ? $matchingMp4Files : $mp4Files;
            
            // Sort by: 1. Version number (descending - latest version first), 2. Size (ascending - smallest first)
            usort($filesToSort, function ($a, $b) {
                // First compare by version number (higher version first)
                if ($a['version_number'] !== $b['version_number']) {
                    return $b['version_number'] <=> $a['version_number'];
                }
                // If versions are equal (or both are -1), sort by size (smaller first)
                return $a['size'] <=> $b['size'];
            });
            
            $bestMp4 = $filesToSort[0];
            
            // Build relative path
            $mp4Dir = dirname($relativePath);
            return $mp4Dir . '/' . $bestMp4['name'];
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
     * Parse TXT file content to text.
     *
     * @param string $txtContent
     * @return string
     */
    private function parseTxtToText(string $txtContent): string
    {
        try {
            // Clean up whitespace but preserve line breaks
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
     * Filter document files to select the best XML file in each directory.
     * Priority: Find the best MP4 (by version > size), then select XML with matching unique ID.
     * If no matching XML found, select XML with highest version.
     *
     * @param array<int, array<string, mixed>> $documentFiles
     * @return array<int, array<string, mixed>>
     */
    private function filterLatestVersionDocuments(array $documentFiles): array
    {
        // Group by directory (folder)
        $groupedByDir = [];
        foreach ($documentFiles as $file) {
            // Extract directory from relative_path or file_path
            $dirPath = dirname($file['relative_path'] ?? $file['file_path'] ?? '');
            if (!isset($groupedByDir[$dirPath])) {
                $groupedByDir[$dirPath] = [];
            }
            $groupedByDir[$dirPath][] = $file;
        }

        $filtered = [];

        foreach ($groupedByDir as $dirPath => $files) {
            // If only one file, keep it
            if (1 === count($files)) {
                $filtered[] = $files[0];
                continue;
            }

            // Step 1: Find the best MP4 file in this directory
            $bestMp4UniqueId = $this->findBestMp4UniqueIdInDirectory($dirPath, $files);

            // Step 2: Select XML file matching the best MP4's unique ID
            // If no matching XML, select XML with highest version
            $selectedXml = $this->selectBestXmlForDirectory($files, $bestMp4UniqueId);

            if (null !== $selectedXml) {
                $filtered[] = $selectedXml;
            }
        }

        return $filtered;
    }

    /**
     * Find the best MP4 file in a directory and return its unique ID.
     * Priority: 1. Highest version number, 2. Smallest file size.
     *
     * @param string $dirPath
     * @param array<int, array<string, mixed>> $files
     * @return string|null
     */
    private function findBestMp4UniqueIdInDirectory(string $dirPath, array $files): ?string
    {
        $storageType = strtolower($this->option('storage'));
        $disk = $this->storageService->getDisk($storageType);

        // Get the actual directory path from the first file
        $firstFile = $files[0];
        $actualDirPath = dirname($firstFile['file_path'] ?? $firstFile['relative_path'] ?? '');

        if (!$disk->exists($actualDirPath)) {
            return null;
        }

        // List all MP4 files in the directory
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

                // Extract unique ID from filename
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

        // Sort by: 1. Version (descending), 2. Size (ascending)
        usort($mp4Files, function ($a, $b) {
            if ($a['version'] !== $b['version']) {
                return $b['version'] <=> $a['version'];
            }
            return $a['size'] <=> $b['size'];
        });

        return $mp4Files[0]['unique_id'];
    }

    /**
     * Select the best XML file for a directory.
     * Priority: 1. XML matching the best MP4's unique ID (highest version), 2. XML with highest version.
     *
     * @param array<int, array<string, mixed>> $files
     * @param string|null $bestMp4UniqueId
     * @return array<string, mixed>|null
     */
    private function selectBestXmlForDirectory(array $files, ?string $bestMp4UniqueId): ?array
    {
        // Separate XML files
        $xmlFiles = [];
        foreach ($files as $file) {
            $extension = strtolower($file['extension'] ?? pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION));
            if ('xml' === $extension) {
                $xmlFiles[] = $file;
            }
        }

        if (empty($xmlFiles)) {
            // No XML files, return first file (shouldn't happen, but handle gracefully)
            return $files[0] ?? null;
        }

        // If we have a best MP4 unique ID, try to find matching XML
        if (null !== $bestMp4UniqueId) {
            $matchingXmls = [];
            foreach ($xmlFiles as $xmlFile) {
                $xmlUniqueId = $this->extractUniqueIdFromFileName($xmlFile['file_name'] ?? '');
                if ($xmlUniqueId === $bestMp4UniqueId) {
                    $matchingXmls[] = $xmlFile;
                }
            }

            if (!empty($matchingXmls)) {
                // Select the highest version among matching XMLs
                usort($matchingXmls, function ($a, $b) {
                    $versionA = $a['file_version'] ?? -1;
                    $versionB = $b['file_version'] ?? -1;
                    return $versionB <=> $versionA;
                });
                return $matchingXmls[0];
            }
        }

        // No matching XML found, select XML with highest version
        usort($xmlFiles, function ($a, $b) {
            $versionA = $a['file_version'] ?? -1;
            $versionB = $b['file_version'] ?? -1;
            return $versionB <=> $versionA;
        });

        return $xmlFiles[0];
    }

    /**
     * Extract unique ID from filename.
     *
     * @param string $fileName
     * @return string|null
     */
    private function extractUniqueIdFromFileName(string $fileName): ?string
    {
        // Pattern: CNNA-ST1-xxxxxxxxxxxxxxxx (16 hex digits)
        if (preg_match('/CNNA-ST1-([a-f0-9]{16})/i', $fileName, $matches)) {
            return 'CNNA-ST1-' . strtoupper($matches[1]);
        }

        return null;
    }
}
