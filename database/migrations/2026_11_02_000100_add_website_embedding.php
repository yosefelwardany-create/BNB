<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Showing a site's pieces on another website: the addresses allowed to
 * frame them, and each home's own page on that store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            // https origins only, normalised; the only sites that may frame
            // the site's pieces.
            $table->jsonb('embed_origins')->nullable();
        });

        Schema::table('website_listings', function (Blueprint $table): void {
            // The home's page on the client's store, where the store's grid
            // sends a guest instead of the pop-up.
            $table->string('store_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_listings', function (Blueprint $table): void {
            $table->dropColumn('store_url');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('embed_origins');
        });
    }
};
