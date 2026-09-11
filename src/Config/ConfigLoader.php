<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Config;

use RuntimeException;

/**
 * Reads the reviewed config files back off disk, one PHP file per connection.
 */
final class ConfigLoader
{
    public function load(string $connection, string $directory): ?ConnectionConfig
    {
        $path = $this->path($directory, $connection);

        if (! is_file($path)) {
            return null;
        }

        $raw = require $path;

        if (! is_array($raw)) {
            throw new RuntimeException("Config file [$path] must return an array.");
        }

        return ConnectionConfig::fromArray($connection, $raw);
    }

    public function loadAll(string $directory): ConfigSet
    {
        $paths = glob($this->path($directory, '*'));

        if ($paths === false) {
            return new ConfigSet([]);
        }

        sort($paths);

        $connections = [];

        foreach ($paths as $path) {
            $connection = basename($path, '.php');
            $config = $this->load($connection, $directory);

            if ($config !== null) {
                $connections[$connection] = $config;
            }
        }

        return new ConfigSet($connections);
    }

    private function path(string $directory, string $connection): string
    {
        return rtrim($directory, '/').'/'.$connection.'.php';
    }
}
