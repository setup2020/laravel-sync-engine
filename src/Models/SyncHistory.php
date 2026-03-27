<?php

namespace png\SyncEngine\Models;

use Illuminate\Database\Eloquent\Model;

class SyncHistory extends Model
{
    protected $table = "sync_histories";
    protected $fillable = [
        'client_id',
        'type', // 'push' or 'pull'
        'table',
        'records_count',
        'status',
        'message'
    ];
}
