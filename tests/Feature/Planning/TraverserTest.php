<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

it('parses a root spec', function () {
    $root = Root::parse('dd_test.companies:1,2');

    expect($root->connection)->toBe('dd_test')
        ->and($root->table)->toBe('companies')
        ->and($root->ids)->toBe([1, 2]);
});

it('descends from the root to direct children', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'orders'))->toBe([1, 2]);
});

it('descends two hops', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'order_items'))->toBe([1, 2]);
});

it('excludes rows belonging to another root', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'orders'))->not->toContain(99);
});

it('ascends to pull a customer referenced by a collected order', function () {
    // customer 7 belongs to no company; order 1 references it. Customer 8 is only on order 99.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'customers'))->toBe([7]);
});

it('does not descend from an ascended row', function () {
    // user 50 belongs to company 2 but placed order 1 for company 1.
    // Ascend must pull user 50, but must NOT then pull company 2's other orders.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'users'))->toContain(50)
        ->and(collectedKeys($result, 'orders'))->not->toContain(99);
});

it('does not descend an edge marked ascend only', function () {
    // user 60 was created_by user 50 but belongs to company 2.
    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'users'))->toBe([10, 50]);
});

it('includes lookup tables whole', function () {
    $result = traverseFixture('dd_test.companies:1');

    expect($result->keySets()['dd_test.countries']->count())->toBe(DB::connection('dd_test')->table('countries')->count());
});

it('applies the window when a since date is given', function () {
    $result = traverseFixture('dd_test.companies:1', since: new DateTimeImmutable('2026-06-01'));

    expect(collectedKeys($result, 'orders'))->toBe([2]);
});

it('reports a reference into a skipped table as unresolved', function () {
    $result = traverseFixture('dd_test.companies:1');

    $reasons = array_map(fn ($u) => "{$u->table}.{$u->column}: {$u->reason}", $result->unresolved());

    expect($reasons)->toContain('users.failed_job_id: target table failed_jobs is skipped');
});

it('resolves every collected foreign key within the collected set', function () {
    $result = traverseFixture('dd_test.companies:1');

    foreach (collectedKeys($result, 'orders') as $orderId) {
        $order = DB::connection('dd_test')->table('orders')->find($orderId);

        expect(collectedKeys($result, 'companies'))->toContain($order->company_id)
            ->and(collectedKeys($result, 'users'))->toContain($order->user_id);

        if ($order->customer_id !== null) {
            expect(collectedKeys($result, 'customers'))->toContain($order->customer_id);
        }
    }
});
