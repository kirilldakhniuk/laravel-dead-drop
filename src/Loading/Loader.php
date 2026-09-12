<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Loading;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\TableManifest;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Database\Connection;

/**
 * Writes one artifact table's rows into a target connection. A loader is
 * handed the reader rather than rows, because the format it names is the one
 * it reads: only the bundled NDJSON loader can use `ArtifactReader::rows()`,
 * and another format reads its own file off the same disk.
 */
interface Loader
{
    /** The `TableManifest::$format` this loader handles. */
    public function format(): string;

    /**
     * @param  Table  $schema  the target's table as introspected, for the shape the insert has to fit
     * @return int the number of rows written
     */
    public function load(TableManifest $table, ArtifactReader $reader, string $artifactId, Connection $target, Table $schema): int;
}
