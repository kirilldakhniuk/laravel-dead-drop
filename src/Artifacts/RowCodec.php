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
                ? ['__base64' => base64_encode((string) $value)]
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
            ColumnType::Boolean => (bool) $value,
            ColumnType::Decimal => (string) $value,
            default => $value,
        };
    }
}
