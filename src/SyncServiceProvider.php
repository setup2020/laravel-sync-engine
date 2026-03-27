<?php

namespace png\SyncEngine;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;

class SyncServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Macros de migrations pour ajouter facilement les colonnes requises
        Blueprint::macro('syncable', function ($defaultIsSynced = null) {
            $isSynced = is_null($defaultIsSynced) ? config('sync-engine.default_is_synced', false) : $defaultIsSynced;

            $this->uuid('uuid')->nullable()->unique();
            $this->string('client_id')->nullable();
            $this->boolean('is_synced')->default($isSynced);
            $this->integer('version')->default(1);
        });

        Blueprint::macro('dropSyncable', function () {
            $this->dropColumn(['uuid', 'client_id', 'is_synced', 'version']);
        });

        // config
        $this->publishes([
            __DIR__ . '/../config/sync-engine.php' =>
            config_path('sync-engine.php'),
        ], 'sync-engine-config');

        // migrations
        $this->loadMigrationsFrom(
            __DIR__ . '/database/migrations'
        );

        if (config('sync-engine.is_server', false)) {
            $this->loadMigrationsFrom(
                __DIR__ . '/database/migrations'
            );
        }

        // routes
        $this->loadRoutesFrom(
            __DIR__ . '/routes/web.php'
        );

        $this->loadRoutesFrom(
            __DIR__ . '/routes/api.php'
        );

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sync-engine');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \png\SyncEngine\Console\Commands\RunSyncCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sync-engine.php',
            'sync-engine'
        );
    }
}
