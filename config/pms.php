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
    /*
    |--------------------------------------------------------------------------
    | What a newly registered company gets
    |--------------------------------------------------------------------------
    |
    | No plan means no caps and every feature, because a null plan resolves to
    | unlimited throughout — see Organization::allows() and effectiveLimits().
    | That is the current policy: sign up and the whole product is yours.
    |
    | `status` and `trial_days` only decide what the interface *says*. A trial
    | that expires is displayed and never enforced, so leaving a 30-day clock
    | running on an account with unlimited access was telling people their access
    | was about to end when it was not.
    |
    | When there is something to sell, set `status` to `trial`, give
    | `trial_days` a number, and assign a plan on registration. This block is the
    | only thing that has to change.
    |
    */
    'registration' => [
        'status' => env('REGISTRATION_STATUS', 'active'),
        'trial_days' => env('REGISTRATION_TRIAL_DAYS') === null
            ? null
            : (int) env('REGISTRATION_TRIAL_DAYS'),
    ],

    /*
     * The per-property bots, where an operator runs one for each flat.
     *
     * `allow_insecure` exists for local development and nothing else. With it on,
     * a bot endpoint may be plain http and may resolve to a private address —
     * which in production would hand every tenant a way to make this server read
     * its own metadata service back to them. It defaults off and no request can
     * turn it on.
     */
    'agents' => [
        'bot' => [
            'timeout' => (int) env('AGENT_BOT_TIMEOUT', 20),
            'allow_insecure' => (bool) env('AGENT_BOT_ALLOW_INSECURE', false),
        ],

        /*
         * Asking a bot that answers later.
         *
         * `timeout` is how long Habitat waits for the webhook to *accept* the
         * question, not to answer it — accepting should take a moment, and
         * anything that does not is more likely to be down than thinking.
         *
         * `window_minutes` is how long the callback token stays good. Long
         * enough for a real agent run, short enough that a key to a write is not
         * left lying about because somebody turned their bot off.
         */
        'webhook' => [
            'timeout' => (int) env('AGENT_WEBHOOK_TIMEOUT', 10),
            'window_minutes' => (int) env('AGENT_WEBHOOK_WINDOW_MINUTES', 30),
        ],
    ],

    'providers' => [
        'payments' => env('PAYMENTS_DEFAULT_PROVIDER', 'mock'),
        'ai' => env('AI_DEFAULT_PROVIDER', 'echo'),
        'locks' => env('LOCKS_DEFAULT_PROVIDER', 'mock'),
        'exchange_rates' => env('EXCHANGE_RATE_PROVIDER', 'stored'),
        'identity' => env('IDENTITY_VERIFIER', 'local'),

        // How a message leaves when its conversation has no channel thread to
        // reply into. The email transport reports itself as not live whenever
        // the mailer cannot actually deliver, so this default never overstates
        // what happened to a guest's message.
        'messaging' => env('MESSAGING_DEFAULT_TRANSPORT', 'email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart locks
    |--------------------------------------------------------------------------
    |
    | How far either side of the stay a guest's code works. Padded at both ends
    | on purpose: a guest whose taxi arrived early should not be standing
    | outside until three o'clock exactly, and a departing guest needs the door
    | to still open while they carry their bags down.
    |
    */
    'locks' => [
        'early_access_minutes' => env('LOCKS_EARLY_ACCESS_MINUTES', 60),
        'late_access_minutes' => env('LOCKS_LATE_ACCESS_MINUTES', 60),

        // How far ahead codes are programmed. Too early wastes a lock's finite
        // code slots; too late risks the lock being offline when it matters.
        'issue_days_ahead' => env('LOCKS_ISSUE_DAYS_AHEAD', 2),
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
    | The admin application
    |--------------------------------------------------------------------------
    |
    | Where the built SPA's entry point lives. The Vite build writes here, and
    | the web server serves it directly; the path is configurable so a
    | deployment that puts the bundle elsewhere does not have to patch code.
    |
    */
    'admin' => [
        'index_path' => env('ADMIN_INDEX_PATH', public_path('app/index.html')),
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
