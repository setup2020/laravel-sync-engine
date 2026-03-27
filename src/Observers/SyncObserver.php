<?php

namespace png\SyncEngine\Observers;

use png\SyncEngine\Models\SyncLog;

class SyncObserver
{
    public function created($model)
    {
        SyncLog::log($model, 'create');
    }

    public function updated($model)
    {
        SyncLog::log($model, 'update');
    }

    public function deleted($model)
    {
        SyncLog::log($model, 'delete');
    }
}
