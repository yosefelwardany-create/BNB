<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The physical estate.
 *
 * Hierarchy: Organization → Portfolio → Complex → Property → Unit.
 * Only Property is mandatory. A standalone villa is one property with no
 * portfolio, no complex and no units; an aparthotel is a complex containing
 * many properties, each with many units of a few unit types.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A grouping used for reporting and for assigning responsibility.
        // Often mirrors an owner, a city or a management contract.
        Schema::create('portfolios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'slug']);
        });

        // A building or development containing several properties. Carries the
        // shared address so the properties inside it do not each repeat it.
        Schema::create('complexes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('floors')->nullable();
            $table->jsonb('shared_amenities')->nullable();
            $table->jsonb('settings')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'portfolio_id']);
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('portfolio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('complex_id')->nullable()->constrained()->nullOnDelete();

            // `name` is what guests see; `internal_name` is what staff use on
            // rotas and reports (e.g. "B2 — 14 Marine Parade").
            $table->string('name');
            $table->string('internal_name')->nullable();
            $table->string('slug');
            $table->string('reference', 32)->nullable();

            $table->string('property_type', 48);       // apartment, villa, hotel_room, ...
            $table->string('rental_kind', 24)->default('entire_place'); // entire_place|private_room|shared_room
            $table->string('status', 24)->default('draft');             // draft|active|inactive|archived

            // A property whose inventory is several interchangeable units
            // (an aparthotel floor, a block of identical studios). Drives
            // whether availability is counted per unit or per property.
            $table->boolean('is_multi_unit')->default(false);

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('neighbourhood')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Every property keeps its own timezone and currency. Scheduled
            // work and cut-off times are always evaluated against these, never
            // against the server clock or the organization default.
            $table->string('timezone', 64);
            $table->char('currency', 3);

            // Capacity
            $table->unsignedSmallInteger('bedrooms')->default(0);
            $table->decimal('bathrooms', 4, 1)->default(0);
            $table->unsignedSmallInteger('beds')->default(0);
            $table->unsignedSmallInteger('max_occupancy')->default(1);
            $table->unsignedSmallInteger('max_adults')->nullable();
            $table->unsignedSmallInteger('max_children')->nullable();
            $table->unsignedSmallInteger('max_infants')->nullable();
            $table->unsignedSmallInteger('max_pets')->default(0);
            $table->unsignedInteger('size_value')->nullable();
            $table->string('size_unit', 8)->nullable();   // sqm|sqft
            $table->string('floor', 16)->nullable();

            // Content
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->text('space_description')->nullable();
            $table->text('neighbourhood_description')->nullable();
            $table->text('transit_description')->nullable();
            $table->text('house_rules')->nullable();
            $table->text('check_in_instructions')->nullable();
            $table->text('check_out_instructions')->nullable();
            $table->text('internal_notes')->nullable();

            // Arrival
            $table->time('check_in_time')->default('15:00');
            $table->time('check_out_time')->default('11:00');
            $table->time('check_in_until')->nullable();
            $table->string('check_in_method', 48)->nullable();  // smart_lock, lockbox, host_greeting, ...

            // Access details are credentials and are stored encrypted.
            $table->text('wifi_network')->nullable();
            $table->text('wifi_password')->nullable();
            $table->text('door_code')->nullable();
            $table->text('access_notes')->nullable();

            // Commercial defaults. The pricing engine layers rules on top of
            // these; they are the floor, not the whole answer.
            $table->bigInteger('base_rate')->default(0);
            $table->bigInteger('cleaning_fee')->default(0);
            $table->bigInteger('security_deposit')->default(0);
            $table->bigInteger('extra_guest_fee')->default(0);
            $table->unsignedSmallInteger('extra_guest_after')->nullable();
            $table->unsignedSmallInteger('minimum_nights')->default(1);
            $table->unsignedSmallInteger('maximum_nights')->nullable();

            // Operational
            $table->unsignedSmallInteger('cleaning_duration_minutes')->default(120);
            $table->unsignedSmallInteger('preparation_hours')->default(0);
            $table->foreignUlid('cancellation_policy_id')->nullable();

            $table->boolean('instant_book')->default(false);
            $table->jsonb('settings')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'portfolio_id']);
            $table->index(['organization_id', 'complex_id']);
            $table->index(['organization_id', 'city']);
            $table->index(['latitude', 'longitude']);
        });

        // Unit types describe interchangeable inventory: "One-bedroom sea
        // view". Guests book a type; a specific unit is assigned later.
        Schema::create('unit_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('bedrooms')->default(0);
            $table->decimal('bathrooms', 4, 1)->default(0);
            $table->unsignedSmallInteger('beds')->default(0);
            $table->unsignedSmallInteger('max_occupancy')->default(1);
            $table->unsignedInteger('size_value')->nullable();

            $table->bigInteger('base_rate')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['property_id', 'code']);
            $table->index(['organization_id', 'property_id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_type_id')->nullable()->constrained()->nullOnDelete();

            // Parent/child models a unit that can be sold whole or split
            // (a two-bedroom flat also sold as two lockable rooms). Booking a
            // parent blocks its children and vice versa.
            $table->ulid('parent_unit_id')->nullable();

            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('floor', 16)->nullable();
            $table->string('status', 24)->default('available'); // available|out_of_service|maintenance|renovation

            // Null means "inherit from the unit type, then the property".
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->unsignedSmallInteger('beds')->nullable();
            $table->unsignedSmallInteger('max_occupancy')->nullable();
            $table->bigInteger('base_rate')->nullable();

            $table->text('access_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->jsonb('settings')->nullable();

            $table->boolean('is_bookable')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['property_id', 'code']);
            $table->index(['organization_id', 'property_id', 'status']);
            $table->index('parent_unit_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreign('parent_unit_id')->references('id')->on('units')->nullOnDelete();
        });

        // Amenities are a shared catalogue so that channel mapping and search
        // can compare like with like. Rows with a null organization_id are the
        // platform catalogue; organizations may add their own.
        Schema::create('amenities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('category', 48);
            $table->string('icon', 48)->nullable();
            $table->boolean('is_highlight')->default(false);
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'key']);
            $table->index('category');
        });

        DB::statement('CREATE UNIQUE INDEX amenities_platform_key_unique ON amenities (key) WHERE organization_id IS NULL');

        Schema::create('amenity_property', function (Blueprint $table) {
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('amenity_id')->constrained()->cascadeOnDelete();
            $table->string('value')->nullable();   // e.g. "2" for parking spaces
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->primary(['property_id', 'amenity_id']);
            $table->index('amenity_id');
        });

        // Photos belong to the property; listings may reorder or hide them but
        // the file itself is stored once.
        Schema::create('property_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('caption')->nullable();
            $table->string('room_type', 48)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_cover')->default(false);

            $table->timestampsTz();

            $table->index(['organization_id', 'property_id', 'position']);
        });

        // Sleeping arrangements, which channels require in structured form.
        Schema::create('property_rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('room_type', 32);   // bedroom|living_room|other
            $table->integer('position')->default(0);

            // [{"type": "king", "count": 1}, {"type": "sofa_bed", "count": 1}]
            $table->jsonb('beds')->nullable();
            $table->boolean('has_ensuite')->default(false);
            $table->timestampsTz();

            $table->index(['organization_id', 'property_id']);
        });

        // Cancellation policies are named, reusable and versioned by their
        // tiers, so a reservation can always be evaluated against the policy
        // that was in force when it was taken.
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Free cancellation up to this many days before arrival.
            $table->unsignedSmallInteger('free_cancellation_days')->default(0);

            // Tiers: [{"days_before": 7, "refund_percent": 50}, ...]
            // Evaluated from the largest days_before downwards.
            $table->jsonb('tiers');

            $table->boolean('refund_cleaning_fee')->default(true);
            $table->boolean('refund_taxes')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreign('cancellation_policy_id')
                ->references('id')->on('cancellation_policies')->nullOnDelete();
        });

        // Restricts a staff member to named properties. Referenced by
        // AccessControl when building a member's visible estate.
        Schema::create('membership_property', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['membership_id', 'property_id']);
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_property');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['cancellation_policy_id']);
        });

        Schema::dropIfExists('cancellation_policies');
        Schema::dropIfExists('property_rooms');
        Schema::dropIfExists('property_photos');
        Schema::dropIfExists('amenity_property');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('units');
        Schema::dropIfExists('unit_types');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('complexes');
        Schema::dropIfExists('portfolios');
    }
};
