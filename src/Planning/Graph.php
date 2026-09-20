<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\TableClass;

final readonly class Graph
{
    /** @var array<string, list<GraphEdge>> */
    private array $inbound;

    /** @var array<string, list<GraphEdge>> */
    private array $outbound;

    /**
     * @param  list<GraphEdge>  $edges
     * @param  array<string, TableClass>  $classes  keyed "{connection}.{table}", removed tables omitted
     * @param  list<string>  $connections  every configured connection, edges or not
     */
    private function __construct(
        private array $edges,
        private array $classes,
        private array $connections,
    ) {
        $inbound = [];
        $outbound = [];

        foreach ($edges as $edge) {
            $inbound["{$edge->targetConnection}.{$edge->targetTable}"][] = $edge;
            $outbound["{$edge->connection}.{$edge->table}"][] = $edge;
        }

        $this->inbound = $inbound;
        $this->outbound = $outbound;
    }

    public static function fromConfig(ConfigSet $config): self
    {
        $edges = [];
        $classes = [];
        $connections = [];

        foreach ($config->connections as $connection => $connectionConfig) {
            $connections[] = $connection;

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

        return new self($edges, $classes, $connections);
    }

    /**
     * @return list<string>
     *
     * @throws CircularConnectionException when no such order exists
     */
    public function connectionOrder(): array
    {
        /** @var array<string, array<string, true>> $pending connection => the connections it still waits on */
        $pending = [];

        foreach ($this->connections as $connection) {
            $pending[$connection] = [];
        }

        foreach ($this->edges as $edge) {
            if ($edge->connection === $edge->targetConnection) {
                continue;
            }

            $pending[$edge->targetConnection] ??= [];
            $pending[$edge->connection][$edge->targetConnection] = true;
        }

        $order = [];

        while ($pending !== []) {
            $ready = [];

            foreach ($pending as $connection => $waitsOn) {
                if ($waitsOn === []) {
                    $ready[] = (string) $connection;
                }
            }

            if ($ready === []) {
                $cycle = array_map(strval(...), array_keys($pending));
                sort($cycle);

                throw new CircularConnectionException('Connections reference each other in a cycle: '.implode(', ', $cycle));
            }

            sort($ready);
            $next = $ready[0];

            unset($pending[$next]);

            foreach ($pending as $connection => $waitsOn) {
                unset($pending[$connection][$next]);
            }

            $order[] = $next;
        }

        return $order;
    }

    /**
     * @return list<GraphEdge>
     */
    public function inboundEdges(string $connection, string $table): array
    {
        return $this->inbound["{$connection}.{$table}"] ?? [];
    }

    /**
     * @return list<GraphEdge>
     */
    public function outboundEdges(string $connection, string $table): array
    {
        return $this->outbound["{$connection}.{$table}"] ?? [];
    }

    public function tableClass(string $connection, string $table): ?TableClass
    {
        return $this->classes["{$connection}.{$table}"] ?? null;
    }
}
