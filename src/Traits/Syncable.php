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

            // On s'assure qu'une donnée fraîchement créée soit envoyée !
            $model->is_synced = false;
        });

        static::updating(function ($model) {
            // Empêcher le "marquage dirty" de repartir en boucle si l'on modifie uniquement le flag 'is_synced' manuellement
            if ($model->isDirty('is_synced') && count($model->getDirty()) === 1) {
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
}
