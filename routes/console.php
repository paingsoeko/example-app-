<?php

use App\Http\Controllers\FeatureTestController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Cache::put(FeatureTestController::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());

    Log::info('Laravel Scheduler is working!', [
        'executed_at' => now()->toDateTimeString(),
    ]);
})->everyMinute()->name('test-scheduler');
