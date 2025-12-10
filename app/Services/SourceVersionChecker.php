<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;

/**
 * Service to check if files need reanalysis based on version rules for different sources.
 */
class SourceVersionChecker
{
    private StorageService $storageService;

    public function __construct(StorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Check if a file needs reanalysis based on source-specific version rules.
     *
     * @param string $sourceName Source name (CNN, AP, RT, etc.)
     * @param Video|null $existingVideo Existing video record
     * @param int|null $currentFileVersion Current file version number extracted from filename (0, 1, 2, etc.)
     * @param string|null $filePath File path (for extracting version if not provided)
     * @param string $fileType File type: 'xml' or 'mp4'
     * @return array{should_reanalyze: bool, reason: string|null, new_version: int|null}
     */
    public function shouldReanalyze(
        string $sourceName,
        ?Video $existingVideo,
        ?int $currentFileVersion = null,
        ?string $filePath = null,
        string $fileType = 'xml'
    ): array {
        $sourceNameUpper = strtoupper($sourceName);
        $sourceConfig = config("sources." . strtolower($sourceName), []);

        // Check if version checking is enabled for this source
        $versionCheckEnabled = $sourceConfig['version_check_enabled'] ?? false;

        if (!$versionCheckEnabled) {
            // Version checking not enabled for this source
            return [
                'should_reanalyze' => false,
                'reason' => null,
                'new_version' => null,
            ];
        }

        // If no existing video, no need to check version
        if (null === $existingVideo) {
            return [
                'should_reanalyze' => false,
                'reason' => null,
                'new_version' => $currentFileVersion ?? 0,
            ];
        }

        // Extract current version if not provided
        if (null === $currentFileVersion && null !== $filePath) {
            $fileName = basename($filePath);
            $currentFileVersion = $this->storageService->extractFileVersion($fileName) ?? 0;
        }

        // Default to 0 if version not found
        if (null === $currentFileVersion) {
            $currentFileVersion = 0;
        }

        // Get existing version based on file type
        // Only use xml_file_version/mp4_file_version for sources that support version checking (e.g., CNN)
        // For other sources, these fields should remain 0 or null
        $existingVersion = null;
        if ($versionCheckEnabled) {
            // Only read version fields if version checking is enabled for this source
            if ('xml' === $fileType) {
                $existingVersion = $existingVideo->xml_file_version ?? 0;
            } elseif ('mp4' === $fileType) {
                $existingVersion = $existingVideo->mp4_file_version ?? 0;
            }
        } else {
            // For sources without version checking, always return 0
            $existingVersion = 0;
        }

        // Compare versions
        if ($existingVersion !== $currentFileVersion) {
            Log::info('[SourceVersionChecker] 檔案版本已更新', [
                'source' => $sourceNameUpper,
                'source_id' => $existingVideo->source_id,
                'file_type' => $fileType,
                'old_version' => $existingVersion,
                'new_version' => $currentFileVersion,
            ]);

            return [
                'should_reanalyze' => true,
                'reason' => "{$fileType} 檔案版本已更新 ({$existingVersion} -> {$currentFileVersion})",
                'new_version' => $currentFileVersion,
            ];
        }

        return [
            'should_reanalyze' => false,
            'reason' => '檔案版本未變更',
            'new_version' => $currentFileVersion,
        ];
    }

    /**
     * Check if completed videos should be included for version check.
     *
     * @param string $sourceName Source name
     * @return bool
     */
    public function shouldIncludeCompletedForVersionCheck(string $sourceName): bool
    {
        $sourceConfig = config("sources." . strtolower($sourceName), []);
        return $sourceConfig['version_check_enabled'] ?? false;
    }
}

