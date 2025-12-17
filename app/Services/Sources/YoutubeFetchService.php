<?php

declare(strict_types=1);

namespace App\Services\Sources;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeFetchService
{
    private string $apiKey;

    /**
     * Create a new YouTube fetch service instance.
     *
     * @param string $apiKey
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Extract video ID from YouTube URL.
     *
     * @param string $url
     * @return string|null
     */
    public function extractVideoId(string $url): ?string
    {
        // Support various YouTube URL formats
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
            '/youtu\.be\/([a-zA-Z0-9_-]+)/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get video metadata from YouTube API or yt-dlp.
     *
     * @param string $videoId
     * @param string|null $url Optional YouTube URL (for yt-dlp fallback)
     * @return array<string, mixed>|null
     */
    public function getVideoMetadata(string $videoId, ?string $url = null): ?array
    {
        // Try YouTube API first if API key is available
        if ('' !== $this->apiKey) {
            return $this->getVideoMetadataFromAPI($videoId);
        }

        // Fallback to yt-dlp if no API key
        Log::info('[YoutubeFetchService] YouTube API Key 未設定，使用 yt-dlp 獲取 metadata');
        return $this->getVideoMetadataFromYtDlp($videoId, $url);
    }

    /**
     * Get video metadata from YouTube API.
     *
     * @param string $videoId
     * @return array<string, mixed>|null
     */
    private function getVideoMetadataFromAPI(string $videoId): ?array
    {

        try {
            $response = Http::get('https://www.googleapis.com/youtube/v3/videos', [
                'id' => $videoId,
                'key' => $this->apiKey,
                'part' => 'snippet,contentDetails,statistics',
            ]);

            if (!$response->successful()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? $response->body();
                
                Log::error('[YoutubeFetchService] YouTube API 請求失敗', [
                    'status' => $response->status(),
                    'video_id' => $videoId,
                    'error' => $errorMessage,
                    'body' => $response->body(),
                ]);
                
                throw new \RuntimeException(
                    sprintf('YouTube API 請求失敗 (狀態碼: %d): %s', $response->status(), $errorMessage)
                );
            }

            $data = $response->json();

            if (!isset($data['items'][0])) {
                Log::warning('[YoutubeFetchService] 找不到影片', ['video_id' => $videoId]);
                throw new \RuntimeException(
                    sprintf('找不到 YouTube 影片 (ID: %s)。請確認影片 ID 是否正確，或影片是否為公開狀態。', $videoId)
                );
            }

            $item = $data['items'][0];
            $snippet = $item['snippet'] ?? [];
            $contentDetails = $item['contentDetails'] ?? [];

            return [
                'video_id' => $videoId,
                'title' => $snippet['title'] ?? '',
                'description' => $snippet['description'] ?? '',
                'published_at' => $snippet['publishedAt'] ?? null,
                'duration' => $this->parseDuration($contentDetails['duration'] ?? ''),
                'thumbnail' => $snippet['thumbnails']['high']['url'] ?? '',
                'channel_title' => $snippet['channelTitle'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error('[YoutubeFetchService] 取得影片資訊失敗', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get video metadata using yt-dlp (no API key required).
     *
     * @param string $videoId
     * @param string|null $url
     * @return array<string, mixed>|null
     */
    private function getVideoMetadataFromYtDlp(string $videoId, ?string $url = null): ?array
    {
        $ytDlpCommand = $this->findYtDlpCommand();
        if (null === $ytDlpCommand) {
            Log::warning('[YoutubeFetchService] yt-dlp 未安裝，無法獲取 metadata');
            // Return minimal metadata
            return [
                'video_id' => $videoId,
                'title' => 'YouTube Video ' . $videoId,
                'description' => '',
                'published_at' => null,
                'duration' => 0,
                'thumbnail' => '',
                'channel_title' => '',
            ];
        }

        $videoUrl = $url ?? "https://www.youtube.com/watch?v={$videoId}";

        try {
            // Use yt-dlp to get video info in JSON format
            $command = sprintf(
                '%s --dump-json --quiet --no-warnings %s 2>&1',
                escapeshellarg($ytDlpCommand),
                escapeshellarg($videoUrl)
            );

            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            if (0 !== $returnVar) {
                Log::warning('[YoutubeFetchService] yt-dlp 獲取 metadata 失敗', [
                    'video_id' => $videoId,
                    'return_code' => $returnVar,
                ]);
                // Return minimal metadata
                return [
                    'video_id' => $videoId,
                    'title' => 'YouTube Video ' . $videoId,
                    'description' => '',
                    'published_at' => null,
                    'duration' => 0,
                    'thumbnail' => '',
                    'channel_title' => '',
                ];
            }

            // Parse JSON output from yt-dlp
            $jsonOutput = implode("\n", $output);
            $data = json_decode($jsonOutput, true);

            if (null === $data) {
                Log::warning('[YoutubeFetchService] 無法解析 yt-dlp JSON 輸出', [
                    'video_id' => $videoId,
                ]);
                return [
                    'video_id' => $videoId,
                    'title' => 'YouTube Video ' . $videoId,
                    'description' => '',
                    'published_at' => null,
                    'duration' => 0,
                    'thumbnail' => '',
                    'channel_title' => '',
                ];
            }

            // Extract metadata from yt-dlp output
            $duration = isset($data['duration']) ? (int) $data['duration'] : 0;
            $publishedAt = isset($data['upload_date']) ? $this->parseYtDlpDate($data['upload_date']) : null;

            return [
                'video_id' => $videoId,
                'title' => $data['title'] ?? 'YouTube Video ' . $videoId,
                'description' => $data['description'] ?? '',
                'published_at' => $publishedAt,
                'duration' => $duration,
                'thumbnail' => $data['thumbnail'] ?? ($data['thumbnails'][0]['url'] ?? ''),
                'channel_title' => $data['uploader'] ?? ($data['channel'] ?? ''),
            ];
        } catch (\Exception $e) {
            Log::error('[YoutubeFetchService] yt-dlp 獲取 metadata 時發生異常', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
            // Return minimal metadata
            return [
                'video_id' => $videoId,
                'title' => 'YouTube Video ' . $videoId,
                'description' => '',
                'published_at' => null,
                'duration' => 0,
                'thumbnail' => '',
                'channel_title' => '',
            ];
        }
    }

    /**
     * Parse yt-dlp date format (YYYYMMDD) to ISO 8601.
     *
     * @param string $dateString
     * @return string|null
     */
    private function parseYtDlpDate(string $dateString): ?string
    {
        if (strlen($dateString) === 8 && ctype_digit($dateString)) {
            // Format: YYYYMMDD
            $year = substr($dateString, 0, 4);
            $month = substr($dateString, 4, 2);
            $day = substr($dateString, 6, 2);
            return sprintf('%s-%s-%sT00:00:00Z', $year, $month, $day);
        }
        return null;
    }

    /**
     * Download YouTube video to temporary location.
     *
     * @param string $videoId YouTube video ID
     * @param string|null $url Optional YouTube URL (for better compatibility)
     * @return string Path to downloaded video file
     * @throws \RuntimeException If download fails
     */
    public function downloadVideo(string $videoId, ?string $url = null): string
    {
        // Check if yt-dlp is available
        $ytDlpCommand = $this->findYtDlpCommand();
        if (null === $ytDlpCommand) {
            throw new \RuntimeException(
                'yt-dlp 未安裝。請先安裝 yt-dlp: brew install yt-dlp (macOS) 或 pip install yt-dlp'
            );
        }

        // Create temp directory
        $tempDir = storage_path('app/temp/youtube');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Generate output file path
        $outputPath = $tempDir . '/' . $videoId . '.%(ext)s';
        $finalPath = $tempDir . '/' . $videoId . '.mp4';

        // Use URL if provided, otherwise construct from video ID
        $videoUrl = $url ?? "https://www.youtube.com/watch?v={$videoId}";

        // Build yt-dlp command
        // -f: best video format available
        // -o: output template
        // --no-playlist: download only the video, not the playlist
        // --quiet: reduce output
        // --no-warnings: suppress warnings
        $command = sprintf(
            '%s -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" -o %s --no-playlist --quiet --no-warnings %s',
            escapeshellarg($ytDlpCommand),
            escapeshellarg($outputPath),
            escapeshellarg($videoUrl)
        );

        Log::info('[YoutubeFetchService] 開始下載 YouTube 影片', [
            'video_id' => $videoId,
            'url' => $videoUrl,
            'command' => $command,
        ]);

        // Execute download command
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if (0 !== $returnVar) {
            $errorMessage = implode("\n", $output);
            Log::error('[YoutubeFetchService] 下載影片失敗', [
                'video_id' => $videoId,
                'return_code' => $returnVar,
                'error' => $errorMessage,
            ]);
            throw new \RuntimeException(
                sprintf('下載 YouTube 影片失敗 (返回碼: %d): %s', $returnVar, $errorMessage)
            );
        }

        // Check if file was downloaded (yt-dlp may use different extensions)
        $possibleExtensions = ['mp4', 'webm', 'mkv', 'm4a'];
        $downloadedPath = null;

        foreach ($possibleExtensions as $ext) {
            $testPath = $tempDir . '/' . $videoId . '.' . $ext;
            if (file_exists($testPath)) {
                $downloadedPath = $testPath;
                break;
            }
        }

        if (null === $downloadedPath) {
            // Try to find any file with the video ID
            $files = glob($tempDir . '/' . $videoId . '.*');
            if (!empty($files)) {
                $downloadedPath = $files[0];
            }
        }

        if (null === $downloadedPath || !file_exists($downloadedPath)) {
            throw new \RuntimeException(
                sprintf('下載完成但找不到影片檔案 (video_id: %s, temp_dir: %s)', $videoId, $tempDir)
            );
        }

        // If file is not mp4, we might want to convert it (optional)
        // For now, we'll use whatever format yt-dlp downloaded
        Log::info('[YoutubeFetchService] 影片下載成功', [
            'video_id' => $videoId,
            'file_path' => $downloadedPath,
            'file_size' => filesize($downloadedPath),
        ]);

        return $downloadedPath;
    }

    /**
     * Find yt-dlp command path.
     *
     * @return string|null
     */
    private function findYtDlpCommand(): ?string
    {
        // Try common locations
        $possibleCommands = ['yt-dlp', '/usr/local/bin/yt-dlp', '/opt/homebrew/bin/yt-dlp'];

        foreach ($possibleCommands as $cmd) {
            $output = [];
            $returnVar = 0;
            exec(sprintf('which %s 2>/dev/null', escapeshellarg($cmd)), $output, $returnVar);
            if (0 === $returnVar && !empty($output)) {
                return trim($output[0]);
            }
        }

        // Try direct execution
        exec('yt-dlp --version 2>/dev/null', $output, $returnVar);
        if (0 === $returnVar) {
            return 'yt-dlp';
        }

        return null;
    }

    /**
     * Get video transcript (subtitles/captions) from YouTube.
     *
     * @param string $videoId YouTube video ID
     * @param string|null $url Optional YouTube URL (for better compatibility)
     * @param string|null $language Language code (e.g., 'zh-TW', 'en', 'zh'). If null, will try multiple languages
     * @return string|null Transcript text, or null if not available
     */
    public function getTranscript(string $videoId, ?string $url = null, ?string $language = null): ?string
    {
        // Check if yt-dlp is available
        $ytDlpCommand = $this->findYtDlpCommand();
        if (null === $ytDlpCommand) {
            Log::warning('[YoutubeFetchService] yt-dlp 未安裝，無法獲取字幕');
            return null;
        }

        // Create temp directory for subtitles
        $tempDir = storage_path('app/temp/youtube/subtitles');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Use URL if provided, otherwise construct from video ID
        $videoUrl = $url ?? "https://www.youtube.com/watch?v={$videoId}";

        // Change to temp directory to save subtitles there
        $originalDir = getcwd();
        chdir($tempDir);

        try {
            // Try multiple approaches to get subtitles
            // Method 1: Try without specifying language (yt-dlp will auto-select)
            // Method 2: Try with specific languages
            $approaches = [];
            
            if ($language) {
                // If specific language requested, try it first
                $approaches[] = ['lang' => $language, 'method' => 'specific'];
            }
            
            // Add default language preferences
            $defaultLanguages = ['zh-TW', 'zh', 'en', 'en-US', 'en-GB'];
            foreach ($defaultLanguages as $lang) {
                if ($language !== $lang) {
                    $approaches[] = ['lang' => $lang, 'method' => 'preferred'];
                }
            }
            
            // Last resort: try without language specification (auto-select)
            $approaches[] = ['lang' => null, 'method' => 'auto'];

            foreach ($approaches as $approach) {
                $lang = $approach['lang'];
                $method = $approach['method'];
                
                try {
                    // Build yt-dlp command to get transcript
                    // --write-auto-sub: write auto-generated subtitles
                    // --write-sub: write subtitles
                    // --sub-lang: subtitle language (omit to get all or auto-select)
                    // --skip-download: skip downloading video
                    // --quiet: reduce output
                    // -o: output template (use video ID as filename)
                    $outputTemplate = $videoId . '.%(ext)s';
                    
                    if (null === $lang) {
                        // Don't specify language, let yt-dlp auto-select
                        $command = sprintf(
                            '%s --write-auto-sub --write-sub --skip-download --quiet --no-warnings -o %s %s 2>&1',
                            escapeshellarg($ytDlpCommand),
                            escapeshellarg($outputTemplate),
                            escapeshellarg($videoUrl)
                        );
                    } else {
                        // Try specific language
                        $command = sprintf(
                            '%s --write-auto-sub --write-sub --sub-lang %s --skip-download --quiet --no-warnings -o %s %s 2>&1',
                            escapeshellarg($ytDlpCommand),
                            escapeshellarg($lang),
                            escapeshellarg($outputTemplate),
                            escapeshellarg($videoUrl)
                        );
                    }

                    Log::info('[YoutubeFetchService] 嘗試獲取字幕', [
                        'video_id' => $videoId,
                        'language' => $lang ?? 'auto',
                        'method' => $method,
                    ]);

                    // Execute command
                    $output = [];
                    $returnVar = 0;
                    exec($command, $output, $returnVar);

                    // Log command output for debugging
                    if (0 !== $returnVar || !empty($output)) {
                        Log::info('[YoutubeFetchService] yt-dlp 命令輸出', [
                            'video_id' => $videoId,
                            'language' => $lang,
                            'return_code' => $returnVar,
                            'output' => implode("\n", $output),
                        ]);
                    }

                    // Find subtitle files in temp directory (try with and without language)
                    $subtitleFiles = [];
                    if (null !== $lang) {
                        $subtitleFiles = $this->findSubtitleFiles($videoId, $lang, $tempDir);
                    }
                    // Also try to find any subtitle files (in case auto-select was used)
                    if (empty($subtitleFiles)) {
                        $subtitleFiles = $this->findSubtitleFiles($videoId, null, $tempDir);
                    }

                    Log::info('[YoutubeFetchService] 搜尋字幕檔案', [
                        'video_id' => $videoId,
                        'language' => $lang ?? 'auto',
                        'temp_dir' => $tempDir,
                        'found_files' => $subtitleFiles,
                    ]);

                    if (!empty($subtitleFiles)) {
                        // Read the first available subtitle file
                        foreach ($subtitleFiles as $subtitleFile) {
                            $transcript = $this->parseSubtitleFile($subtitleFile);
                            if (null !== $transcript && '' !== trim($transcript)) {
                                // Clean up subtitle files
                                $this->cleanupSubtitleFiles($subtitleFiles);

                                // Restore original directory
                                if (false !== $originalDir) {
                                    chdir($originalDir);
                                }

                                Log::info('[YoutubeFetchService] 成功獲取字幕', [
                                    'video_id' => $videoId,
                                    'language' => $lang ?? 'auto',
                                    'method' => $method,
                                    'length' => strlen($transcript),
                                ]);

                                return $transcript;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('[YoutubeFetchService] 獲取字幕失敗', [
                        'video_id' => $videoId,
                        'language' => $lang ?? 'auto',
                        'method' => $method,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        } finally {
            // Restore original directory
            if (false !== $originalDir) {
                chdir($originalDir);
            }
        }

        Log::warning('[YoutubeFetchService] 無法獲取任何字幕', [
            'video_id' => $videoId,
            'tried_approaches' => count($approaches),
        ]);

        return null;
    }

    /**
     * Find subtitle files for a video.
     *
     * @param string $videoId
     * @param string|null $language Language code or null to find any subtitle
     * @param string|null $searchDir Optional directory to search in
     * @return array<string>
     */
    private function findSubtitleFiles(string $videoId, ?string $language, ?string $searchDir = null): array
    {
        $files = [];
        $searchDir = $searchDir ?? (getcwd() ?: storage_path('app/temp/youtube/subtitles'));

        // Common subtitle file patterns (yt-dlp naming conventions)
        $patterns = [];
        
        if (null !== $language) {
            // Try with specific language
            $patterns[] = $searchDir . '/' . $videoId . '.' . $language . '.vtt';
            $patterns[] = $searchDir . '/' . $videoId . '.' . $language . '.srt';
            $patterns[] = $searchDir . '/*.' . $language . '.vtt';
            $patterns[] = $searchDir . '/*.' . $language . '.srt';
        }
        
        // Always try generic patterns (video ID only, or any subtitle file)
        $patterns[] = $searchDir . '/' . $videoId . '.vtt';
        $patterns[] = $searchDir . '/' . $videoId . '.srt';
        $patterns[] = $searchDir . '/*.vtt';
        $patterns[] = $searchDir . '/*.srt';

        foreach ($patterns as $pattern) {
            $found = glob($pattern);
            if (!empty($found)) {
                $files = array_merge($files, $found);
            }
        }

        return array_unique($files);
    }

    /**
     * Parse subtitle file (VTT or SRT format) and extract text.
     *
     * @param string $filePath
     * @return string|null
     */
    private function parseSubtitleFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            return null;
        }

        // Remove VTT/SRT formatting tags and timestamps
        // Remove WEBVTT header
        $content = preg_replace('/WEBVTT.*?\n\n/', '', $content);

        // Remove timestamps (e.g., 00:00:01.000 --> 00:00:03.000)
        $content = preg_replace('/\d{2}:\d{2}:\d{2}[.,]\d{3}\s*-->\s*\d{2}:\d{2}:\d{2}[.,]\d{3}/', '', $content);

        // Remove HTML tags
        $content = preg_replace('/<[^>]+>/', '', $content);

        // Remove cue settings (e.g., align:start position:0%)
        $content = preg_replace('/\w+:\S+/', '', $content);

        // Remove sequence numbers (SRT format)
        $content = preg_replace('/^\d+\s*$/m', '', $content);

        // Clean up whitespace
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = trim($content);

        return '' !== $content ? $content : null;
    }

    /**
     * Clean up subtitle files.
     *
     * @param array<string> $files
     * @return void
     */
    private function cleanupSubtitleFiles(array $files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Get video download URL (for direct analysis, we may need to download).
     *
     * @param string $videoId
     * @return string|null
     */
    public function getVideoDownloadUrl(string $videoId): ?string
    {
        // TODO: Implement YouTube video download URL generation
        // This might require using youtube-dl or similar service
        // For now, return null as we'll handle streaming directly
        return null;
    }

    /**
     * Parse ISO 8601 duration to seconds.
     *
     * @param string $duration
     * @return int
     */
    private function parseDuration(string $duration): int
    {
        // Parse ISO 8601 duration (e.g., PT1H2M10S)
        if (!preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $matches)) {
            return 0;
        }

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}

