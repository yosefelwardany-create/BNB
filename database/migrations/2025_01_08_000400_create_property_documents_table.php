<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property's knowledge base, fetched and kept.
 *
 * The link on the property card was only a link: a person could open it, and the
 * agent could not. An agent that cannot read the house manual answers from ten
 * public facts while the answer sits in a document nobody gave it.
 *
 * So the text is fetched and stored. Three things about that are deliberate.
 *
 * **It is a copy with a timestamp, not a source of truth.** The document is
 * maintained wherever its authors maintain it. This is a snapshot, and the row
 * says when it was taken — because an agent quoting a check-in time that
 * changed last Tuesday is worse than one that says it does not know.
 *
 * **Guest-safe is opt in, and off.** House manuals contain door codes. The whole
 * entitlement design is that arrival details reach only a guest with a
 * confirmed, paid booking inside its window; a document pasted wholesale into a
 * guest's prompt would walk straight around it. So by default this reaches the
 * operator's agent only, and showing it to guests is a decision somebody makes
 * per document, having been told what is in it.
 *
 * **A failure is a state, not an absence.** "We have never fetched this" and
 * "we fetched it and Google said no" are different, and an operator can only
 * act on the second if the row says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            // knowledge_base | faq | house_manual — what this document is for,
            // so an agent can be told which is which rather than being handed
            // one undifferentiated wall of text.
            $table->string('kind', 32)->default('knowledge_base');
            $table->string('title')->nullable();
            $table->string('url', 500);

            /*
             * The text, as fetched.
             *
             * Capped by the fetcher rather than by the column: a column limit
             * truncates mid-sentence with no record that it happened, and the
             * agent then answers from half a paragraph believing it has the lot.
             */
            $table->longText('content')->nullable();
            $table->unsignedInteger('content_bytes')->default(0);
            $table->boolean('was_truncated')->default(false);

            // Lets a refresh say "unchanged" without rewriting the row, and
            // tells an operator whether their edit actually landed.
            $table->string('content_hash', 64)->nullable();

            /*
             * Off by default, and the most consequential column here.
             *
             * See the class note: a house manual with a door code in it,
             * handed to every guest who writes in, defeats the entitlement
             * gate entirely.
             */
            $table->boolean('is_guest_safe')->default(false);

            // pending | ok | unreachable | forbidden | empty | refused
            $table->string('status', 24)->default('pending');
            $table->text('failure')->nullable();

            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestamps();

            // One document per purpose per property. A second knowledge base is
            // two answers to the same question.
            $table->unique(['property_id', 'kind']);
            $table->index(['organization_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_documents');
    }
};
