<?php

use App\Http\Controllers\FeatureTestController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Route::get('/', [FeatureTestController::class, 'index'])->name('home');

Route::prefix('feature-tests')->name('feature-tests.')->controller(FeatureTestController::class)->group(function () {
    Route::post('records', 'storeRecord')->name('records.store');
    Route::put('records/{record}', 'updateRecord')->name('records.update');
    Route::delete('records/{record}', 'destroyRecord')->name('records.destroy');
    Route::post('storage', 'storage')->name('storage');
    Route::post('cache', 'cache')->name('cache');
    Route::post('queue', 'dispatchJob')->name('queue.dispatch');
    Route::get('queue/{token}', 'jobStatus')->name('queue.status');
    Route::get('scheduler', 'schedulerHeartbeat')->name('scheduler');
    Route::post('websockets', 'broadcastEvent')->name('websockets');
    Route::post('mail', 'sendMail')->middleware('throttle:5,1')->name('mail');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
Route::get('test', function () {

    return 'this is test route';
});

Route::get('/test-s3', function () {
    $disk = Storage::disk('s3');

    $path = 'tests/'.Str::uuid().'.txt';
    $content = 'S3 storage test: '.now()->toIso8601String();

    try {
        // 1. Upload
        $uploaded = $disk->put($path, $content);

        if (! $uploaded) {
            throw new RuntimeException('Upload failed.');
        }

        // 2. Verify file exists
        $exists = $disk->exists($path);

        // 3. Read and verify content
        $readContent = $disk->get($path);
        $contentMatches = $readContent === $content;

        // 4. Delete test file
        $deleted = $disk->delete($path);

        return response()->json([
            'success' => $exists && $contentMatches && $deleted,
            'checks' => [
                'upload' => $uploaded,
                'exists' => $exists,
                'read' => $contentMatches,
                'delete' => $deleted,
            ],
            'path' => $path,
        ]);
    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});
