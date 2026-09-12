<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\CircularConnectionException;
use DeadDrop\DeadDrop\Planning\Graph;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');

    Schema::connection('dd_analytics')->create('analytics_sessions', function ($t) {
        $t->id();
        $t->unsignedBigInteger('company_id');
    });
    DB::connection('dd_analytics')->table('analytics_sessions')->insert([
        ['id' => 1, 'company_id' => 1],
        ['id' => 2, 'company_id' => 1],
        ['id' => 9, 'company_id' => 2],
    ]);
});

function crossConnectionConfigDirectory(): string
{
    $path = tempDirectory();

    test()->artisan('dead-drop:init', ['--connection' => ['dd_test', 'dd_analytics'], '--path' => $path, '--no-interaction' => true]);

    $file = $path.'/dd_analytics.php';
    $contents = (string) file_get_contents($file);

    $contents = str_replace(
        "'columns' => ['id', 'company_id'],",
        "'columns' => ['id', 'company_id'],\n        'references' => [\n            'company_id' => ['dd_test.companies.id', 'source' => 'manual'],\n        ],",
        $contents,
    );

    // A table with no sensitive columns and no outbound edges is classified as
    // a lookup; the human edit that adds the cross-connection reference makes
    // it a data table.
    file_put_contents($file, str_replace("'class' => 'lookup',", "'class' => 'data',", $contents));

    return $path;
}

it('collects rows on a second connection via a qualified edge', function () {
    $result = traverseFixture('dd_test.companies:1', crossConnectionConfigDirectory());

    expect(collectedKeys($result, 'analytics_sessions', 'dd_analytics'))->toBe([1, 2]);
});

it('collects cross-connection children added after the parent grew', function () {
    $path = crossConnectionConfigDirectory();

    DB::connection('dd_analytics')->table('analytics_sessions')->insert([
        ['id' => 3, 'company_id' => 2],
    ]);

    $result = traverseFixture('dd_test.companies:1,2', $path);

    expect(collectedKeys($result, 'analytics_sessions', 'dd_analytics'))->toBe([1, 2, 3, 9]);
});

it('refreshes a mirror when the source key set grows', function () {
    $repository = app(KeySetRepository::class);

    $source = $repository->create('dd_test', 'companies', ColumnType::Integer);
    $source->add([1]);

    $mirror = $repository->mirror($source, 'dd_analytics');

    expect($mirror->count())->toBe(1);

    $source->add([2]);
    $refreshed = $repository->mirror($source, 'dd_analytics');

    expect($refreshed->count())->toBe(2)
        ->and($refreshed->tableName)->toBe($mirror->tableName);

    $repository->dropAll();
});

it('keeps mirrors out of the key set listing but drops them with dropAll', function () {
    $repository = app(KeySetRepository::class);

    $source = $repository->create('dd_test', 'companies', ColumnType::Integer);
    $source->add([1]);
    $mirror = $repository->mirror($source, 'dd_analytics');

    expect(array_keys($repository->all()))->toBe(['dd_test.companies']);

    $repository->dropAll();

    // SQLite lists temporary tables under the `temp` schema, so the tables are
    // asked for directly rather than through the schema builder.
    expect(fn () => DB::connection('dd_test')->table($source->tableName)->count())->toThrow(QueryException::class)
        ->and(fn () => DB::connection('dd_analytics')->table($mirror->tableName)->count())->toThrow(QueryException::class);
});

it('orders connections so targets come before the connections that point at them', function () {
    $graph = Graph::fromConfig((new ConfigLoader)->loadAll(crossConnectionConfigDirectory()));

    expect($graph->connectionOrder())->toBe(['dd_test', 'dd_analytics']);
});

it('fails clearly when two connections reference each other', function () {
    $path = crossConnectionConfigDirectory();
    $file = $path.'/dd_test.php';
    file_put_contents($file, str_replace(
        "'company_id' => ['companies.id', 'source' => 'fk'],",
        "'company_id' => ['companies.id', 'source' => 'fk'],\n            'total' => ['dd_analytics.analytics_sessions.id', 'source' => 'manual'],",
        (string) file_get_contents($file),
    ));

    Graph::fromConfig((new ConfigLoader)->loadAll($path))->connectionOrder();
})->throws(CircularConnectionException::class, 'dd_test');

it('pins a connection to its write PDO once it holds a key table', function () {
    $repository = app(KeySetRepository::class);

    $repository->create('dd_test', 'companies', ColumnType::Integer);

    // A temporary key table only exists on the session that created it, so a
    // read/write-split connection must stop reading from its replica.
    expect(DB::connection('dd_test')->getReadPdo())->toBe(DB::connection('dd_test')->getPdo());

    $repository->dropAll();
});
