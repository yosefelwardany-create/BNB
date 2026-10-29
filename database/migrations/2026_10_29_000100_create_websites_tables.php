<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking websites: the platform owner's brand site and any client's own.
 *
 * Not tenant-scoped. A brand site shows properties from every client account,
 * so the site itself belongs to the platform; each listing row carries the
 * organization it belongs to, and every quote and booking is made in that
 * organization's own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The address: /sites/{slug}.
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            // brand: across client accounts; client: one account's own.
            $table->string('kind', 16);
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            // draft | live. Anything but live is not found to the public.
            $table->string('status', 16)->default('draft');
            // Look and contact details: colours, heading, logo and hero paths.
            $table->jsonb('settings')->nullable();
            $table->unsignedSmallInteger('hold_minutes')->default(30);
            $table->unsignedSmallInteger('request_hours')->default(24);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('website_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->unique(['website_id', 'listing_id']);
            $table->index(['website_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_listings');
        Schema::dropIfExists('websites');
    }
};
