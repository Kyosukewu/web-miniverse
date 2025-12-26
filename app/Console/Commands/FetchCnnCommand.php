<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Repositories\VideoRepository;
use App\Services\Sources\CnnFetchService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * CNN 資源獲取命令
 *
 * 流程：
 * 1. 掃描配置的來源目錄（config('sources.cnn.source_path')）中的檔案
 * 2. 根據選擇的分類方式整理檔案：
 *    - label：依描述標籤分類，使用第一個遇到的唯一ID作為資料夾名稱（預設）
 *    - unique-id：直接依唯一ID分類
 * 3. 將整理後的檔案上傳到 GCS 指定路徑
 * 4. 根據 --keep-local 選項決定是否刪除本地檔案（預設會刪除）
 */
class FetchCnnCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:cnn
                            {--batch-size=50 : 每批處理的檔案數量（預設 50）}
                            {--limit= : 總共處理的檔案數量上限（可選，未設定則處理所有檔案）}
                            {--dry-run : 乾跑模式，僅顯示會處理的檔案，不實際上傳}
                            {--keep-local : 保留本地檔案，上傳到 GCS 後不刪除}
                            {--group-by=label : 分類方式：label（依描述標籤分類，使用第一個遇到的唯一ID作為資料夾名稱）或 unique-id（直接依唯一ID分類）}
                            {--file-type= : 指定要處理的檔案類型：mp4、xml 或 all（預設 all，處理所有類型）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '掃描配置的來源目錄，整理檔案後上傳到 GCS';

    /**
     * Create a new command instance.
     *
     * @param CnnFetchService $cnnFetchService
     * @param VideoRepository $videoRepository
     * @param StorageService $storageService
     */
    public function __construct(
        private CnnFetchService $cnnFetchService,
        private VideoRepository $videoRepository,
        private StorageService $storageService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * 執行流程：
     * 1. 掃描配置的來源目錄
     * 2. 根據選擇的分類方式分組檔案（label 或 unique-id）
     * 3. 將檔案移動到 GCS（按唯一識別碼組織）
     * 4. 根據選項決定是否刪除本地檔案
     * 5. 返回 GCS 中的資源列表
     *
     * @return int
     */
    public function handle(): int
    {
        // 增加記憶體限制（處理大量檔案時需要）
        ini_set('memory_limit', '2048M');
        
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        $keepLocal = $this->option('keep-local');
        $groupBy = $this->option('group-by');
        $fileType = $this->option('file-type') ?? 'all';
        $sourcePath = Config::get('sources.cnn.source_path', '/mnt/PushDownloads');

        // 驗證分類方式選項
        if (!in_array($groupBy, ['label', 'unique-id'], true)) {
            $this->error("❌ 無效的分類方式：{$groupBy}。請使用 'label' 或 'unique-id'");
            return Command::FAILURE;
        }

        // 驗證檔案類型選項
        if (!in_array($fileType, ['mp4', 'xml', 'all'], true)) {
            $this->error("❌ 無效的檔案類型：{$fileType}。請使用 'mp4'、'xml' 或 'all'");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('⚠️  乾跑模式：不會實際上傳檔案到 GCS，也不會刪除本地檔案');
        }

        if ($keepLocal && !$dryRun) {
            $this->info('ℹ️  保留本地檔案模式：上傳到 GCS 後不會刪除本地檔案');
        }

        $groupByText = 'label' === $groupBy ? '依描述標籤分類（使用第一個遇到的唯一ID作為資料夾名稱）' : '依唯一ID分類';
        $this->info("📁 分類方式：{$groupByText}");

        // 顯示檔案類型過濾資訊
        if ('all' !== $fileType) {
            $fileTypeText = 'mp4' === $fileType ? 'MP4 影片檔' : 'XML 文件檔';
            $this->info("📄 檔案類型：僅處理 {$fileTypeText}");
        }

        $this->info('開始處理 CNN 資源...');
        if ($dryRun) {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 模擬上傳到 GCS（不實際執行）");
        } else {
            $this->info("流程：掃描 {$sourcePath} → 整理檔案 → 上傳到 GCS" . ($keepLocal ? ' → 保留本地檔案' : ' → 刪除本地檔案'));
        }

        try {
            // 執行完整流程：掃描本地 → 整理 → 上傳到 GCS → 返回資源列表
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            
            if (null !== $limit) {
                $this->info("開始處理（批次大小: {$batchSize}，總處理上限: {$limit}）...");
            } else {
                $this->info("開始處理（批次大小: {$batchSize}）...");
            }
            
            $resources = $this->cnnFetchService->fetchResourceListWithProgress(
                $batchSize,
                $dryRun,
                $keepLocal,
                $groupBy,
                $limit,
                function ($current, $total, $message) {
                    if (null !== $total && $total > 0) {
                        $percentage = round(($current / $total) * 100, 1);
                        $this->line("進度: {$current}/{$total} ({$percentage}%) - {$message}");
                    } else {
                        $this->line("處理中: {$current} - {$message}");
                    }
                },
                $fileType
            );

        if (empty($resources)) {
            $this->warn('未找到任何 CNN 資源');
            return Command::SUCCESS;
        }

        // 如果不是乾跑模式，記錄同步狀態到 videos 表
        if (!$dryRun) {
            $this->info('正在記錄同步狀態到資料庫...');
            $this->recordSyncStatus($resources);
        }

            // 統計資源類型
        $xmlCount = 0;
        $videoCount = 0;

        foreach ($resources as $resource) {
            if ('xml' === $resource['type']) {
                $xmlCount++;
            } elseif ('video' === $resource['type']) {
                $videoCount++;
            }
        }

            // 顯示處理結果
            $this->newLine();
            $this->info('✅ CNN 資源處理完成！');
        $this->table(
            ['類型', '數量'],
            [
                ['XML', $xmlCount],
                ['Video', $videoCount],
                ['總計', count($resources)],
            ]
        );

        return Command::SUCCESS;
        } catch (\Exception $e) {
            Log::error('[FetchCnnCommand] 處理失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('❌ 處理失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * 記錄同步狀態到 videos 表
     * 
     * 邏輯：
     * 1. 如果檔案在 videos 有存在相同的 ID，且 mp4/xml 檔案的版本比資料庫紀錄的大時，
     *    才將 videos 中 sync_status 改成 updated 狀態以供後續分析可以重新抓取
     * 2. 如果存在相同 ID 但是檔案版本沒變時，不做任何動作
     * 3. 如果不存在該 ID，則新增一筆資料
     * 
     * @param array<int, array<string, mixed>> $resources
     * @return void
     */
    private function recordSyncStatus(array $resources): void
    {
        $sourceName = 'CNN';
        $processedUniqueIds = [];
        $updatedCount = 0;
        $skippedCount = 0;
        $createdCount = 0;
        
        // 按 source_id 分組資源，確保每個唯一 ID 只處理一次
        $groupedResources = [];
        foreach ($resources as $resource) {
            $sourceId = $resource['source_id'] ?? null;
            if (null === $sourceId) {
                continue;
            }
            
            if (!isset($groupedResources[$sourceId])) {
                $groupedResources[$sourceId] = [
                    'source_id' => $sourceId,
                    'has_xml' => false,
                    'has_mp4' => false,
                    'xml_path' => null,
                    'mp4_path' => null,
                    'xml_version' => null,
                    'mp4_version' => null,
                    'last_modified' => null,
                ];
            }
            
            // 記錄檔案類型和版本
            if ('xml' === $resource['type']) {
                $groupedResources[$sourceId]['has_xml'] = true;
                $groupedResources[$sourceId]['xml_path'] = $resource['file_path'] ?? null;
                // 從檔案路徑提取檔案名並取得版本號
                if (null !== $groupedResources[$sourceId]['xml_path']) {
                    $fileName = basename($groupedResources[$sourceId]['xml_path']);
                    $groupedResources[$sourceId]['xml_version'] = $this->storageService->extractFileVersion($fileName);
                }
            } elseif ('video' === $resource['type']) {
                $groupedResources[$sourceId]['has_mp4'] = true;
                $groupedResources[$sourceId]['mp4_path'] = $resource['file_path'] ?? null;
                // 從檔案路徑提取檔案名並取得版本號
                if (null !== $groupedResources[$sourceId]['mp4_path']) {
                    $fileName = basename($groupedResources[$sourceId]['mp4_path']);
                    $groupedResources[$sourceId]['mp4_version'] = $this->storageService->extractFileVersion($fileName);
                }
            }
            
            // 記錄最後修改時間（取最新的）
            $resourceModified = $resource['last_modified'] ?? null;
            if (null !== $resourceModified) {
                if (null === $groupedResources[$sourceId]['last_modified'] || 
                    $resourceModified > $groupedResources[$sourceId]['last_modified']) {
                    $groupedResources[$sourceId]['last_modified'] = $resourceModified;
                }
            }
        }
        
        // 處理每個唯一 ID
        foreach ($groupedResources as $sourceId => $resourceInfo) {
            try {
                // 確定 nas_path（優先使用 MP4，如果沒有則使用 XML）
                $nasPath = $resourceInfo['mp4_path'] ?? $resourceInfo['xml_path'] ?? null;
                
                if (null === $nasPath) {
                    Log::warning('[FetchCnnCommand] 無法確定 nas_path', [
                        'source_id' => $sourceId,
                        'resource_info' => $resourceInfo,
                    ]);
                    continue;
                }
                
                // 檢查是否已存在記錄
                $existingVideo = $this->videoRepository->getBySourceId($sourceName, $sourceId);
                
                if (null !== $existingVideo) {
                    // 情況 1 & 2: 已存在記錄，需要比較版本
                    $shouldUpdate = false;
                    $updateData = [
                        'nas_path' => $nasPath,
                    ];
                    
                    // 比較 XML 版本
                    $newXmlVersion = $resourceInfo['xml_version'] ?? null;
                    $existingXmlVersion = $existingVideo->xml_file_version ?? 0;
                    if (null !== $newXmlVersion && $newXmlVersion > $existingXmlVersion) {
                        $updateData['xml_file_version'] = $newXmlVersion;
                        $shouldUpdate = true;
                    }
                    
                    // 比較 MP4 版本
                    $newMp4Version = $resourceInfo['mp4_version'] ?? null;
                    $existingMp4Version = $existingVideo->mp4_file_version ?? 0;
                    if (null !== $newMp4Version && $newMp4Version > $existingMp4Version) {
                        $updateData['mp4_file_version'] = $newMp4Version;
                        $shouldUpdate = true;
                    }
                    
                    // 如果有最後修改時間，更新 fetched_at
                    if (null !== $resourceInfo['last_modified']) {
                        $updateData['fetched_at'] = date('Y-m-d H:i:s', $resourceInfo['last_modified']);
                    }
                    
                    if ($shouldUpdate) {
                        // 情況 1: 版本較大，更新 sync_status 為 updated
                        $updateData['sync_status'] = SyncStatus::UPDATED->value;
                        $this->videoRepository->update($existingVideo->id, $updateData);
                        $updatedCount++;
                        Log::info('[FetchCnnCommand] 已更新記錄（版本較大）', [
                            'source_id' => $sourceId,
                            'video_id' => $existingVideo->id,
                            'xml_version' => ['old' => $existingXmlVersion, 'new' => $newXmlVersion],
                            'mp4_version' => ['old' => $existingMp4Version, 'new' => $newMp4Version],
                            'sync_status' => SyncStatus::UPDATED->value,
                        ]);
                    } else {
                        // 情況 2: 版本相同或更小，不做任何動作
                        $skippedCount++;
                        Log::debug('[FetchCnnCommand] 跳過更新（版本未變或較小）', [
                            'source_id' => $sourceId,
                            'video_id' => $existingVideo->id,
                            'xml_version' => ['db' => $existingXmlVersion, 'file' => $newXmlVersion],
                            'mp4_version' => ['db' => $existingMp4Version, 'file' => $newMp4Version],
                        ]);
                    }
                } else {
                    // 情況 3: 不存在該 ID，新增一筆資料
                    $createData = [
                        'source_name' => $sourceName,
                        'source_id' => $sourceId,
                        'nas_path' => $nasPath,
                        'sync_status' => SyncStatus::UPDATED->value,
                        'fetched_at' => null !== $resourceInfo['last_modified'] 
                            ? date('Y-m-d H:i:s', $resourceInfo['last_modified'])
                            : date('Y-m-d H:i:s'),
                    ];
                    
                    // 如果有版本資訊，一併記錄
                    if (null !== $resourceInfo['xml_version']) {
                        $createData['xml_file_version'] = $resourceInfo['xml_version'];
                    }
                    if (null !== $resourceInfo['mp4_version']) {
                        $createData['mp4_file_version'] = $resourceInfo['mp4_version'];
                    }
                    
                    $videoId = $this->videoRepository->findOrCreate($createData);
                    $createdCount++;
                    Log::info('[FetchCnnCommand] 已建立新記錄', [
                        'source_id' => $sourceId,
                        'video_id' => $videoId,
                        'xml_version' => $resourceInfo['xml_version'],
                        'mp4_version' => $resourceInfo['mp4_version'],
                        'sync_status' => SyncStatus::UPDATED->value,
                    ]);
                }
                
                $processedUniqueIds[] = $sourceId;
            } catch (\Exception $e) {
                Log::error('[FetchCnnCommand] 記錄同步狀態失敗', [
                    'source_id' => $sourceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        $this->info("已處理 " . count($processedUniqueIds) . " 個唯一 ID 的同步狀態");
        $this->info("  - 新增: {$createdCount}");
        $this->info("  - 更新（版本較大）: {$updatedCount}");
        $this->info("  - 跳過（版本未變）: {$skippedCount}");
    }
}

