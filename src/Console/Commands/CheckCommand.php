<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\DriftDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Schema\Introspector;
use Illuminate\Console\Command;

/**
 * Compares each connection's live schema against its reviewed config and
 * fails when they disagree, or when a sensitive column has no redaction
 * decision. Safe to run in CI: fully non-interactive, no writes.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'dead-drop:check {--connection=* : Connections to check (defaults to every config file in the directory)} {--path= : Config directory}';

    /** @var string */
    protected $description = "Detect drift between a connection's schema and its reviewed DeadDrop config";

    public function handle(Introspector $introspector, SensitiveColumnDetector $sensitive, ConfigLoader $loader): int
    {
        $directory = $this->directory();
        $connections = $this->connections($loader, $directory);

        if ($connections === []) {
            // Nothing to compare is not the same as nothing to report: a CI
            // job that never ran init would otherwise pass silently.
            $this->error("No DeadDrop config found in [{$directory}] — run dead-drop:init");

            return self::FAILURE;
        }

        $knownConnections = array_keys((array) config('database.connections'));

        foreach ($connections as $connection) {
            if (! in_array($connection, $knownConnections, true)) {
                $this->error("Unknown database connection [{$connection}].");

                return self::FAILURE;
            }
        }

        $detector = new DriftDetector($sensitive);
        $drifted = false;

        foreach ($connections as $connection) {
            $this->line("<info>{$connection}</info>");

            $config = $loader->load($connection, $directory);

            if ($config === null) {
                $this->error("No config for connection [{$connection}] — run dead-drop:init --connection={$connection}");
                $drifted = true;

                continue;
            }

            $schema = $introspector->inspect($connection);
            $report = $detector->detect($schema, $config);

            if (! $report->hasDrift()) {
                $this->line('  clean');

                continue;
            }

            $drifted = true;

            foreach ($report->toLines() as $line) {
                $this->line($line);
            }
        }

        return $drifted ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function connections(ConfigLoader $loader, string $directory): array
    {
        $option = $this->option('connection');
        $connections = is_array($option) ? array_values(array_map('strval', $option)) : [];

        return $connections !== [] ? $connections : array_values($loader->loadAll($directory)->connections());
    }

    private function directory(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return config_path((string) config('dead-drop.config_path'));
    }
}
