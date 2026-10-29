<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What paying on a booking website needs kept: the guest's Stripe customer
 * and saved card (ids only, never card details), why a payment last failed,
 * and which Stripe events have already been handled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->index();
            // The card saved for later instalments, as Stripe's id for it.
            $table->string('stripe_payment_method_id')->nullable();
        });

        Schema::table('website_checkouts', function (Blueprint $table): void {
            // The bank's own sentence for the last refusal, for the guest.
            $table->string('payment_error', 500)->nullable();
        });

        // A redelivered event is recognised here and does nothing twice.
        Schema::create('processed_provider_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 32);
            $table->string('event_id');
            $table->string('type', 100);
            $table->timestampTz('processed_at');

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_provider_events');

        Schema::table('website_checkouts', function (Blueprint $table): void {
            $table->dropColumn('payment_error');
        });

        Schema::table('guests', function (Blueprint $table): void {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_payment_method_id']);
        });
    }
};
