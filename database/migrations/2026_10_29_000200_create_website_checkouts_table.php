<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to book on a website, and which site a booking came
 * from.
 *
 * A checkout lives in the listing's organization like the booking it holds,
 * but is read without a signed-in user: by its secret link, and by the
 * scheduler that gives back nights nobody paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_checkouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The guest's secret link: /sites/{site}/booking/{access_token}.
            $table->string('access_token', 48)->unique();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('quote_id')->nullable()->constrained()->nullOnDelete();
            // instant | request
            $table->string('mode', 16);
            // Name, email, phone, message, when the policies were accepted.
            $table->jsonb('guest');
            $table->char('currency', 3);
            $table->bigInteger('total_amount');
            $table->bigInteger('due_now_amount');
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_customer_id')->nullable();
            // holding | paid | requested | confirmed | declined | expired | refunded | failed
            $table->string('status', 16)->default('holding');
            $table->timestampTz('hold_expires_at');
            // A keyed hash of the visitor's address and browser, never either.
            $table->string('client_fingerprint', 64);
            $table->timestamps();

            $table->index(['status', 'hold_expires_at']);
            $table->index(['website_id', 'client_fingerprint', 'status']);
        });

        Schema::table('reservations', function (Blueprint $table): void {
            // For reports: which site a direct booking came from.
            $table->foreignUlid('website_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('website_id');
        });

        Schema::dropIfExists('website_checkouts');
    }
};
