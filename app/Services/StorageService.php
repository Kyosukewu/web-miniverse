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
            'gcs' => 'gcs',  // Google Cloud Storage
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

                    $fileName = basename($file);
                    $fileVersion = $this->extractFileVersion($fileName);

                    $videoFiles[$fileKey] = [
                        'source_name' => $detectedSourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $fullRelativePath,
                        'file_name' => $fileName,
                        'extension' => $extension,
                        'is_proxy' => $isProxy,
                        'file_version' => $fileVersion,
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
     * Priority: Unique Identifier (CNNA-ST1-xxxxxxxxxxxxxxxx) > Directory Name > Filename Pattern
     *
     * @param string $fileName
     * @param array<string> $pathParts
     * @return string
     */
    private function extractSourceIdFromFileName(string $fileName, array $pathParts): string
    {
        // First, try to extract unique identifier (CNNA-ST1-xxxxxxxxxxxxxxxx)
        if (preg_match('/CNNA-ST1-(\d{16})/', $fileName, $matches)) {
            return 'CNNA-ST1-' . $matches[1];
        }

        // If file is in a subdirectory, use directory name as source_id
        // Directory name should be the unique identifier
        if (!empty($pathParts) && count($pathParts) > 1) {
            $dirName = $pathParts[count($pathParts) - 2]; // Second to last part (directory name)
            // Check if directory name is a unique identifier
            if (preg_match('/^CNNA-ST1-\d{16}$/', $dirName)) {
                return $dirName;
            }
            // Fallback to directory name
            return $dirName;
        }

        // Try to extract story ID pattern (e.g., MW-006TH)
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
        return pathinfo($fileName, PATHINFO_FILENAME);
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
     * Extract file version number from filename.
     * CNN format: ..._CNNA-ST1-xxxxxxxxxxxxxxxx_174_0.mp4
     * Version is the last number before extension (e.g., _0 -> 0, _1 -> 1, _2 -> 2)
     *
     * @param string $fileName
     * @return int|null Returns version number (0, 1, 2, etc.) or null if not found
     */
    public function extractFileVersion(string $fileName): ?int
    {
        // Pattern: _數字.副檔名 (例如: _0.mp4, _1.xml)
        if (preg_match('/_(\d+)\.\w+$/', $fileName, $matches)) {
            return (int) $matches[1];
        }

        return null;
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

            // Scan recursively for XML files
            $files = $disk->allFiles($sourcePath);

            foreach ($files as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if ('xml' === $extension) {
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

                    // Extract source_id: prefer directory name (unique identifier) over filename
                    if (count($pathParts) > 1) {
                        // File is in a subdirectory, use directory name as source_id
                        // Directory name should be the unique identifier (CNNA-ST1-xxxxxxxxxxxxxxxx)
                        $sourceId = $pathParts[count($pathParts) - 2]; // Second to last part (directory name)
                    } else {
                        // File is directly in source path, extract from filename
                        $fileName = pathinfo($relativePath, PATHINFO_FILENAME);
                        // Try to extract unique identifier first
                        if (preg_match('/CNNA-ST1-(\d{16})/', $fileName, $matches)) {
                            $sourceId = 'CNNA-ST1-' . $matches[1];
                        } else {
                            $sourceId = $fileName;
                        }
                    }

                    // Build relative_path: include basePath if provided, otherwise use detectedSourceName/relativePath
                    if ($normalizedBasePath) {
                        // Include basePath in relative_path to reflect actual file location
                        $fullRelativePath = rtrim($normalizedBasePath, '/') . '/' . $relativePath;
                    } else {
                        // No basePath, use detectedSourceName/relativePath
                        $fullRelativePath = $detectedSourceName . '/' . $relativePath;
                    }

                    $fileName = basename($file);
                    $fileVersion = $this->extractFileVersion($fileName);

                    $xmlFiles[] = [
                        'source_name' => $detectedSourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $fullRelativePath,
                        'file_name' => $fileName,
                        'file_version' => $fileVersion,
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

                    $fileName = basename($file);
                    $fileVersion = $this->extractFileVersion($fileName);

                    $documentFiles[] = [
                        'source_name' => $detectedSourceName,
                        'source_id' => $sourceId,
                        'file_path' => $file,
                        'relative_path' => $fullRelativePath,
                        'file_name' => $fileName,
                        'extension' => $extension,
                        'file_version' => $fileVersion,
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

        // For GCS, download to temporary location
        if ('gcs' === $storageType) {
            return $this->downloadGcsFileToTemp($filePath);
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
     * Download GCS file to temporary location for analysis.
     *
     * @param string $filePath
     * @return string|null
     */
    private function downloadGcsFileToTemp(string $filePath): ?string
    {
        try {
            $disk = $this->getDisk('gcs');

            if (!$disk->exists($filePath)) {
                Log::warning('[StorageService] GCS 檔案不存在', [
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
            $tempPath = $tempDir . '/' . uniqid('gcs_', true) . '_' . $fileName;

            // Download from GCS
            $contents = $disk->get($filePath);
            file_put_contents($tempPath, $contents);

            Log::info('[StorageService] GCS 檔案下載到臨時位置', [
                'gcs_path' => $filePath,
                'temp_path' => $tempPath,
            ]);

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('[StorageService] GCS 檔案下載失敗', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate URL for GCS file.
     * Uses configured GCS domain if available, otherwise falls back to Storage::url().
     *
     * @param string $filePath File path in GCS (relative to bucket root)
     * @param string|null $sourceName Optional source name to get source-specific domain
     * @return string|null
     */
    public function getGcsUrl(string $filePath, ?string $sourceName = null): ?string
    {
        try {
            // If source name is provided, check for source-specific domain
            if (null !== $sourceName) {
                $sourceConfig = config("sources.{$sourceName}");
                if (isset($sourceConfig['gcs_domain']) && !empty($sourceConfig['gcs_domain'])) {
                    $domain = rtrim($sourceConfig['gcs_domain'], '/');
                    $path = ltrim($filePath, '/');
                    return "{$domain}/{$path}";
                }
            }

            // Fallback to filesystem config
            $gcsConfig = config('filesystems.disks.gcs');
            if (isset($gcsConfig['url']) && !empty($gcsConfig['url'])) {
                $domain = rtrim($gcsConfig['url'], '/');
                $path = ltrim($filePath, '/');
                return "{$domain}/{$path}";
            }

            // Last resort: use Storage facade (may generate default GCS URL)
            $disk = $this->getDisk('gcs');
            return $disk->url($filePath);
        } catch (\Exception $e) {
            Log::error('[StorageService] 生成 GCS URL 失敗', [
                'file_path' => $filePath,
                'source_name' => $sourceName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

}

