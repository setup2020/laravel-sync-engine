<?php

namespace png\SyncEngine\Console\Commands;

use Illuminate\Console\Command;
use png\SyncEngine\Jobs\SyncPullJob;
use png\SyncEngine\Jobs\SyncPushJob;
use Illuminate\Support\Facades\DB;

class RunSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Démarre les requêtes Pull et Push pour TOUTES les tables du Moteur de Synchronisation.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tables = config('sync-engine.tables', []);

        if (empty($tables)) {
            $this->warn('[Sync-Engine] Aucune table paramétrée dans config/sync-engine.php.');
            return;
        }

        $this->info('[Sync-Engine] Démarrage du cycle de synchronisation global...');

        foreach ($tables as $tableName => $modelClass) {
            $this->line("Dispatching Push & Pull jobs for table: {$tableName}");

            // On lit dans la base de données l'heure exacte de la dernière synchro réussie (Sinon an 2000)
            $state = DB::table('sync_state')->where('table_name', $tableName)->first();
            $lastSync = $state && $state->last_pull_at
                ? \Carbon\Carbon::parse($state->last_pull_at)->subMinutes(10)->toDateTimeString()
                : '2000-01-01 00:00:00';
            // 2. Envoyer les modifications locales
            dispatch(new SyncPushJob($modelClass));

            // 1. Récupérer les nouveautés du serveur depuis cette date !
            dispatch(new SyncPullJob($modelClass, $lastSync));
        }

        $this->info('[Sync-Engine] Cycle de synchronisation programmé avec succès !');
    }
}
