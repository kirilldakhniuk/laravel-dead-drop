<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Drivers\SqliteDriver;
use DeadDrop\DeadDrop\Schema\ColumnType;
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
    $driver->insertKeys($connection, 'dd_keys_users', [1, 2, 3]);
    $driver->insertKeys($connection, 'dd_keys_users', [3, 4]);

    expect($driver->countKeys($connection, 'dd_keys_users'))->toBe(4);

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
        ->and($driver->normaliseType('json'))->toBe(ColumnType::Json)
        ->and($driver->normaliseType('tinyint'))->toBe(ColumnType::Boolean);
});
