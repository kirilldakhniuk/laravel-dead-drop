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
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('dd_test.orders')
        ->expectsOutputToContain('Loaded')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2);
});

it('asks for confirmation and aborts on no', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
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

    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    $path = 'dead-drops/'.$id.'/manifest.json';
    Storage::disk('local')->put($path, str_replace('"complete"', '"writing"', Storage::disk('local')->get($path)));

    $this->artisan('dead-drop:pull', ['id' => $id, '--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('is incomplete')
        ->assertFailed();
});

it('runs the configured after hooks with the report', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('dead-drop.pull.after', [RecordingAfterHook::class]);

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])->assertSuccessful();

    expect(RecordingAfterHook::$report?->loaded['dd_test.orders'])->toBe(2);
});

it('runs an artisan command after hook and streams its output', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('dead-drop.pull.after', ['dead-drop:dumps --disk=local']);

    // `Root` is a column header only `dead-drop:dumps` prints, so seeing it
    // proves the hook ran and wrote through this command's output.
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain($id)
        ->expectsOutputToContain('Root')
        ->assertSuccessful();
});

it('fails clearly when an after hook cannot be resolved', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('dead-drop.pull.after', ['App\\Hooks\\Missing']);

    // The rows are already in the target, so the summary has to be on screen
    // before the hook failure is reported.
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Loaded')
        ->expectsOutputToContain('After hook [App\\Hooks\\Missing] failed:')
        ->expectsOutputToContain('The artifact was loaded; only the after hook failed.')
        ->assertFailed();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2);
});

it('skips the prompt with --no-interaction', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--no-interaction' => true])
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(2);
});

it('refuses to load back into a source connection of the artifact', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_test', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Refusing to load into [dd_test]: it is a source connection of this artifact.')
        ->assertFailed();

    // The slice only ever held company 1, so the untouched rows of company 2
    // are the proof nothing was replaced.
    expect(DB::connection('dd_test')->table('orders')->count())->toBe(3)
        ->and(DB::connection('dd_test')->table('users')->where('id', 10)->value('email'))->toBe('a@acme.test');
});

it('refuses an unknown target connection', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'nope', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();
});

it('prompts for the artifact and the target connection when run bare', function () {
    $path = initFixtureConfig();
    dumpFixture('dd_test.companies:1', $path);
    dumpFixture('dd_test.companies:2', $path);

    // Only the fixture connections are offered, so the choice list is the
    // candidate rule itself rather than whatever Testbench ships.
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
    dumpFixture('dd_test.companies:1', $path);
    dumpFixture('dd_test.companies:2', $path);
    dumpFixture('dd_test.companies:1', $path);

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

it('explains how to add a target when every connection is a source', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('database.connections', ['dd_test' => config('database.connections.dd_test')]);

    $this->artisan('dead-drop:pull', ['--disk' => 'local', '--no-interaction' => true, '--force' => true])
        ->expectsOutputToContain('nowhere safe to load it')
        ->expectsOutputToContain("'local_copy' =>")
        ->expectsOutputToContain('--connection=local_copy')
        ->assertFailed();
});

it('lists candidates when the default connection is a source in non-interactive mode', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('database.default', 'dd_test');
    config()->set('database.connections', Arr::only((array) config('database.connections'), ['dd_test', 'dd_analytics', 'dd_target']));

    $this->artisan('dead-drop:pull', ['--disk' => 'local', '--no-interaction' => true, '--force' => true])
        ->expectsOutputToContain('The default connection [dd_test] is a source of this artifact; pass --connection=<name>. Candidates: dd_analytics, dd_target.')
        ->assertFailed();
});

it('appends candidates to the source-connection refusal', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());
    config()->set('database.connections', Arr::only((array) config('database.connections'), ['dd_test', 'dd_analytics', 'dd_target']));

    $this->artisan('dead-drop:pull', ['--connection' => 'dd_test', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Refusing to load into [dd_test]: it is a source connection of this artifact. Target one of: dd_analytics, dd_target.')
        ->assertFailed();
});
