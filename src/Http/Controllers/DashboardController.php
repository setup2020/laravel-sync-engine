<?php

namespace png\SyncEngine\Http\Controllers;

use Illuminate\Routing\Controller;
use png\SyncEngine\Models\SyncLog;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return view('sync-engine::dashboard');
    }

    public function logs()
    {
        return SyncLog::latest()->paginate(30);
    }

    public function stats()
    {
        return [
            'total'     => SyncLog::count(),
            'pending'   => SyncLog::where('status', 'pending')->count(),
            'synced'    => SyncLog::where('status', 'synced')->count(),
            'conflicts' => SyncLog::where('status', 'conflict')->count(),
            'errors'    => SyncLog::where('status', 'error')->count(),

            'by_table' => SyncLog::select('table', DB::raw('count(*) as total'))
                ->groupBy('table')
                ->get(),

            'by_client' => SyncLog::select('client_id', DB::raw('count(*) as total'))
                ->groupBy('client_id')
                ->get(),
        ];
    }
}
