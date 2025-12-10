<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sources\CnnFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * CNN 資源獲取命令
 *
 * 流程：
 * 1. 掃描配置的來源目錄（config('sources.cnn.source_path')）中的檔案
 * 2. 根據描述標籤和唯一識別碼整理檔案
 * 3. 將整理後的檔案上傳到 GCS 指定路徑
 * 4. 根據 --keep-local 選項決定是否刪除本地檔案（預設會刪除）
 */
class FetchCnnCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:cnn
                            {--batch-size=50 : 每批處理的檔案數量（預設 50）}
                            {--dry-run : 乾跑模式，僅顯示會處理的檔案，不實際上傳}
                            {--keep-local : 保留本地檔案，上傳到 GCS 後不刪除}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '掃描配置的來源目錄，整理檔案後上傳到 GCS';

    /**
     * Create a new command instance.
     *
     * @param CnnFetchService $cnnFetchService
     */
    public function __construct(
        private CnnFetchService $cnnFetchService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * 執行流程：
     * 1. 掃描配置的來源目錄
     * 2. 根據描述標籤分組檔案
     * 3. 將檔案移動到 GCS（按唯一識別碼組織）
     * 4. 根據選項決定是否刪除本地檔案
     * 5. 返回 GCS 中的資源列表
     *
     * @return int
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        $keepLocal = $this->option('keep-local');
        $sourcePath = Config::get('sources.cnn.source_path', '/mnt/PushDownloads');

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：不會實際上傳檔案到 GCS，也不會刪除本地檔案');
        }

        if ($keepLocal && !$dryRun) {
            $this->info('ℹ️  保留本地檔案模式：上傳到 GCS 後不會刪除本地檔案');
        }

        $this->info('開始處理 CNN 資源...');
        if ($dryRun) {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 模擬上傳到 GCS（不實際執行）");
        } else {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 上傳到 GCS" . ($keepLocal ? ' → 保留本地檔案' : ' → 刪除本地檔案'));
        }

        try {
            // 執行完整流程：掃描本地 → 整理 → 上傳到 GCS → 返回資源列表
            $this->info("開始處理（批次大小: {$batchSize}）...");
            $resources = $this->cnnFetchService->fetchResourceListWithProgress(
                $batchSize,
                $dryRun,
                $keepLocal,
                function ($current, $total, $message) {
                    if (null !== $total && $total > 0) {
                        $percentage = round(($current / $total) * 100, 1);
                        $this->line("進度: {$current}/{$total} ({$percentage}%) - {$message}");
                    } else {
                        $this->line("處理中: {$current} - {$message}");
                    }
                }
            );

            if (empty($resources)) {
                $this->warn('未找到任何 CNN 資源');
                return Command::SUCCESS;
            }

            // 統計資源類型
            $xmlCount = 0;
            $videoCount = 0;

            foreach ($resources as $resource) {
                if ('xml' === $resource['type']) {
                    $xmlCount++;
                } elseif ('video' === $resource['type']) {
                    $videoCount++;
                }
            }

            // 顯示處理結果
            $this->newLine();
            $this->info('✅ CNN 資源處理完成！');
            $this->table(
                ['類型', '數量'],
                [
                    ['XML', $xmlCount],
                    ['Video', $videoCount],
                    ['總計', count($resources)],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            Log::error('[FetchCnnCommand] 處理失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('❌ 處理失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

