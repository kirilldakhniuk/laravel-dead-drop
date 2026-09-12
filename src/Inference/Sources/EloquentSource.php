<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference\Sources;

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\InferredEdge;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Throwable;

/**
 * Discovers belongsTo relations declared on Eloquent models and turns them
 * into inferred edges.
 *
 * Only public, zero-argument, non-static methods declared on the model
 * itself and typed to return a Relation subclass are ever called. Everything
 * else is skipped and reported via skipped(). A model that cannot be
 * instantiated, or a qualifying method that throws when called, is recorded
 * in failed() and inference continues with the next candidate.
 */
final class EloquentSource
{
    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $failed = [];

    /**
     * @param  list<string>  $modelPaths  absolute directory paths to scan for model classes
     */
    public function __construct(
        private readonly array $modelPaths,
    ) {}

    /**
     * @return list<InferredEdge>
     */
    public function infer(DatabaseSchema $schema): array
    {
        $this->skipped = [];
        $this->failed = [];

        $edges = [];

        foreach ($this->discoverModelClasses() as $class) {
            try {
                $model = new $class;
            } catch (Throwable $e) {
                $this->failed[] = $class.': '.$e->getMessage();

                continue;
            }

            if (! $this->isInScope($model, $schema)) {
                continue;
            }

            $reflection = new ReflectionClass($model);

            foreach ($this->relationMethods($reflection) as $method) {
                try {
                    $relation = $model->{$method->getName()}();
                } catch (Throwable $e) {
                    $this->failed[] = $class.'::'.$method->getName().': '.$e->getMessage();

                    continue;
                }

                if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
                    continue;
                }

                $key = $model->getTable().'.'.$relation->getForeignKeyName();

                if (array_key_exists($key, $edges)) {
                    continue;
                }

                $edges[$key] = new InferredEdge(
                    table: $model->getTable(),
                    column: $relation->getForeignKeyName(),
                    targetConnection: null,
                    targetTable: $relation->getRelated()->getTable(),
                    targetColumn: $relation->getOwnerKeyName(),
                    source: EdgeSource::Eloquent,
                );
            }
        }

        return array_values($edges);
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return list<string>
     */
    public function failed(): array
    {
        return $this->failed;
    }

    private function isInScope(Model $model, DatabaseSchema $schema): bool
    {
        if ($schema->table($model->getTable()) === null) {
            return false;
        }

        $connection = $model->getConnectionName();

        return $connection === null || $connection === $schema->connection;
    }

    /**
     * @param  ReflectionClass<Model>  $class
     * @return list<ReflectionMethod>
     */
    private function relationMethods(ReflectionClass $class): array
    {
        $methods = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $type = $method->getReturnType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), Relation::class)) {
                $this->skipped[] = $class->getName().'::'.$method->getName();

                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @return list<class-string<Model>>
     */
    private function discoverModelClasses(): array
    {
        $files = [];

        foreach ($this->modelPaths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        $classes = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $class = $this->classFromFile($contents);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var class-string<Model> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    private function classFromFile(string $contents): ?string
    {
        if (! preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/mi', $contents, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]).'\\'.$classMatch[1];
    }
}
