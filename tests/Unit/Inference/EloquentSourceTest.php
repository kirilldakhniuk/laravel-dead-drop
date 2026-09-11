<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\Models\Broken;
use DeadDrop\DeadDrop\Tests\Fixtures\Models\Company;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureModelSource(): EloquentSource
{
    return new EloquentSource([__DIR__.'/../../Fixtures/Models']);
}

it('infers an edge from a belongs-to relation', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    $edge = collect($edges)->first(fn ($e) => $e->table === 'orders' && $e->column === 'customer_id');

    expect($edge)->not->toBeNull()
        ->and($edge->targetTable)->toBe('customers')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->source)->toBe(EdgeSource::Eloquent);
});

it('does not infer edges from has-many relations', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->first(fn ($e) => $e->table === 'companies' && $e->targetTable === 'orders'))->toBeNull();
});

it('does not infer edges from morph-to relations', function () {
    $edges = fixtureModelSource()->infer(app(Introspector::class)->inspect('dd_test'));

    expect(collect($edges)->where('table', 'comments'))->toBeEmpty();
});

it('never calls a method without a relation return type and reports it', function () {
    // Fixtures\Models\Company::explode() throws if called.
    $source = fixtureModelSource();
    $source->infer(app(Introspector::class)->inspect('dd_test'));

    // failed() is not asserted empty here: Fixtures\Models\Broken (added for the
    // "cannot be instantiated" test below) always fails construction and is
    // discovered from this same fixture directory, so failed() legitimately
    // contains a Broken entry. What must never appear is an explode() entry,
    // which would only exist if the safety filter regressed and let an
    // untyped method through to invocation (it throws 'called' if invoked).
    expect($source->skipped())->toContain(Company::class.'::explode')
        ->and($source->failed())->not->toContain(Company::class.'::explode: called');
});

it('ignores a model path that does not exist', function () {
    $edges = (new EloquentSource([__DIR__.'/nope']))->infer(app(Introspector::class)->inspect('dd_test'));

    expect($edges)->toBe([]);
});

it('reports a model that cannot be instantiated and continues', function () {
    $source = fixtureModelSource();

    $edges = $source->infer(app(Introspector::class)->inspect('dd_test'));

    $edge = collect($edges)->first(fn ($e) => $e->table === 'orders' && $e->column === 'customer_id');

    $failedBroken = collect($source->failed())->contains(fn ($entry) => str_starts_with($entry, Broken::class.':'));

    expect($edge)->not->toBeNull()
        ->and($failedBroken)->toBeTrue();
});
