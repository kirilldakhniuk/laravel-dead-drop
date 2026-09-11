<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Support\Facades\DB;

/**
 * Owns the temporary key tables of one traversal. The caller that started the
 * traversal is responsible for `dropAll()`.
 */
final class KeySetRepository
{
    /** @var array<string, KeySet> keyed "{connection}.{table}" */
    private array $keySets = [];

    public function __construct(
        private readonly DriverFactory $drivers,
    ) {}

    public function create(string $connection, string $table, ColumnType $type): KeySet
    {
        $existing = $this->get($connection, $table);

        if ($existing !== null) {
            return $existing;
        }

        $db = DB::connection($connection);
        $driver = $this->drivers->for($db);
        $name = "dd_keys_{$table}";

        $driver->createKeyTable($db, $name, $type);

        return $this->keySets["{$connection}.{$table}"] = new KeySet($driver, $db, $connection, $table, $type, $name);
    }

    public function get(string $connection, string $table): ?KeySet
    {
        return $this->keySets["{$connection}.{$table}"] ?? null;
    }

    /** @return array<string, KeySet> keyed "{connection}.{table}" */
    public function all(): array
    {
        return $this->keySets;
    }

    public function dropAll(): void
    {
        foreach ($this->keySets as $keySet) {
            $db = DB::connection($keySet->connection);

            $this->drivers->for($db)->dropKeyTable($db, $keySet->tableName);
        }

        $this->keySets = [];
    }
}
