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
                $responseData = $response->json();
                $syncedUuids = $responseData['synced'] ?? $records->pluck('uuid')->all();
                $conflictedUuids = $responseData['conflicts'] ?? [];
                $versions = $responseData['versions'] ?? [];

                $columns = \Illuminate\Support\Facades\Schema::getColumnListing($tableName);
                $hasBaseVersion = in_array('base_version', $columns);

                foreach ($records as $record) {
                    if (in_array($record->uuid, $syncedUuids)) {
                        $record->is_synced = true;
                        if ($hasBaseVersion) {
                            $targetVer = $versions[$record->uuid] ?? $record->version;
                            $record->base_version = $targetVer;
                            $record->version = $targetVer;
                        }
                        $record->saveQuietly();
                    }
                }

                if (!empty($syncedUuids)) {
                    \png\SyncEngine\Models\SyncLog::where('table', $tableName)
                        ->whereIn('uuid', $syncedUuids)
                        ->update(['status' => 'synced', 'message' => null]);
                }

                if (!empty($conflictedUuids)) {
                    \png\SyncEngine\Models\SyncLog::where('table', $tableName)
                        ->whereIn('uuid', $conflictedUuids)
                        ->update([
                            'status'  => 'conflict',
                            'message' => 'Conflit détecté par le serveur lors du push (Server Wins)'
                        ]);

                    Log::warning("[Sync-Engine Local] Conflits détectés sur {$tableName} pour " . count($conflictedUuids) . " enregistrement(s).");
                }

                Log::info('[Sync-Engine Local] Push terminé. Succès: ' . count($syncedUuids) . ', Conflits: ' . count($conflictedUuids));
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
