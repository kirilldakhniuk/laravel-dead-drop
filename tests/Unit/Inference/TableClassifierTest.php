<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\TableClassifier;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function classifyFixtureTable(string $table): TableClass
{
    $schema = app(Introspector::class)->inspect('dd_test');

    return (new TableClassifier)->classify($schema->table($table));
}

it('skips framework tables by name and by prefix', function () {
    Schema::connection('dd_test')->create('telescope_entries', fn ($t) => $t->id());

    expect(classifyFixtureTable('failed_jobs'))->toBe(TableClass::Skip)
        ->and(classifyFixtureTable('telescope_entries'))->toBe(TableClass::Skip);
});

it('skips the PostGIS spatial_ref_sys table', function () {
    Schema::connection('dd_test')->create('spatial_ref_sys', function ($t) {
        $t->integer('srid')->primary();
        $t->string('auth_name')->nullable();
    });

    expect(classifyFixtureTable('spatial_ref_sys'))->toBe(TableClass::Skip);
});

it('classifies every other table as data, whatever it holds or points at', function () {
    // Size, sensitivity and relationships no longer change the class: a dump
    // takes each of these whole and redacts it, so the only question the
    // classifier answers is whether the table belongs in a dump at all.
    expect(classifyFixtureTable('countries'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('orders'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('companies'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('customers'))->toBe(TableClass::Data)
        ->and(classifyFixtureTable('comments'))->toBe(TableClass::Data);
});
