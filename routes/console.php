<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled commands. These commands
| will be executed by Laravel's task scheduler.
|
*/

// CNN 資源抓取：每 30 分鐘執行一次（優先執行，為後續分析提供資料）
Schedule::command('fetch:cnn')->everyThirtyMinutes()->onOneServer()->runInBackground();

// CNN XML 文檔分析：每 10 分鐘執行一次（依賴 fetch:cnn 的結果）
Schedule::command('analyze:document --source=CNN --storage=gcs')->everyTenMinutes()->onOneServer()->runInBackground();

// CNN MP4 影片分析：每 15 分鐘執行一次（依賴 analyze:document 的結果）
Schedule::command('analyze:video --source=CNN --storage=gcs')->everyFifteenMinutes()->onOneServer()->runInBackground();
