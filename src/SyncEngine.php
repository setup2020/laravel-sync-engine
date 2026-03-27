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
     * @param string $tableName  Nom de la table
     * @param string $primaryKey Clé primaire locale (défaut: 'id')
     */
    public static function populateExistingData(string $tableName, string $primaryKey = 'id')
    {
        DB::table($tableName)
            ->whereNull('uuid')
            ->orderBy($primaryKey)
            ->chunk(500, function ($rows) use ($tableName, $primaryKey) {
                foreach ($rows as $row) {
                    DB::table($tableName)
                        ->where($primaryKey, $row->$primaryKey)
                        ->update([
                            'uuid'      => (string) Str::uuid(),
                            'is_synced' => true
                        ]);
                }
            });
    }
}
