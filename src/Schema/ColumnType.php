<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Schema;

enum ColumnType: string
{
    case Integer = 'int';
    case String = 'string';
    case Boolean = 'bool';
    case Json = 'json';
    case DateTime = 'datetime';
    case Decimal = 'decimal';
    case Uuid = 'uuid';
    case Binary = 'binary';
    case Other = 'other';
}
