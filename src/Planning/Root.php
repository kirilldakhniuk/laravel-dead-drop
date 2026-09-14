<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use InvalidArgumentException;

/**
 * The row set a traversal starts from: `connection.table:id[,id]`, or the
 * whole database — `connection:*`, and `*:*` for every configured connection.
 */
final readonly class Root
{
    /**
     * The table of a whole-database root, and the connection of one that is
     * not confined to a single connection.
     */
    public const string ALL = '*';

    /**
     * How many ids `describe()` names before it starts counting.
     */
    private const int DESCRIBED_IDS = 3;

    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public string $connection,
        public string $table,
        public array $ids,
    ) {}

    /**
     * Every dumpable row of every dumpable table, on one connection or — with
     * no connection named — on all of them. There is no row set to start from,
     * so such a root is a scope rather than a seed.
     */
    public static function full(?string $connection): self
    {
        return new self($connection ?? self::ALL, self::ALL, []);
    }

    public function isFull(): bool
    {
        return $this->table === self::ALL;
    }

    /**
     * The one connection a whole-database root is confined to, or null when it
     * covers every configured connection.
     */
    public function scope(): ?string
    {
        return $this->connection === self::ALL ? null : $this->connection;
    }

    /**
     * The spec form `parse()` reads back, and the form the manifest records.
     */
    public function spec(): string
    {
        if ($this->isFull()) {
            return "{$this->connection}:".self::ALL;
        }

        return "{$this->connection}.{$this->table}:".implode(',', $this->ids);
    }

    /**
     * The same row set the way an operator would say it: `users #1, #2 (mysql)`.
     * A root of two hundred ids is a listing column, not a recital, so only
     * the first few are named and the rest are counted.
     */
    public function describe(): string
    {
        if ($this->isFull()) {
            return 'whole database ('.($this->scope() ?? 'all connections').')';
        }

        $shown = array_slice($this->ids, 0, self::DESCRIBED_IDS);
        $rest = count($this->ids) - count($shown);
        $ids = '#'.implode(', #', $shown).($rest > 0 ? " … (+{$rest})" : '');

        return "{$this->table} {$ids} ({$this->connection})";
    }

    public static function parse(string $spec): self
    {
        if (str_ends_with($spec, ':'.self::ALL)) {
            $connection = trim(substr($spec, 0, -2));

            return $connection === '' ? throw self::invalid($spec) : self::full($connection);
        }

        $dot = strpos($spec, '.');

        if ($dot === false) {
            throw self::invalid($spec);
        }

        $connection = trim(substr($spec, 0, $dot));
        $rest = substr($spec, $dot + 1);
        $colon = strpos($rest, ':');

        if ($colon === false) {
            throw self::invalid($spec);
        }

        $table = trim(substr($rest, 0, $colon));

        if ($connection === '' || $table === '') {
            throw self::invalid($spec);
        }

        $ids = [];

        foreach (explode(',', substr($rest, $colon + 1)) as $id) {
            $id = trim($id);

            if ($id === '') {
                throw self::invalid($spec);
            }

            $ids[] = ((string) (int) $id) === $id ? (int) $id : $id;
        }

        return new self($connection, $table, $ids);
    }

    private static function invalid(string $spec): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid root spec [$spec]; expected connection.table:id[,id] or connection:*");
    }
}
