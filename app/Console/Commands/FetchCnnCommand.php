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
                            {--sync-windows : 從 Windows Server 同步資源到 S3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從 CNN 來源取得資源（掃描 S3 或從 Windows Server 同步）';

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
        $this->info('開始掃描 CNN 資源...');

        // Sync from Windows Server if requested
        if ($this->option('sync-windows')) {
            $this->info('從 Windows Server 同步資源到 S3...');
            $synced = $this->cnnFetchService->syncFromWindowsServer();

            if ($synced) {
                $this->info('✓ Windows Server 同步完成');
            } else {
                $this->warn('⚠ Windows Server 同步未執行或失敗');
            }
        }

        // Scan for resources in storage
        $resources = $this->cnnFetchService->fetchResourceList();

        if (empty($resources)) {
            $this->warn('未找到任何 CNN 資源');
            return Command::SUCCESS;
        }

        $this->info('找到 ' . count($resources) . ' 個 CNN 資源');

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

        $this->table(
            ['類型', '數量'],
            [
                ['XML', $xmlCount],
                ['Video', $videoCount],
                ['總計', count($resources)],
            ]
        );

        $this->info('CNN 資源掃描完成！');

        return Command::SUCCESS;
    }
}

