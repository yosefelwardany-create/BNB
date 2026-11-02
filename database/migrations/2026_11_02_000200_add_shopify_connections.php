<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking site's bookings paid on a client's own Shopify checkout: the
 * store the site is connected to, and the one-off Shopify orders made for
 * its stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_shopify_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('shop_domain', 255);
            $table->string('shop_name', 255)->nullable();
            $table->char('currency', 3)->nullable();
            // The store's own addresses, such as its custom domain.
            $table->jsonb('domains')->nullable();
            // Encrypted. Never returned by any API.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('access_token_expires_at')->nullable();
            $table->string('scopes', 255)->nullable();
            $table->string('status', 16);
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('broken_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index('shop_domain');
        });

        Schema::create('website_shopify_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_checkout_id')->constrained()->cascadeOnDelete();
            // What it is for: the stay (in full or its deposit), or a balance.
            $table->string('purpose', 16);
            $table->string('draft_order_id', 120)->unique();
            $table->string('draft_order_name', 60)->nullable();
            $table->string('invoice_url', 1000);
            $table->string('order_id', 120)->nullable()->unique();
            $table->string('order_name', 60)->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->index(['website_checkout_id', 'purpose', 'status']);
        });

        Schema::table('website_checkouts', function (Blueprint $table): void {
            // Where the guest pays: the platform's Stripe, or the client's
            // Shopify checkout. Fixed when the checkout starts.
            $table->string('payment_channel', 16)->default('stripe');
        });
    }

    public function down(): void
    {
        Schema::table('website_checkouts', function (Blueprint $table): void {
            $table->dropColumn('payment_channel');
        });

        Schema::dropIfExists('website_shopify_orders');
        Schema::dropIfExists('website_shopify_connections');
    }
};
