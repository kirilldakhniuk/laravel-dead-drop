<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Extraction;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the executor a dump runs on. `php` ships with the package;
 * anything else is registered by an application through `extend()`, which is
 * how a native-tool executor arrives without the commands knowing about it.
 */
final class ExecutorManager
{
    /** @var array<string, class-string<Executor>> */
    private const array BUILT_IN = ['php' => PhpExecutor::class];

    /** @var array<string, Closure(Container): Executor> */
    private array $factories = [];

    /** @var array<string, Executor> */
    private array $executors = [];

    public function __construct(
        private readonly Container $app,
    ) {}

    public function driver(?string $name = null): Executor
    {
        $name ??= $this->defaultDriver();

        return $this->executors[$name] ??= $this->resolve($name);
    }

    /**
     * @param  Closure(Container): Executor  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;

        unset($this->executors[$name]);
    }

    private function resolve(string $name): Executor
    {
        $factory = $this->factories[$name] ?? null;

        if ($factory !== null) {
            return $factory($this->app);
        }

        $class = self::BUILT_IN[$name] ?? throw new InvalidArgumentException("Unsupported DeadDrop executor [{$name}].");

        return $this->app->make($class);
    }

    private function defaultDriver(): string
    {
        $name = $this->app->make(Repository::class)->get('dead-drop.executor', 'php');

        return is_string($name) ? $name : 'php';
    }
}
