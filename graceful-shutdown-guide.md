# 優雅關機和任務保護指南

## 問題分析

當容器重啟時，正在執行的任務會被強制中斷，可能導致：

1. **Gemini API 調用中斷**：
   - API 已扣費但回應遺失
   - 影片狀態停留在 `PROCESSING`
   - 需要手動重置狀態才能重新分析

2. **文件操作中斷**：
   - GCS 上傳未完成（較安全，有驗證機制）
   - 本地文件可能已刪除（有先上傳後刪除機制）

3. **數據庫寫入中斷**：
   - 可能產生部分數據
   - 無交易保護（Laravel 默認單條操作）

---

## 解決方案

### 1. 實現優雅關機（Graceful Shutdown）

#### 1.1 修改 Laravel Scheduler 配置

在 `docker/supervisord.d/laravel-scheduler.conf` 中已經有：

```ini
stopsignal=TERM
stopwaitsecs=300
```

這會給排程 300 秒時間完成當前任務。

#### 1.2 在 Artisan Commands 中添加信號處理

**修改 `AnalyzeVideoCommand.php`：**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AnalyzeVideoCommand extends Command
{
    private bool $shouldStop = false;
    
    public function handle(): int
    {
        // 註冊信號處理器
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->warn("\n收到關閉信號，將在當前任務完成後停止...");
                $this->shouldStop = true;
            });
            
            pcntl_signal(SIGINT, function () {
                $this->warn("\n收到中斷信號，將在當前任務完成後停止...");
                $this->shouldStop = true;
            });
        }
        
        // 在循環中檢查
        foreach ($videos as $video) {
            // 檢查是否應該停止
            if ($this->shouldStop) {
                $this->warn("正在優雅關閉，已處理 {$processedCount} 個影片");
                break;
            }
            
            // 啟用信號處理
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            // 處理影片...
            try {
                $this->analyzeService->executeVideoAnalysis($video->id);
                $processedCount++;
            } catch (\Exception $e) {
                // 錯誤處理...
            }
        }
        
        return Command::SUCCESS;
    }
}
```

---

### 2. 添加超時檢測和自動恢復

#### 2.1 創建超時檢測命令

**新增 `app/Console/Commands/RecoverStuckAnalysisCommand.php`：**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\VideoRepository;
use App\Enums\AnalysisStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverStuckAnalysisCommand extends Command
{
    protected $signature = 'analysis:recover
                          {--timeout=3600 : 超時時間（秒），默認 1 小時}
                          {--dry-run : 只顯示會被重置的記錄}';

    protected $description = '恢復卡住的分析任務（超時未完成）';

    public function handle(VideoRepository $videoRepository): int
    {
        $timeout = (int) $this->option('timeout');
        $dryRun = $this->option('dry-run');
        
        $timeoutAgo = now()->subSeconds($timeout);
        
        $this->info("查找超過 {$timeout} 秒未更新的 PROCESSING 狀態影片...");
        
        // 查找卡住的任務
        $stuckVideos = DB::table('videos')
            ->where('analysis_status', AnalysisStatus::PROCESSING->value)
            ->where('updated_at', '<', $timeoutAgo)
            ->get();
        
        if ($stuckVideos->isEmpty()) {
            $this->info("✅ 沒有發現卡住的任務");
            return Command::SUCCESS;
        }
        
        $this->warn("發現 {$stuckVideos->count()} 個卡住的任務：");
        
        $table = [];
        foreach ($stuckVideos as $video) {
            $stuckTime = now()->diffInMinutes($video->updated_at);
            $table[] = [
                $video->id,
                $video->source_id,
                $video->updated_at,
                "{$stuckTime} 分鐘前",
            ];
        }
        
        $this->table(
            ['ID', 'Source ID', '最後更新', '卡住時間'],
            $table
        );
        
        if ($dryRun) {
            $this->info("這是 Dry Run，不會實際修改數據");
            return Command::SUCCESS;
        }
        
        if (!$this->confirm("是否將這些任務重置為 METADATA_EXTRACTING 狀態？")) {
            $this->info("已取消");
            return Command::SUCCESS;
        }
        
        // 重置狀態
        $resetCount = 0;
        foreach ($stuckVideos as $video) {
            try {
                $videoRepository->updateAnalysisStatus(
                    $video->id,
                    AnalysisStatus::METADATA_EXTRACTING,
                    new \DateTime()
                );
                $resetCount++;
                
                Log::info('[RecoverStuckAnalysis] 重置卡住的任務', [
                    'video_id' => $video->id,
                    'source_id' => $video->source_id,
                    'stuck_at' => $video->updated_at,
                ]);
            } catch (\Exception $e) {
                $this->error("重置 Video ID {$video->id} 失敗: {$e->getMessage()}");
            }
        }
        
        $this->info("✅ 成功重置 {$resetCount} 個任務");
        
        return Command::SUCCESS;
    }
}
```

#### 2.2 添加到排程

**修改 `routes/console.php`：**

```php
use Illuminate\Support\Facades\Schedule;

// 每 10 分鐘檢查一次超時任務
Schedule::command('analysis:recover --timeout=3600')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->runInBackground();
```

---

### 3. 部署前檢查清單

#### 更新前：

```bash
# 1. 檢查當前排程狀態
./deploy.sh --check

# 2. 查看正在執行的任務
docker compose exec app ps aux | grep artisan

# 3. 檢查是否有 PROCESSING 狀態的影片
docker compose exec app php artisan tinker --execute="
DB::table('videos')->where('analysis_status', 'processing')->count()
"
```

#### 更新時（使用新的優雅關機）：

```bash
# 使用 --skip-build 快速更新（如果只有代碼變更）
./update-and-deploy.sh --skip-build
```

#### 更新後：

```bash
# 1. 檢查排程是否正常
./deploy.sh --check

# 2. 手動恢復卡住的任務（如果有）
docker compose exec app php artisan analysis:recover --dry-run
docker compose exec app php artisan analysis:recover
```

---

### 4. 監控和告警

#### 4.1 添加健康檢查腳本

**新增 `health-check.sh`：**

```bash
#!/bin/bash

# 檢查卡住的任務
STUCK_COUNT=$(docker compose exec -T app php artisan tinker --execute="
echo DB::table('videos')
    ->where('analysis_status', 'processing')
    ->where('updated_at', '<', now()->subHour())
    ->count();
" 2>/dev/null | tail -1)

if [ "$STUCK_COUNT" -gt 0 ]; then
    echo "⚠️  警告: 發現 $STUCK_COUNT 個卡住的任務"
    # 可以在這裡添加告警通知（例如發送郵件、Slack 等）
else
    echo "✅ 所有任務正常"
fi
```

---

### 5. 使用 Database Transactions（進階）

對於需要多步驟操作的關鍵流程，使用交易：

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($videoId, $analysis) {
    // 1. 保存分析結果
    $this->analysisResultRepository->save([
        'video_id' => $videoId,
        'short_summary' => $analysis['short_summary'],
        // ...
    ]);
    
    // 2. 更新影片狀態
    $this->videoRepository->updateAnalysisStatus(
        $videoId,
        AnalysisStatus::VIDEO_ANALYSIS_COMPLETED,
        new \DateTime()
    );
});
```

---

## 最佳實踐

### 部署流程

1. **非緊急更新**（只有代碼變更，無 Dockerfile 變更）：
   ```bash
   ./update-and-deploy.sh --skip-build
   ```
   - 不會重建映像，速度快
   - 容器會優雅重啟（300 秒緩衝）

2. **需要重建時**（有 Dockerfile 或依賴變更）：
   ```bash
   # 先檢查狀態
   ./deploy.sh --check
   
   # 等待當前批次完成（查看日誌）
   docker compose exec app tail -f /var/log/supervisor/scheduler.log
   
   # 然後執行完整部署
   ./update-and-deploy.sh
   ```

3. **緊急更新**：
   ```bash
   # 直接部署，接受可能會中斷當前任務
   ./update-and-deploy.sh
   
   # 部署後立即恢復卡住的任務
   docker compose exec app php artisan analysis:recover
   ```

---

## 總結

### 當前保護級別：⭐⭐⭐☆☆（中等）

- ✅ 文件上傳安全（先上傳後刪除）
- ✅ 狀態追蹤（可手動恢復）
- ✅ 錯誤處理（自動標記失敗）
- ❌ 無優雅關機
- ❌ 無自動恢復機制

### 實施建議後：⭐⭐⭐⭐⭐（高）

- ✅ 優雅關機（300 秒緩衝）
- ✅ 自動超時檢測
- ✅ 自動恢復機制
- ✅ 交易保護
- ✅ 監控告警

---

## 後續待實現

1. ✅ 創建 `RecoverStuckAnalysisCommand`
2. ✅ 修改 Artisan Commands 添加信號處理
3. ✅ 添加到排程（自動恢復）
4. ⭕ 實現健康檢查和告警
5. ⭕ 添加 Database Transactions

