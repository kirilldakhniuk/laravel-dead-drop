<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Streams one table's rows to a local gzip file and, once every row has
 * been appended, moves the finished file onto the disk in a single upload.
 * Created only by `ArtifactWriter::table()`.
 */
final class TableFileWriter
{
    private int $rows = 0;

    private readonly RowCodec $codec;

    /**
     * @param  resource  $handle  an open `gzopen` write handle for `$tempPath`
     * @param  array<string, ColumnType>  $types
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $path,
        private readonly string $tempPath,
        private readonly mixed $handle,
        private readonly array $types,
    ) {
        $this->codec = new RowCodec;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function append(array $row): void
    {
        $written = gzwrite($this->handle, $this->codec->encode($row, $this->types)."\n");

        if ($written === false) {
            throw new RuntimeException("Unable to write to the temporary gzip stream at [{$this->tempPath}].");
        }

        $this->rows++;
    }

    /**
     * @return array{rows: int, bytes: int}
     */
    public function finish(): array
    {
        try {
            if (gzclose($this->handle) === false) {
                throw new RuntimeException("Unable to close the gzip stream for [{$this->tempPath}].");
            }

            $bytes = filesize($this->tempPath);

            if ($bytes === false) {
                throw new RuntimeException("Unable to read the size of [{$this->tempPath}].");
            }

            $stream = fopen($this->tempPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Unable to open [{$this->tempPath}] for upload.");
            }

            try {
                $this->disk->put($this->path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return ['rows' => $this->rows, 'bytes' => $bytes];
        } finally {
            if (is_file($this->tempPath)) {
                unlink($this->tempPath);
            }
        }
    }
}
