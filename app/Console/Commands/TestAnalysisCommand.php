<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Repositories\AnalysisResultRepository;
use App\Repositories\VideoRepository;
use App\Services\AnalyzeService;
use App\Services\GeminiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestAnalysisCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:analysis 
                            {--text-only : 僅測試文本分析}
                            {--video-only : 僅測試影片分析}
                            {--connection-only : 僅測試 Gemini 連線}
                            {--cleanup : 測試完成後清理測試資料}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試文本/影片分析以及 Gemini 連線是否正常';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private AnalyzeService $analyzeService,
        private GeminiClient $geminiClient,
        private VideoRepository $videoRepository,
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
        $textOnly = $this->option('text-only');
        $videoOnly = $this->option('video-only');
        $connectionOnly = $this->option('connection-only');

        $this->info('=== 開始測試分析功能 ===');
        $this->newLine();

        $testResults = [
            'connection' => false,
            'text' => [],
            'video' => [],
            'created_video_ids' => [], // 記錄創建的測試 Video ID，用於清理
        ];

        // 測試 Gemini 連線
        if (!$videoOnly && !$textOnly) {
            $this->info('1. 測試 Gemini 連線...');
            $testResults['connection'] = $this->testGeminiConnection();
            $this->newLine();
        }

        // 如果僅測試連線，則結束
        if ($connectionOnly) {
            $this->displaySummary($testResults);
            return $testResults['connection'] ? Command::SUCCESS : Command::FAILURE;
        }

        // 掃描測試資源目錄
        $testResourcePath = 'test_resource';
        $disk = Storage::disk('local');

        if (!$disk->exists($testResourcePath)) {
            $this->error("測試資源目錄不存在: storage/app/{$testResourcePath}");
            return Command::FAILURE;
        }

        $directories = $disk->directories($testResourcePath);

        if (empty($directories)) {
            $this->warn("測試資源目錄中沒有找到任何子目錄");
            return Command::SUCCESS;
        }

        $this->info('2. 掃描測試資源...');
        $this->line("找到 " . count($directories) . " 個測試目錄");
        $this->newLine();

        // 處理每個測試目錄
        foreach ($directories as $directory) {
            $dirName = basename($directory);
            $this->info("處理目錄: {$dirName}");

            // 掃描目錄中的檔案，確定優先使用的 nas_path
            $disk = Storage::disk('local');
            $files = $disk->files($directory);
            $preferredNasPath = null;
            
            // 優先使用影片檔案，其次使用 txt 檔案
            foreach ($files as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv'], true)) {
                    $preferredNasPath = $file;
                    break;
                }
            }
            
            if (null === $preferredNasPath) {
                foreach ($files as $file) {
                    if (str_ends_with(strtolower($file), '.txt')) {
                        $preferredNasPath = $file;
                        break;
                    }
                }
            }

            // 為每個目錄創建或取得唯一的 Video 記錄
            // 使用固定的 source_id，確保同一個目錄只對應一筆記錄
            $testSourceId = 'TEST_' . $dirName;
            $videoId = $this->videoRepository->findOrCreate([
                'source_name' => 'TEST',
                'source_id' => $testSourceId,
                'nas_path' => $preferredNasPath ?? $directory,
                'fetched_at' => new \DateTime(),
            ]);

            // 測試文本分析
            if (!$videoOnly) {
                $textResult = $this->testTextAnalysis($directory, $dirName, $videoId);
                if (null !== $textResult) {
                    $testResults['text'][] = $textResult;
                }
            }

            // 測試影片分析
            if (!$textOnly) {
                $videoResult = $this->testVideoAnalysis($directory, $dirName, $videoId);
                if (null !== $videoResult) {
                    $testResults['video'][] = $videoResult;
                    // 如果找到影片檔案，更新 nas_path
                    if (null !== $videoResult['video_file'] ?? null) {
                        $this->videoRepository->update($videoId, [
                            'nas_path' => $videoResult['video_file'],
                        ]);
                    }
                }
            }

            // 記錄創建的 Video ID（只記錄一次）
            $testResults['created_video_ids'][] = $videoId;

            $this->newLine();
        }

        // 顯示測試結果摘要
        $this->displaySummary($testResults);

        // 清理測試資料（如果指定）
        if ($this->option('cleanup') && !empty($testResults['created_video_ids'])) {
            $this->cleanupTestData($testResults['created_video_ids']);
        }

        // 判斷是否所有測試都通過
        $allPassed = $testResults['connection'] !== false;
        if (!$videoOnly && !empty($testResults['text'])) {
            $allPassed = $allPassed && !empty(array_filter($testResults['text'], fn($r) => $r['success']));
        }
        if (!$textOnly && !empty($testResults['video'])) {
            $allPassed = $allPassed && !empty(array_filter($testResults['video'], fn($r) => $r['success']));
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * 測試 Gemini 連線
     *
     * @return bool
     */
    private function testGeminiConnection(): bool
    {
        try {
            // 使用簡單的測試文本來測試連線
            $testText = '這是一個測試文本，用來驗證 Gemini API 連線是否正常。';
            $testPrompt = '請回覆 "連線成功" 並以 JSON 格式返回 {"status": "success", "message": "連線成功"}';

            $this->line('正在測試 Gemini API 連線...');

            $result = $this->geminiClient->analyzeText($testText, $testPrompt);

            if (!empty($result)) {
                $parsed = json_decode($result, true);
                if (null !== $parsed) {
                    $this->info('✓ Gemini 連線測試成功');
                    $this->line("回應: " . json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    return true;
                }
            }

            $this->error('✗ Gemini 連線測試失敗：回應格式不正確');
            return false;
        } catch (\Exception $e) {
            $this->error('✗ Gemini 連線測試失敗：' . $e->getMessage());
            Log::error('[TestAnalysisCommand] Gemini 連線測試失敗', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 測試文本分析
     *
     * @param string $directory
     * @param string $dirName
     * @param int $videoId
     * @return array<string, mixed>|null
     */
    private function testTextAnalysis(string $directory, string $dirName, int $videoId): ?array
    {
        $disk = Storage::disk('local');
        $files = $disk->files($directory);

        // 尋找 .txt 檔案
        $txtFile = null;
        foreach ($files as $file) {
            if (str_ends_with(strtolower($file), '.txt')) {
                $txtFile = $file;
                break;
            }
        }

        if (null === $txtFile) {
            $this->warn("  未找到 .txt 檔案");
            return null;
        }

        $this->line("  測試文本分析: " . basename($txtFile));

        try {
            // 讀取文本內容
            $textContent = $disk->get($txtFile);

            if (empty(trim($textContent))) {
                $this->error("  ✗ 文本檔案內容為空");
                return [
                    'directory' => $dirName,
                    'file' => basename($txtFile),
                    'success' => false,
                    'error' => '文本檔案內容為空',
                    'video_id' => null,
                ];
            }

            $this->line("  文本長度: " . strlen($textContent) . " 字元");

            // 使用已存在的 Video 記錄
            // 只有在沒有影片檔案時才更新 nas_path 為 txt 檔案路徑
            $currentVideo = $this->videoRepository->getById($videoId);
            if (null !== $currentVideo) {
                $currentNasPath = $currentVideo->nas_path;
                $currentExt = strtolower(pathinfo($currentNasPath, PATHINFO_EXTENSION));
                // 如果當前 nas_path 不是影片檔案，則更新為 txt 檔案
                if (!in_array($currentExt, ['mp4', 'mov', 'avi', 'mkv'], true)) {
                    $this->videoRepository->update($videoId, [
                        'nas_path' => $txtFile,
                    ]);
                }
            }

            $this->line("  使用 Video 記錄 (ID: {$videoId})");

            // 更新狀態為 metadata_extracting
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::METADATA_EXTRACTING,
                new \DateTime()
            );

            // 執行文本分析
            $analysisResult = $this->analyzeService->executeTextAnalysis($textContent);

            if (empty($analysisResult)) {
                $this->error("  ✗ 文本分析失敗：結果為空");
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::TXT_ANALYSIS_FAILED,
                    new \DateTime()
                );
                return [
                    'directory' => $dirName,
                    'file' => basename($txtFile),
                    'success' => false,
                    'error' => '分析結果為空',
                    'video_id' => $videoId,
                ];
            }

            $this->info("  ✓ 文本分析成功");
            $this->line("  分析結果欄位: " . implode(', ', array_keys($analysisResult)));

            // 更新 Video 記錄的 metadata（模擬完整的文本分析流程）
            $updateData = [];
            if (isset($analysisResult['title'])) {
                $updateData['title'] = $analysisResult['title'];
                $this->line("  標題: " . $analysisResult['title']);
            }
            if (isset($analysisResult['creation_date'])) {
                try {
                    $updateData['published_at'] = new \DateTime($analysisResult['creation_date']);
                } catch (\Exception $e) {
                    // 忽略日期解析錯誤
                }
            }
            if (isset($analysisResult['duration_seconds'])) {
                $updateData['duration_secs'] = (int) $analysisResult['duration_seconds'];
            }
            if (isset($analysisResult['subjects'])) {
                $updateData['subjects'] = is_array($analysisResult['subjects']) 
                    ? $analysisResult['subjects'] 
                    : json_decode($analysisResult['subjects'], true);
            }
            if (isset($analysisResult['location'])) {
                $updateData['location'] = $analysisResult['location'];
            }
            if (isset($analysisResult['restrictions'])) {
                $updateData['restrictions'] = $analysisResult['restrictions'];
            }
            if (isset($analysisResult['tran_restrictions'])) {
                $updateData['tran_restrictions'] = $analysisResult['tran_restrictions'];
            }
            if (isset($analysisResult['shotlist_content'])) {
                $updateData['shotlist_content'] = $analysisResult['shotlist_content'];
            }

            // 更新 prompt version
            $promptVersion = $analysisResult['_prompt_version'] ?? 'v3';
            $updateData['prompt_version'] = $promptVersion;

            if (!empty($updateData)) {
                $this->videoRepository->update($videoId, $updateData);
                $this->line("  ✓ 已更新 Video metadata 到資料庫");
            }

            // 更新狀態為 metadata_extracted
            $this->videoRepository->updateAnalysisStatus(
                $videoId,
                AnalysisStatus::METADATA_EXTRACTED,
                new \DateTime()
            );

            // 驗證資料是否正確儲存
            $savedVideo = $this->videoRepository->getById($videoId);
            if (null === $savedVideo) {
                throw new \Exception('無法驗證：Video 記錄不存在');
            }

            $this->info("  ✓ 資料已成功儲存到資料庫");
            $this->line("  Video 狀態: " . $savedVideo->analysis_status->value);

            return [
                'directory' => $dirName,
                'file' => basename($txtFile),
                'success' => true,
                'result' => $analysisResult,
                'video_id' => $videoId,
            ];
        } catch (\Exception $e) {
            $this->error("  ✗ 文本分析失敗: " . $e->getMessage());
            Log::error('[TestAnalysisCommand] 文本分析失敗', [
                'directory' => $dirName,
                'file' => basename($txtFile),
                'error' => $e->getMessage(),
            ]);

            // 如果已創建 Video 記錄，更新狀態為失敗
            if (isset($videoId)) {
                $this->videoRepository->updateAnalysisStatus(
                    $videoId,
                    AnalysisStatus::TXT_ANALYSIS_FAILED,
                    new \DateTime()
                );
            }

            return [
                'directory' => $dirName,
                'file' => basename($txtFile),
                'success' => false,
                'error' => $e->getMessage(),
                'video_id' => $videoId ?? null,
            ];
        }
    }

    /**
     * 測試影片分析
     *
     * @param string $directory
     * @param string $dirName
     * @param int $videoId
     * @return array<string, mixed>|null
     */
    private function testVideoAnalysis(string $directory, string $dirName, int $videoId): ?array
    {
        $disk = Storage::disk('local');
        $files = $disk->files($directory);

        // 尋找 .mp4 檔案
        $videoFile = null;
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv'], true)) {
                $videoFile = $file;
                break;
            }
        }

        if (null === $videoFile) {
            $this->warn("  未找到影片檔案");
            return null;
        }

        $this->line("  測試影片分析: " . basename($videoFile));

        try {
            // 取得完整檔案路徑
            $videoPath = $disk->path($videoFile);

            if (!file_exists($videoPath)) {
                $this->error("  ✗ 影片檔案不存在: {$videoPath}");
                return [
                    'directory' => $dirName,
                    'file' => basename($videoFile),
                    'success' => false,
                    'error' => '影片檔案不存在',
                    'video_id' => null,
                ];
            }

            $fileSize = filesize($videoPath);
            $this->line("  檔案大小: " . $this->formatBytes($fileSize));

            // 使用已存在的 Video 記錄，更新 nas_path 為影片檔案路徑（優先使用影片檔案）
            $this->videoRepository->update($videoId, [
                'nas_path' => $videoFile,
            ]);

            $this->line("  使用 Video 記錄 (ID: {$videoId})");

            // 使用完整的 executeVideoAnalysis 流程（會自動儲存到資料庫）
            $this->line("  正在分析影片（這可能需要一些時間）...");

            $analysisResult = $this->analyzeService->executeVideoAnalysis($videoId, null, $videoPath);

            if (empty($analysisResult)) {
                $this->error("  ✗ 影片分析失敗：結果為空");
                return [
                    'directory' => $dirName,
                    'file' => basename($videoFile),
                    'success' => false,
                    'error' => '分析結果為空',
                    'video_id' => $videoId,
                ];
            }

            $this->info("  ✓ 影片分析成功");
            $this->line("  分析結果欄位: " . implode(', ', array_keys($analysisResult)));

            // 顯示部分結果
            if (isset($analysisResult['short_summary'])) {
                $summary = $analysisResult['short_summary'];
                $this->line("  摘要: " . Str::limit($summary, 100));
            }

            // 驗證資料是否正確儲存到資料庫
            $savedVideo = $this->videoRepository->getById($videoId);
            if (null === $savedVideo) {
                throw new \Exception('無法驗證：Video 記錄不存在');
            }

            $savedAnalysisResult = $this->analysisResultRepository->getByVideoId($videoId);
            if (null === $savedAnalysisResult) {
                throw new \Exception('無法驗證：AnalysisResult 記錄不存在');
            }

            $this->info("  ✓ 資料已成功儲存到資料庫");
            $this->line("  Video 狀態: " . $savedVideo->analysis_status->value);
            $this->line("  分析結果 ID: " . $savedAnalysisResult->video_id);

            return [
                'directory' => $dirName,
                'file' => basename($videoFile),
                'success' => true,
                'result' => $analysisResult,
                'video_id' => $videoId,
                'video_file' => $videoFile,
            ];
        } catch (\Exception $e) {
            $this->error("  ✗ 影片分析失敗: " . $e->getMessage());
            Log::error('[TestAnalysisCommand] 影片分析失敗', [
                'directory' => $dirName,
                'file' => basename($videoFile),
                'error' => $e->getMessage(),
            ]);

            return [
                'directory' => $dirName,
                'file' => basename($videoFile),
                'success' => false,
                'error' => $e->getMessage(),
                'video_id' => $videoId ?? null,
            ];
        }
    }

    /**
     * 顯示測試結果摘要
     *
     * @param array<string, mixed> $testResults
     * @return void
     */
    private function displaySummary(array $testResults): void
    {
        $this->newLine();
        $this->info('=== 測試結果摘要 ===');
        $this->newLine();

        // Gemini 連線測試
        if ($testResults['connection'] !== false) {
            $this->line('Gemini 連線: ' . ($testResults['connection'] ? '✓ 成功' : '✗ 失敗'));
        }

        // 文本分析測試
        if (!empty($testResults['text'])) {
            $textSuccess = count(array_filter($testResults['text'], fn($r) => $r['success']));
            $textTotal = count($testResults['text']);
            $this->line("文本分析: {$textSuccess}/{$textTotal} 成功");

            $this->table(
                ['目錄', '檔案', '狀態'],
                array_map(function ($result) {
                    return [
                        $result['directory'],
                        $result['file'],
                        $result['success'] ? '✓ 成功' : '✗ 失敗: ' . ($result['error'] ?? '未知錯誤'),
                    ];
                }, $testResults['text'])
            );
        }

        // 影片分析測試
        if (!empty($testResults['video'])) {
            $videoSuccess = count(array_filter($testResults['video'], fn($r) => $r['success']));
            $videoTotal = count($testResults['video']);
            $this->line("影片分析: {$videoSuccess}/{$videoTotal} 成功");

            $this->table(
                ['目錄', '檔案', '狀態'],
                array_map(function ($result) {
                    return [
                        $result['directory'],
                        $result['file'],
                        $result['success'] ? '✓ 成功' : '✗ 失敗: ' . ($result['error'] ?? '未知錯誤'),
                    ];
                }, $testResults['video'])
            );
        }
    }

    /**
     * 清理測試資料
     *
     * @param array<int> $videoIds
     * @return void
     */
    private function cleanupTestData(array $videoIds): void
    {
        $this->newLine();
        $this->info('=== 清理測試資料 ===');

        // 去重並過濾 null 值
        $uniqueVideoIds = array_values(array_unique(array_filter($videoIds, fn($id) => null !== $id)));

        if (empty($uniqueVideoIds)) {
            $this->line('沒有需要清理的測試資料');
            return;
        }

        $cleanedCount = 0;
        foreach ($uniqueVideoIds as $videoId) {
            try {
                // 刪除 AnalysisResult
                $this->analysisResultRepository->deleteByVideoId($videoId);

                // 刪除 Video
                \App\Models\Video::where('id', $videoId)->delete();

                $cleanedCount++;
            } catch (\Exception $e) {
                $this->warn("  清理 Video ID {$videoId} 失敗: " . $e->getMessage());
                Log::warning('[TestAnalysisCommand] 清理測試資料失敗', [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("已清理 {$cleanedCount}/" . count($uniqueVideoIds) . " 筆測試資料");
    }

    /**
     * 格式化位元組大小
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

