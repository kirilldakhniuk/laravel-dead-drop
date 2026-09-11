<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DateTimeInterface;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
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

    /**
     * The descending morph pass's distinct-type scan, which is invariant for
     * the length of one descend — the source database is only read — while the
     * frontier table it is asked about changes on every dequeue.
     *
     * @var array<string, list<array{string, string}>> keyed "{connection}.{table}"
     */
    private array $morphScans = [];

    public function __construct(
        private readonly KeySetRepository $keys,
        private readonly MorphResolver $morphs,
    ) {}

    public function traverse(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): TraversalResult
    {
        // A traversal owns its key tables: a second run in the same container
        // scope must not accumulate into the previous run's.
        $this->keys->dropAll();

        $graph = Graph::fromConfig($config);

        /** @var array<string, UnresolvedReference> $unresolved */
        $unresolved = [];

        $this->seedRoot($root, $graph, $schemas);
        $this->seedLookups($root, $config, $schemas);
        $this->descend($root, $graph, $config, $schemas, $since, $unresolved);
        $this->ascend($graph, $config, $schemas, $unresolved);

        return new TraversalResult($this->keys->all(), array_values($unresolved));
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
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function descend(Root $root, Graph $graph, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since, array &$unresolved): void
    {
        $this->morphScans = [];

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

            foreach ($this->descendMorphs($parent, $config, $schemas, $since, $unresolved) as $grown) {
                $queue[] = $grown;
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

        $query = DB::connection($edge->connection)->table($table)
            ->join($parent->tableName, "{$table}.{$edge->column}", '=', "{$parent->tableName}.k");

        return $this->collect(
            $this->scope($query, $table, $config->for($edge->connection)->table($table), $since)
                ->distinct()
                ->select("{$table}.{$primaryKey} as k")
                ->orderBy("{$table}.{$primaryKey}"),
            $child,
        );
    }

    /**
     * A polymorphic child has no edge in the graph — the table it belongs to
     * is a string in its own rows — so every morph table on the frontier
     * table's connection is asked whether any of its type values names it.
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     * @return list<array{string, string}> the morph tables that grew, to be re-queued
     */
    private function descendMorphs(KeySet $parent, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since, array &$unresolved): array
    {
        if (! $config->has($parent->connection)) {
            return [];
        }

        $connection = $parent->connection;
        $db = DB::connection($connection);
        $grown = [];

        foreach ($config->for($connection)->tables as $table => $tableConfig) {
            $morph = $tableConfig->morph;

            if ($morph === null || $tableConfig->removed || $tableConfig->class !== TableClass::Data) {
                continue;
            }

            if (! $this->morphColumnsExist($connection, $table, $morph, $schemas, $unresolved)) {
                continue;
            }

            $primaryKey = $this->primaryKey($schemas, $connection, $table);

            if ($primaryKey === null) {
                continue;
            }

            // Scanned once per descend, then filtered per frontier table: a
            // morph table only earns a key set once one of its type values
            // actually names the table on the frontier.
            $scan = $this->morphScans["{$connection}.{$table}"]
                ??= $this->morphTargets($db->table($table), $connection, $table, $morph['type'], $unresolved);

            $types = $this->frontierTypes($scan, $parent->table);

            $child = $types === [] ? null : $this->keySet($connection, $table, $schemas);

            if ($child === null) {
                continue;
            }

            $added = 0;

            foreach ($types as $type) {
                $query = $db->table($table)
                    ->join($parent->tableName, "{$table}.{$morph['id']}", '=', "{$parent->tableName}.k")
                    ->where("{$table}.{$morph['type']}", $type);

                $added += $this->collect(
                    $this->scope($query, $table, $tableConfig, $since)
                        ->distinct()
                        ->select("{$table}.{$primaryKey} as k")
                        ->orderBy("{$table}.{$primaryKey}"),
                    $child,
                );
            }

            if ($added > 0) {
                $grown[] = [$connection, $table];
            }
        }

        return $grown;
    }

    /**
     * The type values, out of a morph table's resolved targets, that name one
     * particular table.
     *
     * @param  list<array{string, string}>  $targets
     * @return list<string>
     */
    private function frontierTypes(array $targets, string $table): array
    {
        $types = [];

        foreach ($targets as [$type, $target]) {
            if ($target === $table) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Narrows a descending pass to the rows the config lets it take: inside
     * the incremental window, and not dropped by `exclude`.
     */
    private function scope(Builder $query, string $table, ?TableConfig $config, ?DateTimeInterface $since): Builder
    {
        $window = $config?->window;
        $exclude = $config?->exclude;

        if ($window !== null && $since !== null) {
            $query->where("{$table}.{$window}", '>=', $since);
        }

        if ($exclude !== null) {
            // `exclude` is a SQL boolean fragment naming the rows to drop:
            // wrapping it keeps rows whose fragment is NULL and stops a
            // top-level `or` from escaping the predicate.
            $query->whereRaw($this->fragment("not coalesce(({$exclude}), false)"));
        }

        return $query;
    }

    /**
     * Follows every outbound edge of every key set until no key set grows, so
     * that each collected row's parents are collected too.
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function ascend(Graph $graph, ConfigSet $config, SchemaSet $schemas, array &$unresolved): void
    {
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($this->keys->all() as $source) {
                foreach ($graph->outboundEdges($source->connection, $source->table) as $edge) {
                    $reason = $this->ineligible($edge, $graph, $schemas);

                    if ($reason !== null) {
                        $identifier = $this->identifier($edge->connection, $edge->table, $edge->column, $reason);

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

                if ($this->ascendMorph($source, $graph, $config, $schemas, $unresolved) > 0) {
                    $grew = true;
                }
            }
        }
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
     * The mirror of the descending morph pass: every collected row of a morph
     * table points at a row of whatever table its type names, and that row has
     * to come along. A type nothing answers to, or one naming a table the dump
     * does not carry, is reported rather than thrown — dead morph types in old
     * rows are ordinary, and must not fail the dump.
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     * @return int how many keys this pass added, for the fixpoint
     */
    private function ascendMorph(KeySet $source, Graph $graph, ConfigSet $config, SchemaSet $schemas, array &$unresolved): int
    {
        $connection = $source->connection;
        $table = $source->table;

        if (! $config->has($connection)) {
            return 0;
        }

        $morph = $config->for($connection)->table($table)?->morph;
        $primaryKey = $this->primaryKey($schemas, $connection, $table);

        if ($morph === null || $primaryKey === null) {
            return 0;
        }

        if (! $this->morphColumnsExist($connection, $table, $morph, $schemas, $unresolved)) {
            return 0;
        }

        $db = DB::connection($connection);
        $collected = fn (): Builder => $db->table($table)
            ->join($source->tableName, "{$table}.{$primaryKey}", '=', "{$source->tableName}.k");

        $added = 0;

        foreach ($this->morphTargets($collected(), $connection, $table, $morph['type'], $unresolved) as [$type, $targetTable]) {
            $reason = $this->ineligibleTarget($connection, $targetTable, $graph, $schemas);

            if ($reason !== null) {
                $this->note($unresolved, $connection, $table, $morph['id'], $reason);

                continue;
            }

            $target = $this->keySet($connection, $targetTable, $schemas);

            if ($target === null) {
                continue;
            }

            $added += $this->collect(
                $collected()
                    ->where("{$table}.{$morph['type']}", $type)
                    ->whereNotNull("{$table}.{$morph['id']}")
                    ->distinct()
                    ->select("{$table}.{$morph['id']} as k")
                    ->orderBy("{$table}.{$morph['id']}"),
                $target,
            );
        }

        return $added;
    }

    /**
     * The distinct type values in a morph table's rows, each paired with the
     * table it names. Types nothing answers to are reported once and dropped;
     * there are only ever a handful of distinct type strings, so this reads
     * them in one go — the ids they point at are never read into PHP.
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     * @return list<array{string, string}> pairs of type value and target table
     */
    private function morphTargets(Builder $rows, string $connection, string $table, string $column, array &$unresolved): array
    {
        $targets = [];

        $types = $rows->distinct()
            ->select("{$table}.{$column} as t")
            ->orderBy("{$table}.{$column}")
            ->pluck('t');

        foreach ($types as $type) {
            if (! is_string($type) || $type === '') {
                continue;
            }

            $target = $this->morphs->tableFor($type);

            if ($target === null) {
                $this->note($unresolved, $connection, $table, $column, "unmapped morph type: {$type}");

                continue;
            }

            $targets[] = [$type, $target];
        }

        return $targets;
    }

    /**
     * Whether the morph pair is still in the schema. A config written before
     * the columns were dropped would otherwise fail the whole traversal.
     *
     * @param  array{type: string, id: string}  $morph
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function morphColumnsExist(string $connection, string $table, array $morph, SchemaSet $schemas, array &$unresolved): bool
    {
        $meta = $schemas->for($connection)->table($table);

        if ($meta === null) {
            return false;
        }

        foreach ([$morph['type'], $morph['id']] as $column) {
            if ($meta->column($column) === null) {
                $this->note($unresolved, $connection, $table, $column, "morph column {$column} does not exist");

                return false;
            }
        }

        return true;
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

        $reason = $this->ineligibleTarget($edge->targetConnection, $edge->targetTable, $graph, $schemas);

        if ($reason !== null) {
            return $reason;
        }

        if ($this->primaryKey($schemas, $edge->targetConnection, $edge->targetTable) !== $edge->targetColumn) {
            return "target column {$edge->targetTable}.{$edge->targetColumn} is not the primary key";
        }

        return null;
    }

    /**
     * The reason rows of this table cannot be pulled in, or null when they
     * can. A morph target is only ever named by a type string, so this is the
     * whole check for it; a declared edge has its target column checked too.
     */
    private function ineligibleTarget(string $connection, string $table, Graph $graph, SchemaSet $schemas): ?string
    {
        $class = $graph->tableClass($connection, $table);

        if ($class === TableClass::Skip) {
            return "target table {$table} is skipped";
        }

        if ($class === null) {
            return "target table {$table} is not configured";
        }

        if ($this->primaryKey($schemas, $connection, $table) === null) {
            return "target table {$table} has no single primary key";
        }

        return null;
    }

    /**
     * Records a reference the traversal could not follow, once per distinct
     * reason: the same dead edge or dead morph type is met again on every
     * pass.
     *
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function note(array &$unresolved, string $connection, string $table, string $column, string $reason): void
    {
        $unresolved[$this->identifier($connection, $table, $column, $reason)] ??= new UnresolvedReference($connection, $table, $column, $reason);
    }

    private function identifier(string $connection, string $table, string $column, string $reason): string
    {
        return "{$connection}.{$table}.{$column}: {$reason}";
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
