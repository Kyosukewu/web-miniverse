<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AnalysisResult;
use Illuminate\Support\Facades\DB;

class AnalysisResultRepository
{
    /**
     * Save or update analysis result.
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function save(array $data): bool
    {
        // Use Eloquent model to ensure proper JSON handling
        $analysisResult = AnalysisResult::updateOrCreate(
                ['video_id' => $data['video_id']],
                $data
            );

        return null !== $analysisResult;
    }

    /**
     * Get analysis result by video ID.
     *
     * @param int $videoId
     * @return AnalysisResult|null
     */
    public function getByVideoId(int $videoId): ?AnalysisResult
    {
        return AnalysisResult::find($videoId);
    }

    /**
     * Delete analysis result by video ID.
     *
     * @param int $videoId
     * @return bool
     */
    public function deleteByVideoId(int $videoId): bool
    {
        return AnalysisResult::where('video_id', $videoId)->delete() > 0;
    }
}

