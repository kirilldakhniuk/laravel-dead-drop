<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Drivers\DriverFactory;
use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Support\Facades\DB;

/**
 * Owns a traversal's temporary tables; the caller must call dropAll() afterwards.
 */
final class KeySetRepository
{
    private const int CHUNK = 5000;

    /** @var array<string, KeySet> keyed "{connection}.{table}" */
    private array $keySets = [];

    /**
     * @var array<string, KeySet> keyed "{targetConnection}:{sourceConnection}.{table}"
     */
    private array $mirrors = [];

    /**
     * @var array<string, true>
     */
    private array $pinned = [];

    public function __construct(
        private readonly DriverFactory $drivers,
    ) {}

    public function create(string $connection, string $table, ColumnType $type): KeySet
    {
        $existing = $this->get($connection, $table);

        if ($existing !== null) {
            return $existing;
        }

        return $this->keySets["{$connection}.{$table}"] = $this->make($connection, $table, "dd_keys_{$table}", $type);
    }

    /**
     * Refreshes a source key set's copy on another connection for cross-database joins.
     */
    public function mirror(KeySet $source, string $targetConnection): KeySet
    {
        $mirror = $this->mirrors["{$targetConnection}:{$source->connection}.{$source->table}"] ??= $this->make(
            $targetConnection,
            $source->table,
            'dd_keys_mirror_'.$source->table.'_'.hash('crc32b', "{$source->connection}.{$source->table}"),
            $source->type,
        );

        $source->chunk(self::CHUNK, function (array $keys) use ($mirror): void {
            $mirror->add($keys);
        });

        return $mirror;
    }

    public function get(string $connection, string $table): ?KeySet
    {
        return $this->keySets["{$connection}.{$table}"] ?? null;
    }

    /**
     * @return array<string, KeySet> keyed "{connection}.{table}"
     */
    public function all(): array
    {
        return $this->keySets;
    }

    public function dropAll(): void
    {
        foreach ([...array_values($this->keySets), ...array_values($this->mirrors)] as $keySet) {
            $db = DB::connection($keySet->connection);

            $this->drivers->for($db)->dropKeyTable($db, $keySet->tableName);
        }

        $this->keySets = [];
        $this->mirrors = [];
        $this->pinned = [];
    }

    private function make(string $connection, string $table, string $name, ColumnType $type): KeySet
    {
        $db = DB::connection($connection);

        // Temporary key tables and their joins must use the same write PDO session.
        if (! isset($this->pinned[$connection])) {
            $db->useWriteConnectionWhenReading();

            $this->pinned[$connection] = true;
        }

        $driver = $this->drivers->for($db);

        $driver->createKeyTable($db, $name, $type);

        return new KeySet($driver, $db, $connection, $table, $type, $name);
    }
}
