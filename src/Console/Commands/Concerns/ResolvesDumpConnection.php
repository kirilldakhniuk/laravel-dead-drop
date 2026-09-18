<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Planning\Root;

use function Laravel\Prompts\select;

/**
 * Turns `dead-drop:dump`'s `--connection` option into the scope of a
 * whole-database dump, and asks an operator at a terminal whether the run
 * writes anything.
 *
 * Two rules hold everywhere here: anything given on the command line is never
 * asked for, and nothing at all is asked for when the command is not
 * interactive — a scripted run plans or dumps exactly what its options say.
 */
trait ResolvesDumpConnection
{
    /**
     * The whole-database root to plan from, or `null` once the reason there is
     * none has been printed.
     */
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

    /**
     * The connection to dump, or `Root::ALL` for every configured one. Every
     * configured connection is loaded either way — a cross-connection
     * reference needs them — so this only names what the dump covers.
     */
    private function dumpConnection(ConfigSet $config): ?string
    {
        $named = $this->option('connection');

        if (is_string($named) && $named !== '') {
            return $this->dumpable($named, $config) ? $named : null;
        }

        $configured = $config->connections();

        if ($configured === []) {
            $this->error('No connection is configured — run dead-drop:init first.');

            return null;
        }

        if (count($configured) === 1) {
            return $this->using($configured[0]);
        }

        $default = (string) config('database.default');

        if ($config->has($default)) {
            return $this->using($default);
        }

        if (! $this->input->isInteractive()) {
            // Nothing names a connection, none of them is preferred, and there
            // is nobody to ask: a dump of everything covers every configured
            // connection rather than failing.
            return Root::ALL;
        }

        return (string) select(label: 'Which connection should be dumped?', options: $configured);
    }

    /**
     * Whether a named connection is one this application has and DeadDrop
     * holds a reviewed config for. Both failures name their own fix.
     */
    private function dumpable(string $connection, ConfigSet $config): bool
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

    /**
     * A connection nobody named is still a decision the run made, so it is
     * reported rather than assumed.
     */
    private function using(string $connection): string
    {
        $this->line("Using connection [{$connection}].");

        return $connection;
    }

    /**
     * A dump is never asked which tables it covers, so a connection whose
     * every table is skipped is a fact about that connection rather than a
     * missing argument: it is named and left out.
     */
    private function warnEmptyConnections(ConfigSet $config, Root $root): void
    {
        $scope = $root->scope();

        foreach ($config->connections as $connection => $connectionConfig) {
            if (($scope === null || $scope === $connection) && ! $this->hasDumpableTables($connectionConfig)) {
                $this->warn("No dumpable tables on connection [{$connection}]; skipped.");
            }
        }
    }

    /**
     * Whether the connection holds a table a dump would write at all.
     */
    private function hasDumpableTables(ConnectionConfig $config): bool
    {
        foreach ($config->tables as $table) {
            if (! $table->removed && $table->class !== TableClass::Skip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plan only, or extract. `--dry-run` settles it outright; otherwise an
     * operator at a terminal is asked, because the two are one keystroke apart
     * and only one of them writes data.
     */
    private function planOnly(string $disk, string $path): bool
    {
        if ($this->option('dry-run') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        return select(
            label: 'What now?',
            options: ['plan' => 'Plan only (dry run)', 'dump' => "Dump to {$disk}:{$path}"],
            default: 'plan',
        ) === 'plan';
    }
}
