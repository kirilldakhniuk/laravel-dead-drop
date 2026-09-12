<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('lists artifacts newest first', function () {
    $path = initFixtureConfig();
    dumpFixture('dd_test.companies:1', $path);
    dumpFixture('dd_test.companies:1', $path);

    // Ids carry the UTC timestamp first, so newest-first is a descending sort
    // of the ids themselves — which is what the reader reports and what the
    // listing has to preserve.
    $ids = (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->ids();

    // The id and the status share one table row, and `expectsOutputToContain()`
    // matches at most one expectation per written line, so the rendered output
    // is asserted directly instead.
    $exitCode = Artisan::call('dead-drop:dumps', ['--disk' => 'local']);
    $output = Artisan::output();

    expect($ids)->toHaveCount(2)
        ->and($exitCode)->toBe(0)
        ->and($output)->toContain('complete')
        ->and(strpos($output, $ids[0]))->toBeLessThan(strpos($output, $ids[1]));
});

it('marks an unreadable manifest instead of aborting the listing', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());
    Storage::disk('local')->put('dead-drops/20260101-000000-broken/manifest.json', 'not json');

    $exitCode = Artisan::call('dead-drop:dumps', ['--disk' => 'local']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('unreadable')
        ->toContain($id);
});

it('says so when there are no artifacts', function () {
    $this->artisan('dead-drop:dumps', ['--disk' => 'local'])
        ->expectsOutputToContain('No artifacts on local:dead-drops.')
        ->assertSuccessful();
});
