<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DateTimeInterface;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Support\Facades\DB;

/**
 * Turns a traversal into the ordered work a dump would do.
 *
 * The order is the whole point: a step's table is written after every table it
 * references, so a restore can keep its foreign keys on. Connections come in
 * the graph's order, and inside one connection the tables are sorted by their
 * static edges — morph targets are decided by row data, so they cannot order
 * anything.
 */
final class Planner
{
    public function __construct(
        private readonly Traverser $traverser,
        private readonly KeySetRepository $keys,
    ) {}

    /**
     * Every table the traversal collected rows for, ordered for writing.
     *
     * The key sets live in temporary tables owned by the caller, so the plan
     * is built while they are still there: the caller drops them afterwards.
     *
     * @throws UnsupportedTableException when a configured table cannot be addressed by a single primary key
     * @throws CircularConnectionException when the connections reference each other in a cycle
     */
    public function plan(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): ExtractionPlan
    {
        $this->assertEveryTableIsAddressable($config->connections(), $config, $schemas);

        $result = $this->traverser->traverse($root, $config, $schemas, $since);

        return new ExtractionPlan(
            $this->order($this->steps($result, $root, $config, $schemas), Graph::fromConfig($config)),
            $result->unresolved(),
        );
    }

    /**
     * Every dumpable table of every connection, whole. There is nothing to
     * traverse — a dump that takes all the rows is referentially complete by
     * construction — so this counts the rows instead, and only a table with a
     * `window` narrowed by `--since` needs a key set at all.
     *
     * @param  string|null  $connection  the one connection to cover, or null for every configured one
     *
     * @throws UnsupportedTableException when a configured table cannot be addressed by a single primary key
     * @throws CircularConnectionException when the connections reference each other in a cycle
     */
    public function planFull(ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since, ?string $connection): ExtractionPlan
    {
        // A key set is only ever created here, so a previous run's must not
        // be counted into this one.
        $this->keys->dropAll();

        $connections = $connection === null ? $config->connections() : [$connection];

        $this->assertEveryTableIsAddressable($connections, $config, $schemas);

        $steps = [];

        foreach ($connections as $name) {
            foreach ($config->for($name)->tables as $table => $tableConfig) {
                $step = $this->fullStep($name, (string) $table, $tableConfig, $schemas, $since);

                if ($step !== null) {
                    $steps[] = $step;
                }
            }
        }

        return new ExtractionPlan($this->order($steps, Graph::fromConfig($config)), []);
    }

    /**
     * One table's share of a whole-database dump, or null when it holds
     * nothing the dump would write: a table that is not dumpable, one the
     * schema no longer has, and one with no rows inside the window.
     */
    private function fullStep(string $connection, string $table, TableConfig $config, SchemaSet $schemas, ?DateTimeInterface $since): ?PlanStep
    {
        if ($config->removed || ($config->class !== TableClass::Data && $config->class !== TableClass::Lookup)) {
            return null;
        }

        $meta = $schemas->for($connection)->table($table);

        if ($meta === null) {
            return null;
        }

        $keySet = $config->window === null || $since === null ? null : $this->windowed($connection, $meta, $config->window, $since);
        $rows = $keySet === null ? DB::connection($connection)->table($table)->count() : $keySet->count();

        if ($rows === 0) {
            return null;
        }

        return new PlanStep(
            connection: $connection,
            table: $table,
            keyTable: $keySet?->tableName,
            rows: $rows,
            estimatedBytes: intdiv($meta->estimatedBytes * $rows, max($meta->estimatedRows, 1)),
        );
    }

    /**
     * The keys of the rows a `--since` leaves inside a table's window, held in
     * a key table like a traversal's own: the extraction joins it, and the
     * keys never all exist in PHP at once.
     */
    private function windowed(string $connection, Table $table, string $window, DateTimeInterface $since): ?KeySet
    {
        $primaryKey = $table->primaryKey();

        if ($primaryKey === null) {
            return null;
        }

        $column = $table->column($primaryKey);
        $keySet = $this->keys->create($connection, $table->name, $column === null ? ColumnType::String : $column->type);

        $keySet->fill(
            DB::connection($connection)->table($table->name)
                ->where("{$table->name}.{$window}", '>=', $since)
                ->select("{$table->name}.{$primaryKey} as k")
                ->orderBy("{$table->name}.{$primaryKey}"),
        );

        return $keySet;
    }

    /**
     * A dump addresses rows by primary key, so a table without a usable one
     * cannot be dumped at all — that is a config decision, not something to
     * discover halfway through a traversal. Tables the schema no longer has
     * are drift, which `dead-drop:check` reports.
     *
     * @param  list<string>  $connections
     *
     * @throws UnsupportedTableException
     */
    private function assertEveryTableIsAddressable(array $connections, ConfigSet $config, SchemaSet $schemas): void
    {
        foreach ($connections as $connection) {
            $schema = $schemas->for($connection);

            foreach ($config->for($connection)->tables as $name => $tableConfig) {
                if ($tableConfig->removed || $tableConfig->class === TableClass::Skip) {
                    continue;
                }

                $table = $schema->table((string) $name);

                if ($table === null) {
                    continue;
                }

                if ($table->hasCompositePrimaryKey()) {
                    throw UnsupportedTableException::compositePrimaryKey($connection, $table->name);
                }

                if ($table->primaryKey() === null) {
                    throw UnsupportedTableException::noPrimaryKey($connection, $table->name);
                }
            }
        }
    }

    /**
     * One step per key set that actually holds rows. A lookup table is taken
     * whole, so it carries no key table even though the traversal seeded one —
     * unless it is the root, which is asked for by id like any other root and
     * whose key set therefore holds exactly the rows the plan counted.
     *
     * @return list<PlanStep>
     */
    private function steps(TraversalResult $result, Root $root, ConfigSet $config, SchemaSet $schemas): array
    {
        $steps = [];

        foreach ($result->keySets() as $keySet) {
            $rows = $keySet->count();

            if ($rows === 0) {
                continue;
            }

            $table = $schemas->for($keySet->connection)->table($keySet->table);
            $lookup = $config->for($keySet->connection)->table($keySet->table)?->class === TableClass::Lookup;
            $isRoot = $keySet->connection === $root->connection && $keySet->table === $root->table;

            $steps[] = new PlanStep(
                connection: $keySet->connection,
                table: $keySet->table,
                keyTable: $lookup && ! $isRoot ? null : $keySet->tableName,
                rows: $rows,
                estimatedBytes: $table === null ? 0 : intdiv($table->estimatedBytes * $rows, max($table->estimatedRows, 1)),
            );
        }

        return $steps;
    }

    /**
     * @param  list<PlanStep>  $steps
     * @return list<PlanStep>
     */
    private function order(array $steps, Graph $graph): array
    {
        /** @var array<string, list<PlanStep>> $groups */
        $groups = [];

        foreach ($steps as $step) {
            $groups[$step->connection][] = $step;
        }

        $ordered = [];

        foreach ($graph->connectionOrder() as $connection) {
            if (isset($groups[$connection])) {
                $ordered = [...$ordered, ...$this->orderTables($groups[$connection], $graph, $connection)];

                unset($groups[$connection]);
            }
        }

        // A key set of an unconfigured connection cannot happen, but dropping
        // a step silently would be worse than an unordered tail.
        ksort($groups);

        foreach ($groups as $connection => $group) {
            $ordered = [...$ordered, ...$this->orderTables($group, $graph, (string) $connection)];
        }

        return $ordered;
    }

    /**
     * The steps of one connection, referenced tables first. Ties are broken by
     * table name so a plan is reproducible, and a cycle of references — which
     * no order satisfies — leaves the rest in name order rather than failing:
     * the dump still has to be written.
     *
     * @param  list<PlanStep>  $steps
     * @return list<PlanStep>
     */
    private function orderTables(array $steps, Graph $graph, string $connection): array
    {
        /** @var array<string, PlanStep> $remaining keyed by table name */
        $remaining = [];

        foreach ($steps as $step) {
            $remaining[$step->table] = $step;
        }

        ksort($remaining);

        /** @var array<string, array<string, true>> $pending table => the tables it still waits on */
        $pending = [];

        foreach (array_keys($remaining) as $table) {
            $table = (string) $table;
            $pending[$table] = [];

            foreach ($graph->outboundEdges($connection, $table) as $edge) {
                // Another connection's tables are ordered by the connection
                // order; a self-reference and a table with no step order
                // nothing.
                if ($edge->targetConnection !== $connection || $edge->targetTable === $table || ! isset($remaining[$edge->targetTable])) {
                    continue;
                }

                $pending[$table][$edge->targetTable] = true;
            }
        }

        $ordered = [];

        while ($pending !== []) {
            $ready = [];

            foreach ($pending as $table => $waitsOn) {
                if ($waitsOn === []) {
                    $ready[] = (string) $table;
                }
            }

            if ($ready === []) {
                break;
            }

            sort($ready);

            foreach ($ready as $table) {
                $ordered[] = $remaining[$table];

                unset($pending[$table], $remaining[$table]);
            }

            foreach ($pending as $table => $waitsOn) {
                foreach ($ready as $done) {
                    unset($pending[$table][$done]);
                }
            }
        }

        return [...$ordered, ...array_values($remaining)];
    }
}
