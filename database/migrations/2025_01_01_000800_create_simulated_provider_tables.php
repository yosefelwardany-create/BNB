<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State for the bundled simulated providers.
 *
 * These implementations are real: they keep genuine state, enforce the same
 * invariants a processor or a lock vendor would (a capture cannot exceed its
 * authorization, a refund cannot exceed its capture, a code cannot outlive its
 * window), and they fail in the same shapes. They exist so the platform can be
 * developed, demonstrated and tested end to end before commercial credentials
 * are in place — and they are always labelled as simulated in the interface,
 * never presented as a live third-party connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulated_payment_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 64)->unique();
            $table->string('kind', 16);                  // authorization|capture|refund
            $table->string('status', 24);
            $table->string('parent_reference', 64)->nullable()->index();

            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('captured_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);

            $table->string('customer_reference')->nullable()->index();
            $table->string('instrument_token')->nullable();
            $table->string('instrument_last4', 4)->nullable();
            $table->string('instrument_brand', 24)->nullable();

            $table->string('idempotency_key', 191)->nullable();
            $table->string('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['kind', 'idempotency_key']);
        });

        Schema::create('simulated_locks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('connection_id', 64)->index();
            $table->string('external_lock_id', 64);
            $table->string('name');
            $table->string('model', 64)->nullable();
            $table->boolean('online')->default(true);
            $table->boolean('locked')->default(true);
            $table->unsignedSmallInteger('battery_percent')->default(100);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['connection_id', 'external_lock_id']);
        });

        Schema::create('simulated_access_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_lock_id', 64)->index();
            $table->string('external_code_id', 64)->unique();
            $table->string('code', 16);
            $table->string('label');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('reservation_reference', 64)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_access_codes');
        Schema::dropIfExists('simulated_locks');
        Schema::dropIfExists('simulated_payment_transactions');
    }
};
