<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret Habitat sends when it fires a property's webhook.
 *
 * Its own column rather than reuse of `agent_bot_token`, because they are
 * credentials for two different things. The bot token authenticates Habitat to a
 * bot that answers in the same breath; this one authenticates Habitat to whatever
 * starts the slow agent run — in the case this was built for, an automation
 * platform with its own sender key, issued separately and rotated separately.
 * Sharing one column would mean rotating either breaks both.
 *
 * Encrypted, like the bot token and for the same reason: `properties.settings` is
 * plain JSON and a bearer token is not something to leave in a database dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->text('agent_webhook_token')->nullable()->after('agent_bot_token');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('agent_webhook_token');
        });
    }
};
