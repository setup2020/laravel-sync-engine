<?php

namespace png\SyncEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifySyncApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $configuredKey = config('sync-engine.api_key');

        // Si aucune clé n'est configurée, on bloque par sécurité ou l'on permet ? 
        // Il vaut mieux bloquer si elle est attendue.
        if (empty($configuredKey)) {
            return response()->json(['error' => 'Sync API Key not configured on the server.'], 500);
        }

        $providedKey = $request->header('X-Sync-Key') ?: $request->input('api_key');

        if ($providedKey !== $configuredKey) {
            return response()->json(['error' => 'Unauthorized. Invalid Sync API Key.'], 401);
        }

        return $next($request);
    }
}
