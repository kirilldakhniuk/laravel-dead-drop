<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

it('reads a boolean column as bool and a wider tinyint as an integer', function () {
    // Laravel's SQLite grammar writes `boolean` as `tinyint(1)`; the width is
    // the only thing telling the two apart, and it is in `type`, not
    // `type_name`, so this pins that the full native type reaches the driver.
    DB::connection('dd_test')->statement('CREATE TABLE flags (id integer primary key autoincrement, active tinyint(1) not null, status tinyint(4) not null)');

    $flags = app(Introspector::class)->inspect('dd_test')->table('flags');

    expect($flags->column('active')->type)->toBe(ColumnType::Boolean)
        ->and($flags->column('status')->type)->toBe(ColumnType::Integer);
});

it('marks a generated column as generated', function () {
    Schema::connection('dd_test')->create('gen', function ($t) {
        $t->id();
        $t->integer('a');
        $t->integer('b')->storedAs('a * 2');
    });

    $gen = app(Introspector::class)->inspect('dd_test')->table('gen');

    expect($gen->column('b')->generated)->toBeTrue()
        ->and($gen->column('a')->generated)->toBeFalse()
        ->and($gen->column('id')->generated)->toBeFalse();
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

it('ignores tables that belong to another schema', function () {
    $db = DB::connection('dd_test');

    $db->statement("ATTACH DATABASE ':memory:' AS other");
    $db->statement('CREATE TABLE other.foreign_table (id INTEGER PRIMARY KEY)');

    $names = app(Introspector::class)->inspect('dd_test')->tableNames();

    expect($names)->not->toContain('foreign_table');
    expect($names)->toContain('companies');
});
