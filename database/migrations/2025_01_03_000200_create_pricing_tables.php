<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing, taxes and fees.
 *
 * The design goal is that every price the platform quotes can be explained
 * line by line: which rule fired, in what order, on what base, and what it
 * changed. That is why rules are rows with explicit conditions and priorities
 * rather than code, and why nothing is stored as an opaque "final price".
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A rate plan is a named commercial product: "Standard", "Non-
         * refundable", "Weekly". It groups the rules that apply and carries
         * the terms attached to buying at that price.
         */
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->char('currency', 3);

            // A plan can apply everywhere, to a portfolio, or to one property.
            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->cascadeOnDelete();

            // Derived plans price relative to their parent: "Non-refundable is
            // the standard rate less 10%". Keeping the relationship explicit
            // means a change to the parent flows through automatically.
            $table->ulid('parent_rate_plan_id')->nullable();
            $table->string('derivation_type', 16)->nullable();   // percent|fixed
            $table->decimal('derivation_value', 12, 4)->nullable();

            $table->foreignUlid('cancellation_policy_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('minimum_nights')->nullable();
            $table->unsignedSmallInteger('maximum_nights')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->foreign('parent_rate_plan_id')->references('id')->on('rate_plans')->nullOnDelete();
        });

        /*
         * A pricing rule is one deterministic adjustment.
         *
         * Rules are applied in priority order, each operating on the result of
         * the previous one, and each records what it did into the night's
         * pricing trace. The `conditions` document is evaluated by
         * PricingRuleEvaluator, which supports only a fixed, safe vocabulary —
         * there is no expression language and nothing is ever eval'd.
         */
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // base_rate|seasonal|day_of_week|length_of_stay|occupancy|
            // early_booking|last_minute|gap_night|orphan_night|custom
            $table->string('kind', 32);

            // Scope: null columns mean "applies to everything at this level".
            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('rate_plan_id')->nullable()->constrained()->cascadeOnDelete();

            // Which channels this rule applies to; null means all of them.
            $table->jsonb('channels')->nullable();

            // When the rule itself is in force (not the stay dates).
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // Which stay dates it prices.
            $table->date('stay_from')->nullable();
            $table->date('stay_to')->nullable();

            // 0 = Sunday ... 6 = Saturday; null means every day.
            $table->jsonb('days_of_week')->nullable();

            $table->jsonb('conditions')->nullable();

            // set|increase_percent|decrease_percent|increase_fixed|decrease_fixed
            $table->string('adjustment_type', 24);
            $table->bigInteger('adjustment_value');

            // Guard rails so a mistaken percentage cannot sell a villa for
            // nothing or price it out of the market.
            $table->bigInteger('floor_rate')->nullable();
            $table->bigInteger('ceiling_rate')->nullable();

            // Lower numbers run first.
            $table->integer('priority')->default(100);

            // Stop evaluating further rules once this one matches.
            $table->boolean('is_exclusive')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'is_active', 'priority']);
            $table->index(['organization_id', 'property_id']);
            $table->index(['organization_id', 'kind']);
            $table->index(['stay_from', 'stay_to']);
        });

        /*
         * Promotions are guest-facing discounts, with a code or automatic.
         */
        Schema::create('promotions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 64)->nullable();     // null = applied automatically
            $table->text('description')->nullable();

            $table->string('discount_type', 16);        // percent|fixed|free_nights
            $table->decimal('discount_value', 12, 4);
            $table->char('currency', 3)->nullable();    // required for fixed

            $table->date('bookable_from')->nullable();
            $table->date('bookable_to')->nullable();
            $table->date('stay_from')->nullable();
            $table->date('stay_to')->nullable();

            $table->unsignedSmallInteger('minimum_nights')->nullable();
            $table->bigInteger('minimum_spend')->nullable();
            $table->unsignedInteger('maximum_uses')->nullable();
            $table->unsignedInteger('maximum_uses_per_guest')->nullable();
            $table->unsignedInteger('times_used')->default(0);

            $table->jsonb('property_ids')->nullable();  // null = all properties
            $table->jsonb('channels')->nullable();      // null = all channels

            $table->boolean('combinable')->default(false);
            $table->boolean('applies_to_fees')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * Fees: cleaning, pet, extra guest, resort, linen and anything else a
         * property charges on top of the nightly rate.
         */
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();

            // cleaning|pet|extra_guest|resort|linen|parking|service|custom
            $table->string('kind', 32)->default('custom');

            // per_stay|per_night|per_guest|per_guest_per_night|per_pet|
            // per_pet_per_night|percent_of_accommodation
            $table->string('charge_basis', 32);

            $table->bigInteger('amount');
            $table->decimal('percentage', 8, 4)->nullable();
            $table->char('currency', 3);

            // Thresholds: an extra-guest fee applies only above N guests.
            $table->unsignedSmallInteger('applies_after_guests')->nullable();
            $table->unsignedSmallInteger('applies_after_nights')->nullable();
            $table->unsignedSmallInteger('maximum_units')->nullable();

            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->cascadeOnDelete();
            $table->jsonb('channels')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_refundable')->default(true);
            $table->boolean('is_optional')->default(false);
            $table->boolean('include_in_displayed_rate')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);

            // Which ledger account the fee's revenue lands in.
            $table->string('revenue_account_key', 64)->nullable();

            $table->timestampsTz();

            $table->unique(['organization_id', 'code', 'property_id']);
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * Taxes.
         *
         * Lodging tax is jurisdiction-specific and frequently layered (a city
         * tax per guest per night on top of a percentage VAT), so the model
         * has to express both shapes and their interaction — notably whether
         * one tax is charged on a base that already includes another.
         */
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();

            // percent|fixed_per_stay|fixed_per_night|fixed_per_guest|
            // fixed_per_guest_per_night
            $table->string('calculation', 32);

            $table->decimal('rate', 10, 6)->nullable();  // for percent
            $table->bigInteger('amount')->nullable();    // for fixed
            $table->char('currency', 3)->nullable();

            // What the tax is charged on.
            $table->boolean('applies_to_accommodation')->default(true);
            $table->boolean('applies_to_fees')->default(false);
            $table->jsonb('applies_to_fee_codes')->nullable();

            // Whether taxes applied earlier form part of this tax's base.
            $table->boolean('compounds_on_taxes')->default(false);

            // Caps and exemptions seen in real lodging tax law.
            $table->unsignedSmallInteger('maximum_nights')->nullable();
            $table->unsignedSmallInteger('exempt_after_nights')->nullable();
            $table->unsignedSmallInteger('exempt_guest_age_under')->nullable();
            $table->bigInteger('maximum_amount')->nullable();

            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();

            // Channels that collect and remit this tax themselves. When a
            // booking arrives from one of these, the tax is recorded as
            // channel-collected rather than owed by the manager.
            $table->jsonb('channels_collecting')->nullable();
            $table->jsonb('channels')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);

            $table->string('remittance_reference', 120)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'code', 'property_id']);
            $table->index(['organization_id', 'is_active', 'priority']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreign('rate_plan_id')->references('id')->on('rate_plans')->nullOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('rate_plan_id')->references('id')->on('rate_plans')->nullOnDelete();
        });

        Schema::table('reservation_charges', function (Blueprint $table) {
            $table->foreign('tax_rule_id')->references('id')->on('tax_rules')->nullOnDelete();
            $table->foreign('fee_rule_id')->references('id')->on('fee_rules')->nullOnDelete();
            $table->foreign('promotion_id')->references('id')->on('promotions')->nullOnDelete();
        });

        /*
         * Quotes: a price held for a guest while they decide.
         *
         * Stored rather than recomputed so that what was quoted is what gets
         * booked, and so an expired quote is re-priced explicitly instead of
         * a stale price silently going through.
         */
        Schema::create('quotes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->ulid('guest_id')->nullable();

            $table->string('reference', 32);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('infants')->default(0);
            $table->unsignedSmallInteger('pets')->default(0);

            $table->char('currency', 3);
            $table->bigInteger('accommodation_total')->default(0);
            $table->bigInteger('fees_total')->default(0);
            $table->bigInteger('taxes_total')->default(0);
            $table->bigInteger('discounts_total')->default(0);
            $table->bigInteger('grand_total')->default(0);

            // The full explainable breakdown, exactly as quoted.
            $table->jsonb('breakdown');

            $table->string('channel', 48)->default('direct');
            $table->foreignUlid('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('rate_plan_id')->nullable()->constrained()->nullOnDelete();

            $table->timestampTz('expires_at');
            $table->ulid('converted_reservation_id')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');

        Schema::table('reservation_charges', function (Blueprint $table) {
            $table->dropForeign(['tax_rule_id']);
            $table->dropForeign(['fee_rule_id']);
            $table->dropForeign(['promotion_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['rate_plan_id']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['rate_plan_id']);
        });

        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('fee_rules');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('rate_plans');
    }
};
