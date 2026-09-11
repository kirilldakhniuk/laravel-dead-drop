<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use InvalidArgumentException;

/**
 * The row set a traversal starts from: `connection.table:id[,id]`.
 */
final readonly class Root
{
    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public string $connection,
        public string $table,
        public array $ids,
    ) {}

    public static function parse(string $spec): self
    {
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
        return new InvalidArgumentException("Invalid root spec [$spec]; expected connection.table:id[,id]");
    }
}
