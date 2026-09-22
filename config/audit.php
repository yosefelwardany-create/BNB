<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redacted attributes
    |--------------------------------------------------------------------------
    |
    | Attribute names matching any of these patterns are replaced with a
    | placeholder before an audit entry is written. The audit trail must record
    | *that* a credential changed without recording the credential itself.
    |
    */
    'redacted' => [
        'password',
        'password_*',
        '*_password',
        'token',
        '*_token',
        'token_*',
        'secret',
        '*_secret',
        'secret_*',
        'api_key',
        '*_api_key',
        'credentials',
        'mfa_*',
        'remember_token',
        'card_number',
        'cvv',
        'iban',
        'account_number',
        'routing_number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Audit entries are never deleted by the application. This value is used by
    | the archival command, which moves entries older than the window to cold
    | storage rather than dropping them.
    |
    */
    'retention_days' => env('AUDIT_RETENTION_DAYS', 2555), // ~7 years

    'login_history_retention_days' => env('LOGIN_HISTORY_RETENTION_DAYS', 400),
];
