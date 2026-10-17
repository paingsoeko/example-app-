<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FeatureTestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $token) {}

    /**
     * Get the cache key that records when the job with the given token was processed.
     */
    public static function cacheKey(string $token): string
    {
        return "feature-test:queue:{$token}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put(self::cacheKey($this->token), now()->toIso8601String(), now()->addHour());
    }
}
