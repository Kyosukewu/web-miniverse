<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnalysisStatus;
use App\Repositories\AnalysisResultRepository;
use App\Repositories\VideoRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyzeService
{
    private GeminiClient $geminiClient;
    private VideoRepository $videoRepository;
    private AnalysisResultRepository $analysisResultRepository;
    private PromptService $promptService;
    private string $nasVideoPath;

    /**
     * Create a new analyze service instance.
     *
     * @param GeminiClient $geminiClient
     * @param VideoRepository $videoRepository
     * @param AnalysisResultRepository $analysisResultRepository
     * @param PromptService $promptService
     * @param string $nasVideoPath
     */
    public function __construct(
        GeminiClient $geminiClient,
        VideoRepository $videoRepository,
        AnalysisResultRepository $analysisResultRepository,
        PromptService $promptService,
        string $nasVideoPath
    ) {
        $this->geminiClient = $geminiClient;
        $this->videoRepository = $videoRepository;
        $this->analysisResultRepository = $analysisResultRepository;
        $this->promptService = $promptService;
        $this->nasVideoPath = $nasVideoPath;
    }

    /**
     * Execute text analysis pipeline.
     *
     * @param string $textContent
     * @param string|null $promptVersion
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function executeTextAnalysis(string $textContent, ?string $promptVersion = null): array
    {
        Log::info('[AnalyzeService-TextPipeline] 開始執行文本元數據分析流程...');

        if ('' === trim($textContent)) {
            Log::warning('[AnalyzeService-TextPipeline] TXT 檔案內容為空，跳過 Gemini 分析。');
            return [];
        }

        // Get prompt from PromptService
        try {
            $prompt = $this->promptService->getTextFileAnalysisPrompt($promptVersion);
            $actualPromptVersion = $promptVersion ?? $this->promptService->getTextFileAnalysisCurrentVersion();
            Log::info('[AnalyzeService-TextPipeline] 使用 TextFileAnalysis Prompt 版本', [
                'version' => $actualPromptVersion,
            ]);
        } catch (\Exception $e) {
            Log::error('[AnalyzeService-TextPipeline] 讀取 Prompt 失敗', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $cleanedJsonString = $this->geminiClient->analyzeText($textContent, $prompt);

        if ('' === trim($cleanedJsonString)) {
            Log::warning('[AnalyzeService-TextPipeline] Gemini 對 TXT 檔案的分析回傳了空的或無效的 JSON 字串。');
            return [];
        }

        $parsedData = json_decode($cleanedJsonString, true);
        if (null === $parsedData) {
            Log::error('[AnalyzeService-TextPipeline] 無法將 TXT 分析回應解析為 JSON', [
                'cleaned_json' => $cleanedJsonString,
            ]);
            throw new \Exception('無法將 TXT 分析回應解析為 JSON');
        }

        Log::info('[AnalyzeService-TextPipeline] TXT 檔案 Gemini 分析並解析 JSON 成功。');

        // Store prompt version in parsed data for reference
        $parsedData['_prompt_version'] = $actualPromptVersion;

        return $parsedData;
    }

    /**
     * Execute video content analysis pipeline.
     *
     * @param int $videoId
     * @param string|null $promptVersion
     * @param string|null $videoFilePath Optional file path (if provided, will use this instead of building from nas_path)
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function executeVideoAnalysis(int $videoId, ?string $promptVersion = null, ?string $videoFilePath = null): array
    {
        Log::info('[AnalyzeService-VideoPipeline] 開始執行影片內容分析流程...', [
            'video_id' => $videoId,
        ]);

        $video = $this->videoRepository->getById($videoId);
        if (null === $video) {
            throw new \Exception('影片不存在: ' . $videoId);
        }

        // Build video path (use provided path or build from nas_path)
        if (null !== $videoFilePath) {
            $videoPath = $videoFilePath;
        } else {
            $videoPath = $this->nasVideoPath . '/' . $video->nas_path;
        }

        // Track if this is a temporary file (needs cleanup)
        $isTempFile = str_contains($videoPath, 'storage/app/temp/') || 
                     str_contains($videoPath, '/tmp/');

        Log::info('[AnalyzeService-VideoPipeline] 影片路徑', [
            'video_path' => $videoPath,
            'is_temp_file' => $isTempFile,
        ]);

        // Check if video file exists
        if (!file_exists($videoPath)) {
            $errorMsg = '影片檔案不存在: ' . $videoPath;
            Log::error('[AnalyzeService-VideoPipeline] ' . $errorMsg);
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                new \DateTime()
            );
            throw new \Exception($errorMsg);
        }

        // Update video status to processing
        $this->videoRepository->updateAnalysisStatus(
            $videoId,
            AnalysisStatus::PROCESSING,
            new \DateTime()
        );

        try {
            // Get prompt from PromptService
            try {
                $prompt = $this->promptService->getVideoAnalysisPrompt($promptVersion);
                $actualPromptVersion = $promptVersion ?? $this->promptService->getVideoAnalysisCurrentVersion();
                Log::info('[AnalyzeService-VideoPipeline] 使用 VideoAnalysis Prompt 版本', [
                    'version' => $actualPromptVersion,
                ]);
            } catch (\Exception $e) {
                Log::error('[AnalyzeService-VideoPipeline] 讀取 Prompt 失敗', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // Analyze video using Gemini API
            $analysis = $this->geminiClient->analyzeVideo($videoPath, $prompt);

            // Handle array response (AI may return array with single object)
            // Check if result is an array with numeric keys (indexed array)
            if (is_array($analysis) && isset($analysis[0]) && is_array($analysis[0])) {
                Log::warning('[AnalyzeService-VideoPipeline] AI 返回陣列格式，使用第一個元素', [
                    'video_id' => $videoId,
                ]);
                // Use first element if result is an array
                $analysis = $analysis[0];
            }

            Log::info('[AnalyzeService-VideoPipeline] 解析後的分析結果結構', [
                'video_id' => $videoId,
                'is_array' => is_array($analysis),
                'has_short_summary' => isset($analysis['short_summary']),
                'has_bulleted_summary' => isset($analysis['bulleted_summary']),
                'has_visual_description' => isset($analysis['visual_description']),
                'keys' => is_array($analysis) ? array_keys($analysis) : [],
            ]);

            // Check if analysis result is empty or invalid
            if (empty($analysis) || (!isset($analysis['short_summary']) && !isset($analysis['bulleted_summary']) && !isset($analysis['visual_description']))) {
                $errorMsg = 'Gemini API 回傳的分析結果為空或無效';
                Log::error('[AnalyzeService-VideoPipeline] ' . $errorMsg);

                // Save error analysis result
                $actualPromptVersion = $promptVersion ?? $this->promptService->getVideoAnalysisCurrentVersion();
                $this->analysisResultRepository->save([
                    'video_id' => $videoId,
                    'error_message' => $errorMsg,
                    'prompt_version' => $actualPromptVersion,
                ]);

                // Update video status to failed
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );

                throw new \Exception($errorMsg);
            }

            Log::info('[AnalyzeService-VideoPipeline] 影片 ID: ' . $videoId . ' 分析完成');

            // Save analysis result with prompt version
            $this->saveAnalysisResult($videoId, $analysis, $actualPromptVersion);

            return $analysis;
        } catch (\Exception $e) {
            $errorMsg = 'Gemini API 分析失敗: ' . $e->getMessage();
            Log::error('[AnalyzeService-VideoPipeline] ' . $errorMsg);

            // Save error analysis result
            $actualPromptVersion = $promptVersion ?? $this->promptService->getVideoAnalysisCurrentVersion();
            $this->analysisResultRepository->save([
                'video_id' => $videoId,
                'error_message' => $errorMsg,
                'prompt_version' => $actualPromptVersion,
            ]);

            // Update video status to failed
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                new \DateTime()
            );

            throw $e;
        } finally {
            // Clean up temporary file if it was downloaded from GCS/S3
            if ($isTempFile && file_exists($videoPath)) {
                try {
                    $fileSize = filesize($videoPath);
                    if (@unlink($videoPath)) {
                        Log::info('[AnalyzeService-VideoPipeline] 臨時檔案已清理', [
                            'path' => $videoPath,
                            'size_mb' => round($fileSize / 1024 / 1024, 2),
                        ]);
                    } else {
                        Log::warning('[AnalyzeService-VideoPipeline] 無法刪除臨時檔案', [
                            'path' => $videoPath,
                        ]);
                    }
                } catch (\Exception $cleanupException) {
                    Log::error('[AnalyzeService-VideoPipeline] 清理臨時檔案時發生錯誤', [
                        'path' => $videoPath,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Execute full analysis pipeline (text + video in one request).
     * This is an integrated approach that sends both document text and video to Gemini in a single API call.
     *
     * @param int $videoId
     * @param string $textContent Document text content (from XML/TXT)
     * @param string|null $promptVersion
     * @param string|null $videoFilePath Optional file path (if provided, will use this instead of building from nas_path)
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function executeFullAnalysis(int $videoId, string $textContent, ?string $promptVersion = null, ?string $videoFilePath = null): array
    {
        Log::info('[AnalyzeService-FullPipeline] 開始執行完整分析流程（文本+影片）...', [
            'video_id' => $videoId,
        ]);

        $video = $this->videoRepository->getById($videoId);
        if (null === $video) {
            throw new \Exception('影片不存在: ' . $videoId);
        }

        // Build video path (use provided path or build from nas_path)
        if (null !== $videoFilePath) {
            $videoPath = $videoFilePath;
        } else {
            $videoPath = $this->nasVideoPath . '/' . $video->nas_path;
        }

        // Track if this is a temporary file (needs cleanup)
        $isTempFile = str_contains($videoPath, 'storage/app/temp/') || 
                     str_contains($videoPath, '/tmp/');

        Log::info('[AnalyzeService-FullPipeline] 影片路徑', [
            'video_path' => $videoPath,
            'is_temp_file' => $isTempFile,
        ]);

        // Check if video file exists
        if (!file_exists($videoPath)) {
            $errorMsg = '影片檔案不存在: ' . $videoPath;
            Log::error('[AnalyzeService-FullPipeline] ' . $errorMsg);
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                new \DateTime()
            );
            throw new \Exception($errorMsg);
        }

        // Update video status to processing
        $this->videoRepository->updateAnalysisStatus(
            $videoId,
            AnalysisStatus::PROCESSING,
            new \DateTime()
        );

        try {
            // Get full analysis prompt from PromptService
            try {
                $prompt = $this->promptService->getFullAnalysisPrompt($promptVersion);
                $actualPromptVersion = $promptVersion ?? $this->promptService->getFullAnalysisCurrentVersion();
                Log::info('[AnalyzeService-FullPipeline] 使用 FullAnalysis Prompt 版本', [
                    'version' => $actualPromptVersion,
                ]);
            } catch (\Exception $e) {
                Log::error('[AnalyzeService-FullPipeline] 讀取 Prompt 失敗', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // Combine text content with prompt
            $fullPrompt = $prompt . "\n\n=== 原文新聞稿文本 ===\n" . $textContent;

            // Analyze using Gemini API (video + text)
            $analysis = $this->geminiClient->analyzeVideo($videoPath, $fullPrompt);

            // Handle array response (AI may return array with single object)
            if (is_array($analysis) && isset($analysis[0]) && is_array($analysis[0])) {
                Log::warning('[AnalyzeService-FullPipeline] AI 返回陣列格式，使用第一個元素', [
                    'video_id' => $videoId,
                ]);
                $analysis = $analysis[0];
            }

            Log::info('[AnalyzeService-FullPipeline] 解析後的分析結果結構', [
                'video_id' => $videoId,
                'is_array' => is_array($analysis),
                'has_document_metadata' => isset($analysis['document_metadata']),
                'has_video_analysis' => isset($analysis['video_analysis']),
                'keys' => is_array($analysis) ? array_keys($analysis) : [],
            ]);

            // Check if analysis result is valid
            if (empty($analysis) || (!isset($analysis['document_metadata']) && !isset($analysis['video_analysis']))) {
                $errorMsg = 'Gemini API 回傳的分析結果為空或無效';
                Log::error('[AnalyzeService-FullPipeline] ' . $errorMsg);

                // Save error analysis result
                $this->analysisResultRepository->save([
                    'video_id' => $videoId,
                    'error_message' => $errorMsg,
                    'prompt_version' => $actualPromptVersion,
                ]);

                // Update video status to failed
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );

                throw new \Exception($errorMsg);
            }

            Log::info('[AnalyzeService-FullPipeline] 影片 ID: ' . $videoId . ' 完整分析完成');

            // Store prompt version in analysis result
            $analysis['_prompt_version'] = $actualPromptVersion;

            // Save the complete analysis result
            $this->saveFullAnalysisResult($videoId, $analysis, $actualPromptVersion);

            return $analysis;
        } catch (\Exception $e) {
            $errorMsg = 'Gemini API 完整分析失敗: ' . $e->getMessage();
            Log::error('[AnalyzeService-FullPipeline] ' . $errorMsg);

            // Save error analysis result
            $actualPromptVersion = $promptVersion ?? $this->promptService->getFullAnalysisCurrentVersion();
            $this->analysisResultRepository->save([
                'video_id' => $videoId,
                'error_message' => $errorMsg,
                'prompt_version' => $actualPromptVersion,
            ]);

            // Update video status to failed
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                new \DateTime()
            );

            throw $e;
        } finally {
            // Clean up temporary file if it was downloaded from GCS/S3
            if ($isTempFile && file_exists($videoPath)) {
                try {
                    $fileSize = filesize($videoPath);
                    if (@unlink($videoPath)) {
                        Log::info('[AnalyzeService-FullPipeline] 臨時檔案已清理', [
                            'path' => $videoPath,
                            'size_mb' => round($fileSize / 1024 / 1024, 2),
                        ]);
                    } else {
                        Log::warning('[AnalyzeService-FullPipeline] 無法刪除臨時檔案', [
                            'path' => $videoPath,
                        ]);
                    }
                } catch (\Exception $cleanupException) {
                    Log::error('[AnalyzeService-FullPipeline] 清理臨時檔案時發生錯誤', [
                        'path' => $videoPath,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Save full analysis result (document metadata + video analysis) to database.
     *
     * @param int $videoId
     * @param array<string, mixed> $analysisData
     * @param string $promptVersion
     * @return bool
     */
    private function saveFullAnalysisResult(int $videoId, array $analysisData, string $promptVersion): bool
    {
        // Helper function to normalize JSON fields
        $normalizeJsonField = function ($value) {
            if (null === $value) {
                return null;
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (JSON_ERROR_NONE === json_last_error() && (is_array($decoded) || is_object($decoded))) {
                    return $decoded;
                }
                return $value;
            }
            if (is_array($value) || is_object($value)) {
                return $value;
            }
            return $value;
        };

        // Extract document metadata
        $documentMetadata = $analysisData['document_metadata'] ?? [];
        $videoAnalysis = $analysisData['video_analysis'] ?? [];

        // Update video record with document metadata
        $videoUpdateData = [];
        if (isset($documentMetadata['title'])) {
            $videoUpdateData['title'] = $documentMetadata['title'];
        }
        if (isset($documentMetadata['creation_date'])) {
            try {
                $dateTime = new \DateTime($documentMetadata['creation_date']);
                $videoUpdateData['published_at'] = $dateTime->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                Log::warning('[AnalyzeService-FullPipeline] 日期時間解析失敗', [
                    'date_string' => $documentMetadata['creation_date'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if (isset($documentMetadata['duration_seconds'])) {
            $videoUpdateData['duration_secs'] = (int) $documentMetadata['duration_seconds'];
        }
        if (isset($documentMetadata['subjects'])) {
            $videoUpdateData['subjects'] = json_encode($documentMetadata['subjects']);
        }
        if (isset($documentMetadata['location'])) {
            $videoUpdateData['location'] = $documentMetadata['location'];
        }
        if (isset($documentMetadata['restrictions'])) {
            $videoUpdateData['restrictions'] = $documentMetadata['restrictions'];
        }
        if (isset($documentMetadata['tran_restrictions'])) {
            $videoUpdateData['tran_restrictions'] = $documentMetadata['tran_restrictions'];
        }
        if (isset($documentMetadata['shotlist_content'])) {
            $videoUpdateData['shotlist_content'] = $documentMetadata['shotlist_content'];
        }

        // Update prompt version
        $videoUpdateData['prompt_version'] = $promptVersion;

        // Update video metadata
        if (!empty($videoUpdateData)) {
            $this->videoRepository->update($videoId, $videoUpdateData);
        }

        // Extract importance_rating from video_analysis
        $importanceRating = null;
        if (isset($videoAnalysis['importance_score']['overall_rating'])) {
            $overallRating = $videoAnalysis['importance_score']['overall_rating'];
            if (is_int($overallRating) && $overallRating >= 1 && $overallRating <= 5) {
                $importanceRating = $overallRating;
            }
        }

        // Save analysis result
        $analysisResultData = [
            'video_id' => $videoId,
            'prompt_version' => $promptVersion,
            'transcript' => $videoAnalysis['transcript'] ?? null,
            'translation' => $videoAnalysis['translation'] ?? null,
            'short_summary' => $videoAnalysis['short_summary'] ?? null,
            'bulleted_summary' => $videoAnalysis['bulleted_summary'] ?? null,
            'visual_description' => $videoAnalysis['visual_description'] ?? null,
            'bites' => $normalizeJsonField($videoAnalysis['bites'] ?? null),
            'mentioned_locations' => $normalizeJsonField($videoAnalysis['mentioned_locations'] ?? null),
            'importance_score' => $normalizeJsonField($videoAnalysis['importance_score'] ?? null),
            'importance_rating' => $importanceRating,
            'material_type' => $videoAnalysis['material_type'] ?? null,
            'related_news' => $normalizeJsonField($videoAnalysis['related_news'] ?? null),
            'topics' => $normalizeJsonField($videoAnalysis['topics'] ?? null),
            'keywords' => $normalizeJsonField($videoAnalysis['keywords'] ?? null),
            'error_message' => null,
        ];

        $saved = $this->analysisResultRepository->save($analysisResultData);

        if ($saved) {
            // Update video status to completed
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::COMPLETED,
                new \DateTime()
            );
        }

        return $saved;
    }

    /**
     * Save analysis result to database.
     *
     * @param int $videoId
     * @param array<string, mixed> $analysisData
     * @param string $promptVersion
     * @return bool
     */
    public function saveAnalysisResult(int $videoId, array $analysisData, string $promptVersion): bool
    {
        // Extract importance_rating from importance_score
        // Prompt now returns numeric rating (1-5) directly
        $importanceRating = null;
        if (isset($analysisData['importance_score']['overall_rating'])) {
            $overallRating = $analysisData['importance_score']['overall_rating'];
            // Validate and use numeric rating (1-5)
            if (is_int($overallRating) && $overallRating >= 1 && $overallRating <= 5) {
                $importanceRating = $overallRating;
            }
        }

        // Helper function to normalize JSON fields for Eloquent
        // Eloquent will automatically handle JSON encoding/decoding based on $casts
        $normalizeJsonField = function ($value) {
            if (null === $value) {
                return null;
            }
            // If already a JSON string, decode it to array so Eloquent can handle it
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (JSON_ERROR_NONE === json_last_error() && (is_array($decoded) || is_object($decoded))) {
                    // It's valid JSON, return decoded array/object
                    return $decoded;
                }
                // Not valid JSON, return as is (for text fields)
                return $value;
            }
            // If array or object, return as is (Eloquent will encode it)
            if (is_array($value) || is_object($value)) {
                return $value;
            }
            // For other types, return as is
            return $value;
        };

        $data = [
            'video_id' => $videoId,
            'prompt_version' => $promptVersion,
            'transcript' => $analysisData['transcript'] ?? null,
            'translation' => $analysisData['translation'] ?? null,
            'short_summary' => $analysisData['short_summary'] ?? null,
            'bulleted_summary' => $analysisData['bulleted_summary'] ?? null,
            'visual_description' => $analysisData['visual_description'] ?? null,
            'bites' => $normalizeJsonField($analysisData['bites'] ?? null),
            'mentioned_locations' => $normalizeJsonField($analysisData['mentioned_locations'] ?? null),
            'importance_score' => $normalizeJsonField($analysisData['importance_score'] ?? null),
            'importance_rating' => $importanceRating,
            'material_type' => $analysisData['material_type'] ?? null,
            'related_news' => $normalizeJsonField($analysisData['related_news'] ?? null),
            'topics' => $normalizeJsonField($analysisData['topics'] ?? null),
            'keywords' => $normalizeJsonField($analysisData['keywords'] ?? null),
            'error_message' => null, // Clear error message on successful analysis
        ];

        $saved = $this->analysisResultRepository->save($data);

        if ($saved) {
            // Update video status to completed
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::COMPLETED,
                new \DateTime()
            );
        }

        return $saved;
    }
}

