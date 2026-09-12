<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConfigMerger;
use DeadDrop\DeadDrop\Config\ConfigRenderer;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\Reference;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Config\TableConfig;
use DeadDrop\DeadDrop\Inference\EdgeInferrer;
use DeadDrop\DeadDrop\Inference\InferredEdge;
use DeadDrop\DeadDrop\Inference\MorphPairDetector;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Inference\TableClassifier;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\ColumnType;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\multiselect;

/**
 * Introspects one or more connections, infers their relationships, classifies
 * and scans their tables, and writes (or updates) a reviewed `<connection>.php`
 * config file per connection.
 *
 * This command only composes the schema, inference and config layers: it
 * decides nothing about redaction, classification or edges itself, and it
 * never overwrites a human's prior decisions — an existing file is merged
 * with the freshly discovered one before being re-rendered.
 */
final class InitCommand extends Command
{
    /** @var string */
    protected $signature = 'dead-drop:init {--connection=* : Connections to enroll} {--skip=* : Tables to force to skip} {--path= : Directory for the per-connection config files (defaults to config_path(config(\'dead-drop.config_path\')))}';

    /** @var string */
    protected $description = "Discover a connection's schema and scaffold or update its DeadDrop config";

    /**
     * Introspection is the expensive part of this command, and the prompts
     * need it before the work does, so every connection is read at most once
     * per run.
     *
     * @var array<string, DatabaseSchema>
     */
    private array $schemas = [];

    public function handle(
        Introspector $introspector,
        EdgeInferrer $edgeInferrer,
        TableClassifier $classifier,
        SensitiveColumnDetector $sensitive,
        MorphPairDetector $morphs,
        ConfigLoader $loader,
        ConfigMerger $merger,
        ConfigRenderer $renderer,
        EloquentSource $eloquentSource,
        RedactionRules $rules,
    ): int {
        $connectionOption = $this->option('connection');
        $connections = is_array($connectionOption) ? array_values(array_map('strval', $connectionOption)) : [];

        $skipOption = $this->option('skip');
        $skip = is_array($skipOption) ? array_values(array_map('strval', $skipOption)) : [];

        if ($connections === []) {
            if (! $this->input->isInteractive()) {
                $this->error('Pass --connection=<name> (repeatable) when running without interaction.');

                return self::FAILURE;
            }

            $connections = $this->promptForConnections($introspector);

            if ($connections === []) {
                $this->error('No database connection could be read — check your database configuration.');

                return self::FAILURE;
            }

            $skip = [...$skip, ...$this->promptForSkippedTables($introspector, $connections)];
        }

        $knownConnections = array_keys((array) config('database.connections'));

        foreach ($connections as $connection) {
            if (! in_array($connection, $knownConnections, true)) {
                $this->error("Unknown database connection [{$connection}].");

                return self::FAILURE;
            }
        }

        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create directory [{$directory}].");

            return self::FAILURE;
        }

        foreach ($connections as $connection) {
            $schema = $this->describe($introspector, $connection);
            $edges = $edgeInferrer->infer($schema);

            $discovered = $this->discover($schema, $edges, $classifier, $sensitive, $morphs, $rules, $skip);

            $file = "{$directory}/{$connection}.php";
            $existing = $loader->load($connection, $directory);
            $final = $existing === null ? $discovered : $merger->merge($existing, $discovered);

            if (@file_put_contents($file, $renderer->render($final)) === false) {
                $this->error("Could not write [{$file}].");

                return self::FAILURE;
            }

            $this->report($file, $final, $eloquentSource);
        }

        return self::SUCCESS;
    }

    /**
     * A stock Laravel app ships connections nothing has ever been configured
     * for — an unsupported driver, a host that is not up. Offering them would
     * mean introspecting them, so each one is tried, and the ones that cannot
     * answer are named with their reason and left out of the choices instead
     * of taking the command down with them.
     *
     * @return list<string>
     */
    private function promptForConnections(Introspector $introspector): array
    {
        $names = array_keys((array) config('database.connections'));

        $options = [];

        foreach ($names as $name) {
            $name = (string) $name;

            try {
                $tableCount = count($this->describe($introspector, $name)->tables);
            } catch (Throwable $e) {
                $this->line("<comment>{$name} (unavailable: {$this->reason($e)})</comment>");

                continue;
            }

            $options[$name] = "{$name} ({$tableCount} tables)";
        }

        if ($options === []) {
            return [];
        }

        /** @var list<string> $selected */
        $selected = multiselect(
            label: 'Which connections should DeadDrop enroll?',
            options: $options,
            required: true,
        );

        return $selected;
    }

    /**
     * @param  list<string>  $connections
     * @return list<string>
     */
    private function promptForSkippedTables(Introspector $introspector, array $connections): array
    {
        /** @var list<array{string, string, int}> $candidates */
        $candidates = [];

        foreach ($connections as $connection) {
            foreach ($this->describe($introspector, $connection)->tables as $table) {
                $candidates[] = [$connection, $table->name, $table->estimatedRows];
            }
        }

        usort($candidates, fn (array $a, array $b): int => $b[2] <=> $a[2]);

        $options = [];

        foreach (array_slice($candidates, 0, 10) as [$connection, $table, $rows]) {
            $options["{$connection}.{$table}"] = "{$connection}.{$table} (~{$rows} rows)";
        }

        /** @var list<string> $selected */
        $selected = multiselect(
            label: 'Any large tables to skip?',
            options: $options,
        );

        return $selected;
    }

    /**
     * @throws Throwable when the connection cannot be introspected
     */
    private function describe(Introspector $introspector, string $connection): DatabaseSchema
    {
        return $this->schemas[$connection] ??= $introspector->inspect($connection);
    }

    /**
     * The first line of why a connection could not be read, short enough to
     * sit inside a prompt option.
     */
    private function reason(Throwable $e): string
    {
        return Str::limit(trim(explode("\n", $e->getMessage())[0]), 80);
    }

    private function directory(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return config_path((string) config('dead-drop.config_path'));
    }

    /**
     * @param  array<string, InferredEdge>  $edges
     * @param  list<string>  $skip
     */
    private function discover(
        DatabaseSchema $schema,
        array $edges,
        TableClassifier $classifier,
        SensitiveColumnDetector $sensitive,
        MorphPairDetector $morphs,
        RedactionRules $rules,
        array $skip,
    ): ConnectionConfig {
        $tables = [];

        foreach ($schema->tables as $table) {
            $tables[$table->name] = $this->discoverTable($schema->connection, $table, $edges, $classifier, $sensitive, $morphs, $rules, $skip);
        }

        return new ConnectionConfig($schema->connection, $tables);
    }

    /**
     * @param  array<string, InferredEdge>  $edges
     * @param  list<string>  $skip
     */
    private function discoverTable(
        string $connection,
        Table $table,
        array $edges,
        TableClassifier $classifier,
        SensitiveColumnDetector $sensitive,
        MorphPairDetector $morphs,
        RedactionRules $rules,
        array $skip,
    ): TableConfig {
        $class = $classifier->classify($table, $edges);

        if (in_array($table->name, $skip, true) || in_array("{$connection}.{$table->name}", $skip, true)) {
            $class = TableClass::Skip;
        }

        if ($class === TableClass::Skip) {
            return new TableConfig(
                name: $table->name,
                class: TableClass::Skip,
                columns: array_values($table->columnNames()),
                references: [],
                redact: [],
                window: null,
                exclude: null,
                morph: null,
            );
        }

        $references = [];

        foreach ($edges as $edge) {
            if ($edge->table === $table->name) {
                $references[$edge->column] = new Reference(null, $edge->targetTable, $edge->targetColumn, $edge->descend, $edge->source);
            }
        }

        $redact = $sensitive->detect($table);

        foreach ($sensitive->needsReview($table) as $column) {
            $columnMeta = $table->column($column);

            if ($columnMeta !== null && $columnMeta->type === ColumnType::Json) {
                $redact[$column] ??= 'review';
            }
        }

        $config = new TableConfig(
            name: $table->name,
            class: $class,
            columns: array_values($table->columnNames()),
            references: $references,
            redact: $redact,
            window: $table->column('created_at') !== null ? 'created_at' : null,
            exclude: null,
            morph: $morphs->detect($table),
        );

        return $this->reviewable($config, $table, $rules);
    }

    /**
     * A suggestion is a guess from a column's name, and the gate judges it
     * against the column's type, nullability and indexes — `null` on a NOT
     * NULL `*_key`, `hash` on a non-string `ssn`. Rather than write a map
     * `dead-drop:check` and `dead-drop:dump` will both refuse, every rejected
     * suggestion is handed back to the human as `review`.
     */
    private function reviewable(TableConfig $config, Table $table, RedactionRules $rules): TableConfig
    {
        $redact = $config->redact;

        foreach (array_keys($rules->violationsByColumn($config, $table)) as $column) {
            $redact[$column] = 'review';
        }

        if ($redact === $config->redact) {
            return $config;
        }

        return new TableConfig(
            name: $config->name,
            class: $config->class,
            columns: $config->columns,
            references: $config->references,
            redact: $redact,
            window: $config->window,
            exclude: $config->exclude,
            morph: $config->morph,
            removed: $config->removed,
        );
    }

    private function report(string $file, ConnectionConfig $config, EloquentSource $eloquentSource): void
    {
        $this->info("Wrote {$file}");

        $data = 0;
        $lookup = 0;
        $skip = 0;

        foreach ($config->tables as $table) {
            match ($table->class) {
                TableClass::Data => $data++,
                TableClass::Lookup => $lookup++,
                TableClass::Skip => $skip++,
            };
        }

        $this->line("data: {$data}, lookup: {$lookup}, skip: {$skip}");

        $skipped = $eloquentSource->skipped();

        if ($skipped !== []) {
            // There is one of these for every accessor and helper on every
            // model, so the count is the signal and the list is opt-in.
            $this->line(count($skipped).' model methods were skipped because they lack a relation return type (run with -v to list them)');

            if ($this->output->isVerbose()) {
                foreach ($skipped as $method) {
                    $this->line("  - {$method}");
                }
            }
        }

        if ($eloquentSource->failed() !== []) {
            $this->line('Model methods that threw during inference:');

            foreach ($eloquentSource->failed() as $method) {
                $this->line("  - {$method}");
            }
        }
    }
}
