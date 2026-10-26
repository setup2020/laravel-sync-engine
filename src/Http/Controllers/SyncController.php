<?php

namespace png\SyncEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncController
{
    public function push(Request $r)
    {
        Log::info('[Sync-Engine] Push reçu depuis un client.', ['table' => $r->table, 'total envoyé' => count($r->data ?? [])]);

        $model = config('sync-engine.tables')[$r->table];

        $insertedCount = 0;
        $conflictCount = 0;

        foreach ($r->data as $row) {
            if (config('sync-engine.uses_auto_increment', true)) {
                unset($row['id']);
            }

            $query = $model::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model))) {
                $query->withTrashed();
            }

            $instance = $query->firstOrNew(['uuid' => $row['uuid']]);

            // GESTION DES CONFLITS (Server Wins)
            // Si le serveur a une version plus récente que celle proposée par le client, on rejette la modif du client.
            // On inscrit ce conflit manuellement dans SyncLog pour laisser la main à l'administrateur !

            Log::info('Version', ['serveur' => $instance->version, 'client' => $row['version']]);
            if ($instance->exists && isset($row['version']) && $instance->version > $row['version']) {
                $conflictCount++;

                \png\SyncEngine\Models\SyncLog::updateOrCreate(
                    [
                        'table' => $r->table,
                        'uuid'  => $row['uuid']
                    ],
                    [
                        'action'    => 'update',
                        'status'    => 'conflict',
                        'client_id' => $r->get('client_id', 'unknown'),
                        // On stocke la payload du client en JSON dans le champ 'message' pour l'audit admin
                        'message'   => json_encode($row)
                    ]
                );

                continue;
            }

            try {
                $columns = \Illuminate\Support\Facades\Schema::getColumnListing($instance->getTable());
                $cleanRow = \Illuminate\Support\Arr::only($row, $columns);

                $instance->forceFill($cleanRow);
                $instance->updated_at = now();
                $instance->saveQuietly();
                $insertedCount++;
            } catch (\Illuminate\Database\QueryException $qe) {
                $conflictCount++;
                Log::error("[Sync-Engine] Erreur de contrainte SQL lors du push sur '{$r->table}' pour l'UUID '{$row['uuid']}': " . $qe->getMessage());

                \png\SyncEngine\Models\SyncLog::updateOrCreate(
                    [
                        'table' => $r->table,
                        'uuid'  => $row['uuid'] ?? 'unknown'
                    ],
                    [
                        'action'    => 'push',
                        'status'    => 'error',
                        'client_id' => $r->get('client_id', 'unknown'),
                        'message'   => 'Erreur SQL : ' . $qe->getMessage()
                    ]
                );
            } catch (\Throwable $e) {
                $conflictCount++;
                Log::error("[Sync-Engine] Erreur inattendue lors du push sur '{$r->table}' pour l'UUID '{$row['uuid']}': " . $e->getMessage());

                \png\SyncEngine\Models\SyncLog::updateOrCreate(
                    [
                        'table' => $r->table,
                        'uuid'  => $row['uuid'] ?? 'unknown'
                    ],
                    [
                        'action'    => 'push',
                        'status'    => 'error',
                        'client_id' => $r->get('client_id', 'unknown'),
                        'message'   => 'Erreur inattendue : ' . $e->getMessage()
                    ]
                );
            }
        }

        Log::info("[Sync-Engine] Push traité. Intégrés: {$insertedCount}, Conflits ignorés: {$conflictCount}.");

        if ($insertedCount > 0 || $conflictCount > 0) {
            \png\SyncEngine\Models\SyncHistory::create([
                'client_id'     => $r->get('client_id', 'unknown'),
                'type'          => 'push',
                'table'         => $r->table,
                'records_count' => $insertedCount,
                'status'        => $conflictCount > 0 ? 'conflict' : 'success',
                'message'       => "Intégrés: {$insertedCount}, Conflits: {$conflictCount}"
            ]);
        }

        return response()->json(['ok' => true]);
    }




    public function pull(Request $r)
    {
        Log::info('[Sync-Engine] Pull demandé par un client.', ['client_id' => $r->client_id, 'table' => $r->table, 'last_sync' => $r->get('last_sync')]);

        if (!isset(config('sync-engine.tables')[$r->table])) {
            Log::error("[Sync-Engine] Pull rejeté : La table '{$r->table}' n'est pas configurée.");
            return response()->json(['error' => 'Table non configurée'], 400);
        }

        $modelClass = config('sync-engine.tables')[$r->table];
        $lastSync = $r->get('last_sync', '2000-01-01 00:00:00');

        // On récupère toutes les modifs après last_sync,
        // sauf celles générées originairement par le client qui fait la requête pour éviter les redondances
        $query = $modelClass::query();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass))) {
            $query->withTrashed();
        }



        $records = $query->where('updated_at', '>', $lastSync)
            ->where('client_id', '!=', $r->client_id)
            ->limit(config('sync-engine.batch_size', 1000))
            ->get();

        Log::info('[Sync-Engine] Envoi de la réponse de Pull au client.', ['total recu' => $records->count()]);

        if ($records->count() > 0) {
            \png\SyncEngine\Models\SyncHistory::create([
                'client_id'     => $r->client_id ?? 'unknown',
                'type'          => 'pull',
                'table'         => $r->table,
                'records_count' => $records->count(),
                'status'        => 'success',
                'message'       => 'Données envoyées au client'
            ]);
        }

        return response()->json([
            'data' => $records
        ]);
    }

    /**
     * API pour lister tous les conflits actuels avec comparaison Serveur/Client
     */
    public function getConflicts()
    {
        $logs = \png\SyncEngine\Models\SyncLog::where('status', 'conflict')->get();
        $conflicts = [];

        foreach ($logs as $log) {
            $table = $log->table;
            if (!isset(config('sync-engine.tables')[$table])) continue;

            $modelClass = config('sync-engine.tables')[$table];
            $query = $modelClass::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass))) {
                $query->withTrashed();
            }

            $serverInstance = $query->where('uuid', $log->uuid)->first();

            $conflicts[] = [
                'log_id'      => $log->id,
                'table'       => $table,
                'uuid'        => $log->uuid,
                'client_id'   => $log->client_id,
                'client_data' => json_decode($log->message, true),
                'server_data' => $serverInstance ? $serverInstance->toArray() : null,
                'created_at'  => $log->created_at,
            ];
        }

        return response()->json(['data' => $conflicts]);
    }

    /**
     * API pour permettre au Dashboard Admin de résoudre un conflit.
     */
    public function resolveConflict(Request $r)
    {
        $log = \png\SyncEngine\Models\SyncLog::where('table', $r->table)
            ->where('uuid', $r->uuid)
            ->where('status', 'conflict')
            ->first();

        if (!$log) {
            return response()->json(['error' => 'Aucun conflit actif trouvé pour cet enregistrement.'], 404);
        }

        if ($r->resolution === 'client') {
            // L'admin choisit d'écraser la donnée du Serveur par celle du Client
            $payload = json_decode($log->message, true);
            if ($payload) {
                $modelClass = config('sync-engine.tables')[$r->table];
                $query = $modelClass::query();
                if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass))) {
                    $query->withTrashed();
                }

                $instance = $query->firstOrNew(['uuid' => $r->uuid]);
                $columns = \Illuminate\Support\Facades\Schema::getColumnListing($instance->getTable());
                $cleanRow = \Illuminate\Support\Arr::only($payload, $columns);

                $instance->forceFill($cleanRow);

                // On force une version très élevée pour être sûr qu'aucun client n'écrase ça
                $instance->version = $instance->version + 50;
                $instance->updated_at = now();
                $instance->saveQuietly();
            }
        }

        // L'admin choisit de garder la donnée du serveur ('server') ou le client a été appliqué.
        // Dans tous les cas, le conflit est résolu.
        $log->update([
            'status' => 'synced',
            'message' => 'Résolu manuellement par admin (choix: ' . $r->resolution . ')'
        ]);

        return response()->json(['ok' => true, 'message' => 'Conflit résolu avec succès.']);
    }
}
