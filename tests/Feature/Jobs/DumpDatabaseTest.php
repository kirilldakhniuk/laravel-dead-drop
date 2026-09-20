<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Jobs\DumpDatabase;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 3700);
    Bus::fake();
});

function queueFixtureDump(): DumpDatabase
{
    test()->artisan('dead-drop:dump', [
        '--connection' => 'dd_test', '--path' => initFixtureConfig(), '--queue' => true,
    ])->expectsOutputToContain('Queued artifact:')->assertSuccessful();

    return Bus::dispatched(DumpDatabase::class)->sole();
}

it('queues a whole dump without exporting rows', function () {
    $job = queueFixtureDump();
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($job->artifactId);

    expect($manifest->status)->toBe('queued')
        ->and($manifest->root)->toBe('dd_test:*')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and($job->connection)->toBe('database')
        ->and($job->queue)->toBe('dead-drop');
});

it('executes a serialized job and ignores redelivery after completion', function () {
    $job = unserialize(serialize(queueFixtureDump()));
    app()->call([$job, 'handle']);
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $manifest = $reader->manifest($job->artifactId);
    $files = Storage::disk('local')->allFiles();

    expect($manifest->isComplete())->toBeTrue()->and($manifest->tables)->not->toBeEmpty();
    $users = collect($manifest->tables)->firstWhere('table', 'users');
    $row = iterator_to_array($reader->rows($manifest->id, $users))[0];
    expect($row['email'])->toEndWith('@example.test');

    app()->call([$job, 'handle']);
    expect(Storage::disk('local')->allFiles())->toBe($files)
        ->and($reader->manifest($job->artifactId)->toArray())->toBe($manifest->toArray());
});

it('checks the current config in the worker and marks failures', function () {
    $job = queueFixtureDump();
    file_put_contents($job->configDirectory.'/dd_test.php', '<?php return [];');

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($job->artifactId);
    expect($manifest->status)->toBe('failed')->and($manifest->tables)->toBe([]);
});

it('marks a worker timeout as failed without downgrading completed artifacts', function () {
    $job = queueFixtureDump();
    $job->failed(new RuntimeException('worker timeout'));
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    expect($reader->manifest($job->artifactId)->status)->toBe('failed');

    app()->call([$job, 'handle']);
    $job->failed(new RuntimeException('late failure'));
    expect($reader->manifest($job->artifactId)->status)->toBe(Manifest::STATUS_COMPLETE);
});

it('refuses queue backends that cannot run a durable background dump', function (string $driver) {
    config()->set('queue.connections.unsuitable', ['driver' => $driver]);
    config()->set('dead-drop.queue.connection', 'unsuitable');

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => initFixtureConfig(), '--queue' => true])
        ->expectsOutputToContain('asynchronous queue connection')->assertFailed();
    Bus::assertNothingDispatched();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['sync', 'null', 'deferred', 'background']);

it('rejects queue combined with dry run', function () {
    $this->artisan('dead-drop:dump', ['--queue' => true, '--dry-run' => true])
        ->expectsOutputToContain('--queue cannot be combined with --dry-run')->assertFailed();
    Bus::assertNothingDispatched();
});

it('honours queue routing and validates the reservation timeout', function () {
    config()->set('dead-drop.queue.name', 'exports');
    config()->set('dead-drop.queue.timeout', 120);
    $job = queueFixtureDump();
    expect($job->queue)->toBe('exports')->and($job->timeout)->toBe(120)->and($job->tries)->toBe(1);

    config()->set('queue.connections.database.retry_after', 120);
    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => initFixtureConfig(), '--queue' => true])
        ->expectsOutputToContain('shorter than the queue connection retry_after')->assertFailed();
    Bus::assertDispatchedTimes(DumpDatabase::class, 1);
});

it('does not run another delivery while the artifact is locked', function () {
    $job = queueFixtureDump();
    $middleware = $job->middleware()[0];
    $lock = Cache::lock($middleware->getLockKey($job), 60);
    expect($lock->get())->toBeTrue();
    $called = false;

    try {
        $middleware->handle($job, function () use (&$called): void {
            $called = true;
        });
        expect($called)->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('runs through the real database queue worker', function () {
    Bus::swap(Bus::getFacadeRoot()->dispatcher);
    config()->set('queue.connections.database.connection', 'dd_test');
    Schema::connection('dd_test')->create('jobs', function ($table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => initFixtureConfig(), '--queue' => true])->assertSuccessful();
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $id = $reader->ids()[0];
    expect($reader->manifest($id)->status)->toBe('queued');

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'dead-drop', '--once' => true])->assertSuccessful();
    expect($reader->manifest($id)->isComplete())->toBeTrue()
        ->and(DB::connection('dd_test')->table('jobs')->count())->toBe(0);
});

it('keeps queued and failed artifacts out of pull', function () {
    $job = queueFixtureDump();
    app()->detectEnvironment(fn () => 'local');

    foreach (['queued', 'failed'] as $status) {
        $this->artisan('dead-drop:pull', ['id' => $job->artifactId, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain("status: {$status}")->assertFailed();
        $job->failed(new RuntimeException('failed'));
    }
});
