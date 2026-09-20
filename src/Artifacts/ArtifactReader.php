<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use DeadDrop\DeadDrop\Schema\ColumnType;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ArtifactReader
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $basePath,
    ) {}

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->disk->directories($this->basePath) as $directory) {
            if ($this->disk->exists("{$directory}/manifest.json")) {
                $ids[] = basename($directory);
            }
        }

        rsort($ids);

        return $ids;
    }

    public function manifest(string $id): Manifest
    {
        $path = "{$this->basePath}/{$id}/manifest.json";
        $contents = $this->disk->exists($path) ? $this->disk->get($path) : null;

        if ($contents === null) {
            throw new InvalidArgumentException("No artifact [{$id}] on this disk.");
        }

        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("Artifact [{$id}] has a corrupt manifest.");
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("No artifact [{$id}] on this disk.");
        }

        return Manifest::fromArray($decoded);
    }

    public function latestComplete(): ?Manifest
    {
        foreach ($this->ids() as $id) {
            $manifest = $this->manifest($id);

            if ($manifest->isComplete()) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $id, TableManifest $table): Generator
    {
        $path = "{$this->basePath}/{$id}/{$table->file}";
        $stream = $this->disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Missing artifact file [{$path}].");
        }

        $tempPath = sys_get_temp_dir().'/dead-drop-'.Str::random(12);

        try {
            $handle = fopen($tempPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException("Unable to open a temporary file at [{$tempPath}].");
            }

            $copied = stream_copy_to_stream($stream, $handle);
            fclose($handle);
            fclose($stream);

            if ($copied === false) {
                throw new RuntimeException("Could not read artifact file [{$path}].");
            }

            if ($table->bytes > 0 && $copied !== $table->bytes) {
                throw new RuntimeException("Artifact file [{$path}] is {$copied} bytes but the manifest says {$table->bytes}.");
            }

            $gz = gzopen($tempPath, 'rb');

            if ($gz === false) {
                throw new RuntimeException("Unable to open [{$tempPath}] as a gzip stream.");
            }

            $codec = new RowCodec;
            $types = $this->columnTypes($table);

            try {
                while (($line = gzgets($gz)) !== false) {
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    yield $codec->decode($line, $types);
                }
            } finally {
                gzclose($gz);
            }
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<string, ColumnType>
     */
    private function columnTypes(TableManifest $table): array
    {
        $types = [];

        foreach ($table->columns as $column) {
            $types[$column['name']] = ColumnType::from($column['type']);
        }

        return $types;
    }
}
