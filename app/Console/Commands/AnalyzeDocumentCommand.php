<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
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
                            {--storage=s3 : 儲存空間類型 (nas, s3, storage)}
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
        private VideoRepository $videoRepository
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

                // Determine nas_path (prefer MP4 in same directory, then MP4 from XML, fallback to document path)
                $nasPath = $this->determineNasPath(
                    $storageType,
                    $documentFile,
                    $mp4FilePaths
                );

                // Check if video already exists
                $existingVideo = $this->videoRepository->getBySourceId($documentFile['source_name'], $documentFile['source_id']);

                if (null !== $existingVideo) {
                    if (AnalysisStatus::COMPLETED === $existingVideo->analysis_status) {
                        $this->line("\n跳過已完成分析的文檔: {$documentFile['source_id']}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $videoId = $existingVideo->id;
                } else {
                    // Create new video record
                    $videoId = $this->videoRepository->findOrCreate([
                        'source_name' => $documentFile['source_name'],
                        'source_id' => $documentFile['source_id'],
                        'nas_path' => $nasPath,
                        'fetched_at' => date('Y-m-d H:i:s', $documentFile['last_modified']),
                    ]);
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
                    $this->videoRepository->update($videoId, $updateData);
                }

                // Update status to metadata_extracted
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::METADATA_EXTRACTED,
                    new \DateTime()
                );

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
        // Priority 1: Find MP4 file in the same directory (any MP4 file, no name matching required)
        $mp4InSameDir = $this->storageService->findMp4InSameDirectory(
            $storageType,
            $documentFile['file_path'],
            $documentFile['relative_path']
        );
        if (null !== $mp4InSameDir) {
            return $mp4InSameDir;
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
}
