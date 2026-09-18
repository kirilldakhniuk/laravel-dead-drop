<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Tests\Fixtures\RecordingAfterHook;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    SchemaBuilder::migrate('dd_target');
    fakeArtifactDisk();
    config()->set('dead-drop.pull.allow_environments', ['testing']);
    RecordingAfterHook::$report = null;
});

it('refuses to run outside the allowed environments', function () {
    config()->set('dead-drop.pull.allow_environments', ['local']);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('refuses to run in the [testing] environment')
        ->assertFailed();
});

it('loads the newest complete artifact into the target with --force', function () {
    dumpFixture(initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(3);
});

it('asks for confirmation and aborts on no', function () {
    dumpFixture(initFixtureConfig());
    $id = latestArtifactId();
    $count = count((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id)->tables);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local'])
        ->expectsConfirmation("Replace {$count} tables on connection [dd_target] (sqlite: :memory:) with artifact [{$id}]?", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(0);
});

it('refuses an incomplete artifact and reports when none exists', function () {
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('No complete artifact found on local:dead-drops. Run dead-drop:dump first.')
        ->assertFailed();

    $id = dumpFixture(initFixtureConfig());
    $path = 'dead-drops/'.$id.'/manifest.json';
    Storage::disk('local')->put($path, str_replace('"complete"', '"writing"', Storage::disk('local')->get($path)));

    $this->artisan('dead-drop:pull', ['id' => $id, '--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('is incomplete')
        ->assertFailed();
});

it('runs the configured after hooks with the report', function () {
    dumpFixture(initFixtureConfig());
    config()->set('dead-drop.pull.after', [RecordingAfterHook::class]);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    expect(RecordingAfterHook::$report?->loaded['dd_test.orders'])->toBe(3);
});

it('runs an artisan command after hook and streams its output', function () {
    $id = dumpFixture(initFixtureConfig());
    config()->set('dead-drop.pull.after', ['dead-drop:dumps --disk=local']);

    // `Root` is a column header only `dead-drop:dumps` prints, so seeing it
    // proves the hook ran and wrote through this command's output.
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain($id)
        ->expectsOutputToContain('Root')
        ->assertSuccessful();
});

it('fails clearly when an after hook cannot be resolved', function () {
    dumpFixture(initFixtureConfig());
    config()->set('dead-drop.pull.after', ['App\\Hooks\\Missing']);

    // The rows are already in the target, so the summary has to be on screen
    // before the hook failure is reported.
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Loaded')
        ->expectsOutputToContain('After hook [App\\Hooks\\Missing] failed:')
        ->expectsOutputToContain('The artifact was loaded; only the after hook failed.')
        ->assertFailed();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(3);
});

it('requires --force when non-interactive', function () {
    dumpFixture(initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --force to load without confirmation when running non-interactively.')
        ->assertFailed();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(0);
});

it('uses the default connection when non-interactive with --force', function () {
    dumpFixture(initFixtureConfig());
    config()->set('database.default', 'dd_target');

    $this->artisan('dead-drop:pull', ['--disk' => 'local', '--no-interaction' => true, '--force' => true])
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(3);
});

it('names the database the target connection is open on, not the one the config now says', function () {
    $id = dumpFixture(initFixtureConfig());
    $count = count((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id)->tables);

    // The connection is already resolved, so a config key edited behind it is
    // exactly what an application using DB_URL looks like: the confirmation
    // has to name the database the load will actually write to.
    expect(DB::connection('dd_target')->getDatabaseName())->toBe(':memory:');
    config()->set('database.connections.dd_target.database', 'not-the-database');

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local'])
        ->expectsConfirmation("Replace {$count} tables on connection [dd_target] (sqlite: :memory:) with artifact [{$id}]?", 'no')
        ->assertSuccessful();
});

it('loads into the connection the artifact was dumped from and warns', function () {
    $id = dumpFixture(initFixtureConfig());
    $manifest = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_test', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('This is the connection the artifact was dumped from')
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    // The source is now its own redacted copy: the slice replaced the tables
    // it names, row for row, and the emails came back hashed.
    foreach ($manifest->tables as $table) {
        expect(DB::connection('dd_test')->table($table->table)->count())->toBe($table->rows);
    }

    expect(DB::connection('dd_test')->table('users')->where('id', 10)->value('email'))
        ->toMatch('/^[0-9a-f]{16}@example\\.test$/');
});

it('mentions a matching source database name in the confirmation', function () {
    $id = dumpFixture(initFixtureConfig());
    $count = count((new ArtifactReader(Storage::disk('local'), 'dead-drops'))->manifest($id)->tables);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_test', '--disk' => 'local'])
        ->expectsConfirmation("Replace {$count} tables on connection [dd_test] (sqlite: :memory:) with artifact [{$id}]? \u{2014} same database name as the source", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertSuccessful();

    // Answering no leaves the source exactly as it was.
    expect(DB::connection('dd_test')->table('orders')->count())->toBe(3)
        ->and(DB::connection('dd_test')->table('users')->where('id', 10)->value('email'))->toBe('a@acme.test');
});

it('refuses an unknown target connection', function () {
    dumpFixture(initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'nope', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();
});

it('prompts for the artifact and the target connection when run bare', function () {
    $path = initFixtureConfig();
    dumpFixture($path);
    dumpFixture($path);

    // Only the fixture connections are configured, so the choice list is
    // every one of them rather than whatever Testbench ships.
    config()->set('database.connections', Arr::only((array) config('database.connections'), ['dd_test', 'dd_analytics', 'dd_target']));

    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    $ids = $reader->ids();
    $newest = $reader->manifest($ids[0]);

    $this->artisan('dead-drop:pull', ['--disk' => 'local'])
        ->expectsChoice('Which artifact should be loaded?', $ids[0], [
            $ids[0] => artifactLabel($newest),
            $ids[1] => artifactLabel($reader->manifest($ids[1])),
        ], true)
        ->expectsChoice('Which connection should receive the data?', 'dd_target', [
            'dd_test' => 'dd_test (sqlite: :memory:)',
            'dd_analytics' => 'dd_analytics (sqlite: :memory:)',
            'dd_target' => 'dd_target (sqlite: :memory:)',
        ], true)
        ->expectsConfirmation(
            'Replace '.count($newest->tables)." tables on connection [dd_target] (sqlite: :memory:) with artifact [{$ids[0]}]?",
            'yes',
        )
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    $companies = 0;

    foreach ($newest->tables as $table) {
        if ($table->table === 'companies') {
            $companies = $table->rows;
        }
    }

    expect(DB::connection('dd_target')->table('companies')->count())->toBe($companies);
});

it('does not offer incomplete artifacts and says so', function () {
    $path = initFixtureConfig();
    dumpFixture($path);
    dumpFixture($path);
    dumpFixture($path);

    $reader = new ArtifactReader(Storage::disk('local'), 'dead-drops');
    [$newest, $writing, $oldest] = $reader->ids();
    $manifest = 'dead-drops/'.$writing.'/manifest.json';
    Storage::disk('local')->put($manifest, str_replace('"complete"', '"writing"', (string) Storage::disk('local')->get($manifest)));

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('1 incomplete artifact(s) not offered.')
        ->expectsChoice('Which artifact should be loaded?', $newest, [
            $newest => artifactLabel($reader->manifest($newest)),
            $oldest => artifactLabel($reader->manifest($oldest)),
        ], true)
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();
});
