<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Loading\PullReport;
use DeadDrop\DeadDrop\Loading\PullRunner;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\QueryException;
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

    // `companies` loads before `orders`, so its surviving rows are the proof
    // that the rollback took only the table that failed.
    expect(fn () => app(PullRunner::class)->run($tampered, $reader, 'dd_target'))->toThrow(RuntimeException::class, 'Row count mismatch for [dd_test.orders]')
        ->and(DB::connection('dd_target')->table('orders')->count())->toBe(0)
        ->and(DB::connection('dd_target')->table('companies')->count())->toBeGreaterThan(0)
        ->and((int) DB::connection('dd_target')->scalar('PRAGMA foreign_keys'))->toBe(1);
});

it('refuses a format it has no loader for before writing anything', function () {
    $manifest = new Manifest('20260101-000000-aaaaaa', Manifest::STATUS_COMPLETE, '2026-01-01T00:00:00+00:00', 'dev', 'dd_test.companies:1', null, 'php', [], [
        new TableManifest('dd_test', 'companies', 'dd_test.companies.csv', 'csv', 1, 0, 'id', [
            ['name' => 'id', 'type' => 'int'], ['name' => 'name', 'type' => 'string'], ['name' => 'stripe_id', 'type' => 'string'],
        ], []),
    ], []);

    DB::connection('dd_target')->table('companies')->insert(['id' => 1, 'name' => 'Stale', 'stripe_id' => null]);

    expect(fn () => app(PullRunner::class)->run($manifest, new ArtifactReader(Storage::disk('local'), 'dead-drops'), 'dd_target'))
        ->toThrow(InvalidArgumentException::class, 'No loader for artifact format [csv].')
        ->and(DB::connection('dd_target')->table('companies')->where('name', 'Stale')->exists())->toBeTrue();
});

it('refuses before writing when the target needs a column the artifact has no value for', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    // SQLite cannot ALTER a NOT NULL column in, so the target table is
    // rebuilt with one the dump knows nothing about.
    DB::connection('dd_target')->statement('DROP TABLE orders');
    DB::connection('dd_target')->statement('CREATE TABLE orders (id integer primary key autoincrement, company_id integer not null, user_id integer not null, customer_id integer, total numeric not null, created_at datetime, extra varchar not null)');

    expect(fn () => pullFixtureArtifact($id))
        ->toThrow(RuntimeException::class, 'Target table [orders] requires columns the artifact does not carry: extra')
        ->and(DB::connection('dd_target')->table('companies')->count())->toBe(0);
});

it('names the table when the target refuses a row, without echoing the row', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    // A CHECK the target has and the source did not: the engine's message
    // names the constraint, never which table was being loaded.
    DB::connection('dd_target')->statement('DROP TABLE order_items');
    DB::connection('dd_target')->statement('CREATE TABLE order_items (id integer primary key autoincrement, order_id integer not null, sku varchar not null check (sku = \'nothing\'))');

    try {
        pullFixtureArtifact($id);
    } catch (RuntimeException $e) {
        // Laravel appends `(Connection: …, SQL: insert into … values (…))`,
        // and for an insert those bindings are the row itself — the one thing
        // a redacted dump must not print back out.
        expect($e->getMessage())->toStartWith('Loading [dd_test.order_items] failed:')
            ->and($e->getMessage())->not->toContain('SQL:')
            ->and($e->getMessage())->not->toContain('SKU-1')
            ->and($e->getPrevious())->toBeInstanceOf(QueryException::class)
            // The row is in the exception this one wraps, which is what makes
            // the two assertions above worth making.
            ->and($e->getPrevious()?->getMessage())->toContain('SQL:')
            ->and($e->getPrevious()?->getMessage())->toContain('SKU-1');

        return;
    }

    $this->fail('The load was expected to fail.');
});

it('refuses a manifest holding one bare table name from two connections', function () {
    $columns = [['name' => 'id', 'type' => 'int']];
    $manifest = new Manifest('20260101-000000-aaaaaa', Manifest::STATUS_COMPLETE, '2026-01-01T00:00:00+00:00', 'dev', 'a.users:1', null, 'php', [], [
        new TableManifest('a', 'users', 'a.users.ndjson.gz', 'ndjson', 0, 0, 'id', $columns, []),
        new TableManifest('b', 'users', 'b.users.ndjson.gz', 'ndjson', 0, 0, 'id', $columns, []),
    ], []);

    DB::connection('dd_target')->table('users')->insert(['id' => 10, 'company_id' => 1, 'email' => 'keep@example.test', 'password' => 'x', 'created_by' => null, 'failed_job_id' => null]);

    expect(fn () => app(PullRunner::class)->run($manifest, new ArtifactReader(Storage::disk('local'), 'dead-drops'), 'dd_target'))
        ->toThrow(RuntimeException::class, 'Artifact holds table [users] from more than one connection; a single target cannot hold both.')
        ->and(DB::connection('dd_target')->table('users')->count())->toBe(1);
});
