<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands\Concerns;

use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Planning\Root;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Turns `dead-drop:dump`'s connection, table and id arguments into a `Root`,
 * asking for whatever the operator left out.
 *
 * Two rules hold everywhere here: anything given on the command line is never
 * asked for, and nothing at all is asked for when the command is not
 * interactive — there, a missing piece fails with the argument to pass
 * instead, so the command stays fully drivable from a script.
 */
trait ResolvesDumpRoot
{
    /**
     * Above this many tables a list is worse than a search box.
     */
    private const int TABLE_CHOICE_LIMIT = 15;

    /**
     * Whether any part of the root had to be asked for. A run that named its
     * root in full stays that way: it is never asked what to do with it.
     */
    private bool $askedForRoot = false;

    /**
     * The root to plan from, or `null` once the reason there is none has been
     * printed.
     */
    private function resolveRoot(ConfigSet $config): ?Root
    {
        $connection = $this->rootConnection($config);

        if ($connection === null) {
            return null;
        }

        $table = $this->rootTable($config->for($connection));

        if ($table === null) {
            return null;
        }

        $ids = $this->rootIds($connection, $table);

        return $ids === [] ? null : new Root($connection, $table, $ids);
    }

    /**
     * Plan only, or extract. `--dry-run` settles it outright; otherwise only
     * an operator who was prompted through the root is asked, because for
     * them the two are one keystroke apart and only one writes data.
     */
    private function planOnly(string $disk, string $path): bool
    {
        if ($this->option('dry-run') === true) {
            return true;
        }

        // Only an operator who was asked their way here can be asked again,
        // and only a terminal can answer.
        if (! $this->input->isInteractive() || ! $this->askedForRoot) {
            return false;
        }

        return select(
            label: 'What now?',
            options: ['plan' => 'Plan only (dry run)', 'dump' => "Dump to {$disk}:{$path}"],
            default: 'plan',
        ) === 'plan';
    }

    /**
     * The connection the root row lives on. Every configured connection is
     * loaded either way — a cross-connection reference needs them — so this
     * only names where the traversal starts.
     */
    private function rootConnection(ConfigSet $config): ?string
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
            $this->error('Pass --connection=<name>; configured connections: '.implode(', ', $configured).'.');

            return null;
        }

        $this->askedForRoot = true;

        return (string) select(label: 'Which connection holds the root row?', options: $configured);
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

    private function rootTable(ConnectionConfig $config): ?string
    {
        $tables = $this->dumpableTables($config);

        if ($tables === []) {
            // Every table skipped is a reviewed decision, not a dump waiting
            // for the right argument, so there is nothing to offer or accept.
            $this->error("No table on connection [{$config->connection}] is configured for dumping.");

            return null;
        }

        $named = $this->argument('table');

        if (is_string($named) && $named !== '') {
            if (! in_array($named, $tables, true)) {
                $this->error("Table [{$named}] is not configured for dumping on connection [{$config->connection}].");

                return null;
            }

            return $named;
        }

        if (! $this->input->isInteractive()) {
            $this->error("Pass a table argument, e.g. dead-drop:dump users 1 --connection={$config->connection}.");

            return null;
        }

        $this->askedForRoot = true;
        $label = 'Which table holds the root row?';

        if (count($tables) <= self::TABLE_CHOICE_LIMIT) {
            return (string) select(label: $label, options: $tables);
        }

        // A real schema is hundreds of tables long, and scrolling one is
        // slower than typing three letters of the table's name.
        return (string) search(
            label: $label,
            options: fn (string $value): array => $this->matching($tables, $value),
        );
    }

    /**
     * The tables a dump can start from, most-pointed-at first: the row an
     * operator wants a slice of is usually the one the rest of the schema
     * hangs off.
     *
     * @return list<string>
     */
    private function dumpableTables(ConnectionConfig $config): array
    {
        $inbound = [];

        foreach ($config->tables as $table) {
            if (! $table->removed && $table->class !== TableClass::Skip) {
                $inbound[$table->name] = 0;
            }
        }

        foreach ($config->tables as $table) {
            foreach ($table->references as $reference) {
                $connection = $reference->connection ?? $config->connection;

                if ($connection === $config->connection && isset($inbound[$reference->table])) {
                    $inbound[$reference->table]++;
                }
            }
        }

        $tables = array_keys($inbound);

        usort($tables, fn (string $a, string $b): int => [$inbound[$b], $a] <=> [$inbound[$a], $b]);

        return $tables;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function matching(array $tables, string $value): array
    {
        $matches = $value === ''
            ? $tables
            : array_filter($tables, fn (string $table): bool => str_contains(strtolower($table), strtolower($value)));

        return array_combine($matches, $matches);
    }

    /**
     * @return list<int|string>
     */
    private function rootIds(string $connection, string $table): array
    {
        $arguments = $this->argument('ids');
        $ids = $this->splitIds(is_array($arguments) ? $arguments : []);

        if ($ids !== []) {
            return $ids;
        }

        if (! $this->input->isInteractive()) {
            $this->error("Pass one or more ids, e.g. dead-drop:dump {$table} 1 --connection={$connection}.");

            return [];
        }

        $this->askedForRoot = true;

        return $this->splitIds([text(
            label: 'Which id(s)? Separate several with commas',
            required: true,
            validate: fn (string $value): ?string => $this->splitIds([$value]) === [] ? 'Enter at least one id.' : null,
        )]);
    }

    /**
     * Ids as they were typed: one per argument, and several per argument when
     * they are comma separated, so `dead-drop:dump users 1 2` and
     * `dead-drop:dump users 1,2` mean the same thing. A key that looks like an
     * integer becomes one, so it matches the column's own type.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return list<int|string>
     */
    private function splitIds(array $arguments): array
    {
        $ids = [];

        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                continue;
            }

            foreach (explode(',', $argument) as $id) {
                $id = trim($id);

                if ($id !== '') {
                    $ids[] = ((string) (int) $id) === $id ? (int) $id : $id;
                }
            }
        }

        return $ids;
    }
}
