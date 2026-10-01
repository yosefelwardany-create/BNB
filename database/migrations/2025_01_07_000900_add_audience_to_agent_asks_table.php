<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an ask was asked on behalf of.
 *
 * The agent was built to answer guests, and the facts it is given are the ones a
 * guest may be told: the address, the check-in window, the house rules, a door
 * code where the booking earns one. An owner asking how the flat performed last
 * month gets an agent holding none of the figures — and a model with no facts
 * and a direct question is a model that invents an occupancy rate.
 *
 * So an ask now records its audience, and the audience decides which body of
 * facts is assembled. Defaulting to `guest` keeps every existing row meaning
 * exactly what it meant when it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_asks', function (Blueprint $table): void {
            $table->string('audience', 16)->default('guest')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_asks', function (Blueprint $table): void {
            $table->dropColumn('audience');
        });
    }
};
