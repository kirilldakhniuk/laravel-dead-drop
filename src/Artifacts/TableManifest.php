<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use InvalidArgumentException;

/**
 * One table's entry in a dump manifest: where its rows live on disk, how
 * many there are, and the column shape a loader needs to restore scalars
 * from the NDJSON lines.
 */
final readonly class TableManifest
{
    /**
     * @param  list<array{name: string, type: string}>  $columns  schema order; `type` is a `ColumnType` backing value
     * @param  list<string>  $redacted  names of columns whose values were transformed before writing
     */
    public function __construct(
        public string $connection,
        public string $table,
        public string $file,
        public string $format,
        public int $rows,
        public int $bytes,
        public string $primaryKey,
        public array $columns,
        public array $redacted,
    ) {}

    public function key(): string
    {
        return "{$this->connection}.{$this->table}";
    }

    /**
     * @return array{
     *     connection: string,
     *     table: string,
     *     file: string,
     *     format: string,
     *     rows: int,
     *     bytes: int,
     *     primary_key: string,
     *     columns: list<array{name: string, type: string}>,
     *     redacted: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'table' => $this->table,
            'file' => $this->file,
            'format' => $this->format,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'primary_key' => $this->primaryKey,
            'columns' => $this->columns,
            'redacted' => $this->redacted,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $connection = $raw['connection'] ?? null;
        $table = $raw['table'] ?? null;
        $file = $raw['file'] ?? null;
        $format = $raw['format'] ?? null;
        $rows = $raw['rows'] ?? null;
        $bytes = $raw['bytes'] ?? null;
        $primaryKey = $raw['primary_key'] ?? null;
        $columns = $raw['columns'] ?? null;
        $redacted = $raw['redacted'] ?? null;

        if (
            ! is_string($connection)
            || ! is_string($table)
            || ! is_string($file)
            || ! is_string($format)
            || ! is_int($rows)
            || ! is_int($bytes)
            || ! is_string($primaryKey)
            || ! is_array($columns)
            || ! is_array($redacted)
        ) {
            throw new InvalidArgumentException('Unsupported or malformed manifest.');
        }

        return new self(
            connection: $connection,
            table: $table,
            file: $file,
            format: $format,
            rows: $rows,
            bytes: $bytes,
            primaryKey: $primaryKey,
            columns: self::readColumns($columns),
            redacted: self::readRedacted($redacted),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<array{name: string, type: string}>
     */
    private static function readColumns(array $raw): array
    {
        $columns = [];

        foreach ($raw as $column) {
            if (! is_array($column) || ! is_string($column['name'] ?? null) || ! is_string($column['type'] ?? null)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $columns[] = ['name' => $column['name'], 'type' => $column['type']];
        }

        return $columns;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<string>
     */
    private static function readRedacted(array $raw): array
    {
        $redacted = [];

        foreach ($raw as $column) {
            if (! is_string($column)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $redacted[] = $column;
        }

        return $redacted;
    }
}
