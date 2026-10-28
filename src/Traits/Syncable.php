<?php

namespace png\SyncEngine\Traits;

use Illuminate\Support\Str;

trait Syncable
{
    protected static function bootSyncable()
    {
        static::observe(\png\SyncEngine\Observers\SyncObserver::class);

        static::creating(function ($model) {
            if (empty($model->client_id)) {
                $model->client_id = config('sync-engine.client_id');
            }
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (! isset($model->version)) {
                $model->version = 1;
            }
            if (static::hasSyncColumn($model, 'base_version') && ! isset($model->base_version)) {
                $model->base_version = $model->version;
            }

            // On s'assure qu'une donnée fraîchement créée soit envoyée !
            $model->is_synced = false;
        });

        static::updating(function ($model) {
            // Empêcher le "marquage dirty" de repartir en boucle si l'on modifie uniquement les drapeaux de synchro ('is_synced', 'base_version')
            $dirtyKeys = array_keys($model->getDirty());
            $syncControlKeys = ['is_synced', 'base_version'];
            if (empty(array_diff($dirtyKeys, $syncControlKeys))) {
                return;
            }
            self::markDirty($model);
        });

        static::deleting(fn($m) => self::markDirty($m));

        if (method_exists(static::class, 'restored')) {
            static::restoring(fn($m) => self::markDirty($m));
        }
    }

    protected static function markDirty($model)
    {
        $model->version++;
        $model->is_synced = false;
        $model->client_id = config('sync-engine.client_id');
        // base_version reste inchangé pour conserver la référence de la dernière synchronisation
    }

    public function scopeDirty($q)
    {
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class))) {
            $q->withTrashed();
        }
        return $q->where('is_synced', false);
    }

    public function getSyncKey(): array
    {
        return [
            'client_id' => $this->client_id,
        ];
    }

    protected static array $syncColumnsCache = [];

    protected static function hasSyncColumn($model, string $column): bool
    {
        $table = $model->getTable();
        if (! isset(static::$syncColumnsCache[$table])) {
            try {
                static::$syncColumnsCache[$table] = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return in_array($column, static::$syncColumnsCache[$table]);
    }
}
