<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret a property's own bot authenticates with.
 *
 * A column rather than a key in `properties.settings`, which is where the rest of
 * the agent's brief lives. Settings is plain JSON: readable in a database dump,
 * in a backup, in a log line that prints a model, and by anything that can read
 * the row. A bearer token for somebody's bot is a credential, and the platform
 * already has one place credentials go — an encrypted column, as `wifi_password`
 * and `door_code` are.
 *
 * Text rather than a fixed length: the ciphertext of a short token is not short,
 * and nobody should have to guess how long an operator's token is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->text('agent_bot_token')->nullable()->after('access_notes');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('agent_bot_token');
        });
    }
};
