<?php
return [
    'server_url' => rtrim(env('SYNC_SERVER_URL', ''), '/'),
    'api_key'    => env('SYNC_API_KEY'),
    'client_id'  => env('SYNC_CLIENT_ID', 'default-client'),

    'is_server'  => env('SYNC_IS_SERVER', false),

    'batch_size'          => env('SYNC_BATCH_SIZE', 1000),
    'uses_auto_increment' => env('SYNC_USES_AUTO_INCREMENT', true),
    'default_is_synced'   => env('SYNC_DEFAULT_IS_SYNCED', false),

    'tables' => [
        // 'customers' => App\Models\Customer::class
    ]
];
