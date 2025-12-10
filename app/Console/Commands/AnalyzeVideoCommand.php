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

class AnalyzeVideoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analyze:video 
                            {--source= : 來源名稱 (CNN, AP, RT 等，可選，用於過濾)}
                            {--storage=gcs : 儲存空間類型 (nas, s3, gcs, storage)}
                            {--limit=50 : 每次處理的影片數量上限}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從資料庫查詢未完成分析的影片並進行分析';

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
        $sourceName = $this->option('source') ? strtoupper($this->option('source')) : null;
        $storageType = strtolower($this->option('storage'));
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');

        $sourceFilter = $sourceName ? "來源: {$sourceName}, " : '';
        $this->info("開始查詢未完成分析的影片 ({$sourceFilter}儲存空間: {$storageType})");

        // Include completed videos for version check if source supports it
        $includeCompletedForVersionCheck = false;
        if (null !== $sourceName) {
            $includeCompletedForVersionCheck = $this->versionChecker->shouldIncludeCompletedForVersionCheck($sourceName);
        }

        // Query incomplete videos from database
        $videos = $this->videoRepository->getIncompleteVideos($sourceName, $limit, $includeCompletedForVersionCheck);

        if ($videos->isEmpty()) {
            $this->warn("未找到需要分析的影片");
            return Command::SUCCESS;
        }

        $this->info("找到 " . $videos->count() . " 個需要分析的影片");

        // Analyze videos
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($videos->count());
        $progressBar->start();

        foreach ($videos as $video) {
            if ($processedCount >= $limit) {
                break;
            }

            // Skip if nas_path is empty
            if (empty($video->nas_path)) {
                $this->warn("\n跳過缺少 nas_path 的影片: {$video->source_id}");
                $skippedCount++;
                $progressBar->advance();
                continue;
            }

            try {
                // Check if version checking is enabled for this source
                $versionCheckEnabled = $this->versionChecker->shouldIncludeCompletedForVersionCheck($video->source_name);
                
                // Check if there's a newer version of the MP4 file in the same directory
                // Only do this for sources that support version checking (e.g., CNN)
                $bestMp4Path = null;
                $mp4Version = null;
                
                if ($versionCheckEnabled) {
                    $bestMp4Path = $this->findBestMp4InDirectory($storageType, $video->nas_path);
                    
                    // Extract MP4 version from the best file
                    if (null !== $bestMp4Path) {
                        $mp4FileName = basename($bestMp4Path);
                        $mp4Version = $this->storageService->extractFileVersion($mp4FileName) ?? 0;
                    } else {
                        // Extract from current nas_path
                        $currentFileName = basename($video->nas_path);
                        $mp4Version = $this->storageService->extractFileVersion($currentFileName) ?? 0;
                    }
                    
                    // Update nas_path and mp4_file_version if a better version was found
                    if (null !== $bestMp4Path && $bestMp4Path !== $video->nas_path) {
                        $this->videoRepository->update($video->id, [
                            'nas_path' => $bestMp4Path,
                            'mp4_file_version' => $mp4Version,
                        ]);
                        $this->line("\n更新 nas_path 為最新版本: {$bestMp4Path} (版本: {$mp4Version})");
                        $video->nas_path = $bestMp4Path;
                    } else {
                        // Update mp4_file_version even if path didn't change (to keep it in sync)
                        if (null !== $mp4Version && $video->mp4_file_version !== $mp4Version) {
                            $this->videoRepository->update($video->id, [
                                'mp4_file_version' => $mp4Version,
                            ]);
                        }
                    }
                }

                // Check if file version has been updated (only for sources that support version checking)
                if ($versionCheckEnabled && AnalysisStatus::COMPLETED === $video->analysis_status) {
                    $versionCheck = $this->versionChecker->shouldReanalyze(
                        $video->source_name,
                        $video,
                        $mp4Version,
                        $video->nas_path,
                        'mp4'
                    );

                    if (!$versionCheck['should_reanalyze']) {
                        // Version hasn't changed, skip
                        $this->line("\n跳過版本未變更的已完成影片: {$video->source_id}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->line("\n{$versionCheck['reason']}: {$video->source_id}，將重新分析");
                }

                // Update status to processing
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::PROCESSING,
                    new \DateTime()
                );

                // Get video file path from nas_path
                $videoFilePath = $this->storageService->getVideoFilePath($storageType, $video->nas_path);

                if (null === $videoFilePath) {
                    throw new \Exception("無法取得影片檔案路徑: {$video->nas_path}");
                }

                // For local storage types, check if file exists
                if (in_array($storageType, ['nas', 'local', 'storage'], true)) {
                    if (!file_exists($videoFilePath)) {
                        throw new \Exception("影片檔案不存在: {$videoFilePath} (nas_path: {$video->nas_path})");
                    }
                }

                // Check file size - Gemini API supports up to 300MB
                if (file_exists($videoFilePath)) {
                    $fileSize = filesize($videoFilePath);
                    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                    
                    // Gemini API limit: 300MB
                    $maxFileSizeMB = 300;
                    
                    if ($fileSizeMB > $maxFileSizeMB) {
                        $errorMessage = "影片檔案過大 ({$fileSizeMB}MB)，超過 Gemini API 限制 ({$maxFileSizeMB}MB)";
                        
                        Log::warning('[AnalyzeVideoCommand] 跳過過大檔案', [
                            'video_id' => $video->id,
                            'source_id' => $video->source_id,
                            'nas_path' => $video->nas_path,
                            'file_size_mb' => $fileSizeMB,
                            'max_size_mb' => $maxFileSizeMB,
                        ]);
                        
                        // Update status to failed with error message
                        $this->videoRepository->updateAnalysisStatus(
                            $video->id,
                            AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                            new \DateTime()
                        );
                        
                        // Save error message to analysis result
                        $this->analyzeService->saveAnalysisResult(
                            $video->id,
                            [
                                'error_message' => $errorMessage,
                            ],
                            $promptVersion ?? 'v6'
                        );
                        
                        $this->warn("\n⚠️  跳過過大檔案: {$video->source_id} (檔案大小: {$fileSizeMB}MB)");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Estimate memory needed: file size + base64 encoding overhead (~33%) + JSON payload overhead
                    $estimatedMemoryMB = $fileSizeMB * 2.5; // Conservative estimate
                    
                    if ($estimatedMemoryMB > 400) {
                        // Increase memory limit for large files (set to at least 2GB or 3x file size)
                        $newMemoryLimit = max(2048, (int) ceil($estimatedMemoryMB * 1.5));
                        ini_set('memory_limit', $newMemoryLimit . 'M');
                        $this->line("\n調整記憶體限制為 {$newMemoryLimit}MB (檔案大小: {$fileSizeMB}MB)");
                    }
                }

                // Execute video analysis
                $analysisResult = $this->analyzeService->executeVideoAnalysis(
                    $video->id,
                    $promptVersion,
                    $videoFilePath
                );

                // Save analysis result
                $this->analyzeService->saveAnalysisResult(
                    $video->id,
                    $analysisResult,
                    $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v6'
                );

                // Free memory after processing
                unset($analysisResult);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }

                $this->line("\n✓ 完成分析: {$video->source_id} ({$video->nas_path})");
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeVideoCommand] 分析影片失敗', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'nas_path' => $video->nas_path,
                    'error' => $e->getMessage(),
                ]);

                // Update status to failed
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );

                $this->error("\n✗ 分析失敗: {$video->source_id} - {$e->getMessage()}");
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
     * Find the best MP4 file in the same directory as the given nas_path.
     * Priority: 1. Latest version (highest version number), 2. Smallest file size.
     *
     * @param string $storageType
     * @param string $nasPath
     * @return string|null Returns the best nas_path, or null if no better file found
     */
    private function findBestMp4InDirectory(string $storageType, string $nasPath): ?string
    {
        try {
            $disk = $this->storageService->getDisk($storageType);
            
            // Get directory path from nas_path
            $fileDir = dirname($nasPath);
            $currentFileName = basename($nasPath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            // List all files in the same directory
            $files = $disk->files($fileDir);
            
            $mp4Files = [];
            
            // Collect all MP4 files with their sizes and versions
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' === $extension) {
                    try {
                        $size = $disk->size($file);
                        $fileName = basename($file);
                        $fileVersion = $this->storageService->extractFileVersion($fileName);
                        
                        // Extract version number for sorting (extractFileVersion now returns int directly)
                        $versionNumber = $fileVersion ?? -1;
                        
                        $mp4Files[] = [
                            'file' => $file,
                            'size' => $size,
                            'name' => $fileName,
                            'version' => $fileVersion,
                            'version_number' => $versionNumber,
                        ];
                    } catch (\Exception $e) {
                        // Skip files that can't be read
                        Log::warning('[AnalyzeVideoCommand] 無法取得 MP4 檔案資訊', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            }
            
            // If no MP4 files found, return null
            if (empty($mp4Files)) {
                return null;
            }
            
            // Sort by: 1. Version number (descending - latest version first), 2. Size (ascending - smallest first)
            usort($mp4Files, function ($a, $b) {
                // First compare by version number (higher version first)
                if ($a['version_number'] !== $b['version_number']) {
                    return $b['version_number'] <=> $a['version_number'];
                }
                // If versions are equal (or both are -1), sort by size (smaller first)
                return $a['size'] <=> $b['size'];
            });
            
            $bestMp4 = $mp4Files[0];
            
            // If the best file is the same as current, return null (no update needed)
            if ($bestMp4['name'] === $currentFileName) {
                return null;
            }
            
            // Build relative path (nas_path format)
            $mp4Dir = dirname($nasPath);
            return $mp4Dir . '/' . $bestMp4['name'];
        } catch (\Exception $e) {
            Log::warning('[AnalyzeVideoCommand] 在同資料夾中尋找最佳 MP4 檔案失敗', [
                'storage_type' => $storageType,
                'nas_path' => $nasPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
