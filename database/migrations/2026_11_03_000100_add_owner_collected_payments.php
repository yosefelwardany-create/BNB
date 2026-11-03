<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a client received themselves, through their own Shopify store. What
 * their statements have taken off is read from the statements' own lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // The client holds it: an owner statement takes it off what they
            // are paid.
            $table->boolean('is_collected_by_owner')->default(false);
        });

        Schema::table('owner_statements', function (Blueprint $table): void {
            $table->bigInteger('owner_collected')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('owner_statements', fn (Blueprint $table) => $table->dropColumn('owner_collected'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('is_collected_by_owner'));
    }
};
