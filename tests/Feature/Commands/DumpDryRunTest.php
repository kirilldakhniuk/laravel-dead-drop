<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    // A dry run rehearses the extraction gate, which needs a resolvable salt
    // to report anything but its own absence.
    fakeArtifactDisk();
});

it('reports row counts per table', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('orders')
        ->expectsOutputToContain('order_items')
        ->expectsOutputToContain('Total rows')
        ->assertSuccessful();
});

it('orders parents before children', function () {
    $path = initFixtureConfig();
    $config = (new ConfigLoader)->loadAll($path);
    $schemas = new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]);

    $plan = app(Planner::class)->plan(Root::parse('dd_test.companies:1'), $config, $schemas);

    $tables = array_map(fn ($s) => $s->table, $plan->steps);

    expect(array_search('companies', $tables))->toBeLessThan(array_search('orders', $tables))
        ->and(array_search('orders', $tables))->toBeLessThan(array_search('order_items', $tables))
        ->and(array_search('users', $tables))->toBeLessThan(array_search('orders', $tables));
});

it('refuses a table with a composite primary key', function () {
    Schema::connection('dd_test')->create('tag_post', function ($t) {
        $t->unsignedBigInteger('tag_id');
        $t->unsignedBigInteger('post_id');
        $t->primary(['tag_id', 'post_id']);
    });
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('composite primary key')
        ->assertFailed();
});

it('writes no files and leaves no key tables behind', function () {
    Storage::fake('s3');
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true]);

    expect(Storage::disk('s3')->allFiles())->toBe([])
        ->and(collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all())->toBe([]);
});

it('plans every data and lookup table whole', function () {
    $path = initFixtureConfig();

    // The plan table and the row counts share one line each, so the rendered
    // output is asserted directly rather than through output expectations.
    Artisan::call('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toMatch('/companies\s*\|\s*2\s*\|/')
        ->toMatch('/users\s*\|\s*3\s*\|/')
        ->toMatch('/orders\s*\|\s*3\s*\|/')
        ->toMatch('/countries\s*\|\s*2\s*\|/')
        // Skipped tables are not dumpable, and an empty table has nothing to write.
        ->not->toContain('failed_jobs')
        ->not->toContain('comments')
        // Every row of every table is taken, so nothing can be left dangling.
        ->toContain('Unresolved references (0):');
});

it('names a connection with nothing to dump and plans the rest', function () {
    SchemaBuilder::migrate('dd_analytics');
    SchemaBuilder::seedTwoCompanies('dd_analytics');
    $path = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');
    skipEveryTable($path.'/dd_test.php');

    // Every table skipped is a reviewed decision, not a reason to fail a run
    // that asked for whatever there is — as long as something is left.
    Artisan::call('dead-drop:dump', ['--path' => $path, '--dry-run' => true, '--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->toContain('No dumpable tables on connection [dd_test]; skipped.')
        ->toContain('dd_analytics')
        ->toContain('Total rows');
});

it('rehearses the gate on a dry run and still prints the plan', function () {
    $path = initFixtureConfig();
    config()->set('dead-drop.redaction.salt', null);
    config()->set('app.key', '');
    app()->forgetInstance(RedactionContext::class);

    // A plan an operator reads to decide whether to dump has to say that the
    // dump would not start at all.
    $exitCode = Artisan::call('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true, '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Total rows')
        ->toContain('This dump would be refused:')
        ->toContain('redaction.salt is not set and APP_KEY is empty');
});

it('reports a planning failure by the phase it happened in', function () {
    $path = initFixtureConfig();

    // A database the connection can no longer read: the engine's complaint is
    // passed on, named by the phase that ran into it.
    $file = tempDirectory().'/not-a-database.sqlite';
    file_put_contents($file, 'this is not an SQLite database');
    config()->set('database.connections.dd_test.database', $file);
    DB::purge('dd_test');

    $this->artisan('dead-drop:dump', ['--connection' => 'dd_test', '--path' => $path, '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Planning failed:')
        ->assertFailed();
});

it('covers every configured connection when none can be named', function () {
    SchemaBuilder::migrate('dd_analytics');
    SchemaBuilder::seedTwoCompanies('dd_analytics');
    $path = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');

    // Nothing names a connection, none of them is the default, and there is
    // no terminal to ask: the dump covers all of them rather than failing.
    Artisan::call('dead-drop:dump', ['--path' => $path, '--dry-run' => true, '--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->toContain('dd_analytics')
        ->toContain('dd_test')
        ->not->toContain('Pass --connection=');
});
