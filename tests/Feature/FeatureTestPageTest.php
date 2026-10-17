<?php

use App\Http\Controllers\FeatureTestController;
use App\Jobs\FeatureTestJob;
use App\Mail\FeatureTestMail;
use App\Models\TestRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('the root route renders the feature test page', function () {
    TestRecord::factory()->count(2)->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('FeatureTests')
            ->has('records', 2)
            ->has('disks')
            ->where('scheduler.healthy', false)
        );
});

test('records can be created, updated and deleted', function () {
    $this->postJson(route('feature-tests.records.store'), ['title' => 'First', 'body' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('records.0.title', 'First');

    $record = TestRecord::sole();

    $this->putJson(route('feature-tests.records.update', $record), ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('records.0.title', 'Renamed');

    expect($record->fresh()->title)->toBe('Renamed');

    $this->deleteJson(route('feature-tests.records.destroy', $record))
        ->assertOk()
        ->assertJsonCount(0, 'records');

    expect(TestRecord::count())->toBe(0);
});

test('a record requires a title', function () {
    $this->postJson(route('feature-tests.records.store'), ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

test('the storage test writes, reads and deletes a file', function () {
    Storage::fake('s3');

    $this->postJson(route('feature-tests.storage'), ['disk' => 's3'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'checks' => ['upload' => true, 'exists' => true, 'read' => true, 'delete' => true],
        ]);

    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
});

test('the storage test rejects unknown disks', function () {
    $this->postJson(route('feature-tests.storage'), ['disk' => 'missing'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('disk');
});

test('the queue test dispatches a job', function () {
    Queue::fake();

    $token = $this->postJson(route('feature-tests.queue.dispatch'))
        ->assertOk()
        ->assertJsonPath('processed_at', null)
        ->json('token');

    Queue::assertPushed(FeatureTestJob::class, fn (FeatureTestJob $job) => $job->token === $token);
});

test('the queue status reports when the job was processed', function () {
    (new FeatureTestJob('abc'))->handle();

    $this->getJson(route('feature-tests.queue.status', 'abc'))
        ->assertOk()
        ->assertJsonPath('processed_at', Cache::get(FeatureTestJob::cacheKey('abc')));

    $this->getJson(route('feature-tests.queue.status', 'unknown'))
        ->assertJsonPath('processed_at', null);
});

test('the scheduler heartbeat is healthy only when recent', function () {
    Cache::put(FeatureTestController::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());

    $this->getJson(route('feature-tests.scheduler'))->assertJsonPath('healthy', true);

    Cache::put(FeatureTestController::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(5)->toIso8601String());

    $this->getJson(route('feature-tests.scheduler'))->assertJsonPath('healthy', false);
});

test('the scheduled task records a heartbeat', function () {
    $this->artisan('schedule:run')->assertSuccessful();

    expect(Cache::get(FeatureTestController::SCHEDULER_HEARTBEAT_KEY))->not->toBeNull();
});

test('the email test sends or queues a mail', function () {
    Mail::fake();

    $this->postJson(route('feature-tests.mail'), ['email' => 'test@example.com'])
        ->assertOk()
        ->assertJsonPath('queued', false);

    Mail::assertSent(FeatureTestMail::class, fn (FeatureTestMail $mail) => $mail->hasTo('test@example.com'));

    $this->postJson(route('feature-tests.mail'), ['email' => 'test@example.com', 'queued' => true])
        ->assertOk()
        ->assertJsonPath('queued', true);

    Mail::assertQueued(FeatureTestMail::class);
});

test('the email test validates the recipient', function () {
    $this->postJson(route('feature-tests.mail'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});
