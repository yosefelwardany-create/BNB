<?php

declare(strict_types=1);

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use Illuminate\Database\Migrations\Migration;

/**
 * Channex connections made so far were made for certification, against
 * staging, which is where their keys work. A connection that does not say now
 * talks to production, so these are made to say staging.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChannelAccount::query()->withoutGlobalScopes()->where('channel', 'channex')->each(function (ChannelAccount $account): void {
            $credentials = $account->credentials ?? [];

            if (isset($credentials['base_url'])) {
                return;
            }

            $account->forceFill(['credentials' => $credentials + ['base_url' => ChannexChannelAdapter::SERVERS['staging']]])->saveQuietly();
        });
    }

    public function down(): void
    {
        // Left as they are: without it they would talk to production with a
        // staging key.
    }
};
