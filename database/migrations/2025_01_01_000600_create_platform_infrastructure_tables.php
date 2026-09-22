<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum API tokens (admin SPA sessions use cookies; mobile apps and
        // the public API use bearer tokens).
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            // Sanctum's own model owns this table and expects an
            // auto-incrementing key; the tokenable side is a ULID because our
            // users are.
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        // The domain event store. Every meaningful business change is appended
        // here, and automation, webhooks, notifications and analytics all read
        // from this single stream rather than each hooking into the domain
        // services independently.
        Schema::create('domain_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 128);            // e.g. reservation.confirmed
            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();

            $table->jsonb('payload')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('system');

            // Deduplication key for events produced by external systems, so a
            // redelivered channel webhook does not raise the event twice.
            $table->string('idempotency_key', 191)->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['organization_id', 'name', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        // Generic idempotency ledger, used by the public API, payment provider
        // callbacks, channel webhooks and automation actions. A repeated
        // request with the same key returns the stored outcome instead of
        // performing the work twice.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('scope', 64);            // api, webhook:airbnb, payment:stripe, ...
            $table->string('key', 191);

            $table->string('status', 16)->default('in_progress'); // in_progress|completed|failed
            $table->string('request_fingerprint', 64)->nullable();

            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->jsonb('response')->nullable();

            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['scope', 'key']);
            $table->index(['organization_id', 'scope']);
            $table->index('expires_at');
        });

        // Generic tagging, available to guests, owners, reservations, properties
        // and tasks. Tags are organization-scoped and user defined.
        Schema::create('tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 16)->nullable();
            $table->string('taggable_type')->nullable(); // null = usable anywhere
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug', 'taggable_type']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUlid('tag_id')->constrained()->cascadeOnDelete();
            $table->string('taggable_type');
            $table->ulid('taggable_id');
            $table->timestampsTz();

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });

        // Custom fields let an organization extend core records without schema
        // changes. Values live in a separate table, one row per field, so they
        // remain queryable and typed rather than buried in a JSON blob.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type');          // Reservation, Property, Guest, ...
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 32);             // text, number, date, boolean, select, multiselect
            $table->jsonb('options')->nullable();   // choices for select types
            $table->boolean('is_required')->default(false);
            $table->boolean('show_in_list')->default(false);
            $table->integer('position')->default(0);
            $table->string('help_text')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'entity_type', 'key']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('custom_field_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type');
            $table->ulid('entity_id');

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->jsonb('value_json')->nullable();

            $table->timestampsTz();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('personal_access_tokens');
    }
};
