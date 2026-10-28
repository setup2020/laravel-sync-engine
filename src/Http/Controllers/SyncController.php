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
        $syncedUuids = [];
        $conflictedUuids = [];
        $updatedVersions = [];

        foreach ($r->data as $row) {
            if (config('sync-engine.uses_auto_increment', true)) {
                unset($row['id']);
            }

            $query = $model::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model))) {
                $query->withTrashed();
            }

            $instance = $query->firstOrNew(['uuid' => $row['uuid']]);

            // GESTION DES CONFLITS (Optimistic Concurrency Control - Approche A)
            // Si l'enregistrement existe déjà sur le serveur et que sa version actuelle diffère de la
            // version de départ (base_version) du client, la ligne a été modifiée en ligne entre temps !
            $isConflict = false;

            if ($instance->exists) {
                if (isset($row['base_version'])) {
                    // Si base_version est transmise par le client : vérification exacte OCC
                    if ($instance->version != $row['base_version']) {
                        $isConflict = true;
                    }
                } elseif (isset($row['version']) && $instance->version > $row['version']) {
                    // Fallback rétrocompatible si l'ancien client n'envoie pas base_version
                    $isConflict = true;
                }
            }

            Log::info('[Sync-Engine] Vérification version', [
                'serveur'        => $instance->exists ? $instance->version : null,
                'base_version'   => $row['base_version'] ?? null,
                'client_version' => $row['version'] ?? null,
                'conflict'       => $isConflict
            ]);

            if ($isConflict) {
                $conflictCount++;
                $conflictedUuids[] = $row['uuid'];

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

                // La nouvelle version sur le serveur prend en compte la progression du client
                $targetVersion = max(
                    (int) ($instance->version ?? 0) + 1,
                    (int) ($row['version'] ?? 1)
                );

                $instance->version = $targetVersion;
                if (in_array('base_version', $columns)) {
                    $instance->base_version = $targetVersion;
                }
                if (in_array('is_synced', $columns)) {
                    $instance->is_synced = true;
                }
                $instance->updated_at = now();
                $instance->saveQuietly();

                $insertedCount++;
                $syncedUuids[] = $row['uuid'];
                $updatedVersions[$row['uuid']] = $targetVersion;
            } catch (\Illuminate\Database\QueryException $qe) {
                $conflictCount++;
                $conflictedUuids[] = $row['uuid'] ?? 'unknown';
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
                $conflictedUuids[] = $row['uuid'] ?? 'unknown';
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

        return response()->json([
            'ok'        => true,
            'synced'    => $syncedUuids,
            'conflicts' => $conflictedUuids,
            'versions'  => $updatedVersions,
        ]);
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

        $modelClass = config('sync-engine.tables')[$r->table];
        $query = $modelClass::query();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass))) {
            $query->withTrashed();
        }

        $instance = $query->firstOrNew(['uuid' => $r->uuid]);
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($instance->getTable());

        if ($r->resolution === 'client') {
            // L'admin choisit d'écraser la donnée du Serveur par celle du Client
            $payload = json_decode($log->message, true);
            if ($payload) {
                $cleanRow = \Illuminate\Support\Arr::only($payload, $columns);
                $instance->forceFill($cleanRow);

                // On force une version très élevée pour être sûr qu'elle s'impose sur tout le réseau
                $targetVersion = ((int) ($instance->version ?? 0)) + 50;
                $instance->version = $targetVersion;
                if (in_array('base_version', $columns)) {
                    $instance->base_version = $targetVersion;
                }
                $instance->updated_at = now();
                $instance->saveQuietly();
            }
        } elseif ($r->resolution === 'server') {
            // L'admin choisit de maintenir la vérité du Serveur : on incrémente la version
            // et on met à jour updated_at pour que les clients synchronisent cette décision au prochain Pull
            if ($instance->exists) {
                $targetVersion = ((int) ($instance->version ?? 0)) + 1;
                $instance->version = $targetVersion;
                if (in_array('base_version', $columns)) {
                    $instance->base_version = $targetVersion;
                }
                $instance->updated_at = now();
                $instance->saveQuietly();
            }
        }

        // Dans tous les cas, le conflit est résolu.
        $log->update([
            'status' => 'synced',
            'message' => 'Résolu manuellement par admin (choix: ' . $r->resolution . ')'
        ]);

        return response()->json(['ok' => true, 'message' => 'Conflit résolu avec succès.']);
    }
}
