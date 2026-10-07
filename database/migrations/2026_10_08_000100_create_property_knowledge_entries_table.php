<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The property's own knowledge base: facts the team keeps in Habitat itself.
 *
 * The fetched documents are copies of something maintained elsewhere, and the
 * agent cannot write to them. Saved memory is private to one manager. Neither
 * holds "the cleaner is Ana, +57 300 …" where every colleague and every chat
 * finds it, so a manager who told the agent had nowhere for it to go.
 *
 * One row per topic, so a correction replaces the old fact instead of sitting
 * beside it: two cleaners' numbers for one flat is worse than none. Staff only;
 * nothing here reaches a guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_knowledge_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            $table->string('topic', 120);
            // The topic folded for matching, so "Cleaner" and "cleaner " are
            // the same entry and saving one updates it.
            $table->string('topic_key', 120);
            $table->text('content');

            // agent | manual: whether it was written through the chat or by
            // hand on the screen.
            $table->string('source', 16)->default('manual');
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'topic_key']);
            $table->index(['organization_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_knowledge_entries');
    }
};
