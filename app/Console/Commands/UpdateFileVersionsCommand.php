<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\VideoRepository;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 一次性命令：更新非 completed 記錄的 XML/MP4 版本狀態
 * 
 * 此命令會：
 * 1. 查找所有 analysis_status != 'completed' 的記錄
 * 2. 檢查 GCS 中對應的文件是否存在
 * 3. 根據實際存在的文件更新 xml_file_version 和 mp4_file_version
 * 4. 如果文件不存在，設置為 null
 */
class UpdateFileVersionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:file-versions
                            {--source= : 指定來源（CNN, AP, RT），未指定則處理所有來源}
                            {--limit= : 處理記錄數量上限（可選）}
                            {--dry-run : 乾跑模式，只顯示會更新的記錄，不實際更新}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新非 completed 記錄的 XML/MP4 版本狀態（一次性命令）';

    /**
     * Create a new command instance.
     *
     * @param VideoRepository $videoRepository
     * @param StorageService $storageService
     */
    public function __construct(
        private VideoRepository $videoRepository,
        private StorageService $storageService
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
        $sourceName = $this->option('source');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：只顯示會更新的記錄，不實際更新');
        }

        $this->info('開始檢查並更新文件版本狀態...');
        $this->info('查找條件：analysis_status != "completed"');

        if (null !== $sourceName) {
            $this->info("來源篩選：{$sourceName}");
        }

        if (null !== $limit) {
            $this->info("處理上限：{$limit} 筆記錄");
        }

        // 獲取待處理的記錄
        $query = \App\Models\Video::where('analysis_status', '!=', AnalysisStatus::COMPLETED->value);

        if (null !== $sourceName && '' !== $sourceName) {
            $query->where('source_name', strtoupper($sourceName));
        }

        if (null !== $limit) {
            $query->limit($limit);
        }

        $videos = $query->get();

        if ($videos->isEmpty()) {
            $this->info('未找到需要處理的記錄');
            return Command::SUCCESS;
        }

        $this->info("找到 {$videos->count()} 筆待處理記錄");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($videos->count());
        $progressBar->start();

        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $gcsDisk = Storage::disk('gcs');

        foreach ($videos as $video) {
            try {
                $sourceId = $video->source_id;
                $sourceName = $video->source_name;
                
                // 構建 GCS 路徑（根據來源名稱）
                $gcsBasePath = strtolower($sourceName) . '/' . $sourceId;
                
                // 檢查目錄是否存在
                if (!$gcsDisk->exists($gcsBasePath)) {
                    // 目錄不存在，將版本設置為 null
                    $updateData = $this->buildUpdateData(null, null, $video);
                    
                    if (!empty($updateData)) {
                        if (!$dryRun) {
                            $this->videoRepository->update($video->id, $updateData);
                        }
                        $updatedCount++;
                        Log::info('[UpdateFileVersionsCommand] 已更新（目錄不存在）', [
                            'video_id' => $video->id,
                            'source_id' => $sourceId,
                            'update_data' => $updateData,
                        ]);
                    } else {
                        $skippedCount++;
                    }
                    $progressBar->advance();
                    continue;
                }

                // 掃描該資料夾中的 XML 和 MP4 檔案
                $files = $gcsDisk->files($gcsBasePath);
                
                // 如果直接子目錄沒有文件，嘗試遞歸查找
                if (empty($files)) {
                    try {
                        $allFiles = $gcsDisk->allFiles($gcsBasePath);
                        $files = $allFiles;
                    } catch (\Exception $e) {
                        // allFiles 可能不支持，使用 files
                        Log::debug('[UpdateFileVersionsCommand] allFiles 不可用，使用 files', [
                            'source_id' => $sourceId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // 查找 XML 和 MP4 文件
                $xmlFile = null;
                $mp4File = null;
                $xmlVersion = null;
                $mp4Version = null;

                foreach ($files as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if ('xml' === $extension) {
                        $xmlFile = $file;
                        $fileName = basename($file);
                        $xmlVersion = $this->storageService->extractFileVersion($fileName);
                    } elseif ('mp4' === $extension) {
                        if (null === $mp4File) {
                            $mp4File = $file;
                            $fileName = basename($file);
                            $mp4Version = $this->storageService->extractFileVersion($fileName);
                        } else {
                            // 如果有多個 MP4，選擇版本較新的
                            $fileName = basename($file);
                            $fileVersion = $this->storageService->extractFileVersion($fileName);
                            if (null !== $fileVersion && (null === $mp4Version || $fileVersion > $mp4Version)) {
                                $mp4File = $file;
                                $mp4Version = $fileVersion;
                            }
                        }
                    }
                }

                // 構建更新數據
                $updateData = $this->buildUpdateData($xmlVersion, $mp4Version, $video);

                if (!empty($updateData)) {
                    if (!$dryRun) {
                        $this->videoRepository->update($video->id, $updateData);
                    }
                    $updatedCount++;
                    
                    Log::info('[UpdateFileVersionsCommand] 已更新', [
                        'video_id' => $video->id,
                        'source_id' => $sourceId,
                        'xml_version' => ['old' => $video->xml_file_version, 'new' => $xmlVersion],
                        'mp4_version' => ['old' => $video->mp4_file_version, 'new' => $mp4Version],
                        'update_data' => $updateData,
                    ]);
                } else {
                    $skippedCount++;
                }

            } catch (\Exception $e) {
                $errorCount++;
                Log::error('[UpdateFileVersionsCommand] 處理失敗', [
                    'video_id' => $video->id ?? null,
                    'source_id' => $video->source_id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // 顯示結果
        $this->info('處理完成！');
        $this->table(
            ['狀態', '數量'],
            [
                ['已檢查', $videos->count()],
                ['已更新', $updatedCount],
                ['已跳過', $skippedCount],
                ['錯誤', $errorCount],
            ]
        );

        if ($dryRun) {
            $this->warn('⚠️  這是乾跑模式，未實際更新資料庫');
        } else {
            $this->info("✓ 已更新 {$updatedCount} 筆記錄的文件版本狀態");
        }

        return Command::SUCCESS;
    }

    /**
     * 構建更新數據
     * 
     * @param int|null $xmlVersion
     * @param int|null $mp4Version
     * @param \App\Models\Video $video
     * @return array<string, mixed>
     */
    private function buildUpdateData(?int $xmlVersion, ?int $mp4Version, \App\Models\Video $video): array
    {
        $updateData = [];
        $hasChange = false;

        // 更新 XML 版本
        $currentXmlVersion = $video->xml_file_version;
        if ($xmlVersion !== $currentXmlVersion) {
            $updateData['xml_file_version'] = $xmlVersion;
            $hasChange = true;
        }

        // 更新 MP4 版本
        $currentMp4Version = $video->mp4_file_version;
        if ($mp4Version !== $currentMp4Version) {
            $updateData['mp4_file_version'] = $mp4Version;
            $hasChange = true;
        }

        return $hasChange ? $updateData : [];
    }
}

