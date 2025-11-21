<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageService
{
    /**
     * Get storage disk instance.
     *
     * @param string $type
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public function getDisk(string $type): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $diskName = match ($type) {
            'nas' => 'nas',
            's3' => 's3',
            'storage', 'local' => 'local',
            default => 'local',
        };

        return Storage::disk($diskName);
    }

    /**
     * Scan for video files in storage.
     * For CNN: scans for MP4 files (both Broadcast Quality and Proxy Format).
     *
     * @param string $storageType
     * @param string $sourceName
     * @param string $basePath
     * @return array<int, array<string, mixed>>
     */
    public function scanVideoFiles(string $storageType, string $sourceName, string $basePath = ''): array
    {
        $disk = $this->getDisk($storageType);
        $videoFiles = [];
        $supportedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'ts', 'flv', 'wmv', 'm4v'];

        try {
            $sourcePath = $basePath ? rtrim($basePath, '/') . '/' . $sourceName : $sourceName;

            if (!$disk->exists($sourcePath)) {
                Log::warning('[StorageService] 來源路徑不存在', [
                    'storage_type' => $storageType,
                    'source_name' => $sourceName,
                    'path' => $sourcePath,
                ]);
                return [];
            }

            // For CNN, files might be in subdirectories or directly in source path
            // Scan recursively for MP4 files
            $allFiles = $disk->allFiles($sourcePath);

            foreach ($allFiles as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (in_array($extension, $supportedExtensions, true)) {
                    // Extract source_id from file path
                    // CNN format: files are grouped by story ID (e.g., MW-006TH)
                    $relativePath = str_replace($sourcePath . '/', '', $file);
                    $pathParts = explode('/', $relativePath);
                    
                    // Try to extract story ID from filename or directory
                    // CNN files often have format like: MW-006TH_IL_ PRITZKER SIGNS EX_CNNA-ST1-200000000008cf55_174_0.mp4
                    $fileName = basename($file, '.' . $extension);
                    $sourceId = $this->extractSourceIdFromFileName($fileName, $pathParts);

                    // Prefer Broadcast Quality File over Proxy Format
                    $isProxy = $this->isProxyFile($fileName);
                    $fileKey = $sourceId . ($isProxy ? '_proxy' : '_broadcast');

                    // If we already have a broadcast quality file, skip proxy
                    if ($isProxy && isset($videoFiles[$sourceId . '_broadcast'])) {
                        continue;
                    }

                    // If we find a broadcast quality file, remove proxy if exists
                    if (!$isProxy && isset($videoFiles[$sourceId . '_proxy'])) {
                        unset($videoFiles[$sourceId . '_proxy']);
                    }

                    $videoFiles[$fileKey] = [
                        'source_name' => $sourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $sourceName . '/' . $relativePath,
                        'file_name' => basename($file),
                        'extension' => $extension,
                        'is_proxy' => $isProxy,
                        'size' => $disk->size($file),
                        'last_modified' => $disk->lastModified($file),
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('[StorageService] 掃描影片檔案失敗', [
                'storage_type' => $storageType,
                'source_name' => $sourceName,
                'error' => $e->getMessage(),
            ]);
        }

        return array_values($videoFiles);
    }

    /**
     * Extract source ID from CNN file name or path.
     *
     * @param string $fileName
     * @param array<string> $pathParts
     * @return string
     */
    private function extractSourceIdFromFileName(string $fileName, array $pathParts): string
    {
        // CNN files often start with story ID like MW-006TH
        // Try to extract from filename first
        if (preg_match('/^([A-Z]+-\d+[A-Z]*)/', $fileName, $matches)) {
            return $matches[1];
        }

        // If not found in filename, try directory name
        if (!empty($pathParts)) {
            $dirName = $pathParts[0];
            if (preg_match('/^([A-Z]+-\d+[A-Z]*)/', $dirName, $matches)) {
                return $matches[1];
            }
            return $dirName;
        }

        // Fallback to filename without extension
        return $fileName;
    }

    /**
     * Check if file is a proxy format file.
     *
     * @param string $fileName
     * @return bool
     */
    private function isProxyFile(string $fileName): bool
    {
        // CNN proxy files often have prefixes like WH16x9N_ or contain "proxy" in name
        $proxyIndicators = ['WH16x9N_', 'proxy', 'preview'];
        $fileNameUpper = strtoupper($fileName);

        foreach ($proxyIndicators as $indicator) {
            if (str_contains($fileNameUpper, strtoupper($indicator))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan for XML document files in storage.
     *
     * @param string $storageType
     * @param string $sourceName
     * @param string $basePath
     * @return array<int, array<string, mixed>>
     */
    public function scanXmlFiles(string $storageType, string $sourceName, string $basePath = ''): array
    {
        $disk = $this->getDisk($storageType);
        $xmlFiles = [];

        try {
            $sourcePath = $basePath ? rtrim($basePath, '/') . '/' . $sourceName : $sourceName;

            if (!$disk->exists($sourcePath)) {
                Log::warning('[StorageService] 來源路徑不存在', [
                    'storage_type' => $storageType,
                    'source_name' => $sourceName,
                    'path' => $sourcePath,
                ]);
                return [];
            }

            $files = $disk->allFiles($sourcePath);

            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if ('xml' === $extension) {
                    $relativePath = str_replace($sourcePath . '/', '', $file);
                    $sourceId = pathinfo($relativePath, PATHINFO_FILENAME);

                    $xmlFiles[] = [
                        'source_name' => $sourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $sourceName . '/' . $relativePath,
                        'file_name' => basename($file),
                        'size' => $disk->size($file),
                        'last_modified' => $disk->lastModified($file),
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('[StorageService] 掃描 XML 檔案失敗', [
                'storage_type' => $storageType,
                'source_name' => $sourceName,
                'error' => $e->getMessage(),
            ]);
        }

        return $xmlFiles;
    }

    /**
     * Read file content from storage.
     *
     * @param string $storageType
     * @param string $filePath
     * @return string|null
     */
    public function readFile(string $storageType, string $filePath): ?string
    {
        try {
            $disk = $this->getDisk($storageType);

            if (!$disk->exists($filePath)) {
                Log::warning('[StorageService] 檔案不存在', [
                    'storage_type' => $storageType,
                    'file_path' => $filePath,
                ]);
                return null;
            }

            return $disk->get($filePath);
        } catch (\Exception $e) {
            Log::error('[StorageService] 讀取檔案失敗', [
                'storage_type' => $storageType,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get full path for video file (for analysis).
     * For S3, downloads file to temporary location.
     *
     * @param string $storageType
     * @param string $filePath
     * @return string|null
     */
    public function getVideoFilePath(string $storageType, string $filePath): ?string
    {
        if ('nas' === $storageType || 'local' === $storageType || 'storage' === $storageType) {
            $disk = $this->getDisk($storageType);
            $root = $disk->path('');

            return $root . '/' . ltrim($filePath, '/');
        }

        // For S3, download to temporary location
        if ('s3' === $storageType) {
            return $this->downloadS3FileToTemp($filePath);
        }

        return $filePath;
    }

    /**
     * Download S3 file to temporary location for analysis.
     *
     * @param string $filePath
     * @return string|null
     */
    private function downloadS3FileToTemp(string $filePath): ?string
    {
        try {
            $disk = $this->getDisk('s3');

            if (!$disk->exists($filePath)) {
                Log::warning('[StorageService] S3 檔案不存在', [
                    'file_path' => $filePath,
                ]);
                return null;
            }

            // Create temp directory
            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Generate temp file path
            $fileName = basename($filePath);
            $tempPath = $tempDir . '/' . uniqid('s3_', true) . '_' . $fileName;

            // Download from S3
            $contents = $disk->get($filePath);
            file_put_contents($tempPath, $contents);

            Log::info('[StorageService] S3 檔案下載到臨時位置', [
                's3_path' => $filePath,
                'temp_path' => $tempPath,
            ]);

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('[StorageService] S3 檔案下載失敗', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload file to storage.
     *
     * @param string $storageType
     * @param string $filePath
     * @param string $content
     * @return bool
     */
    public function uploadFile(string $storageType, string $filePath, string $content): bool
    {
        try {
            $disk = $this->getDisk($storageType);
            return $disk->put($filePath, $content);
        } catch (\Exception $e) {
            Log::error('[StorageService] 上傳檔案失敗', [
                'storage_type' => $storageType,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Upload file from local path to storage.
     *
     * @param string $storageType
     * @param string $destinationPath
     * @param string $localFilePath
     * @return bool
     */
    public function uploadFileFromPath(string $storageType, string $destinationPath, string $localFilePath): bool
    {
        try {
            if (!file_exists($localFilePath)) {
                Log::warning('[StorageService] 本地檔案不存在', [
                    'local_path' => $localFilePath,
                ]);
                return false;
            }

            $disk = $this->getDisk($storageType);
            $content = file_get_contents($localFilePath);

            if (false === $content) {
                Log::error('[StorageService] 讀取本地檔案失敗', [
                    'local_path' => $localFilePath,
                ]);
                return false;
            }

            return $disk->put($destinationPath, $content);
        } catch (\Exception $e) {
            Log::error('[StorageService] 上傳檔案失敗', [
                'storage_type' => $storageType,
                'destination_path' => $destinationPath,
                'local_path' => $localFilePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

