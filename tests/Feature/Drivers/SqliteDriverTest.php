<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Drivers\SqliteDriver;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Tests\Fixtures\SchemaBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('resolves a driver from the connection', function () {
    expect((new DriverFactory)->for(DB::connection()))->toBeInstanceOf(SqliteDriver::class);
});

it('round trips keys through a key table', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_users', ColumnType::Integer);
    $driver->insertKeys($connection, 'dd_keys_users', range(1, 2500));

    expect($driver->countKeys($connection, 'dd_keys_users'))->toBe(2500);

    $driver->dropKeyTable($connection, 'dd_keys_users');
});

it('round trips keys through a key table on a prefixed connection', function () {
    config()->set('database.connections.dd_prefixed', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'app_',
    ]);

    $driver = new SqliteDriver;
    $connection = DB::connection('dd_prefixed');

    $driver->createKeyTable($connection, 'dd_keys_users', ColumnType::Integer);
    $driver->insertKeys($connection, 'dd_keys_users', [1, 2, 3]);

    expect($driver->countKeys($connection, 'dd_keys_users'))->toBe(3);

    $driver->dropKeyTable($connection, 'dd_keys_users');
});

it('does not grow the key table when a duplicate key is inserted', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_users', ColumnType::Integer);

    expect($driver->insertKeys($connection, 'dd_keys_users', [1, 2, 3]))->toBe(3)
        ->and($driver->insertKeys($connection, 'dd_keys_users', [3, 4]))->toBe(1)
        ->and($driver->countKeys($connection, 'dd_keys_users'))->toBe(4);

    $driver->dropKeyTable($connection, 'dd_keys_users');
});

it('stores string keys when the key type is not integer', function () {
    $driver = new SqliteDriver;
    $connection = DB::connection();

    $driver->createKeyTable($connection, 'dd_keys_docs', ColumnType::Uuid);
    $driver->insertKeys($connection, 'dd_keys_docs', ['a', 'b', 'a']);

    expect($driver->countKeys($connection, 'dd_keys_docs'))->toBe(2);

    $driver->dropKeyTable($connection, 'dd_keys_docs');
});

it('normalises native types', function () {
    $driver = new SqliteDriver;

    expect($driver->normaliseType('integer'))->toBe(ColumnType::Integer)
        ->and($driver->normaliseType('varchar'))->toBe(ColumnType::String)
        ->and($driver->normaliseType('datetime'))->toBe(ColumnType::DateTime)
        ->and($driver->normaliseType('numeric'))->toBe(ColumnType::Decimal)
        ->and($driver->normaliseType('json'))->toBe(ColumnType::Json);
});

it('treats only tinyint(1) as a boolean', function () {
    $driver = new SqliteDriver;

    expect($driver->normaliseType('tinyint(1)'))->toBe(ColumnType::Boolean)
        ->and($driver->normaliseType('TINYINT(1) unsigned'))->toBe(ColumnType::Boolean)
        ->and($driver->normaliseType('tinyint'))->toBe(ColumnType::Integer)
        ->and($driver->normaliseType('tinyint(4)'))->toBe(ColumnType::Integer);
});

it('normalises a spelled out postgres type', function () {
    $driver = new SqliteDriver;

    expect($driver->normaliseType('timestamp(0) without time zone'))->toBe(ColumnType::DateTime)
        ->and($driver->normaliseType('character varying(255)'))->toBe(ColumnType::String)
        ->and($driver->normaliseType('double precision'))->toBe(ColumnType::Decimal);
});

it('toggles foreign key checks', function () {
    SchemaBuilder::migrate('dd_test');
    $driver = new SqliteDriver;
    $connection = DB::connection('dd_test');

    $driver->disableForeignKeyChecks($connection);
    $connection->table('orders')->insert(['id' => 500, 'company_id' => 999, 'user_id' => 1, 'customer_id' => null, 'total' => 1, 'created_at' => null]);
    expect($connection->table('orders')->where('id', 500)->exists())->toBeTrue();

    $driver->enableForeignKeyChecks($connection);
    expect(fn () => $connection->table('orders')->insert(['id' => 501, 'company_id' => 999, 'user_id' => 1, 'customer_id' => null, 'total' => 1, 'created_at' => null]))
        ->toThrow(QueryException::class);
});
