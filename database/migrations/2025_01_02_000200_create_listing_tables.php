<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listings: the sellable product, distinct from the physical property.
 *
 * A property is a thing that exists; a listing is an offer to sell nights in
 * it. The two are separate because one property routinely carries several
 * offers — a whole-flat listing and two private-room listings, or a
 * long-stay listing with different minimum stays and rates — and because a
 * listing is what a channel maps to.
 *
 *     Property ──< Listing ──< ChannelListing ──> external channel
 *
 * Listing content defaults to the property's content. A listing only stores a
 * field when it deliberately overrides it, so correcting a typo in the
 * property description fixes every listing that has not overridden it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            // A listing sells either the whole property, one unit type
            // (interchangeable inventory) or one specific unit.
            $table->foreignUlid('unit_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');                 // internal label
            $table->string('slug');
            $table->string('status', 24)->default('draft'); // draft|published|paused|archived

            // The listing every channel maps to unless told otherwise, and the
            // one the booking engine shows.
            $table->boolean('is_primary')->default(false);

            // --- Content overrides (null = inherit from the property) ------
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->text('space_description')->nullable();
            $table->text('neighbourhood_description')->nullable();
            $table->text('transit_description')->nullable();
            $table->text('house_rules')->nullable();
            $table->text('check_in_instructions')->nullable();
            $table->text('check_out_instructions')->nullable();

            $table->unsignedSmallInteger('max_occupancy')->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->unsignedSmallInteger('beds')->nullable();

            // --- Commercial ------------------------------------------------
            $table->char('currency', 3);
            $table->bigInteger('base_rate')->nullable();
            $table->bigInteger('cleaning_fee')->nullable();
            $table->bigInteger('extra_guest_fee')->nullable();
            $table->unsignedSmallInteger('extra_guest_after')->nullable();
            $table->unsignedSmallInteger('minimum_nights')->nullable();
            $table->unsignedSmallInteger('maximum_nights')->nullable();
            $table->unsignedSmallInteger('advance_notice_hours')->nullable();
            $table->unsignedSmallInteger('booking_window_days')->nullable();

            $table->foreignUlid('cancellation_policy_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('rate_plan_id')->nullable();

            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();

            $table->boolean('instant_book')->nullable();
            $table->jsonb('settings')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'property_id']);
            $table->index('unit_type_id');
            $table->index('unit_id');
        });

        // Photo selection and ordering per listing. A listing with no rows
        // here shows the property's photos in the property's order.
        Schema::create('listing_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_photo_id')->constrained()->cascadeOnDelete();

            $table->string('caption')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->timestampsTz();

            $table->unique(['listing_id', 'property_photo_id']);
        });

        // Amenity overrides: a listing for one private room does not inherit
        // the whole property's amenity set unchanged.
        Schema::create('amenity_listing', function (Blueprint $table) {
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('amenity_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_excluded')->default(false);
            $table->string('value')->nullable();
            $table->timestampsTz();

            $table->primary(['listing_id', 'amenity_id']);
            $table->index('amenity_id');
        });

        // An append-only record of listing content changes.
        //
        // Channels reject or re-review content changes, and an operator needs
        // to know exactly what was published and when. Storing a full snapshot
        // rather than a diff means a version can be restored directly.
        Schema::create('listing_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->jsonb('changed_fields')->nullable();
            $table->string('reason')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['listing_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_versions');
        Schema::dropIfExists('amenity_listing');
        Schema::dropIfExists('listing_photos');
        Schema::dropIfExists('listings');
    }
};
