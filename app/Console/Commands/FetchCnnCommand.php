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
 * 2. 根據選擇的分類方式整理檔案：
 *    - label：依描述標籤分類，使用第一個遇到的唯一ID作為資料夾名稱（預設）
 *    - unique-id：直接依唯一ID分類
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
                            {--limit= : 總共處理的檔案數量上限（可選，未設定則處理所有檔案）}
                            {--dry-run : 乾跑模式，僅顯示會處理的檔案，不實際上傳}
                            {--keep-local : 保留本地檔案，上傳到 GCS 後不刪除}
                            {--group-by=label : 分類方式：label（依描述標籤分類，使用第一個遇到的唯一ID作為資料夾名稱）或 unique-id（直接依唯一ID分類）}
                            {--file-type= : 指定要處理的檔案類型：mp4、xml 或 all（預設 all，處理所有類型）}';

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
     * 2. 根據選擇的分類方式分組檔案（label 或 unique-id）
     * 3. 將檔案移動到 GCS（按唯一識別碼組織）
     * 4. 根據選項決定是否刪除本地檔案
     * 5. 返回 GCS 中的資源列表
     *
     * @return int
     */
    public function handle(): int
    {
        // 增加記憶體限制（處理大量檔案時需要）
        ini_set('memory_limit', '2048M');
        
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        $keepLocal = $this->option('keep-local');
        $groupBy = $this->option('group-by');
        $fileType = $this->option('file-type') ?? 'all';
        $sourcePath = Config::get('sources.cnn.source_path', '/mnt/PushDownloads');

        // 驗證分類方式選項
        if (!in_array($groupBy, ['label', 'unique-id'], true)) {
            $this->error("❌ 無效的分類方式：{$groupBy}。請使用 'label' 或 'unique-id'");
            return Command::FAILURE;
        }

        // 驗證檔案類型選項
        if (!in_array($fileType, ['mp4', 'xml', 'all'], true)) {
            $this->error("❌ 無效的檔案類型：{$fileType}。請使用 'mp4'、'xml' 或 'all'");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：不會實際上傳檔案到 GCS，也不會刪除本地檔案');
        }

        if ($keepLocal && !$dryRun) {
            $this->info('ℹ️  保留本地檔案模式：上傳到 GCS 後不會刪除本地檔案');
        }

        $groupByText = 'label' === $groupBy ? '依描述標籤分類（使用第一個遇到的唯一ID作為資料夾名稱）' : '依唯一ID分類';
        $this->info("📁 分類方式：{$groupByText}");

        // 顯示檔案類型過濾資訊
        if ('all' !== $fileType) {
            $fileTypeText = 'mp4' === $fileType ? 'MP4 影片檔' : 'XML 文件檔';
            $this->info("📄 檔案類型：僅處理 {$fileTypeText}");
        }

        $this->info('開始處理 CNN 資源...');
        if ($dryRun) {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 模擬上傳到 GCS（不實際執行）");
        } else {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 上傳到 GCS" . ($keepLocal ? ' → 保留本地檔案' : ' → 刪除本地檔案'));
        }

        try {
            // 執行完整流程：掃描本地 → 整理 → 上傳到 GCS → 返回資源列表
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            
            if (null !== $limit) {
                $this->info("開始處理（批次大小: {$batchSize}，總處理上限: {$limit}）...");
            } else {
                $this->info("開始處理（批次大小: {$batchSize}）...");
            }
            
            $resources = $this->cnnFetchService->fetchResourceListWithProgress(
                $batchSize,
                $dryRun,
                $keepLocal,
                $groupBy,
                $limit,
                function ($current, $total, $message) {
                    if (null !== $total && $total > 0) {
                        $percentage = round(($current / $total) * 100, 1);
                        $this->line("進度: {$current}/{$total} ({$percentage}%) - {$message}");
                    } else {
                        $this->line("處理中: {$current} - {$message}");
                    }
                },
                $fileType
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

