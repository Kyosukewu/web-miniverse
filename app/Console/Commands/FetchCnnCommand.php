<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sources\CnnFetchService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchCnnCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:cnn
                            {--batch-size=50 : 每批處理的檔案數量}
                            {--skip-sync : 跳過同步，僅掃描 GCS 中的資源}
                            {--dry-run : 僅顯示會處理的檔案，不實際移動}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從 CNN 來源取得資源（從本地目錄同步到 GCS 或從 GCS 掃描）';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private CnnFetchService $cnnFetchService,
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
        $batchSize = (int) $this->option('batch-size');
        $skipSync = $this->option('skip-sync');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：不會實際移動檔案');
        }

        $this->info('開始處理 CNN 資源...');

        try {
            if ($skipSync) {
                // 僅掃描 GCS，不進行同步
                $this->info('跳過同步，僅掃描 GCS 中的資源...');
                $resources = $this->cnnFetchService->fetchResourceListFromGcsOnly();
            } else {
                // 執行完整同步流程（掃描本地 → 移動到 GCS → 掃描 GCS）
                $this->info("開始同步流程（批次大小: {$batchSize}）...");
                $resources = $this->cnnFetchService->fetchResourceListWithProgress(
                    $batchSize,
                    $dryRun,
                    function ($current, $total, $message) {
                        if (null !== $total && $total > 0) {
                            $percentage = round(($current / $total) * 100, 1);
                            $this->line("進度: {$current}/{$total} ({$percentage}%) - {$message}");
                        } else {
                            $this->line("處理中: {$current} - {$message}");
                        }
                    }
                );
            }

        if (empty($resources)) {
            $this->warn('未找到任何 CNN 資源');
            return Command::SUCCESS;
        }

        // Display summary
        $xmlCount = 0;
        $videoCount = 0;

        foreach ($resources as $resource) {
            if ('xml' === $resource['type']) {
                $xmlCount++;
            } elseif ('video' === $resource['type']) {
                $videoCount++;
            }
        }

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

