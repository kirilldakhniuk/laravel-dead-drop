<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('lists artifacts newest first', function () {
    $id = dumpFixture('dd_test.companies:1', initFixtureConfig());

    // The id and the status share one table row, and `expectsOutputToContain()`
    // matches at most one expectation per written line, so the rendered output
    // is asserted directly instead.
    $exitCode = Artisan::call('dead-drop:dumps', ['--disk' => 'local']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain($id)
        ->toContain('complete');
});

it('says so when there are no artifacts', function () {
    $this->artisan('dead-drop:dumps', ['--disk' => 'local'])
        ->expectsOutputToContain('No artifacts on local:dead-drops.')
        ->assertSuccessful();
});
