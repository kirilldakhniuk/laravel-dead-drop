<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Console\Commands;

use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\DriftDetector;
use DeadDrop\DeadDrop\Config\TableClass;
use DeadDrop\DeadDrop\Inference\SensitiveColumnDetector;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Introspector;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'dead-drop:check {--connection=* : Connections to check (defaults to every config file in the directory)} {--path= : Config directory}';

    /** @var string */
    protected $description = "Detect drift between a connection's schema and its reviewed DeadDrop config";

    public function handle(Introspector $introspector, SensitiveColumnDetector $sensitive, ConfigLoader $loader, RedactionRules $rules): int
    {
        $directory = $this->configDirectory();
        $connections = $this->connections($loader, $directory);

        if ($connections === []) {
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
            $invalid = $this->invalidRedactions($rules, $config, $schema);

            if (! $report->hasDrift() && $invalid === []) {
                $this->line('  clean');

                continue;
            }

            $drifted = true;

            foreach ($report->toLines() as $line) {
                $this->line($line);
            }

            if ($invalid !== []) {
                $this->line('Invalid redaction entries:');

                foreach ($invalid as $line) {
                    $this->line("  - {$line}");
                }
            }
        }

        return $drifted ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function invalidRedactions(RedactionRules $rules, ConnectionConfig $config, DatabaseSchema $schema): array
    {
        $lines = [];

        foreach ($config->tables as $tableConfig) {
            if ($tableConfig->removed || $tableConfig->class === TableClass::Skip) {
                continue;
            }

            $table = $schema->table($tableConfig->name);

            if ($table === null) {
                continue; // already reported as a removed table
            }

            array_push($lines, ...$rules->violations($tableConfig, $table));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function connections(ConfigLoader $loader, string $directory): array
    {
        $connections = array_values(array_map('strval', Arr::wrap($this->option('connection'))));

        return $connections !== [] ? $connections : $loader->loadAll($directory)->connections();
    }

    private function configDirectory(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return config_path((string) config('dead-drop.config_path'));
    }
}
