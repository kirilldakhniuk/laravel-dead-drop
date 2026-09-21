<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes one dump artifact under `{basePath}/{id}/` on a disk: the
 * per-table gzipped NDJSON files and, once every table is written, the
 * manifest that names them. An executor that writes another format puts its
 * own files through `put()` instead of `table()`.
 */
final class ArtifactWriter
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $basePath,
        private readonly string $id,
    ) {}

    public function path(string $file = ''): string
    {
        $root = "{$this->basePath}/{$this->id}";

        return $file === '' ? $root : "{$root}/{$file}";
    }

    public function writeManifest(Manifest $manifest): void
    {
        $this->put(
            'manifest.json',
            json_encode($manifest->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Writes one file into this artifact's directory, for an executor that
     * produces its own format rather than the bundled gzipped NDJSON.
     *
     * @param  resource|string  $stream
     *
     * @throws RuntimeException when the disk refuses the write
     */
    public function put(string $file, mixed $stream): void
    {
        $path = $this->path($file);

        if ($this->disk->put($path, $stream) === false) {
            throw new RuntimeException("Unable to write [{$path}] to the artifact disk.");
        }
    }

    /**
     * @param  array<string, ColumnType>  $types
     */
    public function table(string $file, array $types): TableFileWriter
    {
        $tempPath = sys_get_temp_dir().'/dead-drop-'.Str::random(12);
        $handle = gzopen($tempPath, 'wb6');

        if ($handle === false) {
            throw new RuntimeException("Unable to open a temporary gzip stream at [{$tempPath}].");
        }

        // A whole table's rows stage here before they are uploaded, and the
        // system temp directory is shared with every other user on the host.
        chmod($tempPath, 0600);

        return new TableFileWriter($this->disk, $this->path($file), $tempPath, $handle, $types);
    }
}
