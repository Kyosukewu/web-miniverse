<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAnalysisStatusCommand extends Command
{
    /**
     * 控制台命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'video:reset-status 
                            {--id=* : 指定要重置的影片 ID（必填，可指定多個）}';

    /**
     * 控制台命令描述。
     *
     * @var string
     */
    protected $description = '重置指定影片的分析狀態為 metadata_extracting，使其可以重新分析';

    /**
     * 執行控制台命令。
     *
     * @return int
     */
    public function handle(): int
    {
        $ids = $this->option('id');

        // 驗證必須提供 ID
        if (empty($ids)) {
            $this->error('❌ 請使用 --id 參數指定要重置的影片 ID');
            $this->line('');
            $this->line('範例:');
            $this->line('  php artisan video:reset-status --id=1');
            $this->line('  php artisan video:reset-status --id=1 --id=2 --id=3');
            return Command::FAILURE;
        }

        // 轉換為整數陣列
        $ids = array_map('intval', $ids);

        // 查詢指定 ID 的影片
        $videos = DB::table('videos')
            ->whereIn('id', $ids)
            ->get(['id', 'source_id', 'source_name', 'analysis_status', 'title']);

        // 檢查是否找到資料
        if ($videos->isEmpty()) {
            $this->warn('找不到指定 ID 的影片資料');
            return Command::SUCCESS;
        }

        // 檢查不存在的 ID
        $foundIds = $videos->pluck('id')->toArray();
        $notFoundIds = array_diff($ids, $foundIds);
        if (!empty($notFoundIds)) {
            $this->warn('以下 ID 不存在: ' . implode(', ', $notFoundIds));
            $this->newLine();
        }

        // 顯示將要重置的影片資訊
        $this->info("找到 " . $videos->count() . " 筆影片資料:");
        $this->newLine();

        $tableData = [];
        foreach ($videos as $video) {
            $tableData[] = [
                $video->id,
                $video->source_name,
                $video->source_id,
                $video->analysis_status,
                mb_substr($video->title ?? '', 0, 30) . (mb_strlen($video->title ?? '') > 30 ? '...' : ''),
            ];
        }

        $this->table(
            ['ID', '來源', 'Source ID', '目前狀態', '標題'],
            $tableData
        );

        $this->newLine();
        
        // 確認操作
        $message = "⚠️  確定要將以上 " . $videos->count() . " 筆影片的分析狀態重置為 metadata_extracting 嗎？";
        $this->line('（重置後影片將可以重新被分析）');
        $this->newLine();

        if (!$this->confirm($message)) {
            $this->info('已取消操作');
            return Command::SUCCESS;
        }

        try {
            // 執行更新 - 重置為 metadata_extracting
            $updated = DB::table('videos')
                ->whereIn('id', $foundIds)
                ->update([
                    'analysis_status' => AnalysisStatus::METADATA_EXTRACTING->value,
                    'updated_at' => now(),
                ]);

            $this->newLine();
            $this->info("✅ 已成功重置 {$updated} 筆影片的分析狀態為 metadata_extracting");
            $this->line('這些影片現在可以重新被分析');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ 重置狀態失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

