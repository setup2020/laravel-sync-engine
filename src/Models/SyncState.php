<?php

namespace png\SyncEngine\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $table = 'sync_state';
    protected $primaryKey = 'table_name';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'table_name',
        'last_pull_at',
    ];

    protected $casts = [
        'last_pull_at' => 'datetime',
    ];
}
