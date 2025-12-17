<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sources\CnnFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TestCnnClassifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:cnn-classify
                            {--source= : 來源目錄（預設使用 CnnFetchService 的 source_path）}
                            {--target=storage/app/cnn : 目標目錄}
                            {--dry-run : 僅顯示會處理的檔案，不實際移動}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試 CNN 檔案分類功能：使用 CnnFetchService 的邏輯將檔案從來源目錄移動到目標目錄，並按照唯一識別碼分類';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private CnnFetchService $cnnFetchService
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
        $sourcePath = $this->option('source');
        $targetBasePath = $this->option('target');
        $dryRun = $this->option('dry-run');

        // 如果沒有指定來源目錄，使用 CnnFetchService 的 source_path
        if (null === $sourcePath || '' === $sourcePath) {
            $sourcePath = $this->cnnFetchService->getSourcePath();
        }

        // 如果是相對路徑，轉換為絕對路徑
        if (!str_starts_with($sourcePath, '/')) {
            $sourcePath = base_path($sourcePath);
        }
        if (!str_starts_with($targetBasePath, '/')) {
            $targetBasePath = base_path($targetBasePath);
        }

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：不會實際移動檔案');
        }

        if (!is_dir($sourcePath)) {
            $this->error("❌ 來源目錄不存在: {$sourcePath}");
            return Command::FAILURE;
        }

        $this->info("📁 來源目錄: {$sourcePath}");
        $this->info("📁 目標目錄: {$targetBasePath}");
        $this->info("📁 使用 CnnFetchService 的掃描和分類邏輯");
        $this->newLine();

        // 使用 CnnFetchService 掃描檔案
        $this->info('🔍 使用 CnnFetchService 掃描檔案...');
        
        // 暫時修改 source_path 來掃描指定目錄
        $originalSourcePath = $this->cnnFetchService->getSourcePath();
        $reflection = new \ReflectionClass($this->cnnFetchService);
        $sourcePathProperty = $reflection->getProperty('sourcePath');
        $sourcePathProperty->setAccessible(true);
        $sourcePathProperty->setValue($this->cnnFetchService, $sourcePath);

        try {
            $files = $this->cnnFetchService->scanLocalFilesForTesting();
        } finally {
            // 恢復原始 source_path
            $sourcePathProperty->setValue($this->cnnFetchService, $originalSourcePath);
        }

        if (empty($files)) {
            $this->warn('未找到任何檔案');
            return Command::SUCCESS;
        }

        $this->info("找到 " . count($files) . " 個檔案");
        $this->newLine();

        // 使用 CnnFetchService 的分組邏輯
        $this->info('📦 使用 CnnFetchService 按照唯一識別碼分組...');
        $groupedFiles = $this->cnnFetchService->groupFilesByUniqueIdPublic($files);

        $this->info("找到 " . count($groupedFiles) . " 個唯一識別碼");
        $this->newLine();

        // 顯示分組結果
        $this->displayGroupSummary($groupedFiles);

        if ($dryRun) {
            $this->newLine();
            $this->warn('乾跑模式：未實際移動檔案');
            $this->info('✅ 分類邏輯驗證完成！');
            return Command::SUCCESS;
        }

        // 移動檔案
        $this->newLine();
        $this->info('🚚 開始移動檔案...');
        $result = $this->moveFiles($groupedFiles, $sourcePath, $targetBasePath);

        // 顯示結果
        $this->newLine();
        $this->displayResult($result);

        return Command::SUCCESS;
    }

    /**
     * Display group summary.
     *
     * @param array<string, array<int, array<string, mixed>>> $groupedFiles
     * @return void
     */
    private function displayGroupSummary(array $groupedFiles): void
    {
        $summary = [];
        foreach ($groupedFiles as $uniqueId => $files) {
            $summary[] = [
                '唯一識別碼' => $uniqueId,
                '檔案數量' => count($files),
                '檔案列表' => implode(', ', array_slice(array_column($files, 'name'), 0, 3)) . (count($files) > 3 ? '...' : ''),
            ];
        }

        $this->table(
            ['唯一識別碼', '檔案數量', '檔案列表（前3個）'],
            $summary
        );
    }

    /**
     * Move files to target directory organized by unique ID.
     *
     * @param array<string, array<int, array<string, mixed>>> $groupedFiles
     * @param string $sourcePath
     * @param string $targetBasePath
     * @return array{moved: int, skipped: int, errors: int, details: array}
     */
    private function moveFiles(array $groupedFiles, string $sourcePath, string $targetBasePath): array
    {
        $moved = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        foreach ($groupedFiles as $uniqueId => $files) {
            $targetDir = $targetBasePath . '/' . $uniqueId;

            // 建立目標目錄
            if (!is_dir($targetDir)) {
                if (!File::makeDirectory($targetDir, 0755, true)) {
                    $this->error("無法建立目錄: {$targetDir}");
                    $errors += count($files);
                    continue;
                }
            }

            foreach ($files as $file) {
                $targetPath = $targetDir . '/' . $file['name'];

                // 檢查目標檔案是否已存在
                if (file_exists($targetPath)) {
                    $skipped++;
                    $details[] = [
                        'status' => '跳過',
                        'uniqueId' => $uniqueId,
                        'file' => $file['name'],
                        'reason' => '目標檔案已存在',
                    ];
                    continue;
                }

                // 移動檔案
                try {
                    if (File::move($file['path'], $targetPath)) {
                        $moved++;
                        $details[] = [
                            'status' => '已移動',
                            'uniqueId' => $uniqueId,
                            'file' => $file['name'],
                            'target' => str_replace($targetBasePath . '/', '', $targetPath),
                        ];
                    } else {
                        $errors++;
                        $details[] = [
                            'status' => '失敗',
                            'uniqueId' => $uniqueId,
                            'file' => $file['name'],
                            'reason' => '移動檔案失敗',
                        ];
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $details[] = [
                        'status' => '錯誤',
                        'uniqueId' => $uniqueId,
                        'file' => $file['name'],
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'moved' => $moved,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /**
     * Display move result.
     *
     * @param array{moved: int, skipped: int, errors: int, details: array} $result
     * @return void
     */
    private function displayResult(array $result): void
    {
        $this->info('✅ 檔案移動完成！');
        $this->table(
            ['狀態', '數量'],
            [
                ['已移動', $result['moved']],
                ['已跳過', $result['skipped']],
                ['錯誤', $result['errors']],
                ['總計', $result['moved'] + $result['skipped'] + $result['errors']],
            ]
        );

        if ($result['errors'] > 0) {
            $this->newLine();
            $this->warn('發生錯誤的檔案：');
            $errorDetails = array_filter($result['details'], function ($detail) {
                return in_array($detail['status'], ['失敗', '錯誤']);
            });
            foreach (array_slice($errorDetails, 0, 10) as $detail) {
                $this->line("  - {$detail['file']}: {$detail['reason']}");
            }
            if (count($errorDetails) > 10) {
                $this->line("  ... 還有 " . (count($errorDetails) - 10) . " 個錯誤");
            }
        }

        // 顯示分類結果
        $this->newLine();
        $this->info('📁 分類結果：');
        $groupedByStatus = [];
        foreach ($result['details'] as $detail) {
            if (!isset($groupedByStatus[$detail['uniqueId']])) {
                $groupedByStatus[$detail['uniqueId']] = ['moved' => 0, 'skipped' => 0, 'errors' => 0];
            }
            if ('已移動' === $detail['status']) {
                $groupedByStatus[$detail['uniqueId']]['moved']++;
            } elseif ('跳過' === $detail['status']) {
                $groupedByStatus[$detail['uniqueId']]['skipped']++;
            } else {
                $groupedByStatus[$detail['uniqueId']]['errors']++;
            }
        }

        $summary = [];
        foreach ($groupedByStatus as $uniqueId => $stats) {
            $summary[] = [
                '唯一識別碼' => $uniqueId,
                '已移動' => $stats['moved'],
                '已跳過' => $stats['skipped'],
                '錯誤' => $stats['errors'],
            ];
        }

        $this->table(
            ['唯一識別碼', '已移動', '已跳過', '錯誤'],
            $summary
        );
    }
}
