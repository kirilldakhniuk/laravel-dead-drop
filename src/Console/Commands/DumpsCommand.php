<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Console\Commands\Concerns\FormatsBytes;
use DeadDrop\DeadDrop\Console\Commands\Concerns\ResolvesArtifactLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lists the dump artifacts on a disk, newest first, so an operator can see
 * what is there and which of them a crash left half written.
 */
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
                // One unreadable manifest is a fact about that artifact, not
                // a reason to stop listing the rest.
                $rows[] = [$id, '', '', 'unreadable', '', '', ''];

                continue;
            }

            $rows[] = [
                $id,
                $manifest->createdAt,
                $manifest->root,
                $manifest->status,
                (string) count($manifest->tables),
                (string) $manifest->totalRows(),
                $this->size($manifest->totalBytes()),
            ];
        }

        $this->table(['Id', 'Created', 'Root', 'Status', 'Tables', 'Rows', 'Size'], $rows);

        return self::SUCCESS;
    }
}
