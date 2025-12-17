<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Services\FetchServiceInterface;
use App\Services\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RtFetchService implements FetchServiceInterface
{
    private StorageService $storageService;
    private array $config;

    /**
     * Create a new RT fetch service instance.
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
     * Fetch resource list from RT API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceList(): array
    {
        $apiUrl = $this->config['api_url'] ?? '';
        $apiKey = $this->config['api_key'] ?? '';

        if ('' === $apiUrl || '' === $apiKey) {
            Log::error('[RtFetchService] API 配置不完整', [
                'api_url' => $apiUrl,
                'has_api_key' => '' !== $apiKey,
            ]);
            return [];
        }

        try {
            // TODO: Implement actual RT API call
            // This is a placeholder structure
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->get($apiUrl . '/resources');

            if (!$response->successful()) {
                Log::error('[RtFetchService] API 請求失敗', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $resources = [];

            // TODO: Parse RT API response format
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $resources[] = [
                        'source_id' => $item['id'] ?? '',
                        'source_name' => 'RT',
                        'type' => $item['type'] ?? 'unknown',
                        'url' => $item['url'] ?? '',
                        'metadata' => $item['metadata'] ?? [],
                    ];
                }
            }

            Log::info('[RtFetchService] 取得資源列表', [
                'count' => count($resources),
            ]);

            return $resources;
        } catch (\Exception $e) {
            Log::error('[RtFetchService] 取得資源列表失敗', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Download single resource from RT API.
     *
     * @param string $resourceId
     * @return array<string, mixed>|null
     */
    public function downloadResource(string $resourceId): ?array
    {
        $apiUrl = $this->config['api_url'] ?? '';
        $apiKey = $this->config['api_key'] ?? '';

        try {
            // TODO: Implement actual RT API download call
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->get($apiUrl . '/resources/' . $resourceId);

            if (!$response->successful()) {
                Log::error('[RtFetchService] 下載資源失敗', [
                    'resource_id' => $resourceId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            return [
                'source_id' => $resourceId,
                'source_name' => 'RT',
                'content' => $data['content'] ?? '',
                'metadata' => $data['metadata'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('[RtFetchService] 下載資源失敗', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Download multiple resources.
     *
     * @param array<string> $resourceIds
     * @return array<int, array<string, mixed>>
     */
    public function downloadResources(array $resourceIds): array
    {
        $resources = [];

        foreach ($resourceIds as $resourceId) {
            $resource = $this->downloadResource($resourceId);
            if (null !== $resource) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * Save resources to storage (S3).
     *
     * @param array<int, array<string, mixed>> $resources
     * @param string $storageType
     * @return bool
     */
    public function saveToStorage(array $resources, string $storageType): bool
    {
        $disk = $this->storageService->getDisk($storageType);
        $basePath = $this->config['s3_path'] ?? 'rt/';
        $successCount = 0;

        foreach ($resources as $resource) {
            try {
                $sourceId = $resource['source_id'];
                $filePath = rtrim($basePath, '/') . '/' . $sourceId;

                // Save XML if exists
                if (isset($resource['xml_content'])) {
                    $xmlPath = $filePath . '.xml';
                    $disk->put($xmlPath, $resource['xml_content']);
                    Log::info('[RtFetchService] 儲存 XML', ['path' => $xmlPath]);
                }

                // Save MP4 if exists
                if (isset($resource['video_url'])) {
                    // TODO: Download video from URL and save to S3
                    $videoPath = $filePath . '.mp4';
                    Log::info('[RtFetchService] 影片下載待實作', ['url' => $resource['video_url']]);
                }

                $successCount++;
            } catch (\Exception $e) {
                Log::error('[RtFetchService] 儲存資源失敗', [
                    'resource_id' => $resource['source_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[RtFetchService] 儲存資源完成', [
            'success' => $successCount,
            'total' => count($resources),
        ]);

        return $successCount > 0;
    }
}

