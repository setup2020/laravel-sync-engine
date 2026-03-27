<?php

namespace png\SyncEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncPullJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $modelClass,
        public string $lastSyncTime = '2000-01-01 00:00:00'
    ) {}

    public function handle()
    {
        $table = (new $this->modelClass)->getTable();
        Log::info('[Sync-Engine Local] Démarrage de la vérification de synchronisation Pull...', ['table' => $table, 'last_sync' => $this->lastSyncTime]);

        try {
            // Demande au serveur les enregistrements modifiés récemment
            $response = Http::timeout(10)->withHeaders([
                'X-Sync-Key' => config('sync-engine.api_key')
            ])->get(config('sync-engine.server_url') . '/sync/pull', [
                'table'     => $table,
                'client_id' => config('sync-engine.client_id'),
                'last_sync' => $this->lastSyncTime
            ]);

            if ($response->successful() && isset($response->json()['data'])) {
                $records = $response->json()['data'];

                foreach ($records as $row) {
                    // Pour chaque donnée reçue du serveur, on l'intègre localement
                    if (config('sync-engine.uses_auto_increment', true)) {
                        unset($row['id']);
                    }

                    // On utilise saveQuietly() pour ne pas déclencher le SyncObserver 
                    // sinon la ligne serait marquée comme non-synchronisée
                    $query = $this->modelClass::query();
                    if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($this->modelClass))) {
                        $query->withTrashed();
                    }

                    $model = $query->where('uuid', $row['uuid'])->first();

                    // On filtre pour empêcher l'insertion de champs virtuels (erreur SQL 'Array to string conversion')
                    $columns = \Illuminate\Support\Facades\Schema::getColumnListing((new $this->modelClass)->getTable());
                    $cleanRow = \Illuminate\Support\Arr::only($row, $columns);
                    $cleanRow['is_synced'] = true;

                    if ($model) {
                        $model->forceFill($cleanRow);
                        $model->saveQuietly();

                        \png\SyncEngine\Models\SyncLog::updateOrCreate(
                            ['table' => $table, 'uuid' => $row['uuid']],
                            [
                                'action' => 'update',
                                'status' => 'synced',
                                'client_id' => $row['client_id'] ?? config('sync-engine.client_id'),
                                'message' => 'Réception depuis le serveur'
                            ]
                        );
                    } else {
                        $model = new $this->modelClass;
                        $model->forceFill($cleanRow);
                        $model->saveQuietly();

                        \png\SyncEngine\Models\SyncLog::updateOrCreate(
                            ['table' => $table, 'uuid' => $row['uuid']],
                            [
                                'action' => 'create',
                                'status' => 'synced',
                                'client_id' => $row['client_id'] ?? config('sync-engine.client_id'),
                                'message' => 'Réception depuis le serveur'
                            ]
                        );
                    }
                }

                // On sauvegarde dans la base de données l'heure de ce succès pour ne pas retélécharger la prochaine fois !
                DB::table('sync_state')->updateOrInsert(
                    ['table_name' => $table],
                    [
                        'last_pull_at' => now()->toDateTimeString(),
                        'updated_at' => now()
                    ]
                );

                Log::info('[Sync-Engine Local] Pull réussi. Les données ont été intégrées de manière fluide.', ['total recu' => count($records)]);
            } elseif (!$response->successful()) {
                Log::error('[Sync-Engine Local] Demande de Pull rejetée par le serveur. Statut : ' . $response->status(), ['body' => $response->body()]);
            }
        } catch (\Exception $e) {
            // Cas où la machine est hors-ligne ou le serveur inaccessible
            Log::warning("[Sync-Engine Local] Échec de connexion du Pull (Le serveur ou l'Internet est hors ligne).", ['error' => $e->getMessage()]);
            return;
        }
    }
}
