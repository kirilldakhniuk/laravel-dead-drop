<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DateTimeInterface;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Expands a root row set into the referential closure the dump needs: down to
 * every child row, up to every row a collected row points at, until nothing
 * grows.
 *
 * Rows never leave the database: every pass joins the temporary key tables and
 * writes the keys it finds straight back into them, so only one chunk of keys
 * is ever in PHP memory. Rows reached by ascending are deliberately not
 * descended into — that is what keeps the closure from swallowing the database.
 */
final class Traverser
{
    private const int CHUNK = 5000;

    public function __construct(
        private readonly KeySetRepository $keys,
    ) {}

    public function traverse(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): TraversalResult
    {
        // A traversal owns its key tables: a second run in the same container
        // scope must not accumulate into the previous run's.
        $this->keys->dropAll();

        $graph = Graph::fromConfig($config);

        $this->seedRoot($root, $graph, $schemas);
        $this->seedLookups($root, $config, $schemas);
        $this->descend($root, $graph, $config, $schemas, $since);

        $unresolved = $this->ascend($graph, $schemas);

        return new TraversalResult($this->keys->all(), $unresolved);
    }

    private function seedRoot(Root $root, Graph $graph, SchemaSet $schemas): void
    {
        $class = $graph->tableClass($root->connection, $root->table);

        if ($class === null || $class === TableClass::Skip) {
            throw new InvalidArgumentException("Root table {$root->connection}.{$root->table} is not configured for traversal");
        }

        $keySet = $this->keySet($root->connection, $root->table, $schemas)
            ?? throw new InvalidArgumentException("Root table {$root->connection}.{$root->table} has no single-column primary key.");

        $keySet->add($root->ids);
    }

    /**
     * Lookup tables are copied whole, so their key set is seeded with every
     * primary key before the traversal starts and is never descended into. The
     * root is the exception: a lookup root is traversed as if it were data, so
     * it keeps the ids the caller asked for.
     */
    private function seedLookups(Root $root, ConfigSet $config, SchemaSet $schemas): void
    {
        foreach ($config->connections as $connection => $connectionConfig) {
            foreach ($connectionConfig->tables as $name => $table) {
                if ($table->removed || $table->class !== TableClass::Lookup) {
                    continue;
                }

                if ($connection === $root->connection && $name === $root->table) {
                    continue;
                }

                $keySet = $this->keySet($connection, $name, $schemas);
                $primaryKey = $this->primaryKey($schemas, $connection, $name);

                if ($keySet === null || $primaryKey === null) {
                    continue;
                }

                $this->collect(
                    DB::connection($connection)->table($name)
                        ->select("{$name}.{$primaryKey} as k")
                        ->orderBy("{$name}.{$primaryKey}"),
                    $keySet,
                );
            }
        }
    }

    /**
     * Breadth-first over the inbound descending edges, one table at a time. A
     * table is re-queued every time it grows, so rows reached later through a
     * second inbound edge — or through a self-reference — still have their own
     * children collected. This terminates because a queue entry costs at least
     * one new key and keys are finite.
     */
    private function descend(Root $root, Graph $graph, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since): void
    {
        /** @var list<array{string, string}> $queue */
        $queue = [[$root->connection, $root->table]];

        while ($queue !== []) {
            [$connection, $table] = array_shift($queue);
            $parent = $this->keys->get($connection, $table);

            if ($parent === null) {
                continue;
            }

            foreach ($graph->inboundEdges($connection, $table) as $edge) {
                if (! $edge->descend || $edge->connection !== $edge->targetConnection) {
                    continue;
                }

                // Lookups are already whole and skipped tables are never dumped.
                if ($graph->tableClass($edge->connection, $edge->table) !== TableClass::Data) {
                    continue;
                }

                // An unusable edge is reported once its own rows are collected.
                if ($this->ineligible($edge, $graph, $schemas) !== null) {
                    continue;
                }

                $child = $this->keySet($edge->connection, $edge->table, $schemas);

                if ($child === null) {
                    continue;
                }

                if ($this->descendInto($edge, $parent, $child, $config, $schemas, $since) > 0) {
                    $queue[] = [$edge->connection, $edge->table];
                }
            }
        }
    }

    private function descendInto(GraphEdge $edge, KeySet $parent, KeySet $child, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since): int
    {
        $table = $edge->table;
        $primaryKey = $this->primaryKey($schemas, $edge->connection, $table);

        if ($primaryKey === null) {
            return 0;
        }

        $tableConfig = $config->for($edge->connection)->table($table);
        $window = $tableConfig?->window;
        $exclude = $tableConfig?->exclude;

        $db = DB::connection($edge->connection);

        $query = $db->table($table)
            ->join($parent->tableName, "{$table}.{$edge->column}", '=', "{$parent->tableName}.k");

        if ($window !== null && $since !== null) {
            $query->where("{$table}.{$window}", '>=', $since);
        }

        if ($exclude !== null) {
            // `exclude` is a SQL boolean fragment naming the rows to drop:
            // wrapping it keeps rows whose fragment is NULL and stops a
            // top-level `or` from escaping the predicate.
            $query->whereRaw($this->fragment("not coalesce(({$exclude}), false)"));
        }

        return $this->collect(
            $query->distinct()
                ->select("{$table}.{$primaryKey} as k")
                ->orderBy("{$table}.{$primaryKey}"),
            $child,
        );
    }

    /**
     * Follows every outbound edge of every key set until no key set grows, so
     * that each collected row's parents are collected too.
     *
     * @return list<UnresolvedReference>
     */
    private function ascend(Graph $graph, SchemaSet $schemas): array
    {
        /** @var array<string, UnresolvedReference> $unresolved */
        $unresolved = [];
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($this->keys->all() as $source) {
                foreach ($graph->outboundEdges($source->connection, $source->table) as $edge) {
                    $reason = $this->ineligible($edge, $graph, $schemas);
                    $identifier = "{$edge->connection}.{$edge->table}.{$edge->column}";

                    if ($reason !== null) {
                        if (! isset($unresolved[$identifier]) && $this->hasReferences($edge, $source, $schemas)) {
                            $unresolved[$identifier] = new UnresolvedReference($edge->connection, $edge->table, $edge->column, $reason);
                        }

                        continue;
                    }

                    $target = $this->keySet($edge->targetConnection, $edge->targetTable, $schemas);

                    if ($target !== null && $this->ascendTo($edge, $source, $target, $schemas) > 0) {
                        $grew = true;
                    }
                }
            }
        }

        return array_values($unresolved);
    }

    private function ascendTo(GraphEdge $edge, KeySet $source, KeySet $target, SchemaSet $schemas): int
    {
        $query = $this->referencedRows($edge, $source, $schemas);

        if ($query === null) {
            return 0;
        }

        return $this->collect(
            $query->distinct()
                ->select("{$edge->table}.{$edge->column} as k")
                ->orderBy("{$edge->table}.{$edge->column}"),
            $target,
        );
    }

    /**
     * Whether any collected row actually points along this edge: an edge the
     * traversal cannot follow only matters when it is used.
     */
    private function hasReferences(GraphEdge $edge, KeySet $source, SchemaSet $schemas): bool
    {
        return $this->referencedRows($edge, $source, $schemas)?->exists() === true;
    }

    /**
     * The collected rows of the edge's own table that carry a value in the
     * edge column.
     */
    private function referencedRows(GraphEdge $edge, KeySet $source, SchemaSet $schemas): ?Builder
    {
        $primaryKey = $this->primaryKey($schemas, $edge->connection, $edge->table);

        if ($primaryKey === null) {
            return null;
        }

        return DB::connection($edge->connection)->table($edge->table)
            ->join($source->tableName, "{$edge->table}.{$primaryKey}", '=', "{$source->tableName}.k")
            ->whereNotNull("{$edge->table}.{$edge->column}");
    }

    /**
     * The reason this edge cannot be followed, or null when it can.
     */
    private function ineligible(GraphEdge $edge, Graph $graph, SchemaSet $schemas): ?string
    {
        if ($edge->targetConnection !== $edge->connection) {
            return "cross-connection edge to {$edge->targetConnection}.{$edge->targetTable} is not supported yet";
        }

        $class = $graph->tableClass($edge->targetConnection, $edge->targetTable);

        if ($class === TableClass::Skip) {
            return "target table {$edge->targetTable} is skipped";
        }

        if ($class === null) {
            return "target table {$edge->targetTable} is not configured";
        }

        if ($this->primaryKey($schemas, $edge->targetConnection, $edge->targetTable) !== $edge->targetColumn) {
            return "target column {$edge->targetTable}.{$edge->targetColumn} is not the primary key";
        }

        return null;
    }

    /**
     * A table's `exclude` is a SQL fragment a human wrote into the reviewed
     * config, so it goes into the query as an expression rather than a value.
     */
    private function fragment(string $sql): Expression
    {
        return new class($sql) implements Expression
        {
            public function __construct(
                private readonly string $sql,
            ) {}

            public function getValue(Grammar $grammar): string
            {
                return $this->sql;
            }
        };
    }

    /**
     * Reads the keys a pass found in chunks, adding each chunk to the key set
     * before the next is fetched, and reports how many were new.
     */
    private function collect(Builder $query, KeySet $target): int
    {
        $added = 0;

        $query->chunk(self::CHUNK, function (Collection $rows) use ($target, &$added): void {
            /** @var list<int|string> $keys */
            $keys = [];

            foreach ($rows as $row) {
                $key = $row->k ?? null;

                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }

            $added += $target->add($keys);
        });

        return $added;
    }

    /**
     * Null when the table has no single-column primary key, which is the one
     * shape a key set cannot represent.
     */
    private function keySet(string $connection, string $table, SchemaSet $schemas): ?KeySet
    {
        $meta = $schemas->for($connection)->table($table);
        $primaryKey = $meta?->primaryKey();

        if ($meta === null || $primaryKey === null) {
            return null;
        }

        $column = $meta->column($primaryKey);

        return $this->keys->create($connection, $table, $column === null ? ColumnType::String : $column->type);
    }

    private function primaryKey(SchemaSet $schemas, string $connection, string $table): ?string
    {
        return $schemas->for($connection)->table($table)?->primaryKey();
    }
}
