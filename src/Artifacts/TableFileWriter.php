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

    private bool $finished = false;

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
        if ($this->finished) {
            throw new RuntimeException('This table file was already finished or aborted.');
        }

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
                if ($this->disk->put($this->path, $stream) === false) {
                    throw new RuntimeException("Unable to write [{$this->path}] to the artifact disk.");
                }
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

            $this->finished = true;
        }
    }

    /**
     * Releases the staging gzip handle and deletes the local temp file
     * without uploading anything, for a writer that will never call
     * `finish()` (an aborted dump, or an exception mid-table). Safe to call
     * more than once, and safe to call after `finish()` already ran.
     */
    public function abort(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if (is_resource($this->handle)) {
            gzclose($this->handle);
        }

        if (is_file($this->tempPath)) {
            unlink($this->tempPath);
        }
    }

    public function __destruct()
    {
        $this->abort();
    }
}
