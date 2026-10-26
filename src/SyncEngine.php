<?php

namespace png\SyncEngine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncEngine
{
    /**
     * Remplit la colonne UUID pour les données existantes dans une table
     * et initialise les attributs de synchronisation (is_synced, version, client_id).
     *
     * @param string $tableName Nom de la table
     * @param string|array<string> $primaryKey Clé primaire simple ('id') ou composite (['store_id', 'code'])
     * @param bool|null $isSynced État de synchronisation initial (si null, utilise sync-engine.default_is_synced)
     */
    public static function populateExistingData(string $tableName, $primaryKey = 'id', ?bool $isSynced = null)
    {
        $keys = (array) $primaryKey;
        $isSynced = is_null($isSynced) ? config('sync-engine.default_is_synced', false) : $isSynced;
        $clientId = config('sync-engine.client_id', 'default-client');

        do {
            $query = DB::table($tableName)->whereNull('uuid');

            foreach ($keys as $key) {
                $query->orderBy($key);
            }

            $rows = $query->limit(500)->get();

            if ($rows->isEmpty()) {
                break;
            }

            $updatedAny = false;

            foreach ($rows as $row) {
                $updateQuery = DB::table($tableName);

                foreach ($keys as $key) {
                    $updateQuery->where($key, $row->$key);
                }

                $updateData = [
                    'uuid'      => (string) Str::uuid(),
                    'is_synced' => $isSynced,
                ];

                if (property_exists($row, 'version') && empty($row->version)) {
                    $updateData['version'] = 1;
                }

                if (property_exists($row, 'client_id') && empty($row->client_id)) {
                    $updateData['client_id'] = $clientId;
                }

                $affected = $updateQuery->update($updateData);
                if ($affected > 0) {
                    $updatedAny = true;
                }
            }

            // Sécurité anti-boucle infinie au cas où des lignes ne peuvent être mises à jour
            if (!$updatedAny) {
                break;
            }
        } while ($rows->count() === 500);
    }
}
