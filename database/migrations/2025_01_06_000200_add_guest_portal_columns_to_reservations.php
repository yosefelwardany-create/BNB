<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest portal access, and what online check-in collects.
 *
 * A guest gets a link, not an account. Asking somebody to create a password to
 * look at a booking they have already paid for is friction that produces
 * support calls rather than security, and the link is only ever sent to the
 * address that made the booking.
 *
 * So the token has to carry the whole of the authorisation, which makes two
 * things load-bearing: it is long enough not to be guessable, and it expires.
 * A link that works forever is a link that still works when the guest forwards
 * their confirmation email to a holiday rental group chat two years later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Unguessable, and unique across the platform so a portal request
            // needs no tenant hint before it can be resolved.
            $table->string('portal_token', 64)->nullable();
            $table->timestampTz('portal_token_expires_at')->nullable();
            $table->timestampTz('portal_last_viewed_at')->nullable();

            // Online check-in. Separate from `checked_in_at`, which is the
            // operational fact that somebody arrived: a guest can complete
            // their details a week early, and that is not an arrival.
            $table->timestampTz('online_check_in_completed_at')->nullable();
            $table->string('estimated_arrival_time', 5)->nullable();   // HH:MM, local
            $table->string('estimated_departure_time', 5)->nullable();

            // What the guest told us, as they told us. Kept apart from the
            // guest profile so a correction on one is not silently a
            // correction on the other.
            $table->jsonb('check_in_details')->nullable();

            $table->unique('portal_token');
            $table->index(['organization_id', 'online_check_in_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropIndex(['organization_id', 'online_check_in_completed_at']);

            $table->dropColumn([
                'portal_token',
                'portal_token_expires_at',
                'portal_last_viewed_at',
                'online_check_in_completed_at',
                'estimated_arrival_time',
                'estimated_departure_time',
                'check_in_details',
            ]);
        });
    }
};
