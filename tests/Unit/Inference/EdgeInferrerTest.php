<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Inference\EdgeInferrer;
use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\Sources\ForeignKeySource;
use DeadDrop\DeadDrop\Inference\Sources\NamingSource;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

function fixtureEdges(): array
{
    $inferrer = new EdgeInferrer(
        new ForeignKeySource,
        new EloquentSource([__DIR__.'/../../Fixtures/Models']),
        new NamingSource,
    );

    return $inferrer->infer(app(Introspector::class)->inspect('dd_test'));
}

it('lets a foreign key edge beat a guessed edge for the same column', function () {
    expect(fixtureEdges()['orders.company_id']->source)->toBe(EdgeSource::ForeignKey);
});

it('lets an eloquent edge beat a guessed edge for the same column', function () {
    expect(fixtureEdges()['orders.customer_id']->source)->toBe(EdgeSource::Eloquent);
});

it('guesses an edge from column naming when nothing else declares it', function () {
    $edge = fixtureEdges()['order_items.order_id'];

    expect($edge->targetTable)->toBe('orders')
        ->and($edge->targetColumn)->toBe('id')
        ->and($edge->source)->toBe(EdgeSource::Guessed)
        ->and($edge->descend)->toBeTrue();
});

it('does not guess an edge for a column with no matching table', function () {
    expect(fixtureEdges())->not->toHaveKey('comments.commentable_id');
});

it('marks self-referencing audit columns as ascend only', function () {
    $edge = fixtureEdges()['users.created_by'];

    expect($edge->targetTable)->toBe('users')
        ->and($edge->descend)->toBeFalse();
});

it('guesses an edge into a framework table so the planner can report it', function () {
    expect(fixtureEdges()['users.failed_job_id']->targetTable)->toBe('failed_jobs');
});

it('resolves the inferrer from the container', function () {
    expect(app(EdgeInferrer::class))->toBeInstanceOf(EdgeInferrer::class);
});
