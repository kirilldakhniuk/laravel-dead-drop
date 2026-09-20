<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Jobs;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\ArtifactWriter;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Extraction\DumpRunner;
use DeadDrop\DeadDrop\Planning\Root;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DumpDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $artifactId,
        public readonly string $configDirectory,
        public readonly string $disk,
        public readonly string $path,
        public readonly int $timeout = 3600,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(hash('sha256', "{$this->disk}:{$this->path}:{$this->artifactId}")))
            ->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(DumpRunner $runner, ConfigLoader $loader): void
    {
        $reader = new ArtifactReader(Storage::disk($this->disk), $this->path);
        $pending = $reader->manifest($this->artifactId);

        if ($pending->isComplete()) {
            return;
        }

        try {
            (new ArtifactWriter(Storage::disk($this->disk), $this->path, $this->artifactId))
                ->writeManifest($pending->withStatus(Manifest::STATUS_WRITING));

            $result = $runner->run(Root::parse($pending->root), $loader->loadAll($this->configDirectory), $this->disk, $this->path, pending: $pending);

            if ($result->violations !== []) {
                throw new RuntimeException('Dump refused: '.implode('; ', $result->violations));
            }
        } catch (Throwable $e) {
            $this->failed($e);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $disk = Storage::disk($this->disk);
        $manifest = (new ArtifactReader($disk, $this->path))->manifest($this->artifactId);

        if (! $manifest->isComplete()) {
            (new ArtifactWriter($disk, $this->path, $this->artifactId))->writeManifest($manifest->withStatus(Manifest::STATUS_FAILED));
        }
    }
}
