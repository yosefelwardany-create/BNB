<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reservation engine.
 *
 * Design decisions that the rest of the product depends on:
 *
 *  - Stay dates are `date` columns, not timestamps. A stay occupies calendar
 *    nights in the property's local timezone; storing an instant would make
 *    "which nights are sold" depend on the server's clock.
 *  - `check_out_date` is exclusive, matching how every channel and every
 *    hotelier counts: 3rd → 5th is two nights.
 *  - Every amount is derived from `reservation_nights` and
 *    `reservation_charges`. The totals on `reservations` are a maintained cache
 *    for querying, recomputed inside the same transaction as the lines that
 *    produce them — never the source of truth.
 *  - A reservation is never deleted. Cancelling is a status change, so the
 *    financial and operational history survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('confirmation_code', 32);

            $table->foreignUlid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('unit_type_id')->nullable()->constrained()->nullOnDelete();

            // Null while a stay is only assigned to a unit *type*; filled in
            // when a specific unit is allocated.
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->ulid('guest_id')->nullable();

            $table->string('status', 24)->default('inquiry');

            // Where the booking came from. `source` is the channel key
            // ('direct', 'airbnb', ...) so channel mix reporting needs no
            // special case for direct bookings.
            $table->string('source', 48)->default('direct');
            $table->ulid('channel_account_id')->nullable();
            $table->string('external_reservation_id', 128)->nullable();
            $table->string('external_confirmation_code', 64)->nullable();

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights');

            // Actual arrival/departure times when they differ from the
            // property default (early check-in, late checkout).
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();

            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('infants')->default(0);
            $table->unsignedSmallInteger('pets')->default(0);

            $table->char('currency', 3);

            // --- Maintained totals (derived; see class docblock) -----------
            $table->bigInteger('accommodation_total')->default(0);
            $table->bigInteger('fees_total')->default(0);
            $table->bigInteger('taxes_total')->default(0);
            $table->bigInteger('discounts_total')->default(0);
            $table->bigInteger('upsells_total')->default(0);
            $table->bigInteger('grand_total')->default(0);

            // What the channel keeps and what it will remit.
            $table->bigInteger('channel_commission')->default(0);
            $table->bigInteger('expected_payout')->default(0);

            $table->bigInteger('paid_total')->default(0);
            $table->bigInteger('refunded_total')->default(0);
            $table->bigInteger('balance_due')->default(0);
            $table->bigInteger('security_deposit')->default(0);

            // Reporting currency mirror, captured at the rate in force when
            // the booking was taken. The transaction amounts above are never
            // overwritten by a later rate change.
            $table->char('base_currency', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->bigInteger('base_grand_total')->default(0);

            $table->foreignUlid('rate_plan_id')->nullable();
            $table->foreignUlid('cancellation_policy_id')->nullable()
                ->constrained('cancellation_policies')->nullOnDelete();

            // Snapshot of the policy as it stood when the booking was taken, so
            // a later edit to the policy cannot change what this guest agreed
            // to.
            $table->jsonb('cancellation_policy_snapshot')->nullable();

            $table->text('guest_notes')->nullable();      // special requests from the guest
            $table->text('internal_notes')->nullable();

            $table->timestampTz('booked_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('cancelled_by', 32)->nullable(); // guest|host|channel|system
            $table->bigInteger('cancellation_refund')->default(0);

            // A tentative hold releases its nights automatically when this
            // passes, so an abandoned checkout cannot sit on inventory.
            $table->timestampTz('hold_expires_at')->nullable();

            $table->jsonb('source_metadata')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'confirmation_code']);

            // The same external reservation must never be imported twice.
            $table->unique(['organization_id', 'source', 'external_reservation_id'], 'reservations_external_unique');

            $table->index(['organization_id', 'status', 'check_in_date']);
            $table->index(['organization_id', 'check_in_date', 'check_out_date']);
            $table->index(['organization_id', 'check_out_date']);
            $table->index(['property_id', 'check_in_date', 'check_out_date']);
            $table->index(['unit_id', 'check_in_date', 'check_out_date']);
            $table->index(['organization_id', 'guest_id']);
            $table->index(['organization_id', 'source']);
            $table->index('hold_expires_at');
        });

        // A stay always occupies at least one night, and never ends before it
        // begins. Enforced in the database because every other guarantee in
        // the availability engine assumes it.
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_dates_ordered
            CHECK (check_out_date > check_in_date)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_nights_match
            CHECK (nights = (check_out_date - check_in_date))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_refund_within_paid
            CHECK (refunded_total <= paid_total)
        SQL);

        /*
         * The per-night breakdown.
         *
         * This is what makes revenue recognition, ADR, RevPAR, occupancy and
         * channel rate pushes all derive from the same numbers rather than
         * each recomputing a nightly rate from a total.
         */
        Schema::create('reservation_nights', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();

            $table->date('stay_date');
            $table->bigInteger('rate_amount');
            $table->char('currency', 3);

            // The unit occupied on this night. Recorded per night because a
            // long stay can legitimately move between units mid-stay.
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();

            // How the rate was arrived at, so any price can be explained.
            $table->jsonb('pricing_trace')->nullable();

            // Revenue is earned night by night; this marks the night as posted
            // to the ledger so it cannot be recognised twice.
            $table->timestampTz('recognised_at')->nullable();

            $table->timestampsTz();

            $table->unique(['reservation_id', 'stay_date']);
            $table->index(['organization_id', 'stay_date']);
            $table->index(['unit_id', 'stay_date']);
        });

        /*
         * Every non-accommodation line: fees, taxes, discounts, upsells,
         * damage charges and manual adjustments.
         */
        Schema::create('reservation_charges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();

            // fee|tax|discount|upsell|damage|adjustment|commission|deposit
            $table->string('kind', 24);
            $table->string('code', 64)->nullable();
            $table->string('label');
            $table->text('description')->nullable();

            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');            // quantity x unit_amount, signed
            $table->char('currency', 3);

            // Whether this line is itself subject to tax, and which rule
            // produced it. Both are needed to explain a tax calculation.
            $table->boolean('is_taxable')->default(false);
            $table->ulid('tax_rule_id')->nullable();
            $table->ulid('fee_rule_id')->nullable();
            $table->ulid('promotion_id')->nullable();
            $table->ulid('upsell_order_id')->nullable();

            // Whether the amount comes back to the guest on cancellation.
            $table->boolean('is_refundable')->default(true);

            // system = produced by the pricing engine; manual = added by a
            // person; channel = arrived with an imported booking.
            $table->string('origin', 16)->default('system');

            $table->jsonb('calculation_trace')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'reservation_id', 'kind']);
            $table->index(['organization_id', 'kind']);
        });

        // Additional named guests on the booking, where a channel or a
        // building's access control requires them.
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();
            $table->ulid('guest_id')->nullable();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('guest_type', 16)->default('adult'); // adult|child|infant
            $table->date('date_of_birth')->nullable();
            $table->string('document_type', 32)->nullable();
            $table->text('document_number')->nullable();        // encrypted
            $table->char('nationality', 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['organization_id', 'reservation_id']);
        });

        /*
         * Every status transition, with who made it and why.
         *
         * Kept separate from the generic audit log because reservation state
         * is queried constantly — "how long between booking and confirmation",
         * "who cancelled this" — and should not require scanning an audit
         * table that holds every change in the system.
         */
        Schema::create('reservation_status_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 255)->nullable();
            $table->string('actor_type', 24)->default('user'); // user|guest|channel|system
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('context')->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['organization_id', 'reservation_id', 'created_at']);
            $table->index(['organization_id', 'to_status', 'created_at']);
        });

        /*
         * Anything that occupies inventory without being a reservation: owner
         * stays, maintenance closures, manual blocks and blocks imported from
         * an external calendar.
         */
        Schema::create('calendar_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();

            // owner_stay|maintenance|cleaning|manual|external|hold|renovation
            $table->string('kind', 24);
            $table->date('start_date');
            $table->date('end_date');       // exclusive, as with reservations

            $table->string('title')->nullable();
            $table->text('notes')->nullable();

            // Owner stays are attributed so owner statements can show them.
            $table->ulid('owner_id')->nullable();
            $table->ulid('task_id')->nullable();

            // Blocks imported from an external calendar carry its identifier
            // so a re-import updates rather than duplicates.
            $table->string('source', 48)->default('manual');
            $table->string('external_id', 191)->nullable();
            $table->ulid('channel_account_id')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'property_id', 'start_date', 'end_date']);
            $table->index(['unit_id', 'start_date', 'end_date']);
            $table->index(['organization_id', 'kind']);
            $table->unique(['organization_id', 'source', 'external_id'], 'calendar_blocks_external_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE calendar_blocks
            ADD CONSTRAINT calendar_blocks_dates_ordered
            CHECK (end_date > start_date)
        SQL);

        /*
         * Per-listing, per-date overrides: the calendar an operator edits and
         * the platform publishes to channels.
         *
         * A row exists only where something differs from the defaults, so an
         * untouched two-year horizon costs nothing.
         */
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();

            $table->date('calendar_date');

            // Null means "no override": the pricing engine and the default
            // restrictions apply.
            $table->bigInteger('rate_override')->nullable();
            $table->unsignedSmallInteger('minimum_nights')->nullable();
            $table->unsignedSmallInteger('maximum_nights')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);

            // A manual stop-sell, distinct from being sold out.
            $table->boolean('is_blocked')->default(false);
            $table->string('note', 255)->nullable();

            $table->foreignUlid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['listing_id', 'calendar_date']);
            $table->index(['organization_id', 'calendar_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_days');
        Schema::dropIfExists('calendar_blocks');
        Schema::dropIfExists('reservation_status_changes');
        Schema::dropIfExists('reservation_guests');
        Schema::dropIfExists('reservation_charges');
        Schema::dropIfExists('reservation_nights');
        Schema::dropIfExists('reservations');
    }
};
