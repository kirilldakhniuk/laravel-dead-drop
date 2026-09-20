<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Console\Commands\Concerns\FormatsBytes;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use DeadDrop\DeadDrop\Planning\Root;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class DumpsCommand extends Command
{
    use FormatsBytes;
    use ResolvesArtifactLocation;

    /** @var string */
    protected $signature = 'dead-drop:dumps {--disk= : Disk holding artifacts (defaults to dead-drop.disk)} {--path= : Path on the disk (defaults to dead-drop.path)}';

    /** @var string */
    protected $description = 'List the dump artifacts on a disk';

    public function handle(): int
    {
        $disk = $this->artifactDisk();
        $path = $this->artifactPath();
        $reader = new ArtifactReader(Storage::disk($disk), $path);
        $ids = $reader->ids();

        if ($ids === []) {
            $this->info("No artifacts on {$disk}:{$path}.");

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($ids as $id) {
            try {
                $manifest = $reader->manifest($id);
            } catch (Throwable) {
                $rows[] = [$id, '', '', 'unreadable', '', '', ''];

                continue;
            }

            $rows[] = [
                $id,
                $manifest->createdAt,
                $this->root($manifest->root),
                $manifest->status,
                (string) count($manifest->tables),
                (string) $manifest->totalRows(),
                $this->size($manifest->totalBytes()),
            ];
        }

        $this->table(['Id', 'Created', 'Source', 'Status', 'Tables', 'Rows', 'Size'], $rows);

        return self::SUCCESS;
    }

    private function root(string $spec): string
    {
        try {
            return Root::parse($spec)->describe();
        } catch (InvalidArgumentException) {
            return $spec;
        }
    }
}
