<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Drivers\MySqlDriver;
use Illuminate\Database\Connection;

const STRICT_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

/**
 * A MySQL connection that records the statements it is asked to run, in order.
 *
 * @param  list<array{string, array<int, mixed>}>  $statements
 */
function mysqlConnectionSpy(array &$statements, mixed $sqlMode = STRICT_SQL_MODE): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('scalar')->with('select @@SESSION.sql_mode')->andReturn($sqlMode);
    $connection->shouldReceive('statement')->andReturnUsing(function (string $query, array $bindings = []) use (&$statements): bool {
        $statements[] = [$query, $bindings];

        return true;
    });

    return $connection;
}

it('relaxes only the strictness a faithful copy cannot satisfy, then restores it', function () {
    // The legacy `0000-00-00 00:00:00` a source can hold is refused by a
    // strict target session, and a dump has to be able to load what it took.
    $statements = [];
    $connection = mysqlConnectionSpy($statements);
    $driver = new MySqlDriver;

    $driver->beginLoading($connection);
    $driver->endLoading($connection);

    expect($statements)->toBe([
        ['SET FOREIGN_KEY_CHECKS = 0', []],
        ['SET SESSION sql_mode = ?', ['ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION']],
        ['SET SESSION sql_mode = ?', [STRICT_SQL_MODE]],
        ['SET FOREIGN_KEY_CHECKS = 1', []],
    ]);
});

it('leaves the session alone when it cannot read its sql mode', function () {
    $statements = [];
    $connection = mysqlConnectionSpy($statements, sqlMode: null);
    $driver = new MySqlDriver;

    $driver->beginLoading($connection);
    $driver->endLoading($connection);

    expect($statements)->toBe([
        ['SET FOREIGN_KEY_CHECKS = 0', []],
        ['SET FOREIGN_KEY_CHECKS = 1', []],
    ]);
});
