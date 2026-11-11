<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each change still to be pushed touched: its nights and, for rates, the
 * rules it set. A channel that wants only what changed (Channex) is sent
 * exactly that; one window per mapping could only say "somewhere in here".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->jsonb('dirty_ranges')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_listings', fn (Blueprint $table) => $table->dropColumn('dirty_ranges'));
    }
};
