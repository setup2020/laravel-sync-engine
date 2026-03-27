<?php

namespace png\SyncEngine\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'table',
        'uuid',
        'action',
        'client_id',
        'status',
        'message'
    ];

    public static function log($model, $action, $status = 'pending')
    {
        self::updateOrCreate(
            [
                'table' => $model->getTable(),
                'uuid'  => $model->uuid ?? null,
            ],
            [
                'action'    => $action,
                'status'    => $status,
                'client_id' => $model->client_id ?? config('sync-engine.client_id'),
                'message'   => null
            ]
        );
    }
}
