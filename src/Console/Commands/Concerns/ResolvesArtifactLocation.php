<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

/**
 * Where the artifacts are: the `--disk` and `--path` options, each falling
 * back to its `dead-drop` config value. Shared by every command that reads
 * or writes an artifact directory, so one answer cannot drift from another.
 */
trait ResolvesArtifactLocation
{
    private function artifactDisk(): string
    {
        $disk = $this->option('disk');

        return is_string($disk) && $disk !== '' ? $disk : (string) config('dead-drop.disk');
    }

    private function artifactPath(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== '' ? $path : (string) config('dead-drop.path');
    }
}
