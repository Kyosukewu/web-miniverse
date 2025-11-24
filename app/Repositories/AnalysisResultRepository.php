<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AnalysisResult;

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
        try {
            // Find existing record or create new instance
            $analysisResult = AnalysisResult::find($data['video_id']);
            
            if (null === $analysisResult) {
                // Create new instance
                $analysisResult = new AnalysisResult();
                $analysisResult->video_id = $data['video_id'];
            }
            
            // Fill all attributes (Eloquent will handle JSON casting automatically)
            $analysisResult->fill($data);
            
            // Save the model (this ensures $casts are properly applied)
            return $analysisResult->save();
        } catch (\Exception $e) {
            \Log::error('[AnalysisResultRepository] 儲存分析結果失敗', [
                'video_id' => $data['video_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

}

