<?php

use Illuminate\Support\Facades\Route;
use png\SyncEngine\Http\Controllers\DashboardController;

Route::prefix('sync-dashboard')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/logs', [DashboardController::class, 'logs']);
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/states', [DashboardController::class, 'states']);
    });
