<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a property stopped being inventory.
 *
 * `activated_at` has always recorded the day a property went on the market.
 * Nothing recorded the day it came off, so every occupancy figure was computed
 * against "the properties that are active right now, for every night in the
 * period" — which is right for a portfolio that did not change and wrong in
 * both directions the moment it did:
 *
 *  - A property onboarded on the 20th counted as available for the whole
 *    month, depressing occupancy for nineteen nights it did not own.
 *  - A property archived last week vanished from last year's denominator
 *    entirely, flattering every historical occupancy figure that included it.
 *
 * The second is the worse of the two, because it changes numbers somebody has
 * already read and acted on.
 *
 * Deliberately not backfilled. A property archived before this migration has
 * no record of when, and `updated_at` is whenever anything about it last
 * changed — a plausible date is worse than an absent one here, because a
 * denominator nobody can check is a denominator nobody should trust. Those
 * properties keep the behaviour they have always had (excluded from
 * availability), and everything archived from now on is counted for the
 * nights it really owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->timestampTz('retired_at')->nullable()->after('activated_at');

            // Availability is read by date range across the whole portfolio.
            $table->index(['organization_id', 'activated_at', 'retired_at'], 'properties_inventory_window_index');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropIndex('properties_inventory_window_index');
            $table->dropColumn('retired_at');
        });
    }
};
