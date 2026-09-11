<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;

/**
 * The reviewed config read as a directed graph. This is the only place that
 * knows how config structure maps onto edges, so the traverser can ask about
 * relationships without re-reading references itself.
 */
final readonly class Graph
{
    /**
     * @param  list<GraphEdge>  $edges
     * @param  array<string, TableClass>  $classes  keyed "{connection}.{table}", removed tables omitted
     */
    private function __construct(
        private array $edges,
        private array $classes,
    ) {}

    public static function fromConfig(ConfigSet $config): self
    {
        $edges = [];
        $classes = [];

        foreach ($config->connections as $connection => $connectionConfig) {
            foreach ($connectionConfig->tables as $name => $table) {
                if ($table->removed) {
                    continue;
                }

                $classes["{$connection}.{$name}"] = $table->class;

                if ($table->class === TableClass::Skip) {
                    continue;
                }

                foreach ($table->references as $column => $reference) {
                    $edges[] = new GraphEdge(
                        connection: $connection,
                        table: $name,
                        column: $column,
                        targetConnection: $reference->connection ?? $connection,
                        targetTable: $reference->table,
                        targetColumn: $reference->column,
                        descend: $reference->descend,
                    );
                }
            }
        }

        return new self($edges, $classes);
    }

    /**
     * The edges pointing at this table: the children that can be descended to.
     *
     * @return list<GraphEdge>
     */
    public function inboundEdges(string $connection, string $table): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (GraphEdge $edge): bool => $edge->targetConnection === $connection && $edge->targetTable === $table,
        ));
    }

    /**
     * The edges leaving this table: the parents that must be ascended to.
     *
     * @return list<GraphEdge>
     */
    public function outboundEdges(string $connection, string $table): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (GraphEdge $edge): bool => $edge->connection === $connection && $edge->table === $table,
        ));
    }

    /**
     * Null when the table is not in the config at all, or was removed from the
     * schema since the last init.
     */
    public function tableClass(string $connection, string $table): ?TableClass
    {
        return $this->classes["{$connection}.{$table}"] ?? null;
    }
}
