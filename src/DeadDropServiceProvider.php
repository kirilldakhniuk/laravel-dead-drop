<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop;

use DeadDrop\DeadDrop\Console\Commands\CheckCommand;
use DeadDrop\DeadDrop\Console\Commands\InitCommand;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class DeadDropServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dead-drop.php', 'dead-drop');

        $this->app->scoped(KeySetRepository::class);

        $this->app->singleton(EloquentSource::class, fn (Application $app): EloquentSource => new EloquentSource(
            array_values(array_map(
                fn (string $path): string => $app->basePath($path),
                (array) $app->make('config')->get('dead-drop.model_paths', []),
            )),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dead-drop.php' => config_path('dead-drop.php'),
        ], ['dead-drop', 'dead-drop-config']);

        $this->commands([
            InitCommand::class,
            CheckCommand::class,
        ]);
    }
}
