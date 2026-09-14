<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop;

use DeadDrop\DeadDrop\Console\Commands\CheckCommand;
use DeadDrop\DeadDrop\Console\Commands\DumpCommand;
use DeadDrop\DeadDrop\Console\Commands\DumpsCommand;
use DeadDrop\DeadDrop\Console\Commands\InitCommand;
use DeadDrop\DeadDrop\Console\Commands\PullCommand;
use DeadDrop\DeadDrop\Extraction\ExecutorManager;
use DeadDrop\DeadDrop\Inference\Sources\EloquentSource;
use DeadDrop\DeadDrop\Loading\LoaderRegistry;
use DeadDrop\DeadDrop\Loading\NdjsonLoader;
use DeadDrop\DeadDrop\Planning\KeySetRepository;
use DeadDrop\DeadDrop\Redaction\RedactionContext;
use DeadDrop\DeadDrop\Redaction\RedactionRules;
use DeadDrop\DeadDrop\Redaction\SaltResolver;
use DeadDrop\DeadDrop\Redaction\TransformerFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class DeadDropServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dead-drop.php', 'dead-drop');

        $this->app->scoped(KeySetRepository::class);

        $this->app->singleton(ExecutorManager::class);

        $this->app->singleton(LoaderRegistry::class, fn (): LoaderRegistry => new LoaderRegistry([new NdjsonLoader]));

        // The single place SaltResolver is called: an explicit
        // DEAD_DROP_REDACTION_SALT always wins, otherwise the salt is derived
        // from APP_KEY so a dump works with no redaction configuration at
        // all. Every other consumer resolves this binding rather than
        // building its own RedactionContext, so there is exactly one salt.
        // Scoped, not a plain singleton: a queue worker or Octane resets
        // scoped instances between jobs/requests, so a runtime change to
        // app.key or dead-drop.redaction.* is picked up rather than frozen
        // for the process lifetime.
        $this->app->scoped(RedactionContext::class, function (Application $app): RedactionContext {
            $config = $app->make('config');

            $salt = SaltResolver::resolve(
                $this->nullableString($config->get('dead-drop.redaction.salt')),
                $this->nullableString($config->get('app.key')),
            );

            return new RedactionContext(
                (string) $salt,
                (string) $config->get('dead-drop.redaction.email_domain'),
            );
        });

        // The gate has to judge `hash` against the email domain a dump will
        // actually write, or `dead-drop:check` could pass a map the dump then
        // refuses.
        $this->app->bind(RedactionRules::class, fn (Application $app): RedactionRules => new RedactionRules(
            new TransformerFactory,
            $app->make(RedactionContext::class),
        ));

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
            DumpCommand::class,
            DumpsCommand::class,
            PullCommand::class,
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
