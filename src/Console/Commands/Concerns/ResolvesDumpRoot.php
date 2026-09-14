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
 * Turns `dead-drop:dump`'s connection, table and id arguments — or its
 * `--all` flag — into a `Root`, asking for whatever the operator left out.
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
     * The label the whole-database choice is offered under.
     */
    private const string WHOLE_DATABASE = 'Whole database (every data and lookup table)';

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
        if ($this->option('all') === true) {
            return $this->fullRoot($config);
        }

        $connection = $this->rootConnection($config);

        if ($connection === null) {
            return null;
        }

        $table = $this->rootTable($config->for($connection));

        if ($table === null) {
            return null;
        }

        // The whole-database choice is offered where a root table would be,
        // and answers the question by saying there is no root row.
        if ($table === Root::ALL) {
            return $this->wholeDatabase($config, $connection);
        }

        $ids = $this->rootIds($connection, $table);

        return $ids === [] ? null : new Root($connection, $table, $ids);
    }

    /**
     * The root of a `--all` run. Every row of every dumpable table is taken,
     * so a table or an id would have nothing left to narrow: naming one is a
     * mistake rather than a refinement.
     */
    private function fullRoot(ConfigSet $config): ?Root
    {
        $table = $this->argument('table');
        $ids = $this->argument('ids');

        if ((is_string($table) && $table !== '') || (is_array($ids) && $ids !== [])) {
            $this->error('--all cannot be combined with a table or ids.');

            return null;
        }

        $connection = $this->rootConnection($config, everyConnection: true);

        return $connection === null ? null : $this->wholeDatabase($config, $connection);
    }

    /**
     * A whole-database root, or the reason there cannot be one. Every table is
     * taken whole, so a `window` narrows nothing: honouring `--since` would
     * shrink the windowed tables while their children came along whole, and a
     * dump that leaves rows pointing at nothing is the one thing this package
     * will not write. Time-boxing a whole database needs its own traversal.
     */
    private function wholeDatabase(ConfigSet $config, string $connection): ?Root
    {
        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            $this->error('--all cannot be combined with --since yet; a whole-database dump takes every table whole.');

            return null;
        }

        $root = Root::full($connection);

        $this->warnEmptyConnections($config, $root);

        return $root;
    }

    /**
     * A `--all` run is never asked which tables it covers, so a connection
     * whose every table is skipped is a fact about that connection rather
     * than a missing argument: it is named and left out.
     */
    private function warnEmptyConnections(ConfigSet $config, Root $root): void
    {
        $scope = $root->scope();

        foreach ($config->connections as $connection => $connectionConfig) {
            if (($scope === null || $scope === $connection) && $this->dumpableTables($connectionConfig) === []) {
                $this->warn("No dumpable tables on connection [{$connection}]; skipped.");
            }
        }
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
     *
     * @param  bool  $everyConnection  whether covering all of them is an answer (`--all`) rather than a missing argument
     */
    private function rootConnection(ConfigSet $config, bool $everyConnection = false): ?string
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
            // `--all` means all: with nothing to ask and no connection to
            // prefer, it covers every configured one rather than failing.
            if ($everyConnection) {
                return Root::ALL;
            }

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

        // The widest answer is offered first: an operator who wants everything
        // should not have to know there is a flag for it.
        $options = [Root::ALL => self::WHOLE_DATABASE];

        foreach ($tables as $table) {
            $options[$table] = $table;
        }

        if (count($tables) <= self::TABLE_CHOICE_LIMIT) {
            return (string) select(label: $label, options: $options);
        }

        // A real schema is hundreds of tables long, and scrolling one is
        // slower than typing three letters of the table's name.
        return (string) search(
            label: $label,
            options: fn (string $value): array => $this->matching($options, $value),
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
     * The offered tables whose name contains what has been typed so far. The
     * whole-database choice is not a table name and always stays offered.
     *
     * A search that matches nothing falls back to the whole list, because the
     * fallback prompt a non-TTY run gets is a Symfony choice question, and one
     * with no choices left is an exception rather than an empty list.
     *
     * @param  array<string, string>  $options  label keyed by table
     * @return array<string, string>
     */
    private function matching(array $options, string $value): array
    {
        if ($value === '') {
            return $options;
        }

        // A table named `2024` is an integer key by the time it gets here.
        $matching = array_filter(
            $options,
            fn (int|string $key): bool => $key === Root::ALL || str_contains(strtolower((string) $key), strtolower($value)),
            ARRAY_FILTER_USE_KEY,
        );

        return $matching === [] ? $options : $matching;
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
