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
     * Get videos by source name and multiple source IDs (batch query for optimization).
     *
     * @param string $sourceName
     * @param array<string> $sourceIds
     * @return Collection<int, Video>
     */
    public function getBySourceIds(string $sourceName, array $sourceIds): Collection
    {
        if (empty($sourceIds)) {
            return collect();
        }

        return Video::where('source_name', $sourceName)
            ->whereIn('source_id', $sourceIds)
            ->get();
    }

    /**
     * Get all videos with analysis results query builder.
     * Only returns videos that have been parsed (sync_status = 'parsed').
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
        $query = Video::with('analysisResult')
            ->where('analysis_status', AnalysisStatus::COMPLETED);

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
     * Excludes videos that exceed Gemini API file size limit (300MB).
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

        // 排除檔案過大的影片（超過 Gemini API 限制 300MB）
        // 如果 file_size_mb 為 null，表示尚未檢查過，仍然包含在結果中
        $query->where(function ($q) {
            $q->whereNull('file_size_mb')
              ->orWhere('file_size_mb', '<=', 300);
        });

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

    /**
     * Delete a video and its related records.
     *
     * @param int $videoId
     * @return bool
     */
    public function delete(int $videoId): bool
    {
        $video = Video::find($videoId);
        
        if (null === $video) {
            return false;
        }

        // Delete will cascade to analysis_results due to foreign key constraint
        return $video->delete();
    }

    /**
     * Get videos that are ready for analysis (sync_status = 'updated' or 'synced').
     * These are videos that have been synced to GCS but not yet analyzed.
     *
     * @param string $sourceName
     * @param int $limit
     * @return Collection<int, Video>
     */
    public function getPendingAnalysisVideos(string $sourceName, int $limit = 50): Collection
    {
        return Video::where('source_name', strtoupper($sourceName))
            ->whereIn('sync_status', ['updated', 'synced'])
            ->where(function ($query) {
                // 排除檔案過大的影片（超過 Gemini API 限制 300MB）
                $query->whereNull('file_size_mb')
                      ->orWhere('file_size_mb', '<=', 300);
            })
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get all videos query builder for status page.
     *
     * @param string $searchTerm
     * @param string $sourceName
     * @param string $sortBy
     * @param string $sortOrder
     * @param string $publishedFrom
     * @param string $publishedTo
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getAllVideosQuery(
        string $searchTerm = '',
        string $sourceName = '',
        string $sortBy = 'id',
        string $sortOrder = 'desc',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Video::query();

        // 搜尋條件
        if ('' !== $searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('source_id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('title', 'like', '%' . $searchTerm . '%');
            });
        }

        // 來源篩選
        if ('' !== $sourceName) {
            $query->where('source_name', strtoupper($sourceName));
        }

        // 發布時間範圍篩選
        if ('' !== $publishedFrom) {
            try {
                $fromDate = new \DateTime($publishedFrom);
                $query->where('published_at', '>=', $fromDate->format('Y-m-d 00:00:00'));
            } catch (\Exception $e) {
                // 忽略無效的日期格式
            }
        }

        if ('' !== $publishedTo) {
            try {
                $toDate = new \DateTime($publishedTo);
                $query->where('published_at', '<=', $toDate->format('Y-m-d 23:59:59'));
            } catch (\Exception $e) {
                // 忽略無效的日期格式
            }
        }

        // 排序
        $order = ('asc' === strtolower($sortOrder)) ? 'asc' : 'desc';
        $dbColumn = match ($sortBy) {
            'id' => 'id',
            'source_name' => 'source_name',
            'source_id' => 'source_id',
            'title' => 'title',
            'fetched_at' => 'fetched_at',
            'published_at' => 'published_at',
            'analysis_status' => 'analysis_status',
            'sync_status' => 'sync_status',
            default => 'id',
        };
        
        $query->orderBy($dbColumn, $order);

        return $query;
    }
}

