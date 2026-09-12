<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\Executor;
use DeadDrop\DeadDrop\Extraction\ExecutorManager;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('writes a complete artifact whose counts match the plan', function () {
    $path = initFixtureConfig();
    $id = dumpFixture('dd_test.companies:1', $path);
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id);

    $plan = app(Planner::class)->plan(Root::parse('dd_test.companies:1'), (new ConfigLoader)->loadAll($path), new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]));
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
        ->and($manifest->root)->toBe('dd_test.companies:1')
        ->and($manifest->executor)->toBe('php')
        ->and(collect($manifest->tables)->firstWhere('table', 'users')->redacted)->toBe(['email', 'password']);
});

it('prints progress and the artifact location', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Artifact: local:dead-drops/')
        ->assertSuccessful();
});

it('refuses to extract when the gate fails and writes nothing', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('redaction.salt must be set')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an executor that does not support the source driver', function () {
    $path = initFixtureConfig();
    $executor = Mockery::mock(Executor::class);
    $executor->shouldReceive('name')->andReturn('custom');
    $executor->shouldReceive('supports')->andReturn(false);
    app(ExecutorManager::class)->extend('custom', fn () => $executor);
    config()->set('dead-drop.executor', 'custom');

    $this->artisan('dead-drop:dump', ['--root' => 'dd_test.companies:1', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('does not support the sqlite driver')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('leaves no key tables behind after a real dump', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $leftovers = collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all();

    expect($leftovers)->toBe([]);
});
