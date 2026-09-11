<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => SchemaBuilder::migrate('dd_test'));

it('finds every table', function () {
    $schema = app(Introspector::class)->inspect('dd_test');

    expect($schema->tableNames())->toContain('companies', 'order_items', 'comments')
        ->and($schema->connection)->toBe('dd_test')
        ->and($schema->driver)->toBe('sqlite');
});

it('reads columns with normalised types and nullability', function () {
    $orders = app(Introspector::class)->inspect('dd_test')->table('orders');

    expect($orders->column('company_id')->type)->toBe(ColumnType::Integer)
        ->and($orders->column('created_at')->type)->toBe(ColumnType::DateTime)
        ->and($orders->column('total')->type)->toBe(ColumnType::Decimal)
        ->and($orders->column('customer_id')->nullable)->toBeTrue()
        ->and($orders->column('company_id')->nullable)->toBeFalse()
        ->and($orders->column('id')->autoIncrement)->toBeTrue();
});

it('reads the primary key and unique indexes', function () {
    $users = app(Introspector::class)->inspect('dd_test')->table('users');

    expect($users->primaryKey())->toBe('id')
        ->and($users->isUnique('email'))->toBeTrue()
        ->and($users->isUnique('password'))->toBeFalse();
});

it('reads declared foreign keys', function () {
    $orders = app(Introspector::class)->inspect('dd_test')->table('orders');

    $targets = array_map(fn ($fk) => $fk->foreignTable, $orders->foreignKeys);

    expect($targets)->toContain('companies');
});

it('reports a row estimate per table', function () {
    SchemaBuilder::seedTwoCompanies('dd_test');

    expect(app(Introspector::class)->inspect('dd_test')->table('orders')->estimatedRows)->toBe(3);
});

it('ignores temporary tables', function () {
    DB::connection('dd_test')->statement('CREATE TEMPORARY TABLE "dd_keys_scratch" (k INTEGER PRIMARY KEY)');

    expect(app(Introspector::class)->inspect('dd_test')->tableNames())->not->toContain('dd_keys_scratch');
});
