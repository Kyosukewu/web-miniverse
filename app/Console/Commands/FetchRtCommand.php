<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sources\RtFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchRtCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:rt 
                            {--limit=50 : 每次處理的資源數量上限}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '從 RT API 取得資源並儲存到 S3';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private RtFetchService $rtFetchService
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
        $this->info('開始從 RT API 取得資源...');

        // Fetch resource list
        $resources = $this->rtFetchService->fetchResourceList();

        if (empty($resources)) {
            $this->warn('未找到任何 RT 資源');
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $resources = array_slice($resources, 0, $limit);

        $this->info('準備下載 ' . count($resources) . ' 個資源');

        // Download and save resources
        $progressBar = $this->output->createProgressBar(count($resources));
        $progressBar->start();

        $resourceIds = array_column($resources, 'source_id');
        $downloadedResources = $this->rtFetchService->downloadResources($resourceIds);

        if (empty($downloadedResources)) {
            $this->warn('未成功下載任何資源');
            $progressBar->finish();
            return Command::SUCCESS;
        }

        // Save to S3
        $saved = $this->rtFetchService->saveToStorage($downloadedResources, 's3');

        $progressBar->finish();
        $this->newLine(2);

        if ($saved) {
            $this->info('✓ RT 資源下載並儲存完成');
            $this->info('已處理 ' . count($downloadedResources) . ' 個資源');
        } else {
            $this->error('✗ RT 資源儲存失敗');
        }

        return Command::SUCCESS;
    }
}

