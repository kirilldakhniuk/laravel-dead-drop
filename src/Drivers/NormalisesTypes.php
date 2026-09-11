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
        'bool' => ['bool', 'boolean', 'tinyint'],
        'decimal' => ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'money'],
        'datetime' => ['date', 'datetime', 'timestamp', 'timestamptz', 'time', 'timetz', 'year'],
        'json' => ['json', 'jsonb'],
        'uuid' => ['uuid'],
        'binary' => ['blob', 'binary', 'varbinary', 'bytea', 'tinyblob', 'mediumblob', 'longblob'],
        'string' => ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'character varying', 'enum', 'set', 'citext', 'inet'],
    ];

    public function normaliseType(string $nativeType): ColumnType
    {
        $type = strtolower(strtok($nativeType, '(') ?: $nativeType);

        foreach (self::TYPE_MAP as $columnType => $nativeTypes) {
            if (in_array($type, $nativeTypes, true)) {
                return ColumnType::from($columnType);
            }
        }

        return ColumnType::Other;
    }
}
