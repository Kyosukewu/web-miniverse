<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Services\FetchServiceInterface;
use App\Services\StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CnnFetchService implements FetchServiceInterface
{
    private StorageService $storageService;
    private array $config;
    private string $sourcePath;

    /**
     * Create a new CNN fetch service instance.
     *
     * @param StorageService $storageService
     * @param array $config
     */
    public function __construct(StorageService $storageService, array $config)
    {
        $this->storageService = $storageService;
        $this->config = $config;
        $this->sourcePath = $this->config['source_path'] ?? '/mnt/PushDownloads';
    }

    /**
     * Fetch resources from /mnt/PushDownloads and move to GCS.
     * Files are organized by unique identifier (CNNA-ST1-xxxxxxxxxxxxxxxx).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceList(): array
    {
        return $this->fetchResourceListWithProgress(50, false, false, 'label', null);
    }

    /**
     * Fetch resources with progress callback and batch processing.
     *
     * @param int $batchSize Batch size for processing files
     * @param bool $dryRun If true, only simulate without actually moving files
     * @param bool $keepLocal If true, keep local files after uploading to GCS
     * @param string $groupBy Grouping method: 'label' (by description label, use first unique ID) or 'unique-id' (by unique ID directly)
     * @param int|null $limit Maximum total number of files to process (null = process all)
     * @param callable|null $progressCallback Callback function(current, total, message)
     * @param string $fileType File type filter: 'mp4', 'xml', or 'all' (default: 'all')
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceListWithProgress(
        int $batchSize = 50,
        bool $dryRun = false,
        bool $keepLocal = false,
        string $groupBy = 'label',
        ?int $limit = null,
        ?callable $progressCallback = null,
        string $fileType = 'all'
    ): array {
        $gcsPath = $this->config['gcs_path'] ?? 'cnn/';
        $sourceName = 'CNN';

        Log::info('[CnnFetchService] 開始從本地目錄抓取檔案並移動到 GCS', [
            'source_path' => $this->sourcePath,
            'gcs_path' => $gcsPath,
            'batch_size' => $batchSize,
            'limit' => $limit,
            'dry_run' => $dryRun,
            'keep_local' => $keepLocal,
            'file_type' => $fileType,
        ]);

        // Step 1: First pass - count total files (for progress display)
        if (null !== $progressCallback) {
            $progressCallback(0, null, '計算檔案總數...');
        }

        $totalFiles = 0;
        foreach ($this->scanLocalFilesGenerator() as $file) {
            // 檔案類型過濾（計數時也要過濾）
            if ('all' !== $fileType) {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($fileType !== $extension) {
                    continue;
                }
            }
            $totalFiles++;
        }

        if (null !== $progressCallback) {
            $fileTypeText = 'all' === $fileType ? '' : " ({$fileType} 檔案)";
            $progressCallback(0, $totalFiles, "找到 {$totalFiles} 個檔案{$fileTypeText}，開始處理...");
        }

        // Step 2: Process files in batches
        $checkedCount = 0;    // 已檢查的檔案數（包含跳過、移動、錯誤）
        $movedCount = 0;      // 成功移動的數量（用於 limit 檢查）
        $localFiles = [];
        $skippedCount = 0;
        $errorCount = 0;
        $errorDetails = [];   // 收集所有錯誤詳情

        foreach ($this->scanLocalFilesGenerator() as $file) {
            // 檔案類型過濾
            if ('all' !== $fileType) {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($fileType !== $extension) {
                    continue; // 跳過不符合類型的檔案
                }
            }

            // 如果設定了 limit，檢查是否已達到上限（只計算成功移動的檔案）
            if (null !== $limit && $movedCount >= $limit) {
                if (null !== $progressCallback) {
                    $progressCallback($movedCount, $limit, "已達到處理上限 ({$limit} 個檔案已移動)，停止處理");
                }
                Log::info('[CnnFetchService] 已達到處理上限，停止處理', [
                    'limit' => $limit,
                    'moved_count' => $movedCount,
                    'checked_count' => $checkedCount,
                ]);
                break;
            }

            $localFiles[] = $file;

            // Process in batches to avoid memory issues
            if (count($localFiles) >= $batchSize) {
                $result = $this->processBatch(
                    $localFiles,
                    $gcsPath,
                    $dryRun,
                    $keepLocal,
                    $groupBy,
                    $progressCallback,
                    $movedCount,
                    $limit ?? $totalFiles,
                    $checkedCount,
                    $limit
                );
                $checkedCount += count($localFiles);
                $movedCount += $result['moved'];
                $skippedCount += $result['skipped'];
                $errorCount += $result['errors'];
                
                // 收集錯誤詳情
                if (!empty($result['error_details'])) {
                    $errorDetails = array_merge($errorDetails, $result['error_details']);
                }
                
                $localFiles = []; // Clear batch to free memory

                // Show batch progress（基於成功移動的數量）
                $maxFiles = $limit ?? $totalFiles;
                if (null !== $progressCallback && $maxFiles > 0) {
                    $percentage = round(($movedCount / $maxFiles) * 100, 1);
                    $progressCallback(
                        $movedCount, 
                        $maxFiles, 
                        "已檢查 {$checkedCount} 個 | 已移動 {$movedCount}/{$maxFiles} ({$percentage}%)"
                    );
                }

                // 檢查批次是否因達到 limit 而停止
                if ($result['should_stop'] ?? false) {
                    break;
                }
                
                // 再次檢查是否已達到 limit（只計算成功移動的檔案）
                if (null !== $limit && $movedCount >= $limit) {
                    break;
                }
            }
        }

        // Process remaining files
        // 只有在未達到 limit 時才處理剩餘檔案
        if (!empty($localFiles) && (null === $limit || $movedCount < $limit)) {
            $result = $this->processBatch(
                $localFiles,
                $gcsPath,
                $dryRun,
                $keepLocal,
                $groupBy,
                $progressCallback,
                $movedCount,
                $limit ?? $totalFiles,
                $checkedCount,
                $limit
            );
            $checkedCount += count($localFiles);
            $movedCount += $result['moved'];
            $skippedCount += $result['skipped'];
            $errorCount += $result['errors'];
            
            // 收集錯誤詳情
            if (!empty($result['error_details'])) {
                $errorDetails = array_merge($errorDetails, $result['error_details']);
            }
        }

        if (null !== $progressCallback) {
            $progressCallback(
                $movedCount, 
                $totalFiles, 
                "本地檔案處理完成 (已檢查: {$checkedCount}, 移動: {$movedCount}, 跳過: {$skippedCount}, 錯誤: {$errorCount})"
            );
        }

        Log::info('[CnnFetchService] 檔案處理完成', [
            'total_files' => $totalFiles,
            'moved_count' => $movedCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
        ]);

        // 逐筆記錄所有錯誤詳情
        if (!empty($errorDetails)) {
            Log::error('[CnnFetchService] 檔案處理錯誤詳情', [
                'total_errors' => count($errorDetails),
                'summary' => '以下為所有錯誤的詳細資訊',
            ]);

            foreach ($errorDetails as $index => $error) {
                Log::error('[CnnFetchService] 錯誤 #' . ($index + 1), [
                    'file_name' => $error['file_name'],
                    'file_path' => $error['file_path'],
                    'unique_id' => $error['unique_id'] ?? 'N/A',
                    'error_type' => $error['error_type'],
                    'error_message' => $error['error_message'],
                    'timestamp' => $error['timestamp'],
                    'file_size' => $error['file_size'] ?? 'N/A',
                    'gcs_target_path' => $error['gcs_target_path'] ?? 'N/A',
                ]);
            }

            // 額外統計錯誤類型
            $errorTypes = array_count_values(array_column($errorDetails, 'error_type'));
            Log::warning('[CnnFetchService] 錯誤類型統計', [
                'error_types' => $errorTypes,
            ]);
        }

        // Step 3: Return resource list from GCS
        if (null !== $progressCallback) {
            $progressCallback(0, null, '掃描 GCS 資源...');
        }

        $resources = $this->fetchResourceListFromGcs($gcsPath, $sourceName);

        if (null !== $progressCallback) {
            $progressCallback(count($resources), count($resources), 'GCS 掃描完成');
        }

        return $resources;
    }

    /**
     * Fetch resource list from GCS only (skip local sync).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceListFromGcsOnly(): array
    {
        $gcsPath = $this->config['gcs_path'] ?? 'cnn/';
        $sourceName = 'CNN';

        return $this->fetchResourceListFromGcs($gcsPath, $sourceName);
    }

    /**
     * Process a batch of files.
     *
     * @param array<int, array<string, mixed>> $files
     * @param string $gcsBasePath
     * @param bool $dryRun
     * @param bool $keepLocal
     * @param string $groupBy Grouping method: 'label' or 'unique-id'
     * @param callable|null $progressCallback
     * @param int $currentMoved 當前已成功移動的檔案數
     * @param int $totalFiles 總檔案數或 limit
     * @param int $currentChecked 當前已檢查的檔案數
     * @param int|null $limit 處理上限（如果設定）
     * @return array{moved: int, skipped: int, errors: int, should_stop: bool, error_details: array}
     */
    private function processBatch(
        array $files,
        string $gcsBasePath,
        bool $dryRun,
        bool $keepLocal,
        string $groupBy,
        ?callable $progressCallback,
        int $currentMoved,
        int $totalFiles,
        int $currentChecked = 0,
        ?int $limit = null
    ): array {
        // Group files by selected method
        $groupedFiles = 'unique-id' === $groupBy
            ? $this->groupFilesByUniqueIdOnly($files)
            : $this->groupFilesByUniqueId($files);

        // Move files to GCS
        $batchMovedCount = 0;
        $batchSkippedCount = 0;
        $batchErrorCount = 0;
        $batchErrorDetails = []; // 收集批次錯誤詳情

        $fileIndex = 0;
        $shouldStop = false;
        
        foreach ($groupedFiles as $uniqueId => $groupFiles) {
            foreach ($groupFiles as $file) {
                // 如果已達到 limit，停止處理
                if (null !== $limit && ($currentMoved + $batchMovedCount) >= $limit) {
                    $shouldStop = true;
                    break 2; // 跳出兩層循環
                }
                
                $fileIndex++;
                $currentCheckedNumber = $currentChecked + $fileIndex;
                $currentMovedNumber = $currentMoved + $batchMovedCount;

                try {
                    if ($dryRun) {
                        $batchMovedCount++;
                        // Show progress every 10 files or for first/last file in batch
                        if (null !== $progressCallback && (0 === ($fileIndex % 10) || 1 === $fileIndex || $fileIndex === count($files))) {
                            $progressCallback($currentMovedNumber, $totalFiles, "模擬移動: {$file['name']}");
                        }
                        continue;
                    }

                    $result = $this->moveSingleFileToGcs($file, $uniqueId, $gcsBasePath, $keepLocal, $dryRun);

                    if ($result['moved']) {
                        $batchMovedCount++;
                        $currentMovedNumber = $currentMoved + $batchMovedCount;
                    } elseif ($result['skipped']) {
                        $batchSkippedCount++;
                    } else {
                        $batchErrorCount++;
                        
                        // 記錄錯誤詳情
                        if (isset($result['error_detail'])) {
                            $batchErrorDetails[] = $result['error_detail'];
                        }
                    }

                    // Show progress every 10 files, on errors, or for first/last file in batch
                    if (null !== $progressCallback && (
                        0 === ($fileIndex % 10) ||
                        $result['error'] ||
                        1 === $fileIndex ||
                        $fileIndex === count($files)
                    )) {
                        $status = $result['moved'] ? '已移動' : ($result['skipped'] ? '已跳過' : '失敗');
                        $progressCallback($currentMovedNumber, $totalFiles, "{$status}: {$file['name']}");
                    }
                } catch (\Exception $e) {
                    $batchErrorCount++;
                    
                    // 記錄錯誤詳情
                    $errorDetail = [
                        'file_name' => $file['name'],
                        'file_path' => $file['path'] ?? 'N/A',
                        'unique_id' => $uniqueId,
                        'error_type' => 'exception',
                        'error_message' => $e->getMessage(),
                        'timestamp' => date('Y-m-d H:i:s'),
                        'file_size' => $file['size'] ?? null,
                        'gcs_target_path' => rtrim($gcsBasePath, '/') . '/' . $uniqueId . '/' . $file['name'],
                    ];
                    $batchErrorDetails[] = $errorDetail;
                    
                    Log::error('[CnnFetchService] 處理檔案失敗', [
                        'file' => $file['name'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    if (null !== $progressCallback) {
                        $progressCallback($currentMovedNumber, $totalFiles, "錯誤: {$file['name']} - {$e->getMessage()}");
                    }
                }
            }
        }

        // Log batch summary (only for non-dry-run and significant batches)
        if (!$dryRun && count($files) >= 10) {
            Log::info('[CnnFetchService] 批次處理完成', [
                'batch_size' => count($files),
                'moved' => $batchMovedCount,
                'skipped' => $batchSkippedCount,
                'errors' => $batchErrorCount,
            ]);
        }

        return [
            'moved' => $batchMovedCount,
            'skipped' => $batchSkippedCount,
            'errors' => $batchErrorCount,
            'error_details' => $batchErrorDetails,
        ];
    }

    /**
     * Move a single file to GCS.
     *
     * @param array<string, mixed> $file
     * @param string $uniqueId
     * @param string $gcsBasePath
     * @param bool $keepLocal If true, keep local file after uploading to GCS
     * @param bool $dryRun If true, do not actually upload or delete files
     * @return array{moved: bool, skipped: bool, error: bool, error_detail?: array}
     */
    private function moveSingleFileToGcs(array $file, string $uniqueId, string $gcsBasePath, bool $keepLocal = false, bool $dryRun = false): array
    {
        // 乾跑模式：不實際上傳或刪除檔案
        if ($dryRun) {
            return ['moved' => true, 'skipped' => false, 'error' => false];
        }

        $gcsDisk = Storage::disk('gcs');
        $targetDir = rtrim($gcsBasePath, '/') . '/' . $uniqueId;
        $targetPath = $targetDir . '/' . $file['name'];

        // Check if file already exists in GCS (same filename = same version)
        if ($gcsDisk->exists($targetPath)) {
            // 如果文件已存在於 GCS，且不使用 --keep-local，則刪除本地文件
            if (!$keepLocal) {
                if (@unlink($file['path'])) {
                    Log::info('[CnnFetchService] 檔案已存在於 GCS，已刪除本地檔案', [
                        'local_path' => $file['path'],
                        'gcs_path' => $targetPath,
                        'unique_id' => $uniqueId,
                    ]);
                } else {
                    Log::warning('[CnnFetchService] 檔案已存在於 GCS，但無法刪除本地檔案', [
                        'local_path' => $file['path'],
                        'gcs_path' => $targetPath,
                        'unique_id' => $uniqueId,
                    ]);
                }
            } else {
                Log::debug('[CnnFetchService] 檔案已存在於 GCS，保留本地檔案（--keep-local 模式）', [
                    'local_path' => $file['path'],
                    'gcs_path' => $targetPath,
                    'unique_id' => $uniqueId,
                ]);
            }
            return ['moved' => false, 'skipped' => true, 'error' => false];
        }

        // In keep-local mode, check if there's an older version of the same file type
        // and remove it if the local version is newer
        if ($keepLocal) {
            $this->handleVersionUpdate($file, $uniqueId, $targetDir, $gcsDisk);
        }

        // Read file content (for large files, consider streaming)
        $content = @file_get_contents($file['path']);

        if (false === $content) {
            $errorDetail = [
                'file_name' => $file['name'],
                'file_path' => $file['path'],
                'unique_id' => $uniqueId,
                'error_type' => 'read_failed',
                'error_message' => '無法讀取本地檔案',
                'timestamp' => date('Y-m-d H:i:s'),
                'file_size' => $file['size'] ?? null,
                'gcs_target_path' => $targetPath,
            ];
            
            Log::error('[CnnFetchService] 無法讀取本地檔案', [
                'local_path' => $file['path'],
                'unique_id' => $uniqueId,
            ]);
            
            return [
                'moved' => false, 
                'skipped' => false, 
                'error' => true,
                'error_detail' => $errorDetail,
            ];
        }

        // Upload to GCS
        try {
            $gcsDisk->put($targetPath, $content);
            unset($content); // Free memory immediately

            // 驗證檔案是否真的上傳成功
            if (!$gcsDisk->exists($targetPath)) {
                $errorDetail = [
                    'file_name' => $file['name'],
                    'file_path' => $file['path'],
                    'unique_id' => $uniqueId,
                    'error_type' => 'upload_verification_failed',
                    'error_message' => '檔案上傳後驗證失敗，GCS 中不存在',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'file_size' => $file['size'] ?? null,
                    'gcs_target_path' => $targetPath,
                ];
                
                Log::error('[CnnFetchService] 檔案上傳後驗證失敗，GCS 中不存在', [
                    'local_path' => $file['path'],
                    'gcs_path' => $targetPath,
                    'unique_id' => $uniqueId,
                ]);
                
                return [
                    'moved' => false, 
                    'skipped' => false, 
                    'error' => true,
                    'error_detail' => $errorDetail,
                ];
            }

            Log::info('[CnnFetchService] 檔案成功上傳到 GCS', [
                'local_path' => $file['path'],
                'gcs_path' => $targetPath,
                'unique_id' => $uniqueId,
            ]);

            // Delete local file after successful upload (if not keeping local)
            if (!$keepLocal) {
                if (@unlink($file['path'])) {
                    return ['moved' => true, 'skipped' => false, 'error' => false];
                } else {
                    Log::warning('[CnnFetchService] 檔案已上傳到 GCS，但無法刪除本地檔案', [
                        'local_path' => $file['path'],
                        'gcs_path' => $targetPath,
                    ]);
                    return ['moved' => true, 'skipped' => false, 'error' => false]; // Still consider it moved
                }
            } else {
                // Keep local file
                return ['moved' => true, 'skipped' => false, 'error' => false];
            }
        } catch (\Exception $e) {
            $errorDetail = [
                'file_name' => $file['name'],
                'file_path' => $file['path'],
                'unique_id' => $uniqueId,
                'error_type' => 'upload_failed',
                'error_message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'file_size' => $file['size'] ?? null,
                'gcs_target_path' => $targetPath,
            ];
            
            Log::error('[CnnFetchService] 上傳到 GCS 失敗', [
                'local_path' => $file['path'],
                'gcs_path' => $targetPath,
                'unique_id' => $uniqueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'moved' => false, 
                'skipped' => false, 
                'error' => true,
                'error_detail' => $errorDetail,
            ];
        }
    }

    /**
     * Handle version update in keep-local mode.
     * If a newer version of the same file type exists locally, remove older versions from GCS.
     *
     * @param array<string, mixed> $file Local file info
     * @param string $uniqueId Unique identifier
     * @param string $targetDir Target directory in GCS
     * @param \Illuminate\Contracts\Filesystem\Filesystem $gcsDisk GCS disk instance
     * @return void
     */
    private function handleVersionUpdate(array $file, string $uniqueId, string $targetDir, $gcsDisk): void
    {
        // Extract local file version
        $localVersion = $this->storageService->extractFileVersion($file['name']);
        if (null === $localVersion) {
            // No version found, skip version check
            return;
        }

        // Determine file type (xml or mp4)
        $fileType = strtolower($file['extension']);
        if (!in_array($fileType, ['xml', 'mp4'], true)) {
            // Only check version for XML and MP4 files
            return;
        }

        // List all files in the target directory
        try {
            $existingFiles = $gcsDisk->files($targetDir);
        } catch (\Exception $e) {
            Log::warning('[CnnFetchService] 無法列出 GCS 目錄中的檔案', [
                'target_dir' => $targetDir,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // Find files of the same type with older versions
        foreach ($existingFiles as $existingFilePath) {
            $existingFileName = basename($existingFilePath);
            $existingFileType = strtolower(pathinfo($existingFileName, PATHINFO_EXTENSION));

            // Only check files of the same type
            if ($existingFileType !== $fileType) {
                continue;
            }

            // Extract version from existing file
            $existingVersion = $this->storageService->extractFileVersion($existingFileName);
            if (null === $existingVersion) {
                continue;
            }

            // If local version is newer, remove older version from GCS
            if ($localVersion > $existingVersion) {
                try {
                    $gcsDisk->delete($existingFilePath);
                    Log::info('[CnnFetchService] 已刪除舊版號檔案', [
                        'unique_id' => $uniqueId,
                        'old_file' => $existingFileName,
                        'old_version' => $existingVersion,
                        'new_file' => $file['name'],
                        'new_version' => $localVersion,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[CnnFetchService] 無法刪除舊版號檔案', [
                        'file_path' => $existingFilePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Scan files from local directory /mnt/PushDownloads.
     * Returns all files in memory (for backward compatibility).
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanLocalFiles(): array
    {
        $files = [];
        foreach ($this->scanLocalFilesGenerator() as $file) {
            $files[] = $file;
        }
        return $files;
    }

    /**
     * Scan files from local directory using generator (memory efficient).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function scanLocalFilesGenerator(): \Generator
    {
        if (!is_dir($this->sourcePath)) {
            Log::warning('[CnnFetchService] 來源目錄不存在', [
                'path' => $this->sourcePath,
            ]);
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, ['xml', 'mp4', 'jpg', 'jpeg'], true)) {
                        yield [
                            'path' => $file->getPathname(),
                            'relative_path' => str_replace($this->sourcePath . '/', '', $file->getPathname()),
                            'name' => $file->getFilename(),
                            'extension' => $extension,
                            'size' => $file->getSize(),
                            'modified' => $file->getMTime(),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('[CnnFetchService] 掃描本地檔案失敗', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract unique identifier from filename.
     * Format: CNNA-ST1-xxxxxxxxxxxxxxxx (16 hex digits)
     *
     * @param string $fileName
     * @return string|null
     */
    private function extractUniqueId(string $fileName): ?string
    {
        // Pattern: CNNA-ST1-xxxxxxxxxxxxxxxx (16 hex digits)
        if (preg_match('/CNNA-ST1-([a-f0-9]{16})/i', $fileName, $matches)) {
            return 'CNNA-ST1-' . strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Extract description label from filename.
     * Format: EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-...
     * Description label is the part between underscores, before CNNA-ST1.
     * We extract the segment that appears before the last underscore before CNNA-ST1.
     *
     * @param string $fileName
     * @return string|null
     */
    private function extractDescriptionLabel(string $fileName): ?string
    {
        // Pattern: Match the part before CNNA-ST1
        // Example: EN-07FR_VERTICAL_ KPOP DEMON _CNNA-ST1-20000000000900ca_801_0
        // We want to extract "KPOP DEMON" (the text between the last two underscores before CNNA-ST1)
        
        // First, find the position of CNNA-ST1
        if (!preg_match('/_CNNA-ST1-/i', $fileName)) {
            return null;
        }

        // Split by underscores and find the segment before CNNA-ST1
        // Pattern: match everything up to CNNA-ST1, then extract the last segment before it
        if (preg_match('/(.+?)_CNNA-ST1-/i', $fileName, $matches)) {
            $beforeCnn = $matches[1];
            
            // Split by underscores and get the last segment (description label)
            $parts = explode('_', $beforeCnn);
            
            if (count($parts) > 0) {
                // Get the last part (description label)
                $label = trim(end($parts));
                // Normalize whitespace
                $label = preg_replace('/\s+/', ' ', $label);
                return '' !== $label ? $label : null;
            }
        }

        return null;
    }

    /**
     * Extract unique identifier from filename (public method for testing).
     * Format: CNNA-ST1-xxxxxxxxxxxxxxxx (16 hex digits)
     *
     * @param string $fileName
     * @return string|null
     */
    public function extractUniqueIdFromFileName(string $fileName): ?string
    {
        return $this->extractUniqueId($fileName);
    }

    /**
     * Get grouped files by unique identifier (public method for testing).
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function groupFilesByUniqueIdPublic(array $files): array
    {
        return $this->groupFilesByUniqueId($files);
    }

    /**
     * Get source path (public method for testing).
     *
     * @return string
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Scan local files and return as array (public method for testing).
     *
     * @return array<int, array<string, mixed>>
     */
    public function scanLocalFilesForTesting(): array
    {
        $files = [];
        foreach ($this->scanLocalFilesGenerator() as $file) {
            $files[] = $file;
        }
        return $files;
    }

    /**
     * Group files directly by unique identifier (each unique ID gets its own folder).
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupFilesByUniqueIdOnly(array $files): array
    {
        $grouped = [];

        foreach ($files as $file) {
            $uniqueId = $this->extractUniqueId($file['name']);

            if (null === $uniqueId) {
                Log::warning('[CnnFetchService] 無法從檔名提取唯一識別碼', [
                    'file_name' => $file['name'],
                ]);
                continue;
            }

            if (!isset($grouped[$uniqueId])) {
                $grouped[$uniqueId] = [];
            }

            $grouped[$uniqueId][] = $file;
        }

        return $grouped;
    }

    /**
     * Group files by description label, using the first unique ID encountered for each label as folder name.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array{unique_id: string, files: array<int, array<string, mixed>>}>
     */
    private function groupFilesByUniqueId(array $files): array
    {
        // First pass: group by description label and track first unique ID for each label
        $labelToUniqueId = [];
        $labelToFiles = [];

        foreach ($files as $file) {
            $descriptionLabel = $this->extractDescriptionLabel($file['name']);
            $uniqueId = $this->extractUniqueId($file['name']);

            if (null === $descriptionLabel) {
                Log::warning('[CnnFetchService] 無法從檔名提取描述標籤', [
                    'file_name' => $file['name'],
                ]);
                continue;
            }

            if (null === $uniqueId) {
                Log::warning('[CnnFetchService] 無法從檔名提取唯一識別碼', [
                    'file_name' => $file['name'],
                ]);
                continue;
            }

            // For each description label, use the first unique ID encountered
            if (!isset($labelToUniqueId[$descriptionLabel])) {
                $labelToUniqueId[$descriptionLabel] = $uniqueId;
                $labelToFiles[$descriptionLabel] = [];
            }

            $labelToFiles[$descriptionLabel][] = $file;
        }

        // Second pass: reorganize by unique ID (using first unique ID for each description label)
        $grouped = [];

        foreach ($labelToFiles as $descriptionLabel => $labelFiles) {
            $folderUniqueId = $labelToUniqueId[$descriptionLabel];

            if (!isset($grouped[$folderUniqueId])) {
                $grouped[$folderUniqueId] = [];
            }

            // Add all files with this description label to the folder
            foreach ($labelFiles as $file) {
                $grouped[$folderUniqueId][] = $file;
            }
        }

        return $grouped;
    }

    /**
     * Move files to GCS, organized by unique identifier.
     * Legacy method for backward compatibility.
     *
     * @param array<string, array<int, array<string, mixed>>> $groupedFiles
     * @param string $gcsBasePath
     * @return array<int, array<string, mixed>>
     */
    private function moveFilesToGcs(array $groupedFiles, string $gcsBasePath): array
    {
        $movedFiles = [];

        foreach ($groupedFiles as $uniqueId => $files) {
            foreach ($files as $file) {
                $result = $this->moveSingleFileToGcs($file, $uniqueId, $gcsBasePath);

                if ($result['moved']) {
                    $movedFiles[] = [
                        'unique_id' => $uniqueId,
                        'file_name' => $file['name'],
                        'gcs_path' => rtrim($gcsBasePath, '/') . '/' . $uniqueId . '/' . $file['name'],
                        'type' => $this->getFileType($file['extension']),
                    ];
                }
            }
        }

        return $movedFiles;
    }

    /**
     * Get file type from extension.
     *
     * @param string $extension
     * @return string
     */
    private function getFileType(string $extension): string
    {
        return match (strtolower($extension)) {
            'xml' => 'xml',
            'mp4' => 'video',
            'jpg', 'jpeg' => 'image',
            default => 'unknown',
        };
    }

    /**
     * Fetch resource list from GCS after files are moved.
     *
     * @param string $gcsPath
     * @param string $sourceName
     * @return array<int, array<string, mixed>>
     */
    private function fetchResourceListFromGcs(string $gcsPath, string $sourceName): array
    {
        $storageType = 'gcs';

        // Scan for XML and MP4 files in GCS
        $xmlFiles = $this->storageService->scanXmlFiles($storageType, $sourceName, $gcsPath);
        $videoFiles = $this->storageService->scanVideoFiles($storageType, $sourceName, $gcsPath);

        // Combine and deduplicate by source_id AND file type
        // 改為按 source_id + type 組合去重，這樣同一個 source_id 的 XML 和 MP4 都會被保留
        $resources = [];
        $processedKeys = []; // 改用 source_id + type 組合作為 key

        foreach ($xmlFiles as $xmlFile) {
            $sourceId = $xmlFile['source_id'];
            $key = $sourceId . '_xml'; // 組合 key
            if (!isset($processedKeys[$key])) {
                $resources[] = [
                    'source_id' => $sourceId,
                    'source_name' => $sourceName,
                    'type' => 'xml',
                    'file_path' => $xmlFile['file_path'],
                    'relative_path' => $xmlFile['relative_path'],
                    'last_modified' => $xmlFile['last_modified'],
                ];
                $processedKeys[$key] = true;
            }
        }

        foreach ($videoFiles as $videoFile) {
            $sourceId = $videoFile['source_id'];
            $key = $sourceId . '_video'; // 組合 key
            if (!isset($processedKeys[$key])) {
                $resources[] = [
                    'source_id' => $sourceId,
                    'source_name' => $sourceName,
                    'type' => 'video',
                    'file_path' => $videoFile['file_path'],
                    'relative_path' => $videoFile['relative_path'],
                    'last_modified' => $videoFile['last_modified'],
                ];
                $processedKeys[$key] = true;
            }
        }

        Log::info('[CnnFetchService] 從 GCS 掃描到資源', [
            'total_count' => count($resources),
            'xml_count' => count($xmlFiles),
            'video_count' => count($videoFiles),
            'storage_type' => $storageType,
        ]);

        return $resources;
    }

    /**
     * Download single resource (for CNN, resources are already in GCS).
     *
     * @param string $resourceId
     * @return array<string, mixed>|null
     */
    public function downloadResource(string $resourceId): ?array
    {
        // CNN resources are already in GCS, just return the resource info
        $resources = $this->fetchResourceList();

        foreach ($resources as $resource) {
            if ($resource['source_id'] === $resourceId) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Download multiple resources.
     *
     * @param array<string> $resourceIds
     * @return array<int, array<string, mixed>>
     */
    public function downloadResources(array $resourceIds): array
    {
        $resources = $this->fetchResourceList();
        $result = [];

        foreach ($resources as $resource) {
            if (in_array($resource['source_id'], $resourceIds, true)) {
                $result[] = $resource;
            }
        }

        return $result;
    }

    /**
     * Save resources to storage (for CNN, files are moved from /mnt/PushDownloads to GCS).
     *
     * @param array<int, array<string, mixed>> $resources
     * @param string $storageType
     * @return bool
     */
    public function saveToStorage(array $resources, string $storageType): bool
    {
        // Files are moved from /mnt/PushDownloads to GCS in fetchResourceList()
        Log::info('[CnnFetchService] 資源已移動到 GCS', [
            'count' => count($resources),
            'storage_type' => $storageType,
        ]);

        return true;
    }
}

