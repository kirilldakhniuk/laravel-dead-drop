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
 * asked for, and nothing at all is asked for unless a human is at a terminal —
 * a scripted run plans or dumps exactly what its options say.
 */
trait ResolvesDumpConnection
{
    /**
     * Whether the run stopped at the mode prompt and was told to plan. An
     * explicit `--dry-run` says so itself; an answered prompt is worth
     * reporting back, because the operator could have meant to dump.
     */
    private bool $plannedByChoice = false;

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

        if (! $this->canAsk()) {
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
     * and only one of them writes data. Nobody at a terminal means nobody to
     * ask: the run extracts, rather than silently accepting the prompt's
     * default and writing nothing.
     */
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

    /**
     * Whether a question could actually be answered — the same condition
     * Laravel puts on `Prompt::interactive()`. A cron job, a Docker
     * entrypoint or a CI step that forgot `--no-interaction` is still
     * interactive as far as Symfony is concerned, and a prompt there would
     * quietly return its own default answer, so a run with no terminal in
     * front of it behaves exactly like `--no-interaction` instead. Under
     * tests the framework treats prompts as answerable, and so does this.
     */
    private function canAsk(): bool
    {
        return $this->input->isInteractive() && ($this->hasTerminal() || $this->laravel->runningUnitTests());
    }

    /**
     * Whether standard input is a terminal a question could be answered at.
     * `STDIN` is undefined outside the CLI SAPI, and `stream_isatty()` can be
     * disabled by a hardened build, so both are checked before it is called.
     */
    private function hasTerminal(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }
}
