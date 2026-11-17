<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pixelforge (spec 3-04)
|--------------------------------------------------------------------------
|
| Money values are decimal STRINGS (USD per 1M tokens), parsed only through
| Nanodollars::perMillionFromDecimalString. No floats in this file: the
| arch float scan covers it (spec 0, "Configuration and secrets").
|
*/

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    /** Empty means "not configured": the app boots, nothing is submitted (issue 4). */
    'api_key' => env('PIXELFORGE_API_KEY'),

    /** whsec_… of the same key; verified by the webhook endpoint (spec 5, D-056). */
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /** Maximum age of a webhook signature timestamp, in seconds. */
    'webhook_tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    /**
     * Host of the signed image URLs. PersistTakeImageJob downloads only from
     * this host (SSRF guard, spec 4 "Persist image"); defaults to the API host.
     */
    'image_host' => env('PIXELFORGE_IMAGE_HOST') ?: parse_url((string) env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'), PHP_URL_HOST),

    'images' => [
        'timeout_seconds' => 20,
        'max_bytes' => 20 * 1024 * 1024,
    ],

    'http' => [
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 3,
    ],

    'pricing' => [
        /** GET /v1/pricing is cached on the default cache store. */
        'cache_ttl_seconds' => 3600,

        /** When /v1/pricing fails, the fallback below is served and cached this long. */
        'fallback_cache_ttl_seconds' => 60,

        /**
         * Same shape as GET /v1/pricing (captured 2026-11-17 from
         * https://pixelforge.promptedit.dev/v1/pricing). The API sends
         * text_token_variance as a JSON number; here it is a string.
         */
        'fallback' => [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => '0.15',
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ],
    ],

    /** GET /v1/usage accepts at most this many days; the client chunks longer ranges. */
    'usage_max_days' => 92,

    'submit' => [
        /** Retry window for capacity and ambiguous outcomes, anchored on takes.created_at (D-025). */
        'window_minutes' => 30,

        /** Every job release/backoff delay is capped here (Laravel Cloud queue limit). */
        'max_delay_seconds' => 900,
    ],

    /** Global in-flight gate used by the submit job (spec 3-04 issue 3). */
    'gate' => [
        /** Pixelforge allows 3 queued or processing generations per account. */
        'concurrency_cap' => 3,

        /** The lock must live in a store every worker shares. */
        'store' => 'database',

        'lock_seconds' => 10,
        'lock_wait_seconds' => 2,

        /** How long a "submitting" reservation counts if the job never reports back (> HTTP timeouts). */
        'reservation_seconds' => 30,

        'jitter_min_seconds' => 3,
        'jitter_max_seconds' => 8,
    ],

];
