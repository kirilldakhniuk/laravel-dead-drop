<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Drivers;

use DeadDrop\DeadDrop\Schema\ColumnType;

trait NormalisesTypes
{
    /**
     * @var array<string, list<string>>
     */
    private const array TYPE_MAP = [
        'int' => ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'int2', 'int4', 'int8', 'serial', 'bigserial'],
        'bool' => ['bool', 'boolean'],
        'decimal' => ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'money'],
        'datetime' => ['date', 'datetime', 'timestamp', 'timestamptz', 'time', 'timetz', 'year'],
        'json' => ['json', 'jsonb'],
        'uuid' => ['uuid'],
        'binary' => ['blob', 'binary', 'varbinary', 'bytea', 'tinyblob', 'mediumblob', 'longblob'],
        'string' => ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'character varying', 'enum', 'set', 'citext', 'inet'],
    ];

    /**
     * @param  string  $nativeType  the full native type, parameters and all (`tinyint(1)`, `timestamp(0) without time zone`)
     */
    public function normaliseType(string $nativeType): ColumnType
    {
        $native = strtolower(trim($nativeType));

        // Only `tinyint(1)` is MySQL's boolean; every other width is a small
        // integer, and calling one a boolean would turn a `tinyint(4)` status
        // of 5 into 1 when the artifact is loaded. MySQL 8.0.19+ can report a
        // boolean column as a bare `tinyint`, which is then treated as an
        // integer — lossless, where the other direction is not.
        if (str_starts_with($native, 'tinyint')) {
            return preg_match('/^tinyint\s*\(\s*1\s*\)/', $native) === 1 ? ColumnType::Boolean : ColumnType::Integer;
        }

        $base = trim(strtok($native, '(') ?: $native);

        // Postgres spells a type out in full (`timestamp without time zone`,
        // `double precision`), so the whole name is tried before its first
        // word — `character varying` must not be read as `character`.
        foreach ([$base, explode(' ', $base)[0]] as $candidate) {
            foreach (self::TYPE_MAP as $columnType => $nativeTypes) {
                if (in_array($candidate, $nativeTypes, true)) {
                    return ColumnType::from($columnType);
                }
            }
        }

        return ColumnType::Other;
    }
}
