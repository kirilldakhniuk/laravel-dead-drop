<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\EdgeInferrer;
use DeadDrop\DeadDrop\Inference\MorphPairDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Inference\Sources\NamingSource;
use DeadDrop\DeadDrop\Inference\TableClassifier;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    // Row estimates decide `lookup`, and an unseeded table reports zero — which
    // the classifier reads as "unknown size", not "small".
    SchemaBuilder::seedTwoCompanies('dd_test');
});

function classifyFixtureTable(string $table): TableClass
{
    $schema = app(Introspector::class)->inspect('dd_test');
    $edges = (new EdgeInferrer(new ForeignKeySource, new EloquentSource([__DIR__.'/../../Fixtures/Models']), new NamingSource))->infer($schema);

    return (new TableClassifier(new SensitiveColumnDetector, new MorphPairDetector))->classify($schema->table($table), $edges);
}

it('skips framework tables', function () {
    Schema::connection('dd_test')->create('telescope_entries', fn ($t) => $t->id());

    expect(classifyFixtureTable('failed_jobs'))->toBe(TableClass::Skip)
        ->and(classifyFixtureTable('telescope_entries'))->toBe(TableClass::Skip);
});

it('classifies a small table with no outbound edges and nothing sensitive as lookup', function () {
    expect(classifyFixtureTable('countries'))->toBe(TableClass::Lookup);
});

it('classifies a table with outbound edges as data even when small', function () {
    expect(classifyFixtureTable('orders'))->toBe(TableClass::Data);
});

it('classifies a table holding sensitive columns as data even without outbound edges', function () {
    expect(classifyFixtureTable('companies'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('customers'))->toBe(TableClass::Data);
});

it('classifies a table with a morph pair as data', function () {
    expect(classifyFixtureTable('comments'))->toBe(TableClass::Data);
});

it('does not classify a table with an unknown row estimate as lookup', function () {
    Schema::connection('dd_test')->create('regions', function ($t) {
        $t->id();
        $t->string('code');
    });

    expect(classifyFixtureTable('regions'))->toBe(TableClass::Data);
});
