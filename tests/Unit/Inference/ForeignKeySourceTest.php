<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('infers an edge from a declared foreign key', function () {
    $schema = app(Introspector::class)->inspect('dd_test');

    $edges = (new ForeignKeySource)->infer($schema);

    $edge = collect($edges)->first(fn ($e) => $e->table === 'orders' && $e->column === 'company_id');

    expect($edge)->not->toBeNull()
        ->and($edge->targetTable)->toBe('companies')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->targetConnection)->toBeNull()
        ->and($edge->source)->toBe(EdgeSource::ForeignKey)
        ->and($edge->descend)->toBeTrue();
});

it('ignores composite foreign keys', function () {
    Schema::connection('dd_test')->create('shipments', function ($table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->unsignedBigInteger('company_id');
        $table->foreign(['order_id', 'company_id'])->references(['id', 'company_id'])->on('orders');
    });

    $edges = (new ForeignKeySource)->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->where('table', 'shipments'))->toBeEmpty();
});
