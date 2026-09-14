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
use Illuminate\Support\Facades\Artisan;
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

it('exports only the root ids when the root table is a lookup table', function () {
    // A lookup table is copied whole *as a lookup* — but as a root it is asked
    // for by id like any other, and its key set holds exactly what the plan
    // counted. Taking it whole would put rows nobody planned into the artifact.
    $path = initFixtureConfig();

    expect((require $path.'/dd_test.php')['countries']['class'])->toBe('lookup');

    $id = dumpFixture('dd_test.countries:1', $path);
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $manifest = $reader->manifest($id);
    $countries = collect($manifest->tables)->firstWhere('table', 'countries');

    expect($countries->rows)->toBe(1)
        ->and(array_column(iterator_to_array($reader->rows($id, $countries), false), 'id'))->toBe([1]);
});

it('prints progress and the artifact location', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Artifact: local:dead-drops/')
        ->assertSuccessful();
});

it('refuses to extract when the gate fails and writes nothing', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--disk' => 'local'])
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

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--disk' => 'local'])
        ->expectsOutputToContain('does not support the sqlite driver')
        ->assertFailed();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('leaves no key tables behind after a real dump', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $leftovers = collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all();

    expect($leftovers)->toBe([]);
});

it('uses the only configured connection without asking', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Using connection [dd_test].')
        ->assertSuccessful();
});

it('accepts several ids as arguments and as a comma list', function () {
    $path = initFixtureConfig();

    $plan = function (array $ids) use ($path): string {
        Artisan::call('dead-drop:dump', ['table' => 'companies', 'ids' => $ids, '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true]);

        return Artisan::output();
    };

    expect($plan(['1', '2']))->toMatch('/companies\s*\|\s*2\s*\|/')
        ->and($plan(['1,2']))->toMatch('/companies\s*\|\s*2\s*\|/');
});

it('prompts for table, ids and mode when run bare', function () {
    $path = initFixtureConfig();

    // The table choices are ordered by how much of the schema points at them,
    // so the row an operator is most likely to want is the first one offered.
    $this->artisan('dead-drop:dump', ['--path' => $path])
        ->expectsChoice('Which table holds the root row?', 'companies', ['companies', 'users', 'customers', 'orders', 'comments', 'countries', 'order_items'], true)
        ->expectsQuestion('Which id(s)? Separate several with commas', '1')
        ->expectsChoice('What now?', 'plan', ['plan' => 'Plan only (dry run)', 'dump' => 'Dump to local:dead-drops'])
        ->expectsOutputToContain('Total rows')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('prompts for the connection when several are configured and none is default', function () {
    SchemaBuilder::migrate('dd_analytics');
    $path = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');

    $this->artisan('dead-drop:dump', ['--path' => $path])
        ->expectsChoice('Which connection holds the root row?', 'dd_test', ['dd_analytics', 'dd_test'])
        ->expectsChoice('Which table holds the root row?', 'companies', ['companies', 'users', 'customers', 'orders', 'comments', 'countries', 'order_items'])
        ->expectsQuestion('Which id(s)? Separate several with commas', '1')
        ->expectsChoice('What now?', 'plan', ['plan' => 'Plan only (dry run)', 'dump' => 'Dump to local:dead-drops'])
        ->assertSuccessful();
});

it('fails with guidance when non-interactive and incomplete', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Pass a table argument')
        ->assertFailed();

    $this->artisan('dead-drop:dump', ['table' => 'companies', '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('Pass one or more ids')
        ->assertFailed();

    SchemaBuilder::migrate('dd_analytics');
    $both = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $both, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');

    $this->artisan('dead-drop:dump', ['--path' => $both, '--no-interaction' => true])
        ->expectsOutputToContain('configured connections: dd_analytics, dd_test')
        ->assertFailed();
});

it('refuses a table that is not configured for dumping', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'failed_jobs', 'ids' => ['1'], '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('is not configured for dumping')
        ->assertFailed();
});

it('refuses an unknown connection', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'nope', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Unknown database connection [nope].')
        ->assertFailed();
});

it('refuses a connection whose every table is skipped', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace(
        ["'class' => 'data'", "'class' => 'lookup'"],
        "'class' => 'skip'",
        (string) file_get_contents($file),
    ));

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--no-interaction' => true])
        ->expectsOutputToContain('No table on connection [dd_test] is configured for dumping.')
        ->assertFailed();
});
