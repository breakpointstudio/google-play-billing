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
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Google's client library ships no timeout at all, so a hung request holds
    | the caller until the PHP process itself gives up. These have to leave room
    | inside whatever window the caller answers to — a Pub/Sub push ack deadline
    | is 10s by default.
    |
    */

    'http' => [
        'connect_timeout' => env('GOOGLE_PLAY_CONNECT_TIMEOUT', 3),
        'timeout' => env('GOOGLE_PLAY_TIMEOUT', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time Developer Notifications
    |--------------------------------------------------------------------------
    |
    | `dedupe_ttl` is how long a Pub/Sub messageId is remembered so a redelivery
    | is a no-op. `retries`/`retry_delay` govern re-fetching the purchase from
    | Google, which is routinely 503 for a few seconds after a notification.
    | Retrying in-request is off by default: sleeping past the push ack deadline
    | earns a redelivery anyway, and Pub/Sub's own backoff waits far better.
    |
    | `push_auth` verifies the OIDC token an authenticated push subscription
    | attaches. Leave it off until the subscription actually has a service
    | account — an unauthenticated push sends no Authorization header, so
    | enabling it early rejects every notification. `audience` is whatever the
    | subscription was configured with (usually the endpoint URL).
    |
    */

    'rtdn' => [
        // 96h, not 1h: Pub/Sub redelivers for up to 7 days, so a 1h window let late redeliveries
        // through and double-counted the `received` metrics.
        'dedupe_ttl' => env('GOOGLE_PLAY_RTDN_DEDUPE_TTL', 345600),
        'retries' => env('GOOGLE_PLAY_RTDN_RETRIES', 1),
        'retry_delay' => env('GOOGLE_PLAY_RTDN_RETRY_DELAY', 0),
        'push_auth' => [
            'enabled' => env('GOOGLE_PLAY_RTDN_PUSH_AUTH', false),
            'audience' => env('GOOGLE_PLAY_RTDN_PUSH_AUDIENCE'),
            'service_account_email' => env('GOOGLE_PLAY_RTDN_PUSH_SERVICE_ACCOUNT'),
        ],
    ],

];
