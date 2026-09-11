<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Inference\Sources;

use DeadDrop\DeadDrop\Inference\EdgeSource;
use DeadDrop\DeadDrop\Inference\InferredEdge;
use DeadDrop\DeadDrop\Schema\DatabaseSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

/**
 * Discovers belongsTo relations declared on Eloquent models and turns them
 * into inferred edges.
 *
 * Only public, zero-argument, non-static methods declared on the model
 * itself and typed to return a Relation subclass are ever called. Everything
 * else is skipped and reported via skipped().
 */
final class EloquentSource
{
    /** @var list<string> */
    private array $skipped = [];

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

        $edges = [];

        foreach ($this->discoverModelClasses() as $class) {
            $model = new $class;

            if (! $this->isInScope($model, $schema)) {
                continue;
            }

            $reflection = new ReflectionClass($model);

            foreach ($this->relationMethods($reflection) as $method) {
                $relation = $model->{$method->getName()}();

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
        $existingPaths = array_filter($this->modelPaths, static fn (string $path): bool => is_dir($path));

        if ($existingPaths === []) {
            return [];
        }

        $finder = (new Finder)->files()->in($existingPaths)->name('*.php');

        $classes = [];

        foreach ($finder as $file) {
            $class = $this->classFromFile($file->getContents());

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

        if (! preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]).'\\'.$classMatch[1];
    }
}
