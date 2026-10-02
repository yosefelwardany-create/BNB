<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for an encrypted secret, which is not the length of the secret.
 *
 * `channel_accounts.webhook_secret` was `varchar(255)` and the column is cast
 * `encrypted`. A 48-character secret does not store as 48 characters: Laravel's
 * ciphertext is base64-encoded JSON carrying an initialisation vector and a MAC
 * alongside the value, which lands past 300 characters and overflows the column.
 *
 * Nothing had hit it because nothing wrote this column until now — the webhook
 * machinery was only added with the first channel that actually sends webhooks,
 * and the first secret issued would have been a 500.
 *
 * `text`, for the same reason `properties.agent_bot_token` is: the ciphertext of
 * a short secret is not short, and nobody should have to work out in advance how
 * long an encrypted string is going to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Not reversed.
         *
         * Narrowing the column back would truncate every stored ciphertext to
         * 255 characters, and a truncated ciphertext does not fail to decrypt
         * loudly — it throws on read, which would take out the webhook endpoint
         * for every channel at once. The column staying wide costs nothing.
         */
    }
};
