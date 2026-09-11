<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\MorphResolver;
use DeadDrop\DeadDrop\Tests\Fixtures\Models\Order;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');

    Relation::morphMap(['order' => Order::class]);

    DB::connection('dd_test')->table('comments')->insert([
        ['id' => 1, 'commentable_type' => 'order', 'commentable_id' => 1, 'body' => 'kept'],
        ['id' => 2, 'commentable_type' => 'order', 'commentable_id' => 99, 'body' => 'other company'],
        ['id' => 3, 'commentable_type' => 'gone', 'commentable_id' => 1, 'body' => 'dead type'],
    ]);
});

afterEach(fn () => Relation::morphMap([], false));

it('resolves a morph type through the morph map', function () {
    expect((new MorphResolver)->tableFor('order'))->toBe('orders');
});

it('resolves a fully qualified model class name', function () {
    expect((new MorphResolver)->tableFor(Order::class))->toBe('orders');
});

it('returns null for an unmapped morph type', function () {
    expect((new MorphResolver)->tableFor('gone'))->toBeNull();
});

it('descends into comments attached to a collected order', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'comments'))->toContain(1);
});

it('does not collect comments attached to another roots rows', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'comments'))->not->toContain(2)
        ->and(collectedKeys($result, 'comments'))->not->toContain(3);
});

it('reports an unresolvable morph type instead of throwing', function () {
    $result = traverseFixture('dd_test.companies:1');

    $reasons = array_map(fn ($u) => $u->reason, $result->unresolved());

    expect($reasons)->toContain('unmapped morph type: gone');
});
