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
    protected $description = '從指定儲存空間撈取 XML 文檔並進行分析';

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

        // Scan for XML files
        $xmlFiles = $this->storageService->scanXmlFiles($storageType, $sourceName, $basePath);

        if (empty($xmlFiles)) {
            $this->warn("未找到任何 XML 文檔");
            return Command::SUCCESS;
        }

        $this->info("找到 " . count($xmlFiles) . " 個 XML 文檔");

        // Process XML files
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar(count($xmlFiles));
        $progressBar->start();

        foreach ($xmlFiles as $xmlFile) {
            if ($processedCount >= $limit) {
                break;
            }

            try {
                // Read XML file content
                $xmlContent = $this->storageService->readFile($storageType, $xmlFile['file_path']);

                if (null === $xmlContent) {
                    $this->warn("\n無法讀取 XML 檔案: {$xmlFile['file_path']}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                // Extract MP4 file paths from CNN XML (objPaths) before parsing
                $mp4FilePaths = $this->extractMp4PathsFromXml($xmlContent, $xmlFile);

                // Parse XML to text content
                $textContent = $this->parseXmlToText($xmlContent);

                if ('' === trim($textContent)) {
                    $this->warn("\nXML 檔案內容為空: {$xmlFile['file_path']}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                // Determine nas_path (prefer MP4 from XML, fallback to XML path)
                $nasPath = $xmlFile['relative_path'];
                if (!empty($mp4FilePaths['broadcast'])) {
                    $nasPath = $mp4FilePaths['broadcast'];
                } elseif (!empty($mp4FilePaths['proxy'])) {
                    $nasPath = $mp4FilePaths['proxy'];
                }

                // Check if video already exists
                $existingVideo = $this->videoRepository->getBySourceId($xmlFile['source_name'], $xmlFile['source_id']);

                if (null !== $existingVideo) {
                    if (AnalysisStatus::COMPLETED === $existingVideo->analysis_status) {
                        $this->line("\n跳過已完成分析的文檔: {$xmlFile['source_id']}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $videoId = $existingVideo->id;
                } else {
                    // Create new video record
                    $videoId = $this->videoRepository->findOrCreate([
                        'source_name' => $xmlFile['source_name'],
                        'source_id' => $xmlFile['source_id'],
                        'nas_path' => $nasPath,
                        'fetched_at' => date('Y-m-d H:i:s', $xmlFile['last_modified']),
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

                // Update nas_path if MP4 file path found in XML
                if (!empty($mp4FilePaths['broadcast'])) {
                    // Prefer broadcast quality file
                    $updateData['nas_path'] = $mp4FilePaths['broadcast'];
                } elseif (!empty($mp4FilePaths['proxy'])) {
                    // Fallback to proxy file
                    $updateData['nas_path'] = $mp4FilePaths['proxy'];
                }

                if (!empty($updateData)) {
                    $this->videoRepository->update($videoId, $updateData);
                }

                // Update prompt version
                $promptVersionToSave = $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v3';
                $this->videoRepository->update($videoId, [
                    'prompt_version' => $promptVersionToSave,
                ]);

                // Update status to metadata_extracted
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::METADATA_EXTRACTED,
                    new \DateTime()
                );

                $this->line("\n✓ 完成分析: {$xmlFile['file_name']}");
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeDocumentCommand] 分析文檔失敗', [
                    'source_id' => $xmlFile['source_id'],
                    'file_path' => $xmlFile['file_path'],
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

                $this->error("\n✗ 分析失敗: {$xmlFile['file_name']} - {$e->getMessage()}");
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
     * Extract MP4 file paths from CNN XML objPaths.
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
                                $mp4Paths['broadcast'] = $fileName;
                                break;
                            } elseif ('' === $mp4Paths['broadcast']) {
                                $mp4Paths['broadcast'] = $fileName;
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
                                $mp4Paths['proxy'] = $fileName;
                                break;
                            } elseif ('' === $mp4Paths['proxy']) {
                                $mp4Paths['proxy'] = $fileName;
                            }
                        }
                    }
                }
            }

            // If found, construct relative path based on XML file location
            if ('' !== $mp4Paths['broadcast'] || '' !== $mp4Paths['proxy']) {
                // Extract directory from XML relative path (remove source_name prefix)
                $xmlRelativePath = $xmlFile['relative_path'];
                $xmlDir = dirname($xmlRelativePath);
                
                // If xmlDir is just the source_name, use it directly
                if ($xmlDir === $xmlFile['source_name']) {
                    $xmlDir = $xmlFile['source_name'];
                }
                
                if ('' !== $mp4Paths['broadcast']) {
                    $mp4Paths['broadcast'] = $xmlDir . '/' . basename($mp4Paths['broadcast']);
                }
                if ('' !== $mp4Paths['proxy']) {
                    $mp4Paths['proxy'] = $xmlDir . '/' . basename($mp4Paths['proxy']);
                }
            }
        } catch (\Exception $e) {
            Log::warning('[AnalyzeDocumentCommand] 從 XML 提取 MP4 路徑失敗', [
                'error' => $e->getMessage(),
            ]);
        }

        return $mp4Paths;
    }
}
