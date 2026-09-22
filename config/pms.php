<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reservation defaults
    |--------------------------------------------------------------------------
    |
    | Organizations override these per property; the values here are only the
    | fallback used when nothing more specific is configured.
    |
    */
    'reservations' => [
        'default_check_in_time' => '15:00',
        'default_check_out_time' => '11:00',
        'confirmation_code_prefix' => 'HB',

        // How long a "tentative" hold survives before the availability it
        // occupies is released back to the calendar.
        'hold_minutes' => 30,

        // Maximum nights in a single stay. Guards against fat-fingered date
        // entry producing a five-year booking.
        'max_nights' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */
    'availability' => [
        // How far ahead the calendar and channel synchronisation publish.
        'horizon_days' => 730,

        // Turnaround time reserved between a checkout and the next check-in
        // when the property has no explicit preparation buffer.
        'default_preparation_hours' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        // Quotes older than this are recalculated rather than honoured, so a
        // stale price can never be booked.
        'quote_ttl_minutes' => 30,

        'max_rule_depth' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each integration category resolves an implementation through its own
    | interface. "mock" implementations are real, working local implementations
    | used for development and tests — they are never presented to the user as
    | a live connection to a third party.
    |
    */
    'providers' => [
        'payments' => env('PAYMENTS_DEFAULT_PROVIDER', 'mock'),
        'ai' => env('AI_DEFAULT_PROVIDER', 'echo'),
        'locks' => env('LOCKS_DEFAULT_PROVIDER', 'mock'),

        // How a message leaves when its conversation has no channel thread to
        // reply into. The email transport reports itself as not live whenever
        // the mailer cannot actually deliver, so this default never overstates
        // what happened to a guest's message.
        'messaging' => env('MESSAGING_DEFAULT_TRANSPORT', 'email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    */
    'channels' => [
        'sync_enabled' => env('CHANNELS_SYNC_ENABLED', true),
        'max_attempts' => env('CHANNELS_MAX_ATTEMPTS', 6),

        // Base delay in seconds; retries back off exponentially from here.
        'retry_base_delay' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'max_attempts' => env('WEBHOOKS_MAX_ATTEMPTS', 8),
        'timeout' => env('WEBHOOKS_TIMEOUT', 10),
        'retry_base_delay' => 15,
        'signature_header' => 'X-Habitat-Signature',
        'timestamp_header' => 'X-Habitat-Timestamp',
        'tolerance_seconds' => 300,

        // Whether an endpoint may point at a private or loopback address.
        // False everywhere that matters: a webhook URL is user-supplied and
        // fetched by our server, so without this it is a server-side request
        // forgery against the cloud metadata service and anything else bound
        // to the private network. True only for local development, where the
        // receiver under test is on localhost.
        'allow_local_endpoints' => env('WEBHOOKS_ALLOW_LOCAL_ENDPOINTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest portal
    |--------------------------------------------------------------------------
    */
    'guest_portal' => [
        // Guests access their reservation with an unguessable token rather than
        // an account. The token is valid from booking until after checkout.
        'token_bytes' => 32,
        'valid_days_after_checkout' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Named queues so that a burst of channel synchronisation cannot delay a
    | guest's booking confirmation email.
    |
    */
    'queues' => [
        'default' => 'default',
        'messaging' => 'messaging',
        'channels' => 'channels',
        'webhooks' => 'webhooks',
        'automation' => 'automation',
        'reports' => 'reports',
        'imports' => 'imports',
        'ai' => 'ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    */
    'currencies' => [
        'AED', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EGP', 'EUR',
        'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'MAD', 'MXN',
        'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'QAR', 'RON', 'SAR', 'SEK', 'SGD',
        'THB', 'TRY', 'USD', 'VND', 'ZAR',
    ],
];
