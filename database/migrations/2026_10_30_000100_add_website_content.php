<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's pages, blocks, theme and style, as one document kept twice: the
 * draft editors see and the published one guests see. And the photos a site
 * has of its own, beside its homes'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->jsonb('draft_content')->nullable();
            $table->jsonb('content')->nullable();
            // Counted on every save, so two editors never overwrite each
            // other without knowing.
            $table->unsignedInteger('draft_version')->default(0);
            $table->timestampTz('content_published_at')->nullable();
        });

        Schema::create('website_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('alt', 240)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_images');

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['draft_content', 'content', 'draft_version', 'content_published_at']);
        });
    }
};
