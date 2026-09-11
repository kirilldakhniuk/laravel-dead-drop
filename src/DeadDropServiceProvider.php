<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop;

use DeadDrop\DeadDrop\Console\Commands\DeadDropCommand;
use Illuminate\Support\ServiceProvider;

class DeadDropServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dead-drop.php', 'dead-drop');

        $this->app->singleton(DeadDrop::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/dead-drop.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dead-drop');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'dead-drop');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dead-drop.php' => config_path('dead-drop.php'),
        ], ['dead-drop', 'dead-drop-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dead-drop'),
        ], ['dead-drop', 'dead-drop-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/dead-drop'),
        ], ['dead-drop', 'dead-drop-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/dead-drop'),
        ], ['dead-drop', 'dead-drop-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['dead-drop', 'dead-drop-migrations']);

        $this->commands([
            DeadDropCommand::class,
        ]);
    }
}
