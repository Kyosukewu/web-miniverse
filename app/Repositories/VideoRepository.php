<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\AnalysisStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection;

class VideoRepository
{
    /**
     * Find or create a video by source name and source ID.
     *
     * @param array<string, mixed> $attributes
     * @return int
     */
    public function findOrCreate(array $attributes): int
    {
        $video = Video::firstOrCreate(
            [
                'source_name' => $attributes['source_name'],
                'source_id' => $attributes['source_id'],
            ],
            $attributes
        );

        return $video->id;
    }

    /**
     * Get video by ID.
     *
     * @param int $videoId
     * @return Video|null
     */
    public function getById(int $videoId): ?Video
    {
        return Video::find($videoId);
    }

    /**
     * Get video by source name and source ID.
     *
     * @param string $sourceName
     * @param string $sourceId
     * @return Video|null
     */
    public function getBySourceId(string $sourceName, string $sourceId): ?Video
    {
        return Video::where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Get all videos with analysis results query builder.
     *
     * @param string $searchTerm
     * @param string $sortBy
     * @param string $sortOrder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getAllWithAnalysisQuery(
        string $searchTerm = '',
        string $sortBy = '',
        string $sortOrder = ''
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Video::with('analysisResult');

        if ('' !== $searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('shotlist_content', 'like', '%' . $searchTerm . '%')
                    ->orWhere('location', 'like', '%' . $searchTerm . '%');
            });
        }

        // Handle sorting
        if ('' !== $sortBy) {
            $order = ('asc' === strtolower($sortOrder)) ? 'asc' : 'desc';
            
            if ('importance' === $sortBy) {
                // Sort by importance_rating in analysis_results table
                $query->leftJoin('analysis_results', 'videos.id', '=', 'analysis_results.video_id')
                      ->orderBy('analysis_results.importance_rating', $order)
                      ->orderBy('videos.published_at', 'desc') // Secondary sort
                      ->select('videos.*');
            } else {
                // Map sortBy to actual database columns
                $dbColumn = match ($sortBy) {
                    'published_at' => 'published_at',
                    'fetched_at' => 'fetched_at',
                    'source_id' => 'source_id',
                    default => 'published_at',
                };
                
                $query->orderBy($dbColumn, $order);
            }
        } else {
            $query->orderBy('published_at', 'desc');
        }

        return $query;
    }

    /**
     * Get all videos with analysis results.
     *
     * @param int $limit
     * @param int $offset
     * @param string $searchTerm
     * @param string $sortBy
     * @param string $sortOrder
     * @return Collection<int, Video>
     */
    public function getAllWithAnalysis(
        int $limit = 100,
        int $offset = 0,
        string $searchTerm = '',
        string $sortBy = '',
        string $sortOrder = ''
    ): Collection {
        return $this->getAllWithAnalysisQuery($searchTerm, $sortBy, $sortOrder)
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * Get videos by IDs with analysis results.
     *
     * @param array<int> $ids
     * @param string $sortBy
     * @param string $sortOrder
     * @return Collection<int, Video>
     */
    public function getByIdsWithAnalysis(
        array $ids,
        string $sortBy = '',
        string $sortOrder = 'desc'
    ): Collection {
        if (empty($ids)) {
            return collect();
        }

        $query = Video::with('analysisResult')
            ->whereIn('id', $ids);

        // Handle sorting
        if ('' !== $sortBy) {
            $order = ('asc' === strtolower($sortOrder)) ? 'asc' : 'desc';
            
            if ('importance' === $sortBy) {
                // Sort by importance_rating in analysis_results table
                $query->leftJoin('analysis_results', 'videos.id', '=', 'analysis_results.video_id')
                      ->orderBy('analysis_results.importance_rating', $order)
                      ->orderBy('videos.published_at', 'desc') // Secondary sort
                      ->select('videos.*');
            } else {
                // Map sortBy to actual database columns
                $dbColumn = match ($sortBy) {
                    'published_at' => 'published_at',
                    'fetched_at' => 'fetched_at',
                    'source_id' => 'source_id',
                    default => 'published_at',
                };
                
                $query->orderBy($dbColumn, $order);
            }
        } else {
            $query->orderBy('published_at', 'desc');
        }

        return $query->get();
    }

    /**
     * Get videos that are not completed (analysis_status != COMPLETED).
     * Optionally include completed videos for sources that support version checking.
     *
     * @param string|null $sourceName Optional source name filter
     * @param int $limit
     * @param bool $includeCompletedForVersionCheck Include completed videos for version check (if source supports it)
     * @return Collection<int, Video>
     */
    public function getIncompleteVideos(?string $sourceName = null, int $limit = 100, bool $includeCompletedForVersionCheck = false): Collection
    {
        $query = Video::query();

        if (null !== $sourceName && '' !== $sourceName) {
            $sourceNameUpper = strtoupper($sourceName);
            $query->where('source_name', $sourceNameUpper);

            // Include completed videos for version check if requested
            if ($includeCompletedForVersionCheck) {
                $query->where(function ($q) {
                    $q->where('analysis_status', '!=', AnalysisStatus::COMPLETED)
                      ->orWhere(function ($subQ) {
                          $subQ->where('analysis_status', AnalysisStatus::COMPLETED)
                               ->where(function ($versionQ) {
                                   $versionQ->whereNotNull('xml_file_version')
                                           ->orWhereNotNull('mp4_file_version');
                               });
                      });
                });
            } else {
                $query->where('analysis_status', '!=', AnalysisStatus::COMPLETED);
            }
        } else {
            $query->where('analysis_status', '!=', AnalysisStatus::COMPLETED);
        }

        return $query->orderBy('fetched_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Update video analysis status.
     *
     * @param int $videoId
     * @param AnalysisStatus $status
     * @param \DateTime|null $analyzedAt
     * @return bool
     */
    public function updateAnalysisStatus(
        int $videoId,
        AnalysisStatus $status,
        ?\DateTime $analyzedAt = null
    ): bool {
        $updateData = [
            'analysis_status' => $status,
        ];

        if (null !== $analyzedAt) {
            $updateData['analyzed_at'] = $analyzedAt;
        }

        return Video::where('id', $videoId)
            ->update($updateData) > 0;
    }

    /**
     * Update video with metadata.
     *
     * @param int $videoId
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $videoId, array $data): bool
    {
        return Video::where('id', $videoId)->update($data) > 0;
    }
}

