<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Tests\Fixtures\RecordingAfterHook;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
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
        ->expectsConfirmation("Replace {$count} tables on connection [dd_target] with artifact [{$id}]?", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertSuccessful();

    expect(DB::connection('dd_target')->table('orders')->count())->toBe(0);
});

it('refuses an incomplete artifact and reports when none exists', function () {
    $this->artisan('dead-drop:pull', ['--connection' => 'dd_target', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('No complete artifact found on local:dead-drops.')
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

it('refuses an unknown target connection', function () {
    dumpFixture('dd_test.companies:1', initFixtureConfig());

    $this->artisan('dead-drop:pull', ['--connection' => 'nope', '--disk' => 'local', '--force' => true])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();
});
