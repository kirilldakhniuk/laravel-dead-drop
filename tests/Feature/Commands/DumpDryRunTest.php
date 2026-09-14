<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
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
});

it('reports row counts per table', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
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

it('reports unresolved references', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Unresolved references')
        ->expectsOutputToContain('users.failed_job_id')
        ->assertSuccessful();
});

it('refuses a table with a composite primary key', function () {
    Schema::connection('dd_test')->create('tag_post', function ($t) {
        $t->unsignedBigInteger('tag_id');
        $t->unsignedBigInteger('post_id');
        $t->primary(['tag_id', 'post_id']);
    });
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('composite primary key')
        ->assertFailed();
});

it('writes no files and leaves no key tables behind', function () {
    Storage::fake('s3');
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true]);

    expect(Storage::disk('s3')->allFiles())->toBe([])
        ->and(collect(Schema::connection('dd_test')->getTables())->pluck('name')->filter(fn ($n) => str_starts_with($n, 'dd_keys_'))->all())->toBe([]);
});

it('fails clearly when a hand written exclude fragment is not valid SQL', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace(
        "'window' => 'created_at',",
        "'window' => 'created_at',\n        'exclude' => 'this is not sql',",
        (string) file_get_contents($file),
    ));

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Planning failed')
        ->assertFailed();
});

it('refuses a dry run whose root id does not exist', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['999'], '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Root id 999 does not exist in dd_test.companies')
        ->assertFailed();
});

it('plans every data and lookup table whole with --all', function () {
    $path = initFixtureConfig();

    // The plan table and the row counts share one line each, so the rendered
    // output is asserted directly rather than through output expectations.
    Artisan::call('dead-drop:dump', ['--all' => true, '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true]);
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

it('refuses --all together with --since', function () {
    $path = initFixtureConfig();

    // Narrowing the windowed tables while their children came along whole
    // would leave rows pointing at nothing, which is the one thing a dump
    // must never write.
    $this->artisan('dead-drop:dump', ['--all' => true, '--since' => '2026-06-01', '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('--all cannot be combined with --since yet; a whole-database dump takes every table whole.')
        ->assertFailed();
});

it('refuses --all together with a table or ids', function () {
    $path = initFixtureConfig();

    $this->artisan('dead-drop:dump', ['table' => 'companies', 'ids' => ['1'], '--all' => true, '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('--all cannot be combined with a table or ids.')
        ->assertFailed();
});

it('names a connection with nothing to dump and plans the rest', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace(
        ["'class' => 'data'", "'class' => 'lookup'"],
        "'class' => 'skip'",
        (string) file_get_contents($file),
    ));

    // Every table skipped is a reviewed decision, not a reason to fail a run
    // that asked for whatever there is.
    $this->artisan('dead-drop:dump', ['--all' => true, '--connection' => 'dd_test', '--path' => $path, '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('No dumpable tables on connection [dd_test]; skipped.')
        ->assertSuccessful();
});

it('covers every configured connection when --all can name none', function () {
    SchemaBuilder::migrate('dd_analytics');
    SchemaBuilder::seedTwoCompanies('dd_analytics');
    $path = tempDirectory();
    $this->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true])->assertSuccessful();
    config()->set('database.default', 'sqlite');

    // Nothing names a connection, none of them is the default, and there is
    // no terminal to ask: `--all` means all of them rather than a failure.
    Artisan::call('dead-drop:dump', ['--all' => true, '--path' => $path, '--dry-run' => true, '--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->toContain('dd_analytics')
        ->toContain('dd_test')
        ->not->toContain('Pass --connection=');
});
