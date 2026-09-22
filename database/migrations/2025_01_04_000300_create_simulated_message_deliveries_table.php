<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the local message transport recorded instead of sending.
 *
 * Separate from `messages` on purpose. `messages` is the business record of a
 * conversation — what was written, by whom, and what the platform believes
 * happened to it. This table is the transport's own log: the rendered body as
 * it would have left the building, kept so a developer or a demonstration can
 * read exactly what a guest would have received.
 *
 * Keeping them apart means the conversation never has to carry a "pretend
 * delivery" flag through the rest of the product; the message's status says
 * `sent`, the delivery result says `simulated`, and this is the evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulated_message_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 64)->unique();

            // Nullable and unconstrained: the template editor's test send has
            // no message row, and a delivery log must outlive the thread it
            // came from rather than cascade away with it.
            $table->ulid('organization_id')->nullable()->index();
            $table->ulid('message_id')->nullable()->index();

            $table->string('channel', 48)->nullable();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->text('body_html')->nullable();
            $table->string('language', 12)->nullable();
            $table->unsignedSmallInteger('attachments_count')->default(0);

            $table->timestampsTz();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_message_deliveries');
    }
};
