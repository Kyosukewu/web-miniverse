<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DashboardHelper;
use App\Repositories\VideoRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    public function __construct(
        private VideoRepository $videoRepository
    ) {
    }

    /**
     * Get query parameters from request.
     *
     * @param Request $request
     * @return array{searchTerm: string, sortBy: string, sortOrder: string}
     */
    public function getQueryParameters(Request $request): array
    {
        return [
            'searchTerm' => $request->query('search', ''),
            'sortBy' => $this->normalizeSortBy($request->query('sortBy', 'importance')),
            'sortOrder' => $this->normalizeSortOrder($request->query('sortOrder', 'desc')),
        ];
    }

    /**
     * Get paginated videos with filters and sorting.
     *
     * @param Request $request
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedVideos(Request $request, int $perPage = 50): LengthAwarePaginator
    {
        $params = $this->getQueryParameters($request);
        $searchTerm = $params['searchTerm'];
        $sortBy = $params['sortBy'];
        $sortOrder = $params['sortOrder'];

        try {
            // Get videos query builder
            $query = $this->videoRepository->getAllWithAnalysisQuery(
                $searchTerm,
                $sortBy,
                $sortOrder
            );

            // Use Laravel pagination
            $videos = $query->paginate($perPage)->withQueryString();

            // Transform videos for display
            $displayData = $this->transformVideosForDisplay($videos->getCollection());
            
            // Create paginator with transformed data
            $paginatedVideos = new LengthAwarePaginator(
                collect($displayData),
                $videos->total(),
                $videos->perPage(),
                $videos->currentPage(),
                [
                    'path' => $request->url(),
                    'pageName' => 'page',
                ]
            );
            
            // Preserve query string parameters
            $paginatedVideos->appends($request->query());

            return $paginatedVideos;
        } catch (\Exception $e) {
            Log::error('[DashboardService] 資料庫查詢失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'searchTerm' => $searchTerm,
                'sortBy' => $sortBy,
                'sortOrder' => $sortOrder,
            ]);

            return $this->createEmptyPaginator($request, $perPage);
        }
    }

    /**
     * Create empty paginator for error cases.
     *
     * @param Request $request
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    private function createEmptyPaginator(Request $request, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            collect([]),
            0,
            $perPage,
            1,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Normalize sort by parameter.
     *
     * @param string $sortBy
     * @return string
     */
    private function normalizeSortBy(string $sortBy): string
    {
        if ('' === $sortBy) {
            return 'importance';
        }

        return $sortBy;
    }

    /**
     * Normalize sort order parameter.
     *
     * @param string $sortOrder
     * @return string
     */
    private function normalizeSortOrder(string $sortOrder): string
    {
        if ('' === $sortOrder) {
            return 'desc';
        }

        return $sortOrder;
    }

    /**
     * Transform videos for display.
     *
     * @param Collection $videos
     * @return array<int, array<string, mixed>>
     */
    public function transformVideosForDisplay(Collection $videos): array
    {
        return $videos->map(function ($video) {
            $result = $this->transformSingleVideoForApi($video);
            // Include video model for Blade view
            $result['video'] = $video;
            return $result;
        })->toArray();
    }

    /**
     * Transform a single video for API/display.
     * This is the core transformation logic shared by both Blade and API.
     *
     * @param mixed $video
     * @return array<string, mixed>
     */
    public function transformSingleVideoForApi($video): array
    {
        $analysisResult = $video->analysisResult;
        $importanceScore = $this->parseImportanceScore($analysisResult);
        $duration = $this->formatDuration($video->duration_secs);
        $primarySubjects = $this->parseJsonField($video->subjects, []);
        $consolidatedCategories = $this->buildConsolidatedCategories($primarySubjects, $analysisResult);
        $analysisData = $this->parseAnalysisResultData($analysisResult);
        $processedShotlistContent = $this->getProcessedShotlistContent($video->shotlist_content);

        return [
            'source_name' => $video->source_name,
            'source_id' => $video->source_id,
            'combined_source_id' => strtoupper($video->source_name) . $video->source_id,
            'nas_path' => $video->nas_path,
            'title' => $video->title,
            'analysis_status' => $video->analysis_status->value,
            'formatted_duration_minutes' => $duration['minutes'],
            'formatted_duration_seconds' => $duration['seconds'],
            'primary_subjects' => $primarySubjects,
            'flag_emoji' => DashboardHelper::getFlagForLocation($video->location),
            'video_url' => '/media/' . $video->nas_path,
            'prompt_version' => $video->prompt_version,
            'restrictions' => $video->restrictions,
            'tran_restrictions' => $video->tran_restrictions,
            'shotlist_content_processed' => $processedShotlistContent,
            'analysis_result' => null !== $analysisResult ? array_merge($analysisData, [
                'importance_score' => $importanceScore,
                'consolidated_categories' => $consolidatedCategories,
                'transcript' => $analysisResult->transcript,
                'translation' => $analysisResult->translation,
                'short_summary' => $analysisResult->short_summary,
                'bulleted_summary' => $analysisResult->bulleted_summary,
                'visual_description' => $analysisResult->visual_description,
                'material_type' => $analysisResult->material_type,
                'overall_rating' => $analysisResult->overall_rating,
                'overall_rating_letter' => $analysisResult->overall_rating_letter,
                'importance_rating' => $analysisResult->importance_rating,
                'error_message' => $analysisResult->error_message,
                'prompt_version' => $analysisResult->prompt_version,
                'analysis_created_at' => $analysisResult->created_at,
            ]) : null,
        ];
    }

    /**
     * Parse importance score from analysis result.
     *
     * @param mixed $analysisResult
     * @return array<string, mixed>|null
     */
    private function parseImportanceScore($analysisResult): ?array
    {
        if (null === $analysisResult || null === $analysisResult->importance_score) {
            return null;
        }

        return $this->parseJsonField($analysisResult->importance_score, null);
    }

    /**
     * Format duration in seconds to minutes and seconds.
     *
     * @param int|null $durationSecs
     * @return array{minutes: int, seconds: int}
     */
    private function formatDuration(?int $durationSecs): array
    {
        if (null === $durationSecs) {
            return ['minutes' => 0, 'seconds' => 0];
        }

        return [
            'minutes' => (int) ($durationSecs / 60),
            'seconds' => $durationSecs % 60,
        ];
    }

    /**
     * Build consolidated categories from subjects and topics.
     *
     * @param array<int, string> $primarySubjects
     * @param mixed $analysisResult
     * @return array<int, string>
     */
    private function buildConsolidatedCategories(array $primarySubjects, $analysisResult): array
    {
        $categories = $primarySubjects;

        if (null !== $analysisResult && null !== $analysisResult->topics) {
            $topics = $this->parseJsonField($analysisResult->topics, []);
            if (is_array($topics) && count($topics) > 0) {
                $categories = array_unique(array_merge($categories, $topics));
                sort($categories);
            }
        }

        return $categories;
    }

    /**
     * Parse analysis result data fields.
     *
     * @param mixed $analysisResult
     * @return array<string, mixed>
     */
    private function parseAnalysisResultData($analysisResult): array
    {
        if (null === $analysisResult) {
            return [
                'video_mentioned_locations' => [],
                'keywords' => [],
                'bites' => [],
                'related_news' => [],
            ];
        }

        return [
            'video_mentioned_locations' => $this->parseJsonField($analysisResult->mentioned_locations, []),
            'keywords' => $this->parseJsonField($analysisResult->keywords, []),
            'bites' => $this->parseJsonField($analysisResult->bites, []),
            'related_news' => $this->parseJsonField($analysisResult->related_news, []),
        ];
    }

    /**
     * Parse JSON field, handling both string and array formats.
     *
     * @param mixed $field
     * @param mixed $default
     * @return mixed
     */
    private function parseJsonField($field, $default)
    {
        if (null === $field) {
            return $default;
        }

        $parsed = is_string($field) ? json_decode($field, true) : $field;

        return is_array($parsed) ? $parsed : $default;
    }

    /**
     * Get processed shotlist content.
     *
     * @param string|null $shotlistContent
     * @return string|null
     */
    private function getProcessedShotlistContent(?string $shotlistContent): ?string
    {
        if (null === $shotlistContent || '' === $shotlistContent) {
            return null;
        }

        return $this->processShotlistContent($shotlistContent);
    }

    /**
     * Process shotlist content for display and copy.
     * Handles HTML entity decoding and newline conversion.
     * Returns the processed content (decoded, ready for display).
     * Blade will handle HTML escaping automatically.
     *
     * @param string $shotlistContent
     * @return string|null
     */
    private function processShotlistContent(string $shotlistContent): ?string
    {
        if ('' === $shotlistContent) {
            return null;
        }

        // 1. 先手動處理常見的 HTML 實體（html_entity_decode 可能無法處理所有情況）
        // 處理所有可能的 HTML 實體編碼形式
        $processed = str_replace(
            ['&apos;', '&#039;', '&#39;', '&#x27;', '&#X27;', '&quot;', '&#034;', '&#34;', '&#x22;', '&#X22;', '&amp;', '&#038;', '&#38;', '&#x26;', '&#X26;'],
            ["'", "'", "'", "'", "'", '"', '"', '"', '"', '"', '&', '&', '&', '&', '&'],
            $shotlistContent
        );

        // 2. 解碼所有其他 HTML 實體字符（多次解碼以處理嵌套編碼）
        $processed = html_entity_decode($processed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $processed = html_entity_decode($processed, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // 二次解碼以防嵌套

        // 3. 將字面量 \n 轉換為實際換行符
        $processed = str_replace(['\\n', "\r\n", "\r"], ["\n", "\n", "\n"], $processed);

        // 4. 返回處理後的原始內容
        // Blade 的 {{ }} 會自動進行 HTML 轉義，所以我們不需要在這裡轉義
        // 這樣可以避免雙重轉義的問題
        return $processed;
    }

    /**
     * Get export parameters from request.
     *
     * @param Request $request
     * @return array{selectedIds: array<int>, searchTerm: string, sortBy: string, sortOrder: string}
     */
    public function getExportParameters(Request $request): array
    {
        // Get selected IDs from query parameter
        $idsParam = $request->query('ids', '');
        $selectedIds = [];
        
        if ('' !== $idsParam) {
            $selectedIds = array_filter(
                array_map('intval', explode(',', $idsParam)),
                fn($id) => $id > 0
            );
        }

        // Get query parameters
        $searchTerm = $request->query('search', '');
        $sortBy = $this->normalizeSortBy($request->query('sortBy', 'importance'));
        $sortOrder = $this->normalizeSortOrder($request->query('sortOrder', 'desc'));

        return [
            'selectedIds' => $selectedIds,
            'searchTerm' => $searchTerm,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ];
    }
}

