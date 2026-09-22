<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews, upsells, documents and door access.
 *
 * Four tables that look unrelated and share one property: each of them is a
 * record about a guest that outlives the stay, and each has a rule about what
 * may be changed afterwards.
 *
 * A review cannot be edited by us — it is what the guest said. An upsell order
 * cannot be silently repriced once accepted. A document has a retention date
 * because holding a passport scan forever is a liability, not a feature. An
 * access code has to be revocable and has to record whether it was ever really
 * issued to a lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What guests said, and what we said back.
         *
         * Reviews arrive from channels and cannot be edited here: changing a
         * guest's words would be fabrication, and the public copy on the
         * channel would disagree anyway. Only the response is ours.
         *
         * Ratings are stored as integers on the channel's own scale plus a
         * normalised percentage, because Airbnb rates out of 5 and Booking.com
         * out of 10, and averaging them raw produces a number that means
         * nothing.
         */
        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('guest_id')->nullable();

            // guest_to_host | host_to_guest. Both are reviews and both matter:
            // the second is what other operators see about this guest.
            $table->string('direction', 16)->default('guest_to_host');

            $table->string('source', 48)->default('direct');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();

            $table->unsignedSmallInteger('rating')->nullable();
            $table->unsignedSmallInteger('rating_scale')->default(5);

            // The rating as a percentage of its own scale, so scores from
            // channels that rate out of 5 and out of 10 can be compared.
            $table->unsignedSmallInteger('rating_percent')->nullable();

            // Per-category scores exactly as the channel supplied them.
            $table->jsonb('category_ratings')->nullable();

            $table->string('title')->nullable();
            $table->text('public_comment')->nullable();
            $table->text('private_comment')->nullable();

            $table->text('response')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUlid('responded_by_id')->nullable()->constrained('users')->nullOnDelete();

            // pending|published|responded|hidden. `hidden` is ours alone: it
            // suppresses the review in our interface and changes nothing on
            // the channel, which is the only honest thing it could mean.
            $table->string('status', 16)->default('published');

            $table->timestampTz('submitted_at')->nullable();
            $table->date('stay_date')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            // The same channel review must never be imported twice.
            $table->unique(['organization_id', 'source', 'external_id'], 'reviews_external_unique');
            $table->index(['organization_id', 'property_id', 'submitted_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'direction', 'rating_percent']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reviews
            ADD CONSTRAINT reviews_rating_within_scale
            CHECK (rating IS NULL OR (rating >= 0 AND rating <= rating_scale))
        SQL);

        /*
         * Things a guest can buy on top of the stay.
         */
        Schema::create('upsell_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 48);
            $table->text('description')->nullable();

            // early_check_in|late_check_out|extra_cleaning|transfer|
            // experience|equipment|other
            $table->string('kind', 32)->default('other');

            $table->bigInteger('price');
            $table->char('currency', 3);

            // per_stay|per_night|per_guest|per_unit
            $table->string('charge_basis', 24)->default('per_stay');

            $table->boolean('is_taxable')->default(true);

            // Null means every property.
            $table->jsonb('property_ids')->nullable();

            // How far ahead it must be requested, and how many can exist at
            // once. An early check-in ordered an hour before arrival is a
            // promise the housekeeping team cannot keep.
            $table->unsignedSmallInteger('lead_time_hours')->default(0);
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->unsignedInteger('daily_capacity')->nullable();

            // Whether accepting it is automatic or somebody has to agree.
            $table->boolean('requires_approval')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->string('image_path')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'kind']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE upsell_products
            ADD CONSTRAINT upsell_products_price_non_negative
            CHECK (price >= 0)
        SQL);

        /*
         * What a guest actually ordered.
         *
         * The price is copied onto the order rather than read from the product
         * at fulfilment time. A guest who ordered a transfer at 40 pays 40,
         * whatever the product costs by the time somebody drives them.
         */
        Schema::create('upsell_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('upsell_product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();
            $table->ulid('reservation_charge_id')->nullable();

            $table->string('reference', 32);

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('total_price');
            $table->char('currency', 3);

            // requested|approved|declined|fulfilled|cancelled|refunded
            $table->string('status', 16)->default('requested');

            $table->date('service_date')->nullable();
            $table->text('guest_notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestampTz('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('declined_at')->nullable();
            $table->string('declined_reason')->nullable();
            $table->timestampTz('fulfilled_at')->nullable();

            $table->ulid('task_id')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'service_date']);
            $table->index(['organization_id', 'reservation_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE upsell_orders
            ADD CONSTRAINT upsell_orders_amounts_non_negative
            CHECK (unit_price >= 0 AND total_price >= 0 AND quantity > 0)
        SQL);

        Schema::table('upsell_orders', function (Blueprint $table) {
            $table->foreign('reservation_charge_id')->references('id')->on('reservation_charges')->nullOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });

        /*
         * Files attached to anything.
         *
         * `retention_until` is the column that matters. A passport scan
         * collected for a legal check-in requirement has a lawful period and
         * then becomes a liability; a system with no expiry date holds it
         * forever by default, which is the wrong default.
         */
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('documentable_type')->nullable();
            $table->ulid('documentable_id')->nullable();

            $table->string('name');
            $table->string('kind', 48)->default('other');   // contract|id|receipt|photo|certificate|other
            $table->text('description')->nullable();

            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();

            // Whether a guest or owner may see it through their portal.
            $table->boolean('is_guest_visible')->default(false);
            $table->boolean('is_owner_visible')->default(false);

            // Personal data that must not be kept indefinitely.
            $table->boolean('contains_personal_data')->default(false);
            $table->date('retention_until')->nullable();

            $table->date('expires_on')->nullable();   // an insurance certificate, a licence

            $table->foreignUlid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'documentable_type', 'documentable_id'], 'documents_owner_index');
            $table->index(['organization_id', 'kind']);
            $table->index(['organization_id', 'retention_until']);
        });

        /*
         * Door locks, and the codes issued to them.
         */
        Schema::create('smart_locks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('provider', 48);

            // The provider's own identifiers. `connection_id` groups the locks
            // belonging to one account with that vendor; `external_lock_id`
            // names the individual lock. Both come from the provider contract,
            // which every adapter — real or simulated — speaks.
            $table->string('connection_id', 64)->nullable();
            $table->string('external_lock_id', 64)->nullable();

            // Where the lock is: the front door, the building entrance, a
            // parking barrier. A stay usually needs codes for more than one.
            $table->string('location', 64)->default('entrance');

            $table->string('status', 16)->default('active');  // active|offline|inactive
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            // Whether this connection talks to a real lock. Travels with the
            // record so an access code cannot be reported as issued when it
            // was only simulated.
            $table->boolean('is_simulated')->default(false);

            $table->jsonb('settings')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'provider', 'external_lock_id'], 'smart_locks_external_unique');
            $table->index(['organization_id', 'property_id']);
        });

        Schema::create('access_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('smart_lock_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->cascadeOnDelete();

            // Encrypted: a code is a key, and a database disclosure should not
            // be a set of keys to every door in the portfolio.
            $table->text('code');
            $table->string('code_last4', 8)->nullable();

            // The provider's id for this code, needed to revoke it later.
            $table->string('external_code_id', 64)->nullable();

            $table->string('purpose', 24)->default('guest');  // guest|cleaner|maintenance|owner|master

            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');

            // pending|active|expired|revoked|failed
            $table->string('status', 16)->default('pending');

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->string('last_error')->nullable();

            // True when the code exists only here and was never sent to a real
            // lock. The single most important flag in this table: a guest sent
            // a code that no door knows about is a guest standing outside.
            $table->boolean('is_simulated')->default(false);

            $table->timestampTz('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'reservation_id']);
            $table->index(['organization_id', 'status', 'valid_until']);
            $table->index(['smart_lock_id', 'valid_from', 'valid_until']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access_codes
            ADD CONSTRAINT access_codes_window_ordered
            CHECK (valid_until > valid_from)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access_codes');
        Schema::dropIfExists('smart_locks');
        Schema::dropIfExists('documents');

        Schema::table('upsell_orders', function (Blueprint $table) {
            $table->dropForeign(['reservation_charge_id']);
            $table->dropForeign(['task_id']);
        });

        Schema::dropIfExists('upsell_orders');
        Schema::dropIfExists('upsell_products');
        Schema::dropIfExists('reviews');
    }
};
