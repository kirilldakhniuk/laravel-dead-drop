<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Loading\PullReport;
use DeadDrop\DeadDrop\Loading\PullRunner;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    SchemaBuilder::migrate('dd_target');
    fakeArtifactDisk();
});

function pullFixtureArtifact(string $id): PullReport
{
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');

    return app(PullRunner::class)->run($reader->manifest($id), $reader, 'dd_target');
}

it('loads every table of the artifact into the target in manifest order', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    $report = pullFixtureArtifact($id);

    expect($report->loaded['dd_test.orders'])->toBe(2)
        ->and(DB::connection('dd_target')->table('orders')->orderBy('id')->pluck('id')->all())->toBe([1, 2])
        ->and(DB::connection('dd_target')->table('orders')->where('id', 99)->exists())->toBeFalse()
        ->and(DB::connection('dd_target')->table('users')->where('id', 10)->value('email'))->toMatch('/^[0-9a-f]{16}@example\.test$/')
        ->and(DB::connection('dd_target')->table('companies')->where('id', 1)->value('stripe_id'))->toBe('redacted')
        ->and(array_keys($report->loaded)[0])->toBe('dd_test.companies');
});

it('replaces rows on a second pull instead of duplicating them', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    DB::connection('dd_target')->table('companies')->insert(['id' => 1, 'name' => 'Stale', 'stripe_id' => null]);
    DB::connection('dd_target')->table('orders')->insert(['id' => 777, 'company_id' => 1, 'user_id' => 10, 'customer_id' => null, 'total' => 1, 'created_at' => null]);

    pullFixtureArtifact($id);
    pullFixtureArtifact($id);

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2)
        ->and(DB::connection('dd_target')->table('orders')->where('id', 777)->exists())->toBeFalse();
});

it('skips and names a table missing on the target', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    Schema::connection('dd_target')->drop('order_items');

    $report = pullFixtureArtifact($id);

    expect($report->skipped)->toBe(['dd_test.order_items: not present on the target'])
        ->and($report->loaded)->not->toHaveKey('dd_test.order_items');
});

it('refuses before writing when a target column is missing', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    Schema::connection('dd_target')->table('users', fn ($t) => $t->dropColumn('password'));

    expect(fn () => pullFixtureArtifact($id))->toThrow(RuntimeException::class, 'Target table [users] is missing columns: password')
        ->and(DB::connection('dd_target')->table('companies')->count())->toBe(0);
});

it('rolls a table back on a row count mismatch and restores foreign key checks', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $manifest = $reader->manifest($id);
    $tables = array_map(fn (TableManifest $t) => $t->table === 'orders' ? new TableManifest($t->connection, $t->table, $t->file, $t->format, 5, $t->bytes, $t->primaryKey, $t->columns, $t->redacted) : $t, $manifest->tables);
    $tampered = new Manifest($manifest->id, $manifest->status, $manifest->createdAt, $manifest->packageVersion, $manifest->root, $manifest->since, $manifest->executor, $manifest->connections, $tables, $manifest->unresolved);

    expect(fn () => app(PullRunner::class)->run($tampered, $reader, 'dd_target'))->toThrow(RuntimeException::class, 'Row count mismatch for [dd_test.orders]')
        ->and(DB::connection('dd_target')->table('orders')->count())->toBe(0)
        ->and((int) DB::connection('dd_target')->scalar('PRAGMA foreign_keys'))->toBe(1);
});
