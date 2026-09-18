<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Tests\Fixtures\Models\Order;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SchemaBuilder::migrate('dd_test');
    SchemaBuilder::seedTwoCompanies('dd_test');
});

it('renders a root as the spec it was parsed from and as a readable description', function () {
    expect(Root::parse('dd_test.companies:1,2')->spec())->toBe('dd_test.companies:1,2')
        ->and(Root::parse('dd_test.companies:1,2')->describe())->toBe('companies #1, #2 (dd_test)')
        // A long root is counted rather than recited, so it still fits a column.
        ->and((new Root('mysql', 'users', range(1, 200)))->describe())->toBe('users #1, #2, #3 … (+197) (mysql)');
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

it('applies exclude as a drop filter', function () {
    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace(
        "'window' => 'created_at',",
        "'window' => 'created_at',\n        'exclude' => 'total >= 20',",
        file_get_contents($file),
    ));

    $result = traverseFixture('dd_test.companies:1', $path);

    expect(collectedKeys($result, 'orders'))->toBe([1]);
});

it('re-descends a table that grows through a second inbound edge', function () {
    // Order 3 is reachable only through users: company 2 owns it, but user 10 placed it.
    DB::connection('dd_test')->table('orders')->insert([
        ['id' => 3, 'company_id' => 2, 'user_id' => 10, 'customer_id' => null, 'total' => 5.00, 'created_at' => '2026-02-01 00:00:00'],
    ]);
    DB::connection('dd_test')->table('order_items')->insert([
        ['id' => 4, 'order_id' => 3, 'sku' => 'SKU-4'],
    ]);

    $result = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($result, 'orders'))->toContain(3)
        ->and(collectedKeys($result, 'order_items'))->toContain(4);
});

it('treats a lookup root as data and seeds only its ids', function () {
    $result = traverseFixture('dd_test.countries:1');

    expect(collectedKeys($result, 'countries'))->toBe([1]);
});

it('starts from a clean repository on each traversal', function () {
    $first = traverseFixture('dd_test.companies:1');
    $second = traverseFixture('dd_test.companies:1');

    expect(collectedKeys($second, 'orders'))->toBe([1, 2])
        ->and(count($second->keySets()))->toBe(count($first->keySets()));

    // Company 2's orders: 99 is its own, 1 is user 50's. Neither run before it leaks in.
    expect(collectedKeys(traverseFixture('dd_test.companies:2'), 'orders'))->toBe([1, 99]);
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

it('ascends into a row an exclude fragment kept out of every descending pass', function () {
    // `exclude` scopes descent; referential completeness outranks it on the way
    // up, so a row pointed at by something collected is still pulled in.
    DB::connection('dd_test')->table('comments')->insert([
        ['id' => 2, 'commentable_type' => Order::class, 'commentable_id' => 99, 'body' => 'on an excluded order'],
    ]);

    $path = initFixtureConfig();
    $file = $path.'/dd_test.php';

    file_put_contents($file, str_replace(
        "'window' => 'created_at',",
        "'window' => 'created_at',\n        'exclude' => 'total >= 20',",
        (string) file_get_contents($file),
    ));

    // Order 99's total is 30.00, so the fragment names it for removal: company 2
    // descends to orders 1 and 99, and keeps only 1.
    expect(collectedKeys(traverseFixture('dd_test.companies:2', $path), 'orders'))->toBe([1]);

    // Comment 2 points at order 99 all the same, and a collected row is never
    // left pointing at nothing.
    expect(collectedKeys(traverseFixture('dd_test.comments:2', $path), 'orders'))->toBe([99]);
});

it('names a full root as a spec and back', function () {
    expect(Root::full('dd_test')->spec())->toBe('dd_test:*')
        ->and(Root::full('dd_test')->describe())->toBe('dd_test')
        ->and(Root::full('dd_test')->isFull())->toBeTrue()
        ->and(Root::full('dd_test')->scope())->toBe('dd_test')
        ->and(Root::parse('dd_test:*')->isFull())->toBeTrue()
        ->and(Root::parse('dd_test:*')->connection)->toBe('dd_test')
        // No connection at all means every configured one.
        ->and(Root::full(null)->spec())->toBe('*:*')
        ->and(Root::full(null)->describe())->toBe('all connections')
        ->and(Root::full(null)->scope())->toBeNull()
        ->and(Root::parse('dd_test.companies:1')->isFull())->toBeFalse();

    // `*` names a whole connection and nothing else: neither of these reads
    // back as a whole-database root.
    expect(fn () => Root::parse('dd_test.companies:*'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Root::parse('dd_test.*:1'))->toThrow(InvalidArgumentException::class);
});
