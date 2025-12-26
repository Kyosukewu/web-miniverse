<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\AnalysisStatus;
use App\Enums\SyncStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

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
     * Get videos by IDs.
     *
     * @param array<int> $videoIds
     * @return Collection<int, Video>
     */
    public function getByIds(array $videoIds): Collection
    {
        if (empty($videoIds)) {
            return collect();
        }

        return Video::whereIn('id', $videoIds)->get();
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
        string $sortOrder = '',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): \Illuminate\Database\Eloquent\Builder {
        // 計算時間範圍：如果未指定，使用預設範圍（現在時間-14天 到 現在時間+7天）
        // 預設範圍使用 UTC+8 時區計算，然後轉換為 UTC 進行查詢
        if ('' === $publishedFrom || '' === $publishedTo) {
            // 使用 UTC+8 時區計算預設範圍（因為用戶看到的是 UTC+8 時間）
            $nowUtc8 = Carbon::now('Asia/Taipei');
            $defaultFrom = $nowUtc8->copy()->subDays(14)->startOfDay();
            $defaultTo = $nowUtc8->copy()->addDays(7)->endOfDay();
            
            // 如果用戶未指定，使用預設範圍（已經是 UTC+8 格式的日期字符串）
            if ('' === $publishedFrom) {
                $publishedFrom = $defaultFrom->format('Y-m-d');
            }
            if ('' === $publishedTo) {
                $publishedTo = $defaultTo->format('Y-m-d');
            }
        }

        $query = Video::with('analysisResult')
            ->where('analysis_status', AnalysisStatus::COMPLETED);

        // 應用時間範圍篩選
        // 注意：用戶輸入的日期是 UTC+8 時間，需要轉換為 UTC 時間進行查詢
        if ('' !== $publishedFrom) {
            // 將 UTC+8 的日期轉換為 UTC 時間
            // 例如：用戶輸入 2025-01-01 (UTC+8 的 00:00:00) = 2024-12-31 16:00:00 UTC
            $utcFrom = Carbon::parse($publishedFrom, 'Asia/Taipei')
                ->setTimezone('UTC')
                ->startOfDay();
            $query->where('published_at', '>=', $utcFrom);
        }
        if ('' !== $publishedTo) {
            // 將 UTC+8 的日期轉換為 UTC 時間
            // 例如：用戶輸入 2025-01-01 (UTC+8 的 23:59:59) = 2025-01-01 15:59:59 UTC
            $utcTo = Carbon::parse($publishedTo, 'Asia/Taipei')
                ->setTimezone('UTC')
                ->endOfDay();
            $query->where('published_at', '<=', $utcTo);
        }

        if ('' !== $searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('source_id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('shotlist_content', 'like', '%' . $searchTerm . '%')
                    ->orWhere('location', 'like', '%' . $searchTerm . '%');
            });
        }

        // Handle sorting
        if ('' !== $sortBy) {
            $order = ('asc' === strtolower($sortOrder)) ? 'asc' : 'desc';
            
            if ('importance' === $sortBy) {
                // Sort by importance_rating in analysis_results table
                // 使用 join 而不是 leftJoin，因為 completed 狀態的視頻應該都有 analysis_result
                // 添加 groupBy 避免重複記錄，並優化分頁查詢性能
                $query->join('analysis_results', 'videos.id', '=', 'analysis_results.video_id')
                      ->groupBy('videos.id')
                      ->orderBy('analysis_results.importance_rating', $order)
                      ->orderBy('videos.published_at', 'desc') // Secondary sort
                      ->select('videos.*');
                } else {
                // Map sortBy to actual database columns
                $dbColumn = match ($sortBy) {
                    'published_at' => 'published_at',
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
                // 使用 join 而不是 leftJoin，因為有 analysis_result 的視頻應該都有記錄
                // 添加 groupBy 避免重複記錄
                $query->join('analysis_results', 'videos.id', '=', 'analysis_results.video_id')
                      ->groupBy('videos.id')
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
     * @param array<int> $excludeIds 要排除的 Video ID 列表（已檢查過的記錄）
     * @return Collection<int, Video>
     */
    public function getPendingAnalysisVideos(string $sourceName, int $limit = 50, array $excludeIds = []): Collection
    {
        $query = Video::where('source_name', strtoupper($sourceName))
            ->whereIn('sync_status', ['updated', 'synced'])
            ->whereNotNull('xml_file_version')
            ->whereNotNull('mp4_file_version')
            // 排除檔案過大的影片（analysis_status = 'file_too_large'）
            ->where('analysis_status', '!=', AnalysisStatus::FILE_TOO_LARGE->value)
            ->where(function ($q) {
                // 排除檔案過大的影片（超過 Gemini API 限制 300MB）
                // 注意：如果 file_size_mb 已設定且 > 300，應該已經被標記為 file_too_large
                $q->whereNull('file_size_mb')
                  ->orWhere('file_size_mb', '<=', 300);
            });
        
        // 排除已檢查過的記錄
        if (!empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }
        
        return $query->orderBy('published_at', 'desc')
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
        string $publishedTo = '',
        bool $hideMissingFiles = false
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

        // 隱藏缺少 XML 或 MP4 檔案的記錄
        if ($hideMissingFiles) {
            $query->whereNotNull('xml_file_version')
                  ->whereNotNull('mp4_file_version');
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

    /**
     * 獲取狀態頁面的統計數據。
     *
     * @param string $searchTerm
     * @param string $sourceName
     * @param string $publishedFrom
     * @param string $publishedTo
     * @return array<string, int>
     */
    public function getStatusStatistics(
        string $searchTerm = '',
        string $sourceName = '',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): array {
        // 基礎查詢（應用搜尋、來源、時間範圍篩選）
        $baseQuery = Video::query();

        // 搜尋條件
        if ('' !== $searchTerm) {
            $baseQuery->where(function ($q) use ($searchTerm) {
                $q->where('id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('source_id', 'like', '%' . $searchTerm . '%')
                    ->orWhere('title', 'like', '%' . $searchTerm . '%');
            });
        }

        // 來源篩選
        if ('' !== $sourceName) {
            $baseQuery->where('source_name', strtoupper($sourceName));
        }

        // 發布時間範圍篩選
        if ('' !== $publishedFrom) {
            try {
                $fromDate = new \DateTime($publishedFrom);
                $baseQuery->where('published_at', '>=', $fromDate->format('Y-m-d 00:00:00'));
            } catch (\Exception $e) {
                // 忽略無效的日期格式
            }
        }

        if ('' !== $publishedTo) {
            try {
                $toDate = new \DateTime($publishedTo);
                $baseQuery->where('published_at', '<=', $toDate->format('Y-m-d 23:59:59'));
            } catch (\Exception $e) {
                // 忽略無效的日期格式
            }
        }

        // 總數
        $total = (clone $baseQuery)->count();

        // 缺少 XML
        $missingXml = (clone $baseQuery)->whereNull('xml_file_version')->count();

        // 缺少 MP4
        $missingMp4 = (clone $baseQuery)->whereNull('mp4_file_version')->count();

        // 檔案過大
        $fileTooLarge = (clone $baseQuery)->where('analysis_status', AnalysisStatus::FILE_TOO_LARGE->value)->count();

        // 待更新（sync_status = 'updated' 或 'synced'）
        $pendingUpdate = (clone $baseQuery)
            ->whereIn('sync_status', ['updated', 'synced'])
            ->whereNotNull('xml_file_version')
            ->whereNotNull('mp4_file_version')
            ->where('analysis_status', '!=', AnalysisStatus::FILE_TOO_LARGE->value)
            ->count();

        // 已完成（analysis_status = 'completed' 或 sync_status = 'parsed'）
        $completed = (clone $baseQuery)
            ->where(function ($q) {
                $q->where('analysis_status', AnalysisStatus::COMPLETED->value)
                  ->orWhere('sync_status', SyncStatus::PARSED->value);
            })
            ->count();

        return [
            'total' => $total,
            'missing_xml' => $missingXml,
            'missing_mp4' => $missingMp4,
            'file_too_large' => $fileTooLarge,
            'pending_update' => $pendingUpdate,
            'completed' => $completed,
        ];
    }
}

