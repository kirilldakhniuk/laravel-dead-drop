<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use InvalidArgumentException;

/**
 * Every table plan for one database connection: the parsed form of a single
 * `<connection>.php` config file.
 */
final readonly class ConnectionConfig
{
    /**
     * @param  array<string, TableConfig>  $tables  keyed by table name
     */
    public function __construct(
        public string $connection,
        public array $tables,
    ) {}

    public function table(string $name): ?TableConfig
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(string $connection, array $raw): self
    {
        $tables = [];

        foreach ($raw as $name => $table) {
            $name = (string) $name;

            if (! is_array($table)) {
                throw new InvalidArgumentException("Table [$connection.$name] must be an array.");
            }

            $tables[$name] = TableConfig::fromArray($name, $table);
        }

        return new self($connection, $tables);
    }

    /**
     * The rendered shape: tables sorted by name, each in `TableConfig`'s
     * fixed key order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name] = $table->toArray();
        }

        ksort($tables);

        return $tables;
    }
}
