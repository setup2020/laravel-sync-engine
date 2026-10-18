<?php

namespace png\SyncEngine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncEngine
{
    /**
     * Remplit la colonne UUID pour les données existantes dans une table
     * et les marque comme non-synchronisées pour forcer le push vers le serveur.
     *
     * @param string $tableName Nom de la table
     * @param string|array<string> $primaryKey Clé primaire simple ('id') ou clé composite (['store_id', 'numero'])
     */
    public static function populateExistingData(string $tableName, string|array $primaryKey = 'id')
    {
        $keys = (array) $primaryKey;

        $query = DB::table($tableName)->whereNull('uuid');

        foreach ($keys as $key) {
            $query->orderBy($key);
        }

        $query->chunk(500, function ($rows) use ($tableName, $keys) {
            foreach ($rows as $row) {
                $updateQuery = DB::table($tableName);

                foreach ($keys as $key) {
                    $updateQuery->where($key, $row->$key);
                }

                $updateQuery->update([
                    'uuid'      => (string) Str::uuid(),
                    'is_synced' => true,
                ]);
            }
        });
    }
}
