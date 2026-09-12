<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\ArtifactBuilder;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Planning\Planner;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
    fakeArtifactDisk();
});

it('refuses to export a step whose key set is gone', function () {
    $path = initFixtureConfig();
    $config = (new ConfigLoader)->loadAll($path);
    $schemas = new SchemaSet(['dd_test' => app(Introspector::class)->inspect('dd_test')]);
    $root = Root::parse('dd_test.companies:1');
    $plan = app(Planner::class)->plan($root, $config, $schemas);

    // Without its key set the step would read as "take the whole table",
    // which is the one thing the plan exists to prevent.
    app(KeySetRepository::class)->dropAll();

    $build = fn () => app(ArtifactBuilder::class)->build(
        $plan,
        $root,
        null,
        $config,
        $schemas,
        new RedactionContext(str_repeat('s', 32), 'example.test'),
        Storage::disk('local'),
        'dead-drops',
    );

    expect($build)->toThrow(RuntimeException::class, 'No key set for table [dd_test.companies].');

    $files = Storage::disk('local')->allFiles();

    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('/manifest.json');
});
