<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use RuntimeException;

/**
 * Thrown when a configured table cannot be dumped at all: a traversal
 * addresses rows by a single primary key, so a table without one — or with a
 * composite one — has to be skipped in config before a plan can be built.
 */
final class UnsupportedTableException extends RuntimeException
{
    public static function compositePrimaryKey(string $connection, string $table): self
    {
        return new self("{$connection}.{$table} has a composite primary key, which is not supported — mark it as skip in config.");
    }

    public static function noPrimaryKey(string $connection, string $table): self
    {
        return new self("{$connection}.{$table} has no primary key, which is not supported — mark it as skip in config.");
    }
}
