<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

/**
 * The role a table plays in a dump: taken and redacted like a normal
 * business table, or left out entirely.
 */
enum TableClass: string
{
    case Data = 'data';
    case Skip = 'skip';
}
