<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Services\FetchServiceInterface;
use App\Services\StorageService;
use Illuminate\Support\Facades\Log;

class CnnFetchService implements FetchServiceInterface
{
    private StorageService $storageService;
    private array $config;

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
    }

    /**
     * Fetch resource list from CNN (scan S3 or Windows Server).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceList(): array
    {
        $storageType = $this->config['storage_type'] ?? 's3';
        $sourceName = 'CNN';

        // Scan for XML and MP4 files
        $xmlFiles = $this->storageService->scanXmlFiles($storageType, $sourceName, $this->config['s3_path'] ?? '');
        $videoFiles = $this->storageService->scanVideoFiles($storageType, $sourceName, $this->config['s3_path'] ?? '');

        // Combine and deduplicate by source_id
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

        Log::info('[CnnFetchService] 掃描到資源', [
            'count' => count($resources),
            'storage_type' => $storageType,
        ]);

        return $resources;
    }

    /**
     * Download single resource (for CNN, resources are already in storage).
     *
     * @param string $resourceId
     * @return array<string, mixed>|null
     */
    public function downloadResource(string $resourceId): ?array
    {
        // CNN resources are already in storage, just return the resource info
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
     * Save resources to storage (for CNN, mainly sync from Windows Server to S3 if needed).
     *
     * @param array<int, array<string, mixed>> $resources
     * @param string $storageType
     * @return bool
     */
    public function saveToStorage(array $resources, string $storageType): bool
    {
        // TODO: Implement Windows Server to S3 sync if needed
        // For now, resources are already in S3, so just return true
        Log::info('[CnnFetchService] 資源已存在於儲存空間', [
            'count' => count($resources),
            'storage_type' => $storageType,
        ]);

        return true;
    }

    /**
     * Sync resources from Windows Server to S3 (if configured).
     *
     * @return bool
     */
    public function syncFromWindowsServer(): bool
    {
        $syncEnabled = $this->config['sync_from_windows'] ?? false;
        $windowsPath = $this->config['windows_server_path'] ?? null;

        if (!$syncEnabled || null === $windowsPath) {
            Log::info('[CnnFetchService] Windows Server 同步未啟用或路徑未設定');
            return false;
        }

        // TODO: Implement Windows Server to S3 sync
        // This would involve:
        // 1. Connect to Windows Server (via SMB, FTP, or API)
        // 2. Scan for new files
        // 3. Upload to S3
        // 4. Track synced files

        Log::info('[CnnFetchService] Windows Server 同步功能待實作', [
            'windows_path' => $windowsPath,
        ]);

        return false;
    }
}

