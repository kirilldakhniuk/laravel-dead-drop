<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DateTimeInterface;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Extraction\SourceConnections;
use DeadDrop\DeadDrop\Schema\SchemaSet;

final class Planner
{
    public function __construct(
        private readonly Traverser $traverser,
        private readonly SourceConnections $connections = new SourceConnections,
    ) {}

    /**
     * @throws UnsupportedTableException when a configured table cannot be addressed by a single primary key
     * @throws CircularConnectionException when the connections reference each other in a cycle
     */
    public function plan(Root $root, ConfigSet $config, SchemaSet $schemas, ?DateTimeInterface $since = null): ExtractionPlan
    {
        $this->assertSupportedTables($config->connections(), $config, $schemas);

        $result = $this->traverser->traverse($root, $config, $schemas, $since);

        return new ExtractionPlan(
            $this->orderSteps($this->traversedSteps($result, $root, $config, $schemas), Graph::fromConfig($config)),
            $result->unresolved(),
        );
    }

    /**
     * @param  string|null  $connection  the one connection to cover, or null for every configured one
     *
     * @throws UnsupportedTableException when a configured table cannot be addressed by a single primary key
     * @throws CircularConnectionException when the connections reference each other in a cycle
     */
    public function planFull(ConfigSet $config, SchemaSet $schemas, ?string $connection): ExtractionPlan
    {
        $connections = $connection === null ? $config->connections() : [$connection];

        $this->assertSupportedTables($connections, $config, $schemas);

        $steps = [];

        foreach ($connections as $name) {
            foreach ($config->for($name)->tables as $table => $tableConfig) {
                $step = $this->fullTableStep($name, (string) $table, $tableConfig, $schemas);

                if ($step !== null) {
                    $steps[] = $step;
                }
            }
        }

        return new ExtractionPlan($this->orderSteps($steps, Graph::fromConfig($config)), []);
    }

    private function fullTableStep(string $connection, string $table, TableConfig $config, SchemaSet $schemas): ?PlanStep
    {
        if ($config->removed || ($config->class !== TableClass::Data && $config->class !== TableClass::Lookup)) {
            return null;
        }

        $meta = $schemas->for($connection)->table($table);

        if ($meta === null) {
            return null;
        }

        $rows = $this->connections->get($connection)->table($table)->count();

        if ($rows === 0) {
            return null;
        }

        return new PlanStep(
            connection: $connection,
            table: $table,
            keyTable: null,
            rows: $rows,
            estimatedBytes: intdiv($meta->estimatedBytes * $rows, max($meta->estimatedRows, 1)),
        );
    }

    /**
     * @param  list<string>  $connections
     *
     * @throws UnsupportedTableException
     */
    private function assertSupportedTables(array $connections, ConfigSet $config, SchemaSet $schemas): void
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
     * @return list<PlanStep>
     */
    private function traversedSteps(TraversalResult $result, Root $root, ConfigSet $config, SchemaSet $schemas): array
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
    private function orderSteps(array $steps, Graph $graph): array
    {
        /** @var array<string, list<PlanStep>> $groups */
        $groups = [];

        foreach ($steps as $step) {
            $groups[$step->connection][] = $step;
        }

        $ordered = [];

        foreach ($graph->connectionOrder() as $connection) {
            if (isset($groups[$connection])) {
                array_push($ordered, ...$this->orderTables($groups[$connection], $graph, $connection));

                unset($groups[$connection]);
            }
        }

        // Keep any unconfigured connections in a deterministic tail.
        ksort($groups);

        foreach ($groups as $connection => $group) {
            array_push($ordered, ...$this->orderTables($group, $graph, (string) $connection));
        }

        return $ordered;
    }

    /**
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

        // Cyclic table dependencies have no valid order; keep the rest alphabetical.
        return [...$ordered, ...array_values($remaining)];
    }
}
