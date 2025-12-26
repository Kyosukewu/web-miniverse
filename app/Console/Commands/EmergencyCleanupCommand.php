<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 緊急清理命令 - 在磁碟空間不足時使用
 * 
 * 此命令會立即清理所有臨時檔案和舊日誌，釋放磁碟空間。
 * 用於緊急情況，不建議在正常運行時使用。
 */
class EmergencyCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:emergency
                            {--force : 強制執行，不詢問確認}
                            {--keep-hours=0 : 保留多少小時內的臨時檔案（預設 0，即全部刪除）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '緊急清理：立即清理所有臨時檔案和舊日誌，釋放磁碟空間';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $force = $this->option('force');
        $keepHours = (int) $this->option('keep-hours');

        if (!$force) {
            $this->warn('⚠️  警告：此命令將立即清理所有臨時檔案和舊日誌！');
            if (!$this->confirm('確定要繼續嗎？', false)) {
                $this->info('已取消。');
                return Command::SUCCESS;
            }
        }

        $this->info('🚨 開始緊急清理...');
        $this->newLine();

        $totalFreed = 0;

        // 1. 清理臨時檔案
        $tempFreed = $this->cleanupTempFiles($keepHours);
        $totalFreed += $tempFreed;

        // 2. 清理日誌檔案（保留最近 1 天）
        $logFreed = $this->cleanupLogFiles(1);
        $totalFreed += $logFreed;

        // 3. 清理 MySQL 臨時檔案（如果可能）
        $this->cleanupMysqlTempFiles();

        // 顯示結果
        $this->newLine();
        $this->info('✅ 緊急清理完成！');
        $this->info('釋放空間: ' . $this->formatBytes($totalFreed));

        // 檢查磁碟空間
        $this->checkDiskSpace();

        return Command::SUCCESS;
    }

    /**
     * 清理臨時檔案
     */
    private function cleanupTempFiles(int $keepHours): int
    {
        $this->info('📁 清理臨時檔案...');
        $tempDir = storage_path('app/temp');
        
        if (!is_dir($tempDir)) {
            $this->warn("  臨時目錄不存在: {$tempDir}");
            return 0;
        }

        $deletedCount = 0;
        $deletedSize = 0;
        $cutoffTime = time() - ($keepHours * 3600);

        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $fileMtime = filemtime($file);
            if ($fileMtime < $cutoffTime) {
                $size = filesize($file);
                if (@unlink($file)) {
                    $deletedCount++;
                    $deletedSize += $size;
                }
            }
        }

        if ($deletedCount > 0) {
            $this->line("  ✓ 已刪除 {$deletedCount} 個檔案，釋放 " . $this->formatBytes($deletedSize));
        } else {
            $this->line("  ℹ️  沒有需要清理的檔案");
        }

        return $deletedSize;
    }

    /**
     * 清理日誌檔案
     */
    private function cleanupLogFiles(int $keepDays): int
    {
        $this->info('📝 清理日誌檔案...');
        $logDir = storage_path('logs');
        
        if (!is_dir($logDir)) {
            $this->warn("  日誌目錄不存在: {$logDir}");
            return 0;
        }

        $deletedCount = 0;
        $deletedSize = 0;
        $cutoffTime = time() - ($keepDays * 24 * 3600);

        $files = glob($logDir . '/*.log*');
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $fileMtime = filemtime($file);
            $fileSize = filesize($file);

            // 刪除舊檔案
            if ($fileMtime < $cutoffTime) {
                if (@unlink($file)) {
                    $deletedCount++;
                    $deletedSize += $fileSize;
                }
            } 
            // 截斷大檔案（保留最後 5MB）
            elseif ($fileSize > 50 * 1024 * 1024) { // 50MB
                $keepSize = 5 * 1024 * 1024; // 5MB
                if ($this->truncateFileFromEnd($file, $keepSize)) {
                    $deletedSize += ($fileSize - $keepSize);
                }
            }
        }

        if ($deletedCount > 0 || $deletedSize > 0) {
            $this->line("  ✓ 已清理日誌檔案，釋放 " . $this->formatBytes($deletedSize));
        } else {
            $this->line("  ℹ️  沒有需要清理的日誌檔案");
        }

        return $deletedSize;
    }

    /**
     * 清理 MySQL 臨時檔案（如果可能）
     */
    private function cleanupMysqlTempFiles(): void
    {
        $this->info('🗄️  檢查 MySQL 臨時檔案...');
        
        // MySQL 臨時檔案通常在 /tmp 目錄
        $tmpDirs = ['/tmp', sys_get_temp_dir()];
        
        foreach ($tmpDirs as $tmpDir) {
            if (!is_dir($tmpDir)) {
                continue;
            }

            // 查找 MySQL 臨時檔案（通常以 MY 開頭）
            $files = glob($tmpDir . '/MY*');
            $deletedCount = 0;
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    // 只刪除超過 1 小時的臨時檔案
                    $fileAge = time() - filemtime($file);
                    if ($fileAge > 3600) {
                        if (@unlink($file)) {
                            $deletedCount++;
                        }
                    }
                }
            }

            if ($deletedCount > 0) {
                $this->line("  ✓ 已清理 {$deletedCount} 個 MySQL 臨時檔案");
            }
        }
    }

    /**
     * 檢查磁碟空間
     */
    private function checkDiskSpace(): void
    {
        $this->newLine();
        $this->info('💾 磁碟空間檢查：');
        
        $basePath = storage_path();
        $freeSpace = disk_free_space($basePath);
        $totalSpace = disk_total_space($basePath);
        
        if ($freeSpace !== false && $totalSpace !== false) {
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = round(($usedSpace / $totalSpace) * 100, 1);
            
            $this->line("  總空間: " . $this->formatBytes($totalSpace));
            $this->line("  已使用: " . $this->formatBytes($usedSpace) . " ({$usagePercent}%)");
            $this->line("  可用空間: " . $this->formatBytes($freeSpace));
            
            if ($usagePercent > 90) {
                $this->error("  ⚠️  警告：磁碟使用率超過 90%！");
            } elseif ($usagePercent > 80) {
                $this->warn("  ⚠️  注意：磁碟使用率超過 80%");
            } else {
                $this->info("  ✓ 磁碟空間充足");
            }
        }
    }

    /**
     * 從檔案末尾截斷檔案
     */
    private function truncateFileFromEnd(string $filePath, int $keepSize): bool
    {
        $handle = @fopen($filePath, 'r+b');
        if (false === $handle) {
            return false;
        }

        try {
            fseek($handle, 0, SEEK_END);
            $fileSize = ftell($handle);

            if ($fileSize <= $keepSize) {
                fclose($handle);
                return true;
            }

            $startPos = $fileSize - $keepSize;
            fseek($handle, $startPos, SEEK_SET);
            $chunkSize = 8192;
            $content = '';
            
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if (false === $chunk) {
                    break;
                }
                $content .= $chunk;
            }
            fclose($handle);

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
     * 格式化位元組大小
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

