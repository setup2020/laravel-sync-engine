<?php

use Illuminate\Support\Facades\Route;
use png\SyncEngine\Http\Controllers\SyncController;
use png\SyncEngine\Http\Controllers\DashboardController;

Route::prefix('api/sync')->group(function () {
    // Routes publiques ou gérées par votre propre système d'auth Admin
    Route::get('/conflicts', [SyncController::class, 'getConflicts']);
    Route::post('/resolve-conflict', [SyncController::class, 'resolveConflict']);
    Route::get('/logs', [DashboardController::class, 'logs']);
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/states', [DashboardController::class, 'states']);

    // Routes protégées par la clé secrète (pour les échanges automatiques Serveur <> Client)
    Route::middleware([\png\SyncEngine\Http\Middleware\VerifySyncApiKey::class])
        ->group(function () {
            Route::post('/push', [SyncController::class, 'push']);
            Route::get('/pull', [SyncController::class, 'pull']);

            Route::get('/run', function () {
                \Illuminate\Support\Facades\Artisan::call('sync:run');
                return response()->json([
                    'success' => true,
                    'message' => 'Synchronisation lancée avec succès.'
                ]);
            });
        });
});
