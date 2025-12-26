<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 清理舊日誌檔案命令
 * 
 * 此命令用於清理 Laravel 日誌目錄中的舊日誌檔案，釋放磁碟空間。
 * 支援清理單一日誌檔案和按日期輪轉的日誌檔案。
 */
class CleanupLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:logs
                            {--days=7 : 保留多少天內的日誌（預設 7 天）}
                            {--dry-run : 乾跑模式，只顯示會刪除的檔案，不實際刪除}
                            {--max-size=100 : 單一日誌檔案最大大小（MB），超過此大小會清理（預設 100MB）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理舊的日誌檔案，釋放磁碟空間';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $maxSizeMB = (int) $this->option('max-size');
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：只顯示會刪除的檔案，不實際刪除');
        }

        $this->info("開始清理日誌檔案（保留 {$days} 天內的日誌，單檔最大 {$maxSizeMB}MB）...");

        $logDir = storage_path('logs');
        
        if (!is_dir($logDir)) {
            $this->error("日誌目錄不存在: {$logDir}");
            return Command::FAILURE;
        }

        $deletedCount = 0;
        $deletedSize = 0;
        $cutCount = 0;
        $cutSize = 0;
        $cutFiles = [];

        // 計算截止日期
        $cutoffTime = time() - ($days * 24 * 60 * 60);

        // 掃描日誌目錄
        $files = glob($logDir . '/*.log*');
        
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $fileName = basename($file);
            $fileSize = filesize($file);
            $fileMtime = filemtime($file);
            $fileAge = time() - $fileMtime;
            $fileAgeDays = round($fileAge / (24 * 60 * 60), 1);

            // 情況 1: 檔案過舊，直接刪除
            if ($fileMtime < $cutoffTime) {
                if ($dryRun) {
                    $this->line("  [將刪除] {$fileName} ({$fileAgeDays} 天前, " . $this->formatBytes($fileSize) . ")");
                } else {
                    if (@unlink($file)) {
                        $deletedCount++;
                        $deletedSize += $fileSize;
                        $this->line("  ✓ 已刪除: {$fileName} ({$fileAgeDays} 天前, " . $this->formatBytes($fileSize) . ")");
                    } else {
                        $this->warn("  ✗ 無法刪除: {$fileName}");
                    }
                }
                continue;
            }

            // 情況 2: 檔案過大，截斷（只處理單一日誌檔案，不處理按日期輪轉的）
            // 按日期輪轉的檔案格式：laravel-2024-01-01.log
            if ($fileSize > $maxSizeBytes && !preg_match('/laravel-\d{4}-\d{2}-\d{2}\.log$/', $fileName)) {
                if ($dryRun) {
                    $this->line("  [將截斷] {$fileName} (" . $this->formatBytes($fileSize) . " > {$maxSizeMB}MB)");
                } else {
                    // 截斷檔案：保留最後 10MB（使用流式處理避免內存問題）
                    $keepSize = 10 * 1024 * 1024; // 10MB
                    if ($fileSize > $keepSize) {
                        if ($this->truncateFileFromEnd($file, $keepSize)) {
                            $cutCount++;
                            $cutSize += ($fileSize - $keepSize);
                            $cutFiles[] = $fileName;
                            $this->line("  ✓ 已截斷: {$fileName} (" . $this->formatBytes($fileSize) . " → " . $this->formatBytes($keepSize) . ")");
                        } else {
                            $this->warn("  ✗ 無法截斷: {$fileName}");
                        }
                    }
                }
            }
        }

        // 顯示統計
        $this->newLine();
        $this->info('✅ 清理完成！');
        
        if ($dryRun) {
            $this->warn('⚠️  這是乾跑模式，檔案未實際刪除或截斷。');
        } else {
            $this->table(
                ['操作', '數量', '釋放空間'],
                [
                    ['刪除舊檔案', $deletedCount, $this->formatBytes($deletedSize)],
                    ['截斷大檔案', $cutCount, $this->formatBytes($cutSize)],
                    ['總計', $deletedCount + $cutCount, $this->formatBytes($deletedSize + $cutSize)],
                ]
            );

            if ($deletedCount > 0 || $cutCount > 0) {
                Log::info('[CleanupLogsCommand] 日誌清理完成', [
                    'deleted_count' => $deletedCount,
                    'deleted_size_mb' => round($deletedSize / 1024 / 1024, 2),
                    'cut_count' => $cutCount,
                    'cut_size_mb' => round($cutSize / 1024 / 1024, 2),
                    'retention_days' => $days,
                    'max_size_mb' => $maxSizeMB,
                ]);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 從檔案末尾截斷檔案（使用流式處理避免內存問題）
     * 
     * @param string $filePath 檔案路徑
     * @param int $keepSize 保留的位元組數（從檔案末尾開始）
     * @return bool 是否成功
     */
    private function truncateFileFromEnd(string $filePath, int $keepSize): bool
    {
        $handle = @fopen($filePath, 'r+b');
        if (false === $handle) {
            return false;
        }

        try {
            // 獲取檔案大小
            fseek($handle, 0, SEEK_END);
            $fileSize = ftell($handle);

            if ($fileSize <= $keepSize) {
                fclose($handle);
                return true; // 不需要截斷
            }

            // 計算需要保留的起始位置
            $startPos = $fileSize - $keepSize;

            // 讀取需要保留的內容（分塊讀取，避免內存問題）
            fseek($handle, $startPos, SEEK_SET);
            $chunkSize = 8192; // 8KB chunks
            $content = '';
            
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if (false === $chunk) {
                    break;
                }
                $content .= $chunk;
            }
            fclose($handle);

            // 寫回檔案
            $writeHandle = @fopen($filePath, 'wb');
            if (false === $writeHandle) {
                return false;
            }

            fwrite($writeHandle, $content);
            fclose($writeHandle);

            return true;
        } catch (\Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        }
    }

    /**
     * 格式化位元組大小為可讀格式
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

