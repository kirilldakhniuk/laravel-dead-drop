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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplQueue;

final class Traverser
{
    /**
     * @var array<string, list<array{string, string}>> keyed "{connection}.{table}"
     */
    private array $morphScans = [];

    public function __construct(
        private readonly KeySetRepository $keys,
        private readonly MorphResolver $morphs,
    ) {}

    public function traverse(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): TraversalResult
    {
        $this->keys->dropAll();

        $graph = Graph::fromConfig($config);

        // Reject connection cycles before creating temporary tables.
        $graph->connectionOrder();

        /** @var array<string, UnresolvedReference> $unresolved */
        $unresolved = [];

        $this->seedRoot($root, $graph, $schemas);
        $this->seedLookups($root, $config, $schemas);
        $this->descend($root, $graph, $config, $schemas, $since, $unresolved);

        // Parents added for referential integrity must not expand the descending scope.
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

                $keySet->fill(
                    DB::connection($connection)->table($name)
                        ->select("{$name}.{$primaryKey} as k")
                        ->orderBy("{$name}.{$primaryKey}"),
                );
            }
        }
    }

    /**
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function descend(Root $root, Graph $graph, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since, array &$unresolved): void
    {
        $this->morphScans = [];

        /** @var SplQueue<array{string, string}> $queue */
        $queue = new SplQueue;
        $queue->enqueue([$root->connection, $root->table]);

        while (! $queue->isEmpty()) {
            [$connection, $table] = $queue->dequeue();
            $parent = $this->keys->get($connection, $table);

            if ($parent === null) {
                continue;
            }

            foreach ($graph->inboundEdges($connection, $table) as $edge) {
                if (! $edge->descend) {
                    continue;
                }

                if ($graph->tableClass($edge->connection, $edge->table) !== TableClass::Data) {
                    continue;
                }

                // Report unusable edges only when their source rows are collected.
                if ($this->edgeRejectionReason($edge, $graph, $schemas) !== null) {
                    continue;
                }

                $child = $this->keySet($edge->connection, $edge->table, $schemas);

                if ($child === null) {
                    continue;
                }

                if ($this->descendInto($edge, $parent, $child, $config, $schemas, $since) > 0) {
                    $queue->enqueue([$edge->connection, $edge->table]);
                }
            }

            foreach ($this->descendMorphs($parent, $config, $schemas, $since, $unresolved) as $grown) {
                $queue->enqueue($grown);
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

        $keys = $edge->connection === $parent->connection
            ? $parent->tableName
            : $this->keys->mirror($parent, $edge->connection)->tableName;

        $query = DB::connection($edge->connection)->table($table)
            ->join($keys, "{$table}.{$edge->column}", '=', "{$keys}.k");

        return $child->fill(
            $this->applyDescendingScope($query, $table, $config->for($edge->connection)->table($table), $since)
                ->distinct()
                ->select("{$table}.{$primaryKey} as k")
                ->orderBy("{$table}.{$primaryKey}"),
        );
    }

    /**
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

            $scan = $this->morphScans["{$connection}.{$table}"]
                ??= $this->morphTargets($db->table($table), $connection, $table, $morph['type'], $unresolved);

            $types = $this->typesTargetingTable($scan, $parent->table);

            $child = $types === [] ? null : $this->keySet($connection, $table, $schemas);

            if ($child === null) {
                continue;
            }

            $added = 0;

            foreach ($types as $type) {
                $query = $db->table($table)
                    ->join($parent->tableName, "{$table}.{$morph['id']}", '=', "{$parent->tableName}.k")
                    ->where("{$table}.{$morph['type']}", $type);

                $added += $child->fill(
                    $this->applyDescendingScope($query, $table, $tableConfig, $since)
                        ->distinct()
                        ->select("{$table}.{$primaryKey} as k")
                        ->orderBy("{$table}.{$primaryKey}"),
                );
            }

            if ($added > 0) {
                $grown[] = [$connection, $table];
            }
        }

        return $grown;
    }

    /**
     * @param  list<array{string, string}>  $targets
     * @return list<string>
     */
    private function typesTargetingTable(array $targets, string $table): array
    {
        $types = [];

        foreach ($targets as [$type, $target]) {
            if ($target === $table) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function applyDescendingScope(Builder $query, string $table, ?TableConfig $config, ?DateTimeInterface $since): Builder
    {
        $window = $config?->window;
        $exclude = $config?->exclude;

        if ($window !== null && $since !== null) {
            $query->where("{$table}.{$window}", '>=', $since);
        }

        if ($exclude !== null) {
            // Keep NULL results and contain any OR clauses inside the exclusion predicate.
            $query->whereRaw($this->configuredSqlExpression("not coalesce(({$exclude}), false)"));
        }

        return $query;
    }

    /**
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function ascend(Graph $graph, ConfigSet $config, SchemaSet $schemas, array &$unresolved): void
    {
        $grew = true;

        while ($grew) {
            $grew = false;

            foreach ($this->keys->all() as $source) {
                foreach ($graph->outboundEdges($source->connection, $source->table) as $edge) {
                    $reason = $this->edgeRejectionReason($edge, $graph, $schemas);

                    if ($reason !== null) {
                        $identifier = $this->referenceIdentifier($edge->connection, $edge->table, $edge->column, $reason);

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

        return $target->fill(
            $query->distinct()
                ->select("{$edge->table}.{$edge->column} as k")
                ->orderBy("{$edge->table}.{$edge->column}"),
        );
    }

    /**
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
            $reason = $this->targetRejectionReason($connection, $targetTable, $graph, $schemas);

            if ($reason !== null) {
                $this->recordUnresolvedReference($unresolved, $connection, $table, $morph['id'], $reason);

                continue;
            }

            $target = $this->keySet($connection, $targetTable, $schemas);

            if ($target === null) {
                continue;
            }

            $added += $target->fill(
                $collected()
                    ->where("{$table}.{$morph['type']}", $type)
                    ->whereNotNull("{$table}.{$morph['id']}")
                    ->distinct()
                    ->select("{$table}.{$morph['id']} as k")
                    ->orderBy("{$table}.{$morph['id']}"),
            );
        }

        return $added;
    }

    /**
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
                $this->recordUnresolvedReference($unresolved, $connection, $table, $column, "unmapped morph type: {$type}");

                continue;
            }

            $targets[] = [$type, $target];
        }

        return $targets;
    }

    /**
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
                $this->recordUnresolvedReference($unresolved, $connection, $table, $column, "morph column {$column} does not exist");

                return false;
            }
        }

        return true;
    }

    private function hasReferences(GraphEdge $edge, KeySet $source, SchemaSet $schemas): bool
    {
        return $this->referencedRows($edge, $source, $schemas)?->exists() === true;
    }

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

    private function edgeRejectionReason(GraphEdge $edge, Graph $graph, SchemaSet $schemas): ?string
    {
        $reason = $this->targetRejectionReason($edge->targetConnection, $edge->targetTable, $graph, $schemas);

        if ($reason !== null) {
            return $reason;
        }

        if ($this->primaryKey($schemas, $edge->targetConnection, $edge->targetTable) !== $edge->targetColumn) {
            return "target column {$edge->targetTable}.{$edge->targetColumn} is not the primary key";
        }

        return null;
    }

    private function targetRejectionReason(string $connection, string $table, Graph $graph, SchemaSet $schemas): ?string
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
     * @param  array<string, UnresolvedReference>  $unresolved
     */
    private function recordUnresolvedReference(array &$unresolved, string $connection, string $table, string $column, string $reason): void
    {
        $unresolved[$this->referenceIdentifier($connection, $table, $column, $reason)] ??= new UnresolvedReference($connection, $table, $column, $reason);
    }

    private function referenceIdentifier(string $connection, string $table, string $column, string $reason): string
    {
        return "{$connection}.{$table}.{$column}: {$reason}";
    }

    // Configured SQL is trusted but cannot satisfy Laravel's literal-string expression type.
    private function configuredSqlExpression(string $sql): Expression
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
