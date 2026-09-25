<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    |
    | The key is what makes the Claude provider live. Without it the provider
    | reports itself as a simulation and every surface that shows a drafted
    | reply says so, rather than the feature quietly doing nothing.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),

        /*
         * Haiku is the default because the guest agent's work is short: a
         * classification and a three-sentence reply from a fixed set of facts.
         * Set ANTHROPIC_MODEL to a larger model where the wording matters more
         * than the bill, and re-run `php artisan agent:evaluate` — that is what
         * the eval suite is for, and the two numbers it prints are the only
         * honest basis for the trade.
         */
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),

        /*
         * US dollars per million tokens, as published in April 2026.
         *
         * A local copy, which means it can go stale. It is here rather than
         * hard-coded so `php artisan ai:check` can report what a call actually
         * cost — and a model that is not in this list is reported as "no price
         * on file" rather than priced with a guess, because a confidently wrong
         * figure on a cost report is worse than an absent one.
         *
         * `cache_write` is the surcharge for putting a prefix in the cache and
         * `cache_read` the discount for reading it back. Both are per million
         * tokens like the others.
         */
        'prices' => [
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
            'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        ],

        /*
         * The smallest prompt each model will cache, in tokens. Below it the
         * cache breakpoint is a silent no-op: `cache_write` comes back zero and
         * nothing is saved. Worth recording because the numbers are not
         * intuitive — Haiku's minimum is eight times Opus 5's — and because the
         * agent's facts block sits near the line for a sparsely filled property.
         */
        'cache_minimums' => [
            'claude-haiku-4-5' => 4096,
            'claude-sonnet-5' => 1024,
            'claude-opus-5' => 512,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
