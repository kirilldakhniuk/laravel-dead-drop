<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

/**
 * What an executor wrote for one table: the file it left on the disk and
 * what is in it, which is everything the manifest entry needs.
 */
final readonly class TableArtifact
{
    public function __construct(
        public string $connection,
        public string $table,
        public string $file,
        public string $format,
        public int $rows,
        public int $bytes,
    ) {}
}
