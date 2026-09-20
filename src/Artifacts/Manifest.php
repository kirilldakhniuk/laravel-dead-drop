<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Artifacts;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The on-disk contract for a dump: every table it wrote (in load order),
 * every reference it could not resolve, and whether it finished. Written
 * as `manifest.json` alongside one gzipped NDJSON file per table.
 */
final readonly class Manifest
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_WRITING = 'writing';

    public const string STATUS_COMPLETE = 'complete';

    public const int VERSION = 1;

    /**
     * @param  array<string, array{driver: string, database: string|null}>  $connections  keyed by connection name, each with the driver and the name of the database it was read from
     * @param  list<TableManifest>  $tables  in plan-step (load) order
     * @param  list<array{connection: string, table: string, column: string, reason: string}>  $unresolved
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $createdAt,
        public string $packageVersion,
        public string $root,
        public ?string $since,
        public string $executor,
        public array $connections,
        public array $tables,
        public array $unresolved,
    ) {}

    public static function newId(DateTimeImmutable $now): string
    {
        return $now->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    /**
     * @return array{
     *     version: int,
     *     id: string,
     *     status: string,
     *     created_at: string,
     *     package_version: string,
     *     root: string,
     *     since: string|null,
     *     executor: string,
     *     connections: array<string, array{driver: string, database: string|null}>,
     *     tables: list<array{connection: string, table: string, file: string, format: string, rows: int, bytes: int, primary_key: string, columns: list<array{name: string, type: string}>, redacted: list<string>}>,
     *     unresolved: list<array{connection: string, table: string, column: string, reason: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'id' => $this->id,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'package_version' => $this->packageVersion,
            'root' => $this->root,
            'since' => $this->since,
            'executor' => $this->executor,
            'connections' => $this->connections,
            'tables' => array_map(fn (TableManifest $table): array => $table->toArray(), $this->tables),
            'unresolved' => $this->unresolved,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $keys = ['version', 'id', 'status', 'created_at', 'package_version', 'root', 'since', 'executor', 'connections', 'tables', 'unresolved'];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }
        }

        if ($raw['version'] !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported or malformed manifest.');
        }

        $id = $raw['id'];
        $status = $raw['status'];
        $createdAt = $raw['created_at'];
        $packageVersion = $raw['package_version'];
        $root = $raw['root'];
        $since = $raw['since'];
        $executor = $raw['executor'];
        $connections = $raw['connections'];
        $tables = $raw['tables'];
        $unresolved = $raw['unresolved'];

        if (
            ! is_string($id)
            || ! is_string($status)
            || ! is_string($createdAt)
            || ! is_string($packageVersion)
            || ! is_string($root)
            || ($since !== null && ! is_string($since))
            || ! is_string($executor)
            || ! is_array($connections)
            || ! is_array($tables)
            || ! is_array($unresolved)
        ) {
            throw new InvalidArgumentException('Unsupported or malformed manifest.');
        }

        return new self(
            id: $id,
            status: $status,
            createdAt: $createdAt,
            packageVersion: $packageVersion,
            root: $root,
            since: $since,
            executor: $executor,
            connections: self::readConnections($connections),
            tables: self::readTables($tables),
            unresolved: self::readUnresolved($unresolved),
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            $this->id,
            $status,
            $this->createdAt,
            $this->packageVersion,
            $this->root,
            $this->since,
            $this->executor,
            $this->connections,
            $this->tables,
            $this->unresolved,
        );
    }

    public function withTable(TableManifest $table): self
    {
        return new self(
            $this->id,
            $this->status,
            $this->createdAt,
            $this->packageVersion,
            $this->root,
            $this->since,
            $this->executor,
            $this->connections,
            [...$this->tables, $table],
            $this->unresolved,
        );
    }

    public function totalRows(): int
    {
        return array_sum(array_map(fn (TableManifest $table): int => $table->rows, $this->tables));
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(fn (TableManifest $table): int => $table->bytes, $this->tables));
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * The connections a dump read from. `database` was added after the format
     * was first written, so a manifest without it is read as a connection
     * whose database name is simply unknown rather than a malformed one.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, array{driver: string, database: string|null}>
     */
    private static function readConnections(array $raw): array
    {
        $connections = [];

        foreach ($raw as $name => $connection) {
            if (! is_array($connection) || ! is_string($connection['driver'] ?? null)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $database = $connection['database'] ?? null;

            if ($database !== null && ! is_string($database)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $connections[(string) $name] = ['driver' => $connection['driver'], 'database' => $database];
        }

        return $connections;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<TableManifest>
     */
    private static function readTables(array $raw): array
    {
        $tables = [];

        foreach ($raw as $table) {
            if (! is_array($table)) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $tables[] = TableManifest::fromArray($table);
        }

        return $tables;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<array{connection: string, table: string, column: string, reason: string}>
     */
    private static function readUnresolved(array $raw): array
    {
        $unresolved = [];

        foreach ($raw as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['connection'] ?? null)
                || ! is_string($entry['table'] ?? null)
                || ! is_string($entry['column'] ?? null)
                || ! is_string($entry['reason'] ?? null)
            ) {
                throw new InvalidArgumentException('Unsupported or malformed manifest.');
            }

            $unresolved[] = [
                'connection' => $entry['connection'],
                'table' => $entry['table'],
                'column' => $entry['column'],
                'reason' => $entry['reason'],
            ];
        }

        return $unresolved;
    }
}
