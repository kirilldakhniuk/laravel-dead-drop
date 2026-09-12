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
    private const int CHUNK = 5000;

    /** @var array<string, KeySet> keyed "{connection}.{table}" */
    private array $keySets = [];

    /**
     * Copies of another connection's key sets, kept apart from the real ones
     * so a mirror can never become a plan step or an ascend source.
     *
     * @var array<string, KeySet> keyed "{targetConnection}:{sourceConnection}.{table}"
     */
    private array $mirrors = [];

    /**
     * Connections already pinned to their write PDO.
     *
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
     * A copy of a key set on another connection, so an edge that crosses
     * connections has something to join against. The source keeps growing
     * while the traversal runs, so every call refreshes the mirror: the keys
     * travel in chunks and the ones already there are ignored.
     *
     * The table name carries a hash of the source rather than its connection
     * name: both names can contain underscores, and joining them would let two
     * different sources claim one table — which `make()` would silently reuse.
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
     * The key sets the traversal collected — mirrors are working copies and
     * are deliberately left out.
     *
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

        // A temporary key table lives on the session that created it. On a
        // read/write-split connection that is the write PDO, so every read of
        // it — and every join against it — has to go there too.
        if (! isset($this->pinned[$connection])) {
            $db->useWriteConnectionWhenReading();

            $this->pinned[$connection] = true;
        }

        $driver = $this->drivers->for($db);

        $driver->createKeyTable($db, $name, $type);

        return new KeySet($driver, $db, $connection, $table, $type, $name);
    }
}
