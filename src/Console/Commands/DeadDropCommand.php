<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use Illuminate\Console\Command;

class DeadDropCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'dead-drop:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package dead-drop.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('DeadDrop placeholder command executed.');

        return self::SUCCESS;
    }
}
