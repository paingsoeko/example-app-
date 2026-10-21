<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendTestMailRequest;
use App\Http\Requests\TestRecordRequest;
use App\Jobs\FeatureTestJob;
use App\Mail\FeatureTestMail;
use App\Models\TestRecord;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FeatureTestController extends Controller
{
    /**
     * Cache key the scheduled heartbeat task writes its last run time to.
     */
    public const SCHEDULER_HEARTBEAT_KEY = 'feature-test:scheduler:last-run';

    /**
     * Show the feature test page.
     */
    public function index(): Response
    {
        return Inertia::render('FeatureTests', [
            'environment' => [
                'app' => app()->environment(),
                'database' => config('database.default'),
                'filesystem' => config('filesystems.default'),
                'queue' => config('queue.default'),
                'mailer' => config('mail.default'),
                'cache' => config('cache.default'),
                'redis_client' => config('database.redis.client'),
            ],
            'disks' => array_keys(config('filesystems.disks')),
            'cacheStores' => array_keys(config('cache.stores')),
            'records' => $this->latestRecords(),
            'scheduler' => $this->schedulerStatus(),
        ]);
    }

    /**
     * Create a test record.
     */
    public function storeRecord(TestRecordRequest $request): JsonResponse
    {
        $record = TestRecord::create($request->validated());

        return response()->json([
            'record' => $record,
            'records' => $this->latestRecords(),
        ], 201);
    }

    /**
     * Update a test record.
     */
    public function updateRecord(TestRecordRequest $request, TestRecord $record): JsonResponse
    {
        $record->update($request->validated());

        return response()->json([
            'record' => $record,
            'records' => $this->latestRecords(),
        ]);
    }

    /**
     * Delete a test record.
     */
    public function destroyRecord(TestRecord $record): JsonResponse
    {
        $record->delete();

        return response()->json([
            'records' => $this->latestRecords(),
        ]);
    }

    /**
     * Upload, read and delete a temporary file on the given disk.
     */
    public function storage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['required', 'string', Rule::in(array_keys(config('filesystems.disks')))],
        ]);

        $disk = Storage::disk($validated['disk']);
        $path = 'feature-tests/'.Str::uuid().'.txt';
        $content = 'Storage test: '.now()->toIso8601String();

        try {
            $uploaded = $disk->put($path, $content);
            $exists = $disk->exists($path);
            $contentMatches = $disk->get($path) === $content;
            $deleted = $disk->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'disk' => $validated['disk'],
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => $uploaded && $exists && $contentMatches && $deleted,
            'disk' => $validated['disk'],
            'path' => $path,
            'checks' => [
                'upload' => $uploaded,
                'exists' => $exists,
                'read' => $contentMatches,
                'delete' => $deleted,
            ],
        ]);
    }

    /**
     * Write, read, increment, lock and forget a temporary key on the given cache store.
     */
    public function cache(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store' => ['required', 'string', Rule::in(array_keys(config('cache.stores')))],
        ]);

        $store = Cache::store($validated['store']);
        $key = 'feature-tests:cache:'.Str::uuid();
        $value = 'Cache test: '.now()->toIso8601String();

        try {
            $written = $store->put($key, $value, 60);
            $valueMatches = $store->get($key) === $value;

            $store->put($key.':counter', 1, 60);
            $incremented = (int) $store->increment($key.':counter') === 2;

            $lock = $store->getStore() instanceof LockProvider ? $store->lock($key.':lock', 10) : null;
            $locked = $lock?->get() ?? null;
            $lock?->release();

            $deleted = $store->forget($key) && $store->forget($key.':counter') && ! $store->has($key);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'store' => $validated['store'],
                'error' => $exception->getMessage(),
            ], 500);
        }

        $checks = array_filter([
            'write' => $written,
            'read' => $valueMatches,
            'increment' => $incremented,
            'lock' => $locked,
            'delete' => $deleted,
        ], fn (?bool $passed) => $passed !== null);

        return response()->json([
            'success' => ! in_array(false, $checks, true),
            'store' => $validated['store'],
            'driver' => config("cache.stores.{$validated['store']}.driver"),
            'key' => $key,
            'checks' => $checks,
        ]);
    }

    /**
     * Dispatch a job that records when it was processed.
     */
    public function dispatchJob(): JsonResponse
    {
        $token = (string) Str::uuid();

        FeatureTestJob::dispatch($token);

        return response()->json([
            'token' => $token,
            'connection' => config('queue.default'),
            'dispatched_at' => now()->toIso8601String(),
            'processed_at' => Cache::get(FeatureTestJob::cacheKey($token)),
        ]);
    }

    /**
     * Report whether the job with the given token has been processed.
     */
    public function jobStatus(string $token): JsonResponse
    {
        return response()->json([
            'token' => $token,
            'processed_at' => Cache::get(FeatureTestJob::cacheKey($token)),
        ]);
    }

    /**
     * Report when the scheduled heartbeat task last ran.
     */
    public function schedulerHeartbeat(): JsonResponse
    {
        return response()->json($this->schedulerStatus());
    }

    /**
     * Send a test email to the given address.
     */
    public function sendMail(SendTestMailRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $mail = new FeatureTestMail(now()->toIso8601String());

        try {
            if ($request->boolean('queued')) {
                Mail::to($validated['email'])->queue($mail);
            } else {
                Mail::to($validated['email'])->send($mail);
            }
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'email' => $validated['email'],
            'mailer' => config('mail.default'),
            'queued' => $request->boolean('queued'),
        ]);
    }

    /**
     * Get the most recent test records.
     *
     * @return Collection<int, TestRecord>
     */
    private function latestRecords(): Collection
    {
        return TestRecord::query()->latest('id')->limit(20)->get();
    }

    /**
     * Get the scheduler heartbeat status.
     *
     * @return array{last_run_at: string|null, healthy: bool}
     */
    private function schedulerStatus(): array
    {
        $lastRunAt = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

        return [
            'last_run_at' => $lastRunAt,
            'healthy' => $lastRunAt !== null && Carbon::parse($lastRunAt)->gt(now()->subMinutes(2)),
        ];
    }
}
