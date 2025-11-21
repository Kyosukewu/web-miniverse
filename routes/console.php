<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;

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

Schedule::command('fetch:cnn')->hourly()->onOneServer()->runInBackground();

Schedule::command('analyze:document --source=CNN --storage=s3')->everyTenMinutes()->onOneServer()->runInBackground();

Schedule::command('analyze:video --source=CNN --storage=s3')->everyFifteenMinutes()->onOneServer()->runInBackground();
