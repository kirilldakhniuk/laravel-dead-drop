<?php

declare(strict_types=1);

use DeadDrop\DeadDrop\Artifacts\ArtifactReader;
use DeadDrop\DeadDrop\Artifacts\Manifest;
use DeadDrop\DeadDrop\Config\ConfigLoader;
use DeadDrop\DeadDrop\Planning\Root;
use DeadDrop\DeadDrop\Planning\TraversalResult;
use DeadDrop\DeadDrop\Planning\Traverser;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use DeadDrop\DeadDrop\Schema\Introspector;
use DeadDrop\DeadDrop\Schema\SchemaSet;
use DeadDrop\DeadDrop\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(TestCase::class)->in(__DIR__);

function fakeArtifactDisk(): void
{
    Storage::fake('local');
    config()->set('dead-drop.disk', 'local');
    config()->set('dead-drop.path', 'dead-drops');
    config()->set('dead-drop.redaction.salt', str_repeat('s', 32));
}

function tempDirectory(): string
{
    $directory = sys_get_temp_dir().'/dead-drop-'.Str::random(8);

    mkdir($directory, recursive: true);

    return $directory;
}

function initFixtureConfig(): string
{
    $path = tempDirectory();

    test()->artisan('dead-drop:init', ['--connection' => ['dd_test'], '--path' => $path, '--no-interaction' => true])
        ->assertSuccessful();

    return $path;
}

/**
 * The id of the newest artifact on the faked disk.
 */
function latestArtifactId(): string
{
    return (new ArtifactReader(Storage::disk('local'), 'dead-drops'))->ids()[0];
}

/**
 * An artifact the way `dead-drop:pull` offers it in its choice list.
 */
function artifactLabel(Manifest $manifest): string
{
    return Root::parse($manifest->root)->describe()
        .' · '.(new DateTimeImmutable($manifest->createdAt))->format('Y-m-d H:i')
        .' · '.$manifest->totalRows().' rows · '.count($manifest->tables).' tables';
}

/**
 * Runs a real (non dry-run) dump of the fixture connection and returns the id
 * of the artifact it wrote. The caller must have called `fakeArtifactDisk()`.
 */
function dumpFixture(string $root = 'dd_test.companies:1', ?string $configDirectory = null): string
{
    $directory = $configDirectory ?? initFixtureConfig();
    $parsed = Root::parse($root);

    test()->artisan('dead-drop:dump', [
        'table' => $parsed->table,
        'ids' => array_map('strval', $parsed->ids),
        '--connection' => $parsed->connection,
        '--path' => $directory,
        '--disk' => 'local',
    ])->assertSuccessful();

    return latestArtifactId();
}

function traverseFixture(string $rootSpec, ?string $configDirectory = null, ?DateTimeInterface $since = null): TraversalResult
{
    $directory = $configDirectory ?? initFixtureConfig();
    $config = (new ConfigLoader)->loadAll($directory);

    $schemas = new SchemaSet(array_combine(
        $config->connections(),
        array_map(
            fn (string $connection): DatabaseSchema => app(Introspector::class)->inspect($connection),
            $config->connections(),
        ),
    ));

    return app(Traverser::class)->traverse(Root::parse($rootSpec), $config, $schemas, $since);
}

/**
 * @return list<int|string>
 */
function collectedKeys(TraversalResult $result, string $table, string $connection = 'dd_test'): array
{
    $keySet = $result->keySets()["{$connection}.{$table}"] ?? null;

    if ($keySet === null) {
        return [];
    }

    return array_map(
        fn (mixed $key): int|string => is_numeric($key) ? (int) $key : (string) $key,
        $keySet->query()->orderBy('k')->pluck('k')->all(),
    );
}
