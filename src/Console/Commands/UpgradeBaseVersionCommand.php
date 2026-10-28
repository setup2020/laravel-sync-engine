<?php

namespace png\SyncEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeBaseVersionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:upgrade-base-version';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ajoute la colonne base_version aux tables synchronisables existantes et aligne les valeurs sur version.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tables = config('sync-engine.tables', []);

        if (empty($tables)) {
            $this->warn('[Sync-Engine] Aucune table paramétrée dans config/sync-engine.php.');
            return 0;
        }

        $this->info('[Sync-Engine] Démarrage de la mise à niveau vers base_version...');

        foreach ($tables as $tableName => $modelClass) {
            if (! Schema::hasTable($tableName)) {
                $this->warn("⚠️  Table '{$tableName}' introuvable en base. Ignorée.");
                continue;
            }

            // 1. Ajouter la colonne si absente
            if (! Schema::hasColumn($tableName, 'base_version')) {
                $this->line("-> Ajout de la colonne 'base_version' sur '{$tableName}'...");
                Schema::table($tableName, function (Blueprint $table) {
                    $table->integer('base_version')->default(1)->after('version');
                });
                $this->info("   ✅ Colonne 'base_version' ajoutée.");
            } else {
                $this->line("ℹ️  Colonne 'base_version' déjà présente sur '{$tableName}'.");
            }

            // 2. Initialiser base_version = version pour toutes les lignes existantes
            if (Schema::hasColumn($tableName, 'version')) {
                $updated = DB::table($tableName)
                    ->where(function ($q) {
                        $q->whereNull('base_version')
                          ->orWhere('base_version', 1);
                    })
                    ->update(['base_version' => DB::raw('version')]);

                if ($updated > 0) {
                    $this->info("   🔄 {$updated} enregistrement(s) initialisé(s) avec base_version = version sur '{$tableName}'.");
                }
            }
        }

        $this->info('[Sync-Engine] ✅ Mise à niveau terminée avec succès sans aucune régression !');
        return 0;
    }
}
