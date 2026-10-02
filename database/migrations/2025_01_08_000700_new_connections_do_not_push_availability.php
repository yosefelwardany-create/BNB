<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new channel connection imports before it ever pushes.
 *
 * `sync_availability` and `sync_rates` defaulted to true, which is the wrong
 * default and the expensive kind of wrong.
 *
 * ## Why this is not a preference
 *
 * A channel manager becomes the source of truth for availability the moment it
 * is linked. A brand-new connection has an empty calendar — every night open,
 * no blocks — and pushing that is not "syncing", it is publishing the sentence
 * *everything is available* over a calendar where it is not true.
 *
 * Hostex documents this behaviour for its own link step: dates closed by hand on
 * the OTA before the connection existed are not imported, and the OTA is then
 * updated to match Hostex. The nights come back open, they sell, and the first
 * anybody knows is two parties at one door. This platform sits one layer further
 * out and would do exactly the same thing to Hostex — which would pass it
 * straight on to Airbnb.
 *
 * The asymmetry is the whole argument. A connection that imports and does not
 * push is merely incomplete: somebody notices within a day that their rates are
 * not going out, and nothing is lost. A connection that pushes an empty calendar
 * costs real bookings, real guests and a review that cannot be deleted. When one
 * direction of a mistake is recoverable and the other is not, the default
 * belongs on the recoverable side.
 *
 * So pushing is now something an operator turns on, per connection, after they
 * have seen the imported calendar and agreed it is right. Existing rows keep
 * whatever they were set to: a column default applies to new rows, and a
 * connection somebody is already relying on must not stop pushing because of a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->boolean('sync_availability')->default(false)->change();
            $table->boolean('sync_rates')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->boolean('sync_availability')->default(true)->change();
            $table->boolean('sync_rates')->default(true)->change();
        });
    }
};
