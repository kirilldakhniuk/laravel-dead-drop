<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use DeadDrop\DeadDrop\Artifacts\TableManifest;
use Illuminate\Database\Connection;

/**
 * Writes one artifact table's rows into a target connection. The rows arrive
 * already decoded by the reader, so a loader only has to move them; the
 * format it names is the one it can read.
 */
interface Loader
{
    /** The `TableManifest::$format` this loader handles. */
    public function format(): string;

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  decoded rows, in artifact order
     * @return int the number of rows written
     */
    public function load(TableManifest $table, iterable $rows, Connection $target): int;
}
