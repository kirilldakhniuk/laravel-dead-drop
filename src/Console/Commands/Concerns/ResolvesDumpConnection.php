<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Planning\Root;

use function Laravel\Prompts\select;

trait ResolvesDumpConnection
{
    private bool $plannedByChoice = false;

    private function dumpRoot(ConfigSet $config): ?Root
    {
        $connection = $this->dumpConnection($config);

        if ($connection === null) {
            return null;
        }

        $root = Root::full($connection);

        $this->warnEmptyConnections($config, $root);

        return $root;
    }

    private function dumpConnection(ConfigSet $config): ?string
    {
        $named = $this->option('connection');

        if (is_string($named) && $named !== '') {
            return $this->validateDumpConnection($named, $config) ? $named : null;
        }

        $configured = $config->connections();

        if ($configured === []) {
            $this->error('No connection is configured — run dead-drop:init first.');

            return null;
        }

        if (count($configured) === 1) {
            return $this->reportSelectedConnection($configured[0]);
        }

        $default = (string) config('database.default');

        if ($config->has($default)) {
            return $this->reportSelectedConnection($default);
        }

        if (! $this->canAsk()) {
            return Root::ALL;
        }

        return (string) select(label: 'Which connection should be dumped?', options: $configured);
    }

    private function validateDumpConnection(string $connection, ConfigSet $config): bool
    {
        if (! in_array($connection, array_keys((array) config('database.connections')), true)) {
            $this->error("Unknown database connection [{$connection}].");

            return false;
        }

        if (! $config->has($connection)) {
            $this->error("No config for connection [{$connection}] — run dead-drop:init --connection={$connection}");

            return false;
        }

        return true;
    }

    private function reportSelectedConnection(string $connection): string
    {
        $this->line("Using connection [{$connection}].");

        return $connection;
    }

    private function warnEmptyConnections(ConfigSet $config, Root $root): void
    {
        $scope = $root->scope();

        foreach ($config->connections as $connection => $connectionConfig) {
            if (($scope === null || $scope === $connection) && ! $this->hasDumpableTables($connectionConfig)) {
                $this->warn("No dumpable tables on connection [{$connection}]; skipped.");
            }
        }
    }

    private function hasDumpableTables(ConnectionConfig $config): bool
    {
        foreach ($config->tables as $table) {
            if (! $table->removed && $table->class !== TableClass::Skip) {
                return true;
            }
        }

        return false;
    }

    private function planOnly(string $disk, string $path): bool
    {
        if ($this->option('dry-run') === true) {
            return true;
        }

        if (! $this->canAsk()) {
            return false;
        }

        $this->plannedByChoice = select(
            label: 'What now?',
            options: ['plan' => 'Plan only (dry run)', 'dump' => "Dump to {$disk}:{$path}"],
            default: 'plan',
        ) === 'plan';

        return $this->plannedByChoice;
    }

    private function canAsk(): bool
    {
        return $this->input->isInteractive() && ($this->hasTerminal() || $this->laravel->runningUnitTests());
    }

    private function hasTerminal(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }
}
