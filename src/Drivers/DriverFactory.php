<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use Illuminate\Database\Connection;
use InvalidArgumentException;

final class DriverFactory
{
    public function for(Connection $connection): DatabaseDriver
    {
        return match ($connection->getDriverName()) {
            'sqlite' => new SqliteDriver,
            'mysql', 'mariadb' => new MySqlDriver,
            'pgsql' => new PostgresDriver,
            default => throw new InvalidArgumentException("Unsupported database driver [{$connection->getDriverName()}]."),
        };
    }
}
