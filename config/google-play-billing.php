<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service account
    |--------------------------------------------------------------------------
    |
    | Path to the Google Play service account JSON with AndroidPublisher scope.
    |
    */

    'credentials_path' => env('GOOGLE_PLAY_CREDENTIALS_PATH', storage_path('app/google_play_service.json')),

    'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.consumedbycode.slopes'),

    'application_name' => env('GOOGLE_PLAY_APPLICATION_NAME', 'Slopes'),

    /*
    |--------------------------------------------------------------------------
    | Real-time Developer Notifications
    |--------------------------------------------------------------------------
    |
    | `dedupe_ttl` is how long a Pub/Sub messageId is remembered so a redelivery
    | is a no-op. `retries`/`retry_delay` govern re-fetching the purchase from
    | Google, which is routinely 503 for a few seconds after a notification.
    |
    */

    'rtdn' => [
        'dedupe_ttl' => env('GOOGLE_PLAY_RTDN_DEDUPE_TTL', 3600),
        'retries' => env('GOOGLE_PLAY_RTDN_RETRIES', 5),
        'retry_delay' => env('GOOGLE_PLAY_RTDN_RETRY_DELAY', 3),
    ],

];
