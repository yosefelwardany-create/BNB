<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel distribution.
 *
 * The platform is always the source of truth for availability, pricing and
 * reservations; a channel is a projection of that truth plus a source of
 * inbound bookings. Every table here follows from that: we record what we
 * believe, what we last told the channel, and where the two diverge.
 *
 * The divergence is the whole problem. Channels are eventually consistent at
 * best, they rate-limit, they reject silently, and they send bookings for
 * dates we closed an hour ago. So `channel_listings` carries both the state we
 * intend and the state we last successfully pushed, and `sync_jobs` records
 * every attempt — because "why is this flat still bookable on Airbnb?" is the
 * question this subsystem exists to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 48);                      // airbnb|booking_com|vrbo|ical|direct
            $table->string('name');

            // Credentials are encrypted at rest and never returned by the API.
            // Stored as a blob rather than named columns because each channel
            // needs a different set, and a column per channel would mean a
            // migration for every integration.
            $table->text('credentials')->nullable();
            $table->string('external_account_id', 191)->nullable();

            // connected|disconnected|error|pending
            $table->string('status', 24)->default('pending');
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->string('last_error')->nullable();

            // How this connection behaves, per account rather than per channel:
            // the same OTA is used differently by different customers.
            $table->boolean('sync_availability')->default(true);
            $table->boolean('sync_rates')->default(true);
            $table->boolean('import_reservations')->default(true);
            $table->boolean('export_reservations')->default(false);
            $table->boolean('sync_messages')->default(false);

            // The commission the channel keeps, used to compute what they will
            // actually remit. Basis points, so 1500 is 15%.
            $table->unsignedInteger('commission_basis_points')->default(0);

            // Whether the channel collects from the guest itself. This decides
            // whether a booking produces cash we hold or a receivable from the
            // channel, and getting it wrong misstates the bank balance.
            $table->boolean('collects_payment')->default(false);

            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_imported_at')->nullable();

            $table->string('webhook_secret')->nullable();
            $table->jsonb('settings')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'channel', 'external_account_id'], 'channel_accounts_external_unique');
            $table->index(['organization_id', 'channel', 'status']);
        });

        Schema::create('channel_listings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('unit_type_id')->nullable()->constrained()->nullOnDelete();

            $table->string('external_listing_id', 191);
            $table->string('external_name')->nullable();
            $table->string('external_url')->nullable();

            // unmapped|mapped|publishing|published|error|paused
            $table->string('status', 24)->default('mapped');
            $table->boolean('is_active')->default(true);

            // A per-listing markup, because the same room is often sold dearer
            // on a channel that charges the guest less visibly. Basis points.
            $table->integer('rate_adjustment_basis_points')->default(0);
            $table->unsignedInteger('commission_basis_points')->nullable();

            // What we last successfully told the channel, and when. Kept
            // separate from what we intend so the gap between the two is
            // visible rather than inferred — that gap is the reason a flat
            // stays bookable after we closed it.
            $table->timestampTz('availability_pushed_at')->nullable();
            $table->timestampTz('rates_pushed_at')->nullable();
            $table->string('availability_hash', 64)->nullable();
            $table->string('rates_hash', 64)->nullable();

            // Set when something changed locally and has not reached the
            // channel yet. The sync engine drains these.
            $table->boolean('availability_dirty')->default(false);
            $table->boolean('rates_dirty')->default(false);
            $table->date('dirty_from')->nullable();
            $table->date('dirty_to')->nullable();

            $table->timestampTz('last_error_at')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['channel_account_id', 'external_listing_id'],
                'channel_listings_external_unique',
            );

            // One mapping per listing per account: a listing published twice
            // to the same account would double-book itself.
            $table->unique(['channel_account_id', 'listing_id'], 'channel_listings_listing_unique');

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'availability_dirty']);
            $table->index(['organization_id', 'rates_dirty']);
        });

        /*
         * Every synchronisation attempt.
         *
         * Kept because a channel that silently rejected a price change looks
         * exactly like one that accepted it, until a guest books at the old
         * rate. The record of what we sent, when, and what came back is the
         * only way to tell the two apart afterwards.
         */
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('channel_listing_id')->nullable()->constrained()->nullOnDelete();

            // availability|rates|listing|reservations|reviews|messages|
            // connection_test
            $table->string('kind', 32);
            $table->string('direction', 16)->default('push');   // push|pull

            // pending|running|succeeded|failed|skipped
            $table->string('status', 16)->default('pending');

            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();

            $table->unsignedInteger('records_sent')->default(0);
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_failed')->default(0);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            // Whether another attempt could succeed. A rejected price is
            // permanent and needs a human; a timeout should back off and retry.
            $table->boolean('is_retryable')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();

            // Whether the adapter that ran this talks to a real system. Stored
            // per job because the answer can change between runs, and a report
            // of "synchronisation succeeded" must not conceal that nothing
            // left the building.
            $table->boolean('is_simulated')->default(false);

            $table->jsonb('payload_summary')->nullable();
            $table->jsonb('result')->nullable();

            // One job per logical operation. A retried queue message or a
            // redelivered webhook resolves to the same row.
            $table->string('idempotency_key', 191)->nullable();

            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'status', 'next_attempt_at']);
            $table->index(['organization_id', 'channel_account_id', 'created_at']);
            $table->index(['channel_listing_id', 'kind', 'created_at']);
        });

        // Reservations imported from a channel point back at the account they
        // came from, so a booking's origin survives the channel disconnecting.
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('channel_account_id')->references('id')->on('channel_accounts')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('channel_account_id')->references('id')->on('channel_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropForeign(['channel_account_id']));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropForeign(['channel_account_id']));
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('channel_listings');
        Schema::dropIfExists('channel_accounts');
    }
};
