<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest and owner records.
 *
 * Both are people the business has a continuing relationship with, so both are
 * first-class records rather than fields copied onto each booking. A guest who
 * returns three times is one profile with three stays, which is what makes
 * repeat-guest reporting, tagging and marketing consent workable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('secondary_phone', 32)->nullable();

            // Normalised copies used for duplicate detection and search. Kept
            // as columns rather than computed on the fly so they can be
            // indexed — a portfolio accumulates hundreds of thousands of
            // guests and a full scan per lookup is not viable.
            $table->string('email_normalised')->nullable();
            $table->string('phone_normalised', 32)->nullable();

            $table->char('country_code', 2)->nullable();
            $table->string('language', 12)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('company')->nullable();
            $table->string('vat_number', 64)->nullable();

            // Identity documents are encrypted; only the type and the last few
            // characters are ever shown.
            $table->string('document_type', 32)->nullable();
            $table->text('document_number')->nullable();
            $table->date('document_expiry')->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->timestampTz('verified_at')->nullable();

            // Consent is tracked with when and how it was given, because
            // "we have consent" is not a defensible answer on its own.
            $table->boolean('marketing_consent')->default(false);
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->string('marketing_consent_source', 64)->nullable();
            $table->jsonb('communication_preferences')->nullable();

            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();

            // Denormalised lifetime figures, maintained by the reservation
            // service inside the same transaction as the booking that changes
            // them. Derived from reservations; kept here so a guest list can
            // be sorted by value without aggregating on every request.
            $table->unsignedInteger('reservations_count')->default(0);
            $table->unsignedInteger('nights_count')->default(0);
            $table->bigInteger('lifetime_value')->default(0);
            $table->char('lifetime_value_currency', 3)->nullable();
            $table->date('first_stay_date')->nullable();
            $table->date('last_stay_date')->nullable();

            // Where the guest first came from.
            $table->string('source', 48)->nullable();
            $table->ulid('merged_into_id')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'email_normalised']);
            $table->index(['organization_id', 'phone_normalised']);
            $table->index(['organization_id', 'last_name', 'first_name']);
            $table->index(['organization_id', 'last_stay_date']);
            $table->index('merged_into_id');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('guests')->nullOnDelete();
        });

        // Trigram indexes make fuzzy name and email search fast enough to use
        // as you type across a large guest list.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX guests_name_trgm ON guests USING gin ((coalesce(first_name,\'\') || \' \' || coalesce(last_name,\'\')) gin_trgm_ops)');
        DB::statement('CREATE INDEX guests_email_trgm ON guests USING gin (coalesce(email,\'\') gin_trgm_ops)');

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('guest_id')->references('id')->on('guests')->nullOnDelete();
        });

        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->foreign('guest_id')->references('id')->on('guests')->nullOnDelete();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreign('guest_id')->references('id')->on('guests')->nullOnDelete();
        });

        // ---------------------------------------------------------------
        // Owners
        // ---------------------------------------------------------------

        Schema::create('owners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('type', 16)->default('individual');   // individual|company
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('display_name');

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('language', 12)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();

            $table->string('tax_identifier', 64)->nullable();
            $table->string('vat_number', 64)->nullable();

            // The currency the owner is paid in. May differ from the
            // property's; conversions are recorded with their rate.
            $table->char('payout_currency', 3)->nullable();

            // Bank details are encrypted at rest and never returned by the API
            // in full.
            $table->string('payout_method', 32)->nullable();     // bank_transfer|cheque|manual
            $table->text('bank_account_name')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('bank_routing_number')->nullable();
            $table->text('bank_iban')->nullable();
            $table->text('bank_swift')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_country', 2)->nullable();

            // How often statements are produced for this owner.
            $table->string('statement_frequency', 16)->default('monthly');
            $table->unsignedSmallInteger('statement_day')->default(1);

            // A reserve the manager holds back for expenses before paying out.
            $table->bigInteger('reserve_amount')->default(0);

            $table->string('status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();

            // Portal access, when the owner has been given a login.
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('portal_enabled')->default(false);
            $table->jsonb('portal_permissions')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'display_name']);
            $table->index(['organization_id', 'email']);
        });

        /*
         * Ownership of a property, with a share and a period.
         *
         * Fractional and joint ownership are ordinary cases in this industry,
         * and ownership changes hands mid-year, so the relationship carries
         * both a percentage and a date range. Owner statements attribute
         * revenue by the shares in force on the night in question.
         */
        Schema::create('property_ownerships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained()->cascadeOnDelete();

            $table->decimal('ownership_percentage', 7, 4)->default(100);
            $table->boolean('is_primary')->default(false);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'property_id']);
            $table->index(['organization_id', 'owner_id']);
        });

        /*
         * The commercial agreement between the manager and an owner.
         *
         * Everything that decides what an owner is paid lives here, versioned
         * by date, so a statement can always be reproduced against the terms
         * that were in force for the period it covers.
         */
        Schema::create('management_agreements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('reference', 64)->nullable();

            // percent_of_revenue|percent_of_net|fixed_monthly|fixed_per_booking|
            // per_night|tiered
            $table->string('commission_model', 32)->default('percent_of_revenue');
            $table->decimal('commission_rate', 7, 4)->nullable();
            $table->bigInteger('commission_amount')->nullable();
            $table->jsonb('commission_tiers')->nullable();
            $table->char('currency', 3);

            // What the commission is charged on. Whether channel commission
            // and cleaning fees come out before the management fee is the
            // single most disputed line on an owner statement, so each is an
            // explicit setting rather than an assumption.
            $table->boolean('commission_on_accommodation')->default(true);
            $table->boolean('commission_on_fees')->default(false);
            $table->boolean('commission_on_taxes')->default(false);
            $table->boolean('deduct_channel_commission_first')->default(true);
            $table->boolean('deduct_payment_fees_first')->default(true);

            // Who bears which costs.
            $table->boolean('owner_pays_cleaning')->default(false);
            $table->boolean('owner_pays_maintenance')->default(true);
            $table->boolean('owner_pays_supplies')->default(true);
            $table->decimal('maintenance_markup_percent', 7, 4)->default(0);
            $table->bigInteger('maintenance_approval_threshold')->nullable();

            $table->unsignedSmallInteger('owner_stay_nights_included')->default(0);
            $table->boolean('charge_cleaning_for_owner_stays')->default(true);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();

            $table->string('status', 24)->default('active');  // draft|active|expired|terminated
            $table->text('terms')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['organization_id', 'owner_id', 'status']);
            $table->index(['organization_id', 'property_id', 'status']);
        });

        Schema::table('calendar_blocks', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_blocks', fn (Blueprint $t) => $t->dropForeign(['owner_id']));
        Schema::dropIfExists('management_agreements');
        Schema::dropIfExists('property_ownerships');
        Schema::dropIfExists('owners');

        Schema::table('quotes', fn (Blueprint $t) => $t->dropForeign(['guest_id']));
        Schema::table('reservation_guests', fn (Blueprint $t) => $t->dropForeign(['guest_id']));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropForeign(['guest_id']));

        Schema::dropIfExists('guests');
    }
};
