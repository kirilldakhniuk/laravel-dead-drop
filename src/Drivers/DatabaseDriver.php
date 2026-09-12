<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Database\Connection;

interface DatabaseDriver
{
    public function name(): string;

    public function normaliseType(string $nativeType): ColumnType;

    /** @return array<string, int> table => estimated rows */
    public function estimatedRowCounts(Connection $connection): array;

    /**
     * The schema (or database) the connection's unqualified names resolve in,
     * so introspection can ignore tables that belong to another one. A null
     * means the driver cannot name it and every table is in scope.
     */
    public function currentSchema(Connection $connection): ?string;

    public function createKeyTable(Connection $connection, string $name, ColumnType $keyType): void;

    /**
     * Adds keys, ignoring the ones already present, and reports how many rows
     * the inserts actually created.
     *
     * @param  array<int, int|string>  $keys
     */
    public function insertKeys(Connection $connection, string $name, array $keys): int;

    public function countKeys(Connection $connection, string $name): int;

    public function dropKeyTable(Connection $connection, string $name): void;

    /** Turn off referential-integrity enforcement for this session (loading inserts parents and children in plan order under one transaction per table). */
    public function disableForeignKeyChecks(Connection $connection): void;

    /** Restore referential-integrity enforcement for this session. */
    public function enableForeignKeyChecks(Connection $connection): void;

    /**
     * Prepare the session for bulk loading: integrity enforcement off, and any
     * engine-specific strictness that would reject values the source accepted.
     * A dump is a faithful copy, so whatever the source held has to go in —
     * a legacy `0000-00-00` datetime included.
     */
    public function beginLoading(Connection $connection): void;

    /**
     * Restore everything `beginLoading()` relaxed, whatever the load did.
     */
    public function endLoading(Connection $connection): void;

    public function quote(string $identifier): string;
}
