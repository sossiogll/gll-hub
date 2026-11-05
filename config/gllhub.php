<?php

return [

    'packages' => [
        // Composer's record of what is installed, including each package's `extra`.
        'installed' => base_path('vendor/composer/installed.json'),
    ],

    // The HTTP client every package gets through its context.
    'http' => [
        'timeout' => (int) env('GLLHUB_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('GLLHUB_HTTP_CONNECT_TIMEOUT', 5),
        'user_agent' => env('GLLHUB_USER_AGENT', 'gllHub (+'.env('APP_URL', 'http://localhost').')'),
        // Seconds to wait when a 429 comes without a usable Retry-After.
        'default_retry_after' => 60,
    ],

    'sync' => [
        // Unavailable is retried this many times, doubling the wait from `backoff`, up to `max_backoff`.
        'max_attempts' => 6,
        'backoff' => 60,
        'max_backoff' => 3600,
        // A source that says "more" without moving its cursor is stopped, not looped on.
        'max_batches' => 1000,
    ],

    'media' => [
        'max_bytes' => 20 * 1024 * 1024,
    ],

    'webhooks' => [
        'per_minute' => (int) env('GLLHUB_WEBHOOKS_PER_MINUTE', 120),
    ],

    'api' => [
        'per_minute' => (int) env('GLLHUB_API_PER_MINUTE', 300),
    ],

    // Declared, never guessed from the content: the API cannot answer without them.
    'languages' => [
        'default' => env('GLLHUB_DEFAULT_LANGUAGE'),
        'available' => array_values(array_filter(array_map('trim', explode(',', (string) env('GLLHUB_LANGUAGES', ''))))),
    ],

];
