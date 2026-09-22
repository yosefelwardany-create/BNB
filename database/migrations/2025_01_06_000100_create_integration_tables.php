<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's outward-facing edges: API keys and outbound webhooks.
 *
 * Both are credential-bearing, and both store a *hash* rather than the secret
 * itself. An API key is shown once at creation and never again; a webhook's
 * signing secret is encrypted, because unlike an API key it has to be readable
 * by the sender to compute the signature. Neither is ever returned by the API.
 *
 * Deliveries are recorded as their own rows rather than as a counter on the
 * endpoint. "It didn't arrive" is the most common integration complaint, and
 * answering it needs the request body, the response, the status and the time —
 * per attempt, because the attempt that failed is the interesting one.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Keys for the public API.
         *
         * The key itself is never stored: only a SHA-256 hash of it, so a
         * database disclosure does not hand anybody a working credential. The
         * prefix is stored in clear purely so a key can be identified in a
         * list ("pk_live_8fA2…") without being usable.
         */
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Identifies the key without being it. Indexed, because every
            // authenticated request looks a key up by this.
            $table->string('prefix', 16);
            $table->string('token_hash', 64);

            // Which permissions requests made with this key carry. A key is
            // never more powerful than the permissions listed here, whatever
            // the user who created it can do.
            $table->jsonb('abilities')->nullable();

            // Optional network restriction. Empty means any address.
            $table->jsonb('allowed_ips')->nullable();

            $table->unsignedInteger('rate_limit_per_minute')->default(120);

            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->unsignedBigInteger('request_count')->default(0);

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique('token_hash');
            $table->index(['organization_id', 'revoked_at']);
            $table->index('prefix');
        });

        /*
         * Where domain events are sent.
         *
         * `events` lists the event names this endpoint wants. An empty list
         * means everything, which is a deliberate choice: an integrator who
         * forgets to subscribe gets too much rather than silence, and silence
         * is the failure mode nobody notices.
         */
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('url', 500);

            $table->jsonb('events')->nullable();

            // Encrypted, not hashed: the signature has to be computed from it
            // on every send, so it must be recoverable. Never returned by the
            // API after creation.
            $table->text('signing_secret');

            $table->string('status', 16)->default('active');   // active|paused|disabled

            // Consecutive failures, and the point at which the endpoint is
            // switched off. An endpoint that has been gone for a week should
            // stop consuming queue capacity.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('failure_threshold')->default(20);

            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->string('last_error')->nullable();

            // Extra headers the receiver asked for — a tenant identifier, a
            // gateway token. Encrypted, because they frequently carry one.
            $table->text('headers')->nullable();

            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('max_attempts')->default(6);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
        });

        /*
         * One row per attempt.
         *
         * Per attempt, not per event: the attempt that failed is the one an
         * integrator needs to see, and collapsing them into a single row with
         * a counter loses exactly the response body that explains why.
         */
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('webhook_endpoint_id')->constrained()->cascadeOnDelete();

            $table->ulid('domain_event_id')->nullable();
            $table->string('event_name', 128);

            // The exact bytes that were sent, so a signature dispute can be
            // settled. Stored rather than regenerated: regenerating it would
            // reflect the record as it stands now, not as it was sent.
            $table->jsonb('payload');

            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 16)->default('pending'); // pending|succeeded|failed|abandoned

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_message')->nullable();

            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();

            $table->timestampsTz();

            $table->index(['organization_id', 'webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['organization_id', 'event_name']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreign('domain_event_id')->references('id')->on('domain_events')->nullOnDelete();
        });

        // One delivery row per endpoint, event and attempt. This is what makes
        // a retried job harmless: a redelivery that races with the original
        // hits the constraint instead of sending the webhook twice.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX webhook_deliveries_attempt_unique
            ON webhook_deliveries (webhook_endpoint_id, domain_event_id, attempt)
            WHERE domain_event_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dropForeign(['domain_event_id']));
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
    }
};
