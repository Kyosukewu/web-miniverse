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
            // Normalize basePath: remove leading slash and storage/app prefix if present
            $normalizedBasePath = $basePath;
            if ($normalizedBasePath) {
                // Remove leading slash
                $normalizedBasePath = ltrim($normalizedBasePath, '/');
                // Remove storage/app prefix if present
                $normalizedBasePath = preg_replace('#^storage/app/#', '', $normalizedBasePath);
                $normalizedBasePath = preg_replace('#^storage/app$#', '', $normalizedBasePath);
            }

            // Build source path
            if ($normalizedBasePath) {
                // If basePath is provided, check if it contains source subdirectory
                $testPathWithSource = rtrim($normalizedBasePath, '/') . '/' . $sourceName;
                if ($disk->exists($testPathWithSource)) {
                    // Path structure: basePath/sourceName/
                    $sourcePath = $testPathWithSource;
                } else {
                    // Path structure: basePath/ (scan all files in basePath)
                    $sourcePath = rtrim($normalizedBasePath, '/');
                }
            } else {
                // No basePath, use sourceName directly
                $sourcePath = $sourceName;
            }

            if (!$disk->exists($sourcePath)) {
                Log::warning('[StorageService] 來源路徑不存在', [
                    'storage_type' => $storageType,
                    'source_name' => $sourceName,
                    'base_path' => $basePath,
                    'normalized_base_path' => $normalizedBasePath,
                    'source_path' => $sourcePath,
                ]);
                return [];
            }

            // For CNN, files might be in subdirectories or directly in source path
            // Scan recursively for video files
            $allFiles = $disk->allFiles($sourcePath);

            foreach ($allFiles as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (in_array($extension, $supportedExtensions, true)) {
                    // Extract source_id from file path
                    // CNN format: files are grouped by story ID (e.g., MW-006TH)
                    $relativePath = str_replace($sourcePath . '/', '', $file);
                    $pathParts = explode('/', $relativePath);
                    
                    // Extract source name from path if available
                    $detectedSourceName = $sourceName;
                    if (count($pathParts) > 0) {
                        // Try to detect source name from directory structure
                        $firstDir = $pathParts[0];
                        if (in_array(strtoupper($firstDir), ['CNN', 'AP', 'RT'], true)) {
                            $detectedSourceName = strtoupper($firstDir);
                        }
                    }

                    // Use directory name as source_id if file is in a subdirectory
                    // Otherwise extract from filename
                    $fileName = basename($file, '.' . $extension);
                    if (count($pathParts) > 1) {
                        // File is in a subdirectory, use the directory name as source_id
                        // e.g., CNN/WE-012TH/video.mp4 -> source_id = WE-012TH
                        $sourceId = $pathParts[count($pathParts) - 2]; // Second to last part (directory name)
                    } else {
                        // File is directly in source path, extract from filename
                    $sourceId = $this->extractSourceIdFromFileName($fileName, $pathParts);
                    }

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

                    // Build relative_path: include basePath if provided, otherwise use detectedSourceName/relativePath
                    if ($normalizedBasePath) {
                        // Include basePath in relative_path to reflect actual file location
                        $fullRelativePath = rtrim($normalizedBasePath, '/') . '/' . $relativePath;
                    } else {
                        // No basePath, use detectedSourceName/relativePath
                        $fullRelativePath = $detectedSourceName . '/' . $relativePath;
                    }

                    $videoFiles[$fileKey] = [
                        'source_name' => $detectedSourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $fullRelativePath,
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
                'base_path' => $basePath,
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
     * Scan for document files (XML and TXT) in storage.
     *
     * @param string $storageType
     * @param string $sourceName
     * @param string $basePath
     * @return array<int, array<string, mixed>>
     */
    public function scanDocumentFiles(string $storageType, string $sourceName, string $basePath = ''): array
    {
        $disk = $this->getDisk($storageType);
        $documentFiles = [];

        try {
            // Normalize basePath: remove leading slash and storage/app prefix if present
            $normalizedBasePath = $basePath;
            if ($normalizedBasePath) {
                // Remove leading slash
                $normalizedBasePath = ltrim($normalizedBasePath, '/');
                // Remove storage/app prefix if present
                $normalizedBasePath = preg_replace('#^storage/app/#', '', $normalizedBasePath);
                $normalizedBasePath = preg_replace('#^storage/app$#', '', $normalizedBasePath);
            }

            // Build source path
            if ($normalizedBasePath) {
                // If basePath is provided, check if it contains source subdirectory
                $testPathWithSource = rtrim($normalizedBasePath, '/') . '/' . $sourceName;
                if ($disk->exists($testPathWithSource)) {
                    // Path structure: basePath/sourceName/
                    $sourcePath = $testPathWithSource;
                } else {
                    // Path structure: basePath/ (scan all files in basePath)
                    $sourcePath = rtrim($normalizedBasePath, '/');
                }
            } else {
                // No basePath, use sourceName directly
                $sourcePath = $sourceName;
            }

            if (!$disk->exists($sourcePath)) {
                Log::warning('[StorageService] 來源路徑不存在', [
                    'storage_type' => $storageType,
                    'source_name' => $sourceName,
                    'base_path' => $basePath,
                    'normalized_base_path' => $normalizedBasePath,
                    'source_path' => $sourcePath,
                ]);
                return [];
            }

            $files = $disk->allFiles($sourcePath);

            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (in_array($extension, ['xml', 'txt'], true)) {
                    $relativePath = str_replace($sourcePath . '/', '', $file);
                    $pathParts = explode('/', $relativePath);

                    // Extract source name from path if available
                    $detectedSourceName = $sourceName;
                    if (count($pathParts) > 1) {
                        // Try to detect source name from directory structure
                        $firstDir = $pathParts[0];
                        if (in_array(strtoupper($firstDir), ['CNN', 'AP', 'RT'], true)) {
                            $detectedSourceName = strtoupper($firstDir);
                        }
                    }

                    // Use directory name as source_id if file is in a subdirectory
                    // Otherwise use filename without extension
                    if (count($pathParts) > 1) {
                        // File is in a subdirectory, use the directory name as source_id
                        // e.g., CNN/WE-012TH/file.xml -> source_id = WE-012TH
                        $sourceId = $pathParts[count($pathParts) - 2]; // Second to last part (directory name)
                    } else {
                        // File is directly in source path, use filename without extension
                        $sourceId = pathinfo($relativePath, PATHINFO_FILENAME);
                    }

                    // Build relative_path: include basePath if provided, otherwise use detectedSourceName/relativePath
                    if ($normalizedBasePath) {
                        // Include basePath in relative_path to reflect actual file location
                        $fullRelativePath = rtrim($normalizedBasePath, '/') . '/' . $relativePath;
                    } else {
                        // No basePath, use detectedSourceName/relativePath
                        $fullRelativePath = $detectedSourceName . '/' . $relativePath;
                    }

                    $documentFiles[] = [
                        'source_name' => $detectedSourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $fullRelativePath,
                        'file_name' => basename($file),
                        'extension' => $extension,
                        'size' => $disk->size($file),
                        'last_modified' => $disk->lastModified($file),
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('[StorageService] 掃描文檔檔案失敗', [
                'storage_type' => $storageType,
                'source_name' => $sourceName,
                'base_path' => $basePath,
                'error' => $e->getMessage(),
            ]);
        }

        return $documentFiles;
    }

    /**
     * Find MP4 file in the same directory as the given file.
     *
     * @param string $storageType
     * @param string $filePath
     * @param string $relativePath
     * @return string|null
     */
    public function findMp4InSameDirectory(string $storageType, string $filePath, string $relativePath): ?string
    {
        try {
            $disk = $this->getDisk($storageType);
            
            // Get directory path from file path
            $fileDir = dirname($filePath);
            
            if (!$disk->exists($fileDir)) {
                return null;
            }
            
            // List all files in the same directory
            $files = $disk->files($fileDir);
            
            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ('mp4' === $extension) {
                    // Found MP4 file, build relative path
                    // Get directory from relativePath
                    $mp4Dir = dirname($relativePath);
                    // Return relative path with MP4 filename
                    return $mp4Dir . '/' . basename($file);
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('[StorageService] 在同資料夾中尋找 MP4 檔案失敗', [
                'storage_type' => $storageType,
                'file_path' => $filePath,
                'relative_path' => $relativePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

}

