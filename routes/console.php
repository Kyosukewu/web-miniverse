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

Schedule::command('fetch:cnn')->hourly()->onOneServer()->runInBackground();

Schedule::command('analyze:document --source=CNN --storage=gcs')->everyTenMinutes()->onOneServer()->runInBackground();

Schedule::command('analyze:video --source=CNN --storage=gcs')->everyFifteenMinutes()->onOneServer()->runInBackground();
