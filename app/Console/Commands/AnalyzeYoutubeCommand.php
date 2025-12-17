<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\AnalysisResultRepository;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
use App\Services\GeminiClient;
use App\Services\PromptService;
use App\Services\Sources\YoutubeFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AnalyzeYoutubeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analyze:youtube 
                            {url : YouTube 影片 URL}
                            {--prompt-version= : Prompt 版本 (可選)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '分析 YouTube 影片（直接分析，不儲存檔案）';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private YoutubeFetchService $youtubeFetchService,
        private AnalyzeService $analyzeService,
        private VideoRepository $videoRepository,
        private GeminiClient $geminiClient,
        private PromptService $promptService,
        private AnalysisResultRepository $analysisResultRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $url = $this->argument('url');
        $promptVersion = $this->option('prompt-version');

        $this->info("開始分析 YouTube 影片: {$url}");

        // Extract video ID (YouTube video ID string)
        $youtubeVideoId = $this->youtubeFetchService->extractVideoId($url);

        if (null === $youtubeVideoId) {
            $this->error('無法從 URL 提取 YouTube 影片 ID');
            return Command::FAILURE;
        }

        $this->info("影片 ID: {$youtubeVideoId}");

        // Get video metadata (optional - will use yt-dlp if API key not available)
        $metadata = null;
        try {
            $metadata = $this->youtubeFetchService->getVideoMetadata($youtubeVideoId, $url);
            if (null !== $metadata) {
                $this->info("標題: {$metadata['title']}");
            } else {
                $this->warn('無法取得影片 metadata，將使用預設值繼續分析');
                $metadata = [
                    'video_id' => $youtubeVideoId,
                    'title' => 'YouTube Video ' . $youtubeVideoId,
                    'description' => '',
                    'published_at' => null,
                    'duration' => 0,
                    'thumbnail' => '',
                    'channel_title' => '',
                ];
            }
        } catch (\Exception $e) {
            $this->warn('取得影片 metadata 時發生錯誤，將使用預設值繼續分析: ' . $e->getMessage());
            $metadata = [
                'video_id' => $youtubeVideoId,
                'title' => 'YouTube Video ' . $youtubeVideoId,
                'description' => '',
                'published_at' => null,
                'duration' => 0,
                'thumbnail' => '',
                'channel_title' => '',
            ];
        }

        // Check if video already exists
        $existingVideo = $this->videoRepository->getBySourceId('YOUTUBE', $youtubeVideoId);

        // $videoId will be the database ID (int), $youtubeVideoId remains the YouTube ID (string)
        if (null !== $existingVideo) {
            if (AnalysisStatus::COMPLETED === $existingVideo->analysis_status) {
                $this->warn('此影片已完成分析');
                return Command::SUCCESS;
            }
            $videoId = $existingVideo->id; // Database ID (int)
        } else {
            // Create new video record
            $videoId = $this->videoRepository->findOrCreate([
                'source_name' => 'YOUTUBE',
                'source_id' => $youtubeVideoId, // YouTube video ID (string)
                'nas_path' => $url, // Store URL as path
                'title' => $metadata['title'],
                'published_at' => $metadata['published_at'] ? date('Y-m-d H:i:s', strtotime($metadata['published_at'])) : null,
                'duration_secs' => $metadata['duration'],
            ]);
        }

        try {
            // Update status to processing
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::PROCESSING,
                new \DateTime()
            );

            // Get prompt from PromptService
            try {
                $prompt = $this->promptService->getVideoAnalysisPrompt($promptVersion);
                $actualPromptVersion = $promptVersion ?? $this->promptService->getVideoAnalysisCurrentVersion();
            } catch (\Exception $e) {
                $this->error('讀取 Prompt 失敗: ' . $e->getMessage());
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );
                return Command::FAILURE;
            }

            // Method 1: Try to get transcript first (cost-efficient)
            // Use $youtubeVideoId (string) for transcript, not $videoId (int database ID)
            $this->info('嘗試獲取影片字幕...');
            $transcript = $this->youtubeFetchService->getTranscript($youtubeVideoId, $url);

            $analysisResult = null;

            if (null !== $transcript && '' !== trim($transcript)) {
                // Use transcript-based analysis (cost-efficient)
                $this->info('✓ 成功獲取字幕，使用字幕進行分析（節省成本）');
                $this->info('開始分析字幕內容...');

                try {
                    // Use video analysis prompt but with transcript text
                    $cleanedJsonString = $this->geminiClient->analyzeText($transcript, $prompt);

                    if ('' === trim($cleanedJsonString)) {
                        throw new \Exception('Gemini API 分析回傳了空的 JSON 字串');
                    }

                    $analysisResult = json_decode($cleanedJsonString, true);
                    if (null === $analysisResult) {
                        throw new \Exception('無法將分析回應解析為 JSON');
                    }

                    // Store prompt version
                    $analysisResult['_prompt_version'] = $actualPromptVersion;
                    $analysisResult['_analysis_method'] = 'transcript'; // Mark as transcript-based

                    $this->info('✓ 字幕分析完成！');
                } catch (\Exception $e) {
                    $this->warn('字幕分析失敗，將降級使用直接 URL 分析: ' . $e->getMessage());
                    $transcript = null; // Reset to trigger fallback
                }
            }

            // Method 2: Fallback to direct URL analysis (if transcript not available or failed)
            if (null === $analysisResult) {
                $this->info('無法使用字幕，改用直接 URL 分析（成本較高）...');
                $this->warn('注意：直接 URL 分析會消耗更多 Token，成本較高');

                try {
                    $analysisResult = $this->geminiClient->analyzeYouTubeUrl($url, $prompt);

                    // Handle array response
                    if (is_array($analysisResult) && isset($analysisResult[0]) && is_array($analysisResult[0])) {
                        Log::warning('[AnalyzeYoutubeCommand] AI 返回陣列格式，使用第一個元素', [
                            'video_id' => $videoId,
                        ]);
                        $analysisResult = $analysisResult[0];
                    }

                    // Store prompt version
                    $analysisResult['_prompt_version'] = $actualPromptVersion;
                    $analysisResult['_analysis_method'] = 'direct_url'; // Mark as direct URL-based

                    $this->info('✓ 直接 URL 分析完成！');
                } catch (\Exception $e) {
                    throw new \Exception('直接 URL 分析也失敗: ' . $e->getMessage());
                }
            }

            // Validate analysis result
            if (empty($analysisResult) || (!isset($analysisResult['short_summary']) && !isset($analysisResult['bulleted_summary']) && !isset($analysisResult['visual_description']))) {
                $errorMsg = 'Gemini API 回傳的分析結果為空或無效';
                Log::error('[AnalyzeYoutubeCommand] ' . $errorMsg);

                // Save error analysis result
                $this->analysisResultRepository->save([
                    'video_id' => $videoId,
                    'error_message' => $errorMsg,
                    'prompt_version' => $actualPromptVersion,
                ]);

                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );

                throw new \Exception($errorMsg);
            }

            // Save analysis result
            $this->analyzeService->saveAnalysisResult(
                $videoId,
                $analysisResult,
                $actualPromptVersion
            );

            // After analysis is complete, try to get full metadata and update video record
            $this->info('正在更新影片 metadata...');
            try {
                // Try to get complete metadata using yt-dlp (now that it's installed)
                $completeMetadata = $this->youtubeFetchService->getVideoMetadata($youtubeVideoId, $url);
                
                if (null !== $completeMetadata && !empty($completeMetadata['title']) && $completeMetadata['title'] !== 'YouTube Video ' . $youtubeVideoId) {
                    // Update video with complete metadata
                    $updateData = [];
                    
                    if (!empty($completeMetadata['title'])) {
                        $updateData['title'] = $completeMetadata['title'];
                    }
                    
                    if (!empty($completeMetadata['description'])) {
                        // Store description in source_metadata if needed, or skip if not in schema
                        // For now, we'll just update title and other available fields
                    }
                    
                    if (null !== $completeMetadata['published_at']) {
                        $updateData['published_at'] = date('Y-m-d H:i:s', strtotime($completeMetadata['published_at']));
                    }
                    
                    if ($completeMetadata['duration'] > 0) {
                        $updateData['duration_secs'] = $completeMetadata['duration'];
                    }
                    
                    // Update source_metadata with complete information
                    $currentVideo = $this->videoRepository->getById($videoId);
                    $sourceMetadata = $currentVideo?->source_metadata ?? [];
                    if (is_array($sourceMetadata)) {
                        $sourceMetadata = array_merge($sourceMetadata, [
                            'channel_title' => $completeMetadata['channel_title'] ?? '',
                            'thumbnail' => $completeMetadata['thumbnail'] ?? '',
                            'description' => $completeMetadata['description'] ?? '',
                            'fetched_at' => date('Y-m-d H:i:s'),
                        ]);
                        $updateData['source_metadata'] = $sourceMetadata;
                    }
                    
                    if (!empty($updateData)) {
                        $updated = $this->videoRepository->update($videoId, $updateData);
                        if ($updated) {
                            $this->info('✓ 影片 metadata 已更新');
                            Log::info('[AnalyzeYoutubeCommand] 影片 metadata 更新成功', [
                                'video_id' => $videoId,
                                'youtube_video_id' => $youtubeVideoId,
                                'updated_fields' => array_keys($updateData),
                            ]);
                        } else {
                            $this->warn('無法更新影片 metadata');
                        }
                    }
                } else {
                    $this->warn('無法獲取完整的影片 metadata，將保留現有資訊');
                }
            } catch (\Exception $e) {
                // Don't fail the whole process if metadata update fails
                $this->warn('更新影片 metadata 時發生錯誤: ' . $e->getMessage());
                Log::warning('[AnalyzeYoutubeCommand] 更新影片 metadata 失敗', [
                    'video_id' => $videoId,
                    'youtube_video_id' => $youtubeVideoId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->info('✓ 影片分析完成！');
            $this->info('分析方法: ' . ($analysisResult['_analysis_method'] ?? 'unknown'));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            Log::error('[AnalyzeYoutubeCommand] 分析失敗', [
                'video_id' => $videoId,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('分析失敗: ' . $e->getMessage());

            // Update status to failed if not already updated
            try {
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::VIDEO_ANALYSIS_FAILED,
                    new \DateTime()
                );
            } catch (\Exception $updateException) {
                Log::error('[AnalyzeYoutubeCommand] 更新狀態失敗', [
                    'video_id' => $videoId,
                    'error' => $updateException->getMessage(),
                ]);
            }

            return Command::FAILURE;
        }
    }
}

