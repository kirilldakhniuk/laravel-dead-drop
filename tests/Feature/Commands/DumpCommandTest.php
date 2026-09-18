<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\Executor;
use DeadDrop\DeadDrop\Extraction\ExecutorManager;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('writes a complete artifact whose counts match the plan', function () {
    $path = initFixtureConfig();
    $id = dumpFixture($path);
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id);

    $plan = app(Planner::class)->planFull((new ConfigLoader)->loadAll($path), new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]), 'dd_test');
    $expected = [];
    foreach ($plan->steps as $step) {
        $expected["{$step->connection}.{$step->table}"] = $step->rows;
    }
    $actual = [];
    foreach ($manifest->tables as $table) {
        $actual[$table->key()] = $table->rows;
    }

    expect($manifest->isComplete())->toBeTrue()
        ->and($actual)->toBe($expected)
        ->and($manifest->root)->toBe('dd_test:*')
        ->and($manifest->executor)->toBe('php')
        ->and(collect($manifest->tables)->firstWhere('table', 'users')->redacted)->toBe(['email', 'password']);
});

it('prints progress and the artifact location', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Artifact: local:dead-drops/')
        ->assertSuccessful();
});

it('refuses to extract when the gate fails and writes nothing', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);
    config()->set('app.key', '');
    app()->forgetInstance(RedactionContext::class);

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('redaction.salt is not set and APP_KEY is empty')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('dumps with zero configuration when APP_KEY is set', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);
    config()->set('app.key', 'base64:some-app-key');
    app()->forgetInstance(RedactionContext::class);

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->assertSuccessful();

    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $manifest = $reader->manifest(latestArtifactId());
    $users = collect($manifest->tables)->firstWhere('table', 'users');
    $rows = iterator_to_array($reader->rows($manifest->id, $users), false);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['email'])->toMatch('/^[0-9a-f]+@example\.test$/');
    }
});

it('refuses an executor that does not support the source driver', function () {
    $path = initFixtureConfig();
    $executor = Mockery::mock(Executor::class);
    $executor->shouldReceive('name')->andReturn('custom');
    $executor->shouldReceive('supports')->andReturn(false);
    app(ExecutorManager::class)->extend('custom', fn () => $executor);
    config()->set('dead-drop.executor', 'custom');

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('does not support the sqlite driver')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('leaves no key tables behind after a real dump', function () {
    dumpFixture(initFixtureConfig());

    $leftovers = collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all();

    expect($leftovers)->toBe([]);
});

it('refuses a dump with nothing in it and writes no artifact', function () {
    $path = initFixtureConfig();
    skipEveryTable($path.'/dd_test.php');

    // A connection whose every table is skipped is a reviewed decision, but an
    // artifact holding no table at all is a surprise nobody asked for.
    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('Nothing to dump: every table in scope is skipped or missing.')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('uses the only configured connection without asking', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Using connection [dd_test].')
        ->assertSuccessful();
});

it('asks whether to plan or dump when run bare', function () {
    $path = initFixtureConfig();

    // One connection is configured, so nothing about the scope is in doubt:
    // the only question left is whether this run writes anything.
    $this->artisan('dead-drop:dump', ['--path' => $path])
        ->expectsChoice('What now?', 'plan', ['plan' => 'Plan only (dry run)', 'dump' => 'Dump to local:dead-drops'])
        ->expectsOutputToContain('Total rows')
        ->expectsOutputToContain('Planned only; nothing was written.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('prompts for the connection when several are configured and none is default', function () {
    SchemaBuilder::migrate('dd_analytics');
    $path = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');

    $this->artisan('dead-drop:dump', ['--path' => $path])
        ->expectsChoice('Which connection should be dumped?', 'dd_test', ['dd_analytics', 'dd_test'])
        ->expectsChoice('What now?', 'plan', ['plan' => 'Plan only (dry run)', 'dump' => 'Dump to local:dead-drops'])
        ->assertSuccessful();
});

it('refuses an unknown connection', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'nope', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Unknown database connection [nope].')
        ->assertFailed();
});

it('dumps the whole database and the artifact matches the source', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--disk' => 'local', '--no-interaction' => true])
        ->assertSuccessful();

    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest(latestArtifactId());
    $rows = [];

    foreach ($manifest->tables as $table) {
        $rows[$table->table] = $table->rows;
    }

    // Every `data` and `lookup` table with rows, and nothing else: `failed_jobs`
    // is skipped and `comments` is empty.
    expect(array_keys($rows))->toEqualCanonicalizing(['companies', 'users', 'customers', 'orders', 'order_items', 'countries'])
        ->and($manifest->root)->toBe('dd_test:*');

    foreach ($rows as $table => $count) {
        expect($count)->toBe(DB::connection('dd_test')->table($table)->count());
    }
});
