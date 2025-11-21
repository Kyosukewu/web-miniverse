<?php

declare(strict_types=1);

namespace App\Services;

interface FetchServiceInterface
{
    /**
     * Fetch resource list from source.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchResourceList(): array;

    /**
     * Download single resource.
     *
     * @param string $resourceId
     * @return array<string, mixed>|null
     */
    public function downloadResource(string $resourceId): ?array;

    /**
     * Download multiple resources.
     *
     * @param array<string> $resourceIds
     * @return array<int, array<string, mixed>>
     */
    public function downloadResources(array $resourceIds): array;

    /**
     * Save resources to storage.
     *
     * @param array<int, array<string, mixed>> $resources
     * @param string $storageType
     * @return bool
     */
    public function saveToStorage(array $resources, string $storageType): bool;
}

