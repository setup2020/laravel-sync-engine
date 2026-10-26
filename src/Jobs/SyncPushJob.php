<?php

namespace png\SyncEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $modelClass
    ) {}

    public function handle()
    {
        $records = $this->modelClass::dirty()
            ->limit(config('sync-engine.batch_size', 1000))->get();

        if ($records->isEmpty()) return;

        Log::info('[Sync-Engine Local] Démarrage du Push vers le serveur central...', ['table' => (new $this->modelClass)->getTable(), 'to_send' => $records->count()]);

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Sync-Key' => config('sync-engine.api_key')
            ])->post(config('sync-engine.server_url') . '/sync/push', [
                'table'     => (new $this->modelClass)->getTable(),
                'client_id' => config('sync-engine.client_id'),
                'data'      => $records
            ]);

            $tableName = (new $this->modelClass)->getTable();

            if ($response->successful()) {
                $instance = new $this->modelClass;
                $keyName = $instance->getKeyName();

                if (is_array($keyName)) {
                    // Clé composite : mise à jour par combinaison des colonnes de la clé
                    foreach ($records as $record) {
                        $itemQuery = $this->modelClass::query();
                        foreach ($keyName as $col) {
                            $itemQuery->where($col, $record->$col);
                        }
                        $itemQuery->update(['is_synced' => true]);
                    }
                } else {
                    $this->modelClass::whereIn($keyName, $records->pluck($keyName))
                        ->update(['is_synced' => true]);
                }

                \png\SyncEngine\Models\SyncLog::where('table', $tableName)
                    ->whereIn('uuid', $records->pluck('uuid'))
                    ->update(['status' => 'synced', 'message' => null]);

                Log::info('[Sync-Engine Local] Push réussi ! Les enregistrements sont marqués comme synchronisés.');
            } else {
                \png\SyncEngine\Models\SyncLog::where('table', $tableName)
                    ->whereIn('uuid', $records->pluck('uuid'))
                    ->update(['status' => 'error', 'message' => 'Erreur serveur: ' . $response->status()]);

                Log::error('[Sync-Engine Local] Échec du Push côté serveur (Mauvaise requête ou accès refusé). Statut : ' . $response->status() . ' - ' . $response->body());
            }
        } catch (\Exception $e) {
            $tableName = (new $this->modelClass)->getTable();
            \png\SyncEngine\Models\SyncLog::where('table', $tableName)
                ->whereIn('uuid', $records->pluck('uuid'))
                ->update(['status' => 'error', 'message' => 'Erreur de connexion: ' . $e->getMessage()]);

            Log::warning('[Sync-Engine Local] Échec de la connexion pour le Push (serveur introuvable, hors-ligne ou timeout). Le Job sera retenté plus tard.', ['error' => $e->getMessage()]);
        }
    }
}
