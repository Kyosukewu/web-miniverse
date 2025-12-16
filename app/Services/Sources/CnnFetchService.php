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
     * @param callable|null $progressCallback Callback function(current, total, message)
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceListWithProgress(
        int $batchSize = 50,
        bool $dryRun = false,
        bool $keepLocal = false,
        string $groupBy = 'label',
        ?callable $progressCallback = null
    ): array {
        $gcsPath = $this->config['gcs_path'] ?? 'cnn/';
        $sourceName = 'CNN';

        Log::info('[CnnFetchService] 開始從本地目錄抓取檔案並移動到 GCS', [
            'source_path' => $this->sourcePath,
            'gcs_path' => $gcsPath,
            'batch_size' => $batchSize,
            'dry_run' => $dryRun,
            'keep_local' => $keepLocal,
        ]);

        // Step 1: First pass - count total files (for progress display)
        if (null !== $progressCallback) {
            $progressCallback(0, null, '計算檔案總數...');
        }

        $totalFiles = 0;
        foreach ($this->scanLocalFilesGenerator() as $file) {
            $totalFiles++;
        }

        if (null !== $progressCallback) {
            $progressCallback(0, $totalFiles, "找到 {$totalFiles} 個檔案，開始處理...");
        }

        // Step 2: Process files in batches
        $processedCount = 0;
        $localFiles = [];
        $movedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($this->scanLocalFilesGenerator() as $file) {
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
                    $processedCount,
                    $totalFiles
                );
                $processedCount += count($localFiles);
                $movedCount += $result['moved'];
                $skippedCount += $result['skipped'];
                $errorCount += $result['errors'];
                $localFiles = []; // Clear batch to free memory

                // Show batch progress
                if (null !== $progressCallback && $totalFiles > 0) {
                    $percentage = round(($processedCount / $totalFiles) * 100, 1);
                    $progressCallback($processedCount, $totalFiles, "已處理 {$processedCount}/{$totalFiles} ({$percentage}%)");
                }
            }
        }

        // Process remaining files
        if (!empty($localFiles)) {
            $result = $this->processBatch(
                $localFiles,
                $gcsPath,
                $dryRun,
                $keepLocal,
                $groupBy,
                $progressCallback,
                $processedCount,
                $totalFiles
            );
            $processedCount += count($localFiles);
            $movedCount += $result['moved'];
            $skippedCount += $result['skipped'];
            $errorCount += $result['errors'];
        }

        if (null !== $progressCallback) {
            $progressCallback($processedCount, $totalFiles, "本地檔案處理完成 (移動: {$movedCount}, 跳過: {$skippedCount}, 錯誤: {$errorCount})");
        }

        Log::info('[CnnFetchService] 檔案處理完成', [
            'total_files' => $totalFiles,
            'moved_count' => $movedCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
        ]);

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
     * @param int $currentProcessed
     * @param int $totalFiles
     * @return array{moved: int, skipped: int, errors: int}
     */
    private function processBatch(
        array $files,
        string $gcsBasePath,
        bool $dryRun,
        bool $keepLocal,
        string $groupBy,
        ?callable $progressCallback,
        int $currentProcessed,
        int $totalFiles
    ): array {
        // Group files by selected method
        $groupedFiles = 'unique-id' === $groupBy
            ? $this->groupFilesByUniqueIdOnly($files)
            : $this->groupFilesByUniqueId($files);

        // Move files to GCS
        $movedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $fileIndex = 0;
        foreach ($groupedFiles as $uniqueId => $groupFiles) {
            foreach ($groupFiles as $file) {
                $fileIndex++;
                $currentFileNumber = $currentProcessed + $fileIndex;

                try {
                    if ($dryRun) {
                        $movedCount++;
                        // Show progress every 10 files or for first/last file in batch
                        if (null !== $progressCallback && (0 === ($fileIndex % 10) || 1 === $fileIndex || $fileIndex === count($files))) {
                            $progressCallback($currentFileNumber, $totalFiles, "模擬移動: {$file['name']}");
                        }
                        continue;
                    }

                    $result = $this->moveSingleFileToGcs($file, $uniqueId, $gcsBasePath, $keepLocal, $dryRun);

                    if ($result['moved']) {
                        $movedCount++;
                    } elseif ($result['skipped']) {
                        $skippedCount++;
                    } else {
                        $errorCount++;
                    }

                    // Show progress every 10 files, on errors, or for first/last file in batch
                    if (null !== $progressCallback && (
                        0 === ($fileIndex % 10) ||
                        $result['error'] ||
                        1 === $fileIndex ||
                        $fileIndex === count($files)
                    )) {
                        $status = $result['moved'] ? '已移動' : ($result['skipped'] ? '已跳過' : '失敗');
                        $progressCallback($currentFileNumber, $totalFiles, "{$status}: {$file['name']}");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('[CnnFetchService] 處理檔案失敗', [
                        'file' => $file['name'],
                        'error' => $e->getMessage(),
                    ]);

                    if (null !== $progressCallback) {
                        $progressCallback($currentFileNumber, $totalFiles, "錯誤: {$file['name']} - {$e->getMessage()}");
                    }
                }
            }
        }

        // Log batch summary (only for non-dry-run and significant batches)
        if (!$dryRun && count($files) >= 10) {
            Log::info('[CnnFetchService] 批次處理完成', [
                'batch_size' => count($files),
                'moved' => $movedCount,
                'skipped' => $skippedCount,
                'errors' => $errorCount,
            ]);
        }

        return [
            'moved' => $movedCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
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
     * @return array{moved: bool, skipped: bool, error: bool}
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
            Log::error('[CnnFetchService] 無法讀取本地檔案', [
                'local_path' => $file['path'],
            ]);
            return ['moved' => false, 'skipped' => false, 'error' => true];
        }

        // Upload to GCS
        try {
            $gcsDisk->put($targetPath, $content);
            unset($content); // Free memory immediately

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
            Log::error('[CnnFetchService] 上傳到 GCS 失敗', [
                'local_path' => $file['path'],
                'gcs_path' => $targetPath,
                'error' => $e->getMessage(),
            ]);
            return ['moved' => false, 'skipped' => false, 'error' => true];
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

        // Combine and deduplicate by source_id (unique identifier)
        $resources = [];
        $processedIds = [];

        foreach ($xmlFiles as $xmlFile) {
            $sourceId = $xmlFile['source_id'];
            if (!isset($processedIds[$sourceId])) {
                $resources[] = [
                    'source_id' => $sourceId,
                    'source_name' => $sourceName,
                    'type' => 'xml',
                    'file_path' => $xmlFile['file_path'],
                    'relative_path' => $xmlFile['relative_path'],
                    'last_modified' => $xmlFile['last_modified'],
                ];
                $processedIds[$sourceId] = true;
            }
        }

        foreach ($videoFiles as $videoFile) {
            $sourceId = $videoFile['source_id'];
            if (!isset($processedIds[$sourceId])) {
                $resources[] = [
                    'source_id' => $sourceId,
                    'source_name' => $sourceName,
                    'type' => 'video',
                    'file_path' => $videoFile['file_path'],
                    'relative_path' => $videoFile['relative_path'],
                    'last_modified' => $videoFile['last_modified'],
                ];
                $processedIds[$sourceId] = true;
            }
        }

        Log::info('[CnnFetchService] 從 GCS 掃描到資源', [
            'count' => count($resources),
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

