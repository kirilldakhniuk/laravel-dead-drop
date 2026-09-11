<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

/**
 * The role a table plays in a dump: descended and scoped like a normal
 * business table, copied whole because it is a small reference table with
 * nothing to scope, or left out entirely.
 */
enum TableClass: string
{
    case Data = 'data';
    case Lookup = 'lookup';
    case Skip = 'skip';
}
