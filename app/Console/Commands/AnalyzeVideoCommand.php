<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
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
                            {--source=CNN : 來源名稱 (CNN, AP, RT 等)}
                            {--storage=s3 : 儲存空間類型 (nas, s3, storage)}
                            {--path= : 基礎路徑 (可選)}
                            {--limit=50 : 每次處理的影片數量上限}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從指定儲存空間撈取影片並進行分析';

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

        // Scan for video files
        $videoFiles = $this->storageService->scanVideoFiles($storageType, $sourceName, $basePath);

        if (empty($videoFiles)) {
            $this->warn("未找到任何影片檔案");
            return Command::SUCCESS;
        }

        $this->info("找到 " . count($videoFiles) . " 個影片檔案");

        // Filter videos that need analysis
        $videosToAnalyze = [];
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($videoFiles as $videoFile) {
            if ($processedCount >= $limit) {
                break;
            }

            // Check if video already exists and is completed
            $existingVideo = $this->videoRepository->getBySourceId($videoFile['source_name'], $videoFile['source_id']);

            if (null !== $existingVideo) {
                if (AnalysisStatus::COMPLETED === $existingVideo->analysis_status) {
                    $this->line("跳過已完成分析的影片: {$videoFile['source_id']}");
                    $skippedCount++;
                    continue;
                }

                // Update video path if needed
                if ($existingVideo->nas_path !== $videoFile['relative_path']) {
                    $this->videoRepository->update($existingVideo->id, [
                        'nas_path' => $videoFile['relative_path'],
                    ]);
                }

                $videoId = $existingVideo->id;
            } else {
                // Create new video record
                $videoId = $this->videoRepository->findOrCreate([
                    'source_name' => $videoFile['source_name'],
                    'source_id' => $videoFile['source_id'],
                    'nas_path' => $videoFile['relative_path'],
                    'fetched_at' => date('Y-m-d H:i:s', $videoFile['last_modified']),
                ]);
            }

            $videosToAnalyze[] = [
                'video_id' => $videoId,
                'file_path' => $videoFile['file_path'],
                'relative_path' => $videoFile['relative_path'],
                'storage_type' => $storageType,
            ];

            $processedCount++;
        }

        $this->info("準備分析 " . count($videosToAnalyze) . " 個影片");

        // Analyze videos
        $progressBar = $this->output->createProgressBar(count($videosToAnalyze));
        $progressBar->start();

        foreach ($videosToAnalyze as $videoInfo) {
            try {
                // Update status to processing
                $this->videoRepository->updateAnalysisStatus(
                    $videoInfo['video_id'],
                    AnalysisStatus::PROCESSING,
                    new \DateTime()
                );

                // Get video file path
                $videoFilePath = $this->getVideoFilePathForAnalysis(
                    $videoInfo['storage_type'],
                    $videoInfo['file_path']
                );

                if (null === $videoFilePath || !file_exists($videoFilePath)) {
                    throw new \Exception("影片檔案不存在: {$videoInfo['file_path']}");
                }

                // Execute video analysis
                $analysisResult = $this->analyzeService->executeVideoAnalysis(
                    $videoInfo['video_id'],
                    $promptVersion,
                    $videoFilePath
                );

                // Save analysis result
                $this->analyzeService->saveAnalysisResult(
                    $videoInfo['video_id'],
                    $analysisResult,
                    $analysisResult['_prompt_version'] ?? $promptVersion ?? 'v6'
                );

                $this->line("\n✓ 完成分析: {$videoInfo['relative_path']}");
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[AnalyzeVideoCommand] 分析影片失敗', [
                    'video_id' => $videoInfo['video_id'],
                    'file_path' => $videoInfo['file_path'],
                    'error' => $e->getMessage(),
                ]);

                $this->error("\n✗ 分析失敗: {$videoInfo['relative_path']} - {$e->getMessage()}");
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
     * Get video file path for analysis (download from S3 if needed).
     *
     * @param string $storageType
     * @param string $filePath
     * @return string|null
     */
    private function getVideoFilePathForAnalysis(string $storageType, string $filePath): ?string
    {
        // Use StorageService's getVideoFilePath which handles S3 download automatically
        return $this->storageService->getVideoFilePath($storageType, $filePath);
    }
}
