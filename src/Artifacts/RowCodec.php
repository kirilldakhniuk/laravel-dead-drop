<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use DeadDrop\DeadDrop\Schema\ColumnType;
use RuntimeException;

/**
 * Turns one row into an NDJSON line and back, using the column's
 * `ColumnType` to keep binary payloads intact through JSON and to restore
 * scalar types (`int`, `bool`, decimal-as-string) that JSON alone loses.
 */
final class RowCodec
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, ColumnType>  $types
     */
    public function encode(array $row, array $types): string
    {
        $encoded = [];

        foreach ($row as $column => $value) {
            $type = $types[$column] ?? null;

            $encoded[$column] = $type === ColumnType::Binary && $value !== null
                ? ['__base64' => base64_encode($this->binaryString($value))]
                : $value;
        }

        return json_encode($encoded, self::JSON_FLAGS);
    }

    /**
     * @param  array<string, ColumnType>  $types
     * @return array<string, mixed>
     */
    public function decode(string $line, array $types): array
    {
        $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Malformed artifact row: expected a JSON object.');
        }

        $row = [];

        foreach ($decoded as $column => $value) {
            $row[(string) $column] = $this->decodeValue($types[(string) $column] ?? null, $value);
        }

        return $row;
    }

    private function decodeValue(?ColumnType $type, mixed $value): mixed
    {
        if ($type === ColumnType::Binary) {
            if ($value === null) {
                return null;
            }

            if (! is_array($value) || ! is_string($value['__base64'] ?? null)) {
                throw new RuntimeException('Malformed artifact row: expected a base64-wrapped binary value.');
            }

            $decoded = base64_decode($value['__base64'], true);

            if ($decoded === false) {
                throw new RuntimeException('Malformed artifact row: invalid base64 payload.');
            }

            return $decoded;
        }

        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::Integer => (int) $value,
            ColumnType::Boolean => $this->decodeBoolean($value),
            ColumnType::Decimal => (string) $value,
            default => $value,
        };
    }

    /**
     * PDO drivers (notably Postgres) can hand back boolean columns as the
     * strings `t`/`f` rather than native booleans, and `(bool) 'f'` is
     * `true` — so string values get their own truth table instead of a
     * plain cast.
     *
     * Anything else is passed through untouched: a column this package read
     * as a boolean but the source stored a 5 in (a MySQL `tinyint` narrowed
     * by an older introspection) must arrive as 5, not as `true`.
     */
    private function decodeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => $value,
            };
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'f', 'false', '0' => false,
                't', 'true', '1' => true,
                default => $value,
            };
        }

        return $value;
    }

    /**
     * A binary column's value can arrive as a stream (some PDO drivers hand
     * back BLOB/bytea columns as resources rather than strings), so it is
     * read to completion before it is base64-encoded.
     */
    private function binaryString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            if ($contents === false) {
                throw new RuntimeException('Malformed artifact row: unable to read a binary stream.');
            }

            return $contents;
        }

        return (string) $value;
    }
}
