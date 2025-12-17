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
     * 控制台命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'analyze:video 
                            {--source= : 來源名稱 (CNN, AP, RT 等，可選，用於過濾)}
                            {--storage=gcs : 儲存空間類型 (nas, s3, gcs, storage)}
                            {--limit=50 : 每次處理的影片數量上限}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '從資料庫查詢未完成分析的影片並進行分析';

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
        
        $sourceName = $this->option('source') ? strtoupper($this->option('source')) : null;
        $storageType = strtolower($this->option('storage'));
        $limit = (int) $this->option('limit');
        $promptVersion = $this->option('prompt-version');

        $sourceFilter = $sourceName ? "來源: {$sourceName}, " : '';
        $this->info("開始查詢未完成分析的影片 ({$sourceFilter}儲存空間: {$storageType})");

        // 如果來源支援，則包含已完成影片以進行版本檢查
        $includeCompletedForVersionCheck = false;
        if (null !== $sourceName) {
            $includeCompletedForVersionCheck = $this->versionChecker->shouldIncludeCompletedForVersionCheck($sourceName);
        }

        // 從資料庫查詢未完成的影片
        $videos = $this->videoRepository->getIncompleteVideos($sourceName, $limit, $includeCompletedForVersionCheck);

        if ($videos->isEmpty()) {
            $this->warn("未找到需要分析的影片");
            return Command::SUCCESS;
        }

        $this->info("找到 " . $videos->count() . " 個需要分析的影片");

        // 分析影片
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($videos->count());
        $progressBar->start();

        foreach ($videos as $video) {
            if ($processedCount >= $limit) {
                break;
            }

            // 如果 nas_path 為空則跳過
            if (empty($video->nas_path)) {
                $this->warn("\n跳過缺少 nas_path 的影片: {$video->source_id}");
                $skippedCount++;
                $progressBar->advance();
                continue;
            }

            try {
                // 檢查此來源是否啟用版本檢查
                $versionCheckEnabled = $this->versionChecker->shouldIncludeCompletedForVersionCheck($video->source_name);
                
                // 檢查同目錄中是否有更新版本的 MP4 檔案
                // 僅對支援版本檢查的來源執行此操作（例如 CNN）
                $bestMp4Path = null;
                $mp4Version = null;
                
                if ($versionCheckEnabled) {
                    $bestMp4Path = $this->findBestMp4InDirectory($storageType, $video->nas_path);
                    
                    // 從最佳檔案提取 MP4 版本
                    if (null !== $bestMp4Path) {
                        $mp4FileName = basename($bestMp4Path);
                        $mp4Version = $this->storageService->extractFileVersion($mp4FileName) ?? 0;
                    } else {
                        // 從當前 nas_path 提取
                        $currentFileName = basename($video->nas_path);
                        $mp4Version = $this->storageService->extractFileVersion($currentFileName) ?? 0;
                    }
                    
                    // 如果找到更好的版本，更新 nas_path 和 mp4_file_version
                    if (null !== $bestMp4Path && $bestMp4Path !== $video->nas_path) {
                        $this->videoRepository->update($video->id, [
                            'nas_path' => $bestMp4Path,
                            'mp4_file_version' => $mp4Version,
                        ]);
                        $this->line("\n更新 nas_path 為最新版本: {$bestMp4Path} (版本: {$mp4Version})");
                        $video->nas_path = $bestMp4Path;
                    } else {
                        // 即使路徑未變更，也更新 mp4_file_version（以保持同步）
                        if (null !== $mp4Version && $video->mp4_file_version !== $mp4Version) {
                            $this->videoRepository->update($video->id, [
                                'mp4_file_version' => $mp4Version,
                            ]);
                        }
                    }
                }

                // 檢查檔案版本是否已更新（僅適用於支援版本檢查的來源）
                if ($versionCheckEnabled && AnalysisStatus::COMPLETED === $video->analysis_status) {
                    $versionCheck = $this->versionChecker->shouldReanalyze(
                        $video->source_name,
                        $video,
                        $mp4Version,
                        $video->nas_path,
                        'mp4'
                    );

                    if (!$versionCheck['should_reanalyze']) {
                        // 版本未變更，跳過
                        $this->line("\n跳過版本未變更的已完成影片: {$video->source_id}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->line("\n{$versionCheck['reason']}: {$video->source_id}，將重新分析");
                }

                // 將狀態更新為處理中
                $this->videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::PROCESSING,
                    new \DateTime()
                );

                // 從 nas_path 獲取影片檔案路徑
                $videoFilePath = $this->storageService->getVideoFilePath($storageType, $video->nas_path);

                if (null === $videoFilePath) {
                    throw new \Exception("無法取得影片檔案路徑: {$video->nas_path}");
                }

                // 對於本地儲存類型，檢查檔案是否存在
                if (in_array($storageType, ['nas', 'local', 'storage'], true)) {
                    if (!file_exists($videoFilePath)) {
                        throw new \Exception("影片檔案不存在: {$videoFilePath} (nas_path: {$video->nas_path})");
                    }
                }

                // 檢查檔案大小 - Gemini API 最多支援 300MB
                if (file_exists($videoFilePath)) {
                    $fileSize = filesize($videoFilePath);
                    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                    
                    // 儲存檔案大小到資料庫（如果尚未儲存）
                    if (null === $video->file_size_mb) {
                        $this->videoRepository->update($video->id, [
                            'file_size_mb' => $fileSizeMB,
                        ]);
                        $video->file_size_mb = $fileSizeMB;
                    }
                    
                    // Gemini API 限制：300MB
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
                        
                        // 將狀態更新為失敗並附上錯誤訊息
                        $this->videoRepository->updateAnalysisStatus(
                            $video->id,
                            AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                            new \DateTime()
                        );
                        
                        // 將錯誤訊息保存到分析結果
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
                    
                    // 估算所需記憶體：檔案大小 + base64 編碼開銷（約 33%）+ JSON 負載開銷
                    $estimatedMemoryMB = $fileSizeMB * 2.5; // 保守估算
                    
                    if ($estimatedMemoryMB > 400) {
                        // 為大檔案增加記憶體限制（設為至少 2GB 或 3 倍檔案大小）
                        $newMemoryLimit = max(2048, (int) ceil($estimatedMemoryMB * 1.5));
                        ini_set('memory_limit', $newMemoryLimit . 'M');
                        $this->line("\n調整記憶體限制為 {$newMemoryLimit}MB (檔案大小: {$fileSizeMB}MB)");
                    }
                }

                // 執行影片分析
                $analysisResult = $this->analyzeService->executeVideoAnalysis(
                    $video->id,
                    $promptVersion,
                    $videoFilePath
                );

                // 保存分析結果
                $this->analyzeService->saveAnalysisResult(
                    $video->id,
                    $analysisResult,
                    $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v6'
                );

                // 處理後釋放記憶體
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

                // 將狀態更新為失敗
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

        // 摘要
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
     * 在與給定 nas_path 相同的目錄中尋找最佳 MP4 檔案。
     * 優先順序：1. 最新版本（最高版本號），2. 最小檔案大小。
     *
     * @param string $storageType
     * @param string $nasPath
     * @return string|null 返回最佳 nas_path，如果未找到更好的檔案則返回 null
     */
    private function findBestMp4InDirectory(string $storageType, string $nasPath): ?string
    {
        try {
            $disk = $this->storageService->getDisk($storageType);
            
            // 從 nas_path 獲取目錄路徑
            $fileDir = dirname($nasPath);
            $currentFileName = basename($nasPath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            // 列出同目錄中的所有檔案
            $files = $disk->files($fileDir);
            
            $mp4Files = [];
            
            // 收集所有 MP4 檔案及其大小和版本
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' === $extension) {
                    try {
                        $size = $disk->size($file);
                        $fileName = basename($file);
                        $fileVersion = $this->storageService->extractFileVersion($fileName);
                        
                        // 提取版本號以進行排序（extractFileVersion 現在直接返回 int）
                        $versionNumber = $fileVersion ?? -1;
                        
                        $mp4Files[] = [
                            'file' => $file,
                            'size' => $size,
                            'name' => $fileName,
                            'version' => $fileVersion,
                            'version_number' => $versionNumber,
                        ];
                    } catch (\Exception $e) {
                        // 跳過無法讀取的檔案
                        Log::warning('[AnalyzeVideoCommand] 無法取得 MP4 檔案資訊', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            }
            
            // 如果未找到 MP4 檔案，返回 null
            if (empty($mp4Files)) {
                return null;
            }
            
            // 排序方式：1. 版本號（降序 - 最新版本優先），2. 大小（升序 - 最小優先）
            usort($mp4Files, function ($a, $b) {
                // 首先按版本號比較（較高版本優先）
                if ($a['version_number'] !== $b['version_number']) {
                    return $b['version_number'] <=> $a['version_number'];
                }
                // 如果版本相等（或兩者都是 -1），按大小排序（較小優先）
                return $a['size'] <=> $b['size'];
            });
            
            $bestMp4 = $mp4Files[0];
            
            // 如果最佳檔案與當前檔案相同，返回 null（無需更新）
            if ($bestMp4['name'] === $currentFileName) {
                return null;
            }
            
            // 構建相對路徑（nas_path 格式）
            // 對於 GCS，$bestMp4['file'] 已經是完整的 GCS 路徑（相對於 bucket 根目錄）
            // 例如：cnn/CNNA-ST1-xxx/檔案.mp4
            if ('gcs' === $storageType) {
                return ltrim($bestMp4['file'], '/');
            } else {
                // 對於其他儲存類型，使用目錄和檔案名構建路徑
                $mp4Dir = dirname($nasPath);
                return $mp4Dir . '/' . $bestMp4['name'];
            }
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
