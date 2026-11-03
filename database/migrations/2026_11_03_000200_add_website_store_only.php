<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Store only": the site's pages at the platform's address send visitors to
 * the client's Shopify store, where its pieces are shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('store_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('store_only'));
    }
};
