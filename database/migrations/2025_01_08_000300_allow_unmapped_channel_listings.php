<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a channel listing exist before anybody has said what it is.
 *
 * `channel_listings.listing_id` was NOT NULL, which quietly made one thing
 * impossible: discovering what a channel already has. A row could only be
 * written once somebody had decided which of our listings it was, so there was
 * nowhere to put "Hostex has six properties and here they are, which is which?"
 * — and `importListings()` sat on the adapter interface with no caller,
 * because the schema could not hold its result.
 *
 * Discovered-but-unmapped is a real and useful state. It is the list somebody
 * works through, and leaving it unrepresentable forced the alternative: guess a
 * mapping at import time. A mapping decides which calendar a booking lands on,
 * so a guess there double-books one property and empties another, and nobody
 * finds out until a guest is at the door.
 *
 * The indexes need no change, which is worth stating because it looks as though
 * they would. `(channel_account_id, listing_id)` stops one listing being mapped
 * twice on a connection and still does: PostgreSQL treats nulls as distinct, so
 * it simply stops constraining the unmapped rows — correct, since any number of
 * them may be waiting to be sorted out. The other direction,
 * `(channel_account_id, external_listing_id)`, is what the importer looks a row
 * up by and what keeps a repeated import from duplicating it, and that index was
 * already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->nullable()->change();
        });

    }

    public function down(): void
    {
        /*
         * Rows with no mapping cannot survive a column that forbids them.
         *
         * Deleted rather than guessed at: an unmapped row is a question nobody
         * answered, and inventing an answer on the way down would attach a
         * channel listing to a property at random. Nothing points at these rows
         * — a reservation is attached to the listing it was imported against,
         * and an unmapped row has never imported one.
         */
        DB::table('channel_listings')->whereNull('listing_id')->delete();

        Schema::table('channel_listings', function (Blueprint $table): void {
            $table->foreignUlid('listing_id')->nullable(false)->change();
        });
    }
};
