<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use Closure;
use DeadDrop\DeadDrop\Config\ConfigSet;
use DeadDrop\DeadDrop\Config\ConnectionConfig;
use DeadDrop\DeadDrop\Config\TableClass;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

final class SourceConnections
{
    /** @var array<string, Connection> */
    private array $snapshots = [];

    private bool $running = false;

    public function get(string $name): Connection
    {
        return $this->snapshots[$name] ?? DB::connection($name);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function snapshot(ConfigSet $config, ?string $scope, Closure $callback): mixed
    {
        if ($this->running) {
            throw new LogicException('A dump snapshot is already active.');
        }

        $this->running = true;

        try {
            foreach ($scope === null ? $config->connections() : [$scope] as $name) {
                $source = DB::connection($name);

                if (! in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
                    continue;
                }

                $settings = $source->getConfig();
                unset($settings['read'], $settings['write']);
                $db = app(ConnectionFactory::class)->make($settings, $name);
                $this->snapshots[$name] = $db;
                $db->useWriteConnectionWhenReading();
                $this->assertTransactionalTables($db, $config->for($name));

                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $db->beginTransaction();
                // The first table read establishes the snapshot; all plan and export reads share it.
            }

            $result = $callback();
        } finally {
            $cleanupFailure = $this->release();
        }

        // Raised here rather than from the `finally`, so it can only reach a
        // caller whose dump actually succeeded: when the callback threw, that
        // exception is already on its way out and is the one worth seeing.
        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        return $result;
    }

    /**
     * Rolls back and closes every snapshot connection, whatever the dump did,
     * and reports the first rollback that failed.
     */
    private function release(): ?Throwable
    {
        $cleanupFailure = null;

        foreach ($this->snapshots as $db) {
            try {
                $db->rollBack();
            } catch (Throwable $e) {
                $cleanupFailure ??= $e;
            } finally {
                $db->disconnect();
            }
        }

        $this->snapshots = [];
        $this->running = false;

        return $cleanupFailure;
    }

    private function assertTransactionalTables(Connection $db, ConnectionConfig $config): void
    {
        $tables = $db->select(
            'select TABLE_NAME as name, ENGINE as engine from information_schema.TABLES where TABLE_SCHEMA = ?',
            [$db->getDatabaseName()],
        );

        foreach ($tables as $table) {
            $tableConfig = $config->table($table->name);

            if ($tableConfig === null || $tableConfig->removed || $tableConfig->class === TableClass::Skip) {
                continue;
            }

            if (strtolower((string) $table->engine) !== 'innodb') {
                throw new RuntimeException("Consistent MySQL dumps require InnoDB: [{$config->connection}.{$table->name}] uses ".($table->engine ?? 'a view').'. Skip this table or convert it to InnoDB.');
            }
        }
    }
}
