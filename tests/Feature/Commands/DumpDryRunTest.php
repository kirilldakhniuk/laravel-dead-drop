<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
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
