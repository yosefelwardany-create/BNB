<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something an agent proposes to do, and what became of it.
 *
 * A row rather than a function call, because most of these wait. An agent asked
 * to block a weekend produces a proposal; a person reads it and approves it;
 * only then does anything reach the channel. Without somewhere to put the
 * proposal there is no waiting, and without waiting every action is autonomous
 * whatever the settings say.
 *
 * It is also the record. "The agent blocked those nights" is a sentence somebody
 * will want evidence for three months later — who asked, what was proposed, who
 * approved it, what the channel said back.
 *
 * Nothing here is ever rewritten. A proposal that was rejected stays rejected
 * and a new one is a new row: an audit trail that can be edited is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();

            // Who asked the agent for this. Null when nobody did — which is
            // what autonomous means, and why it is worth being able to see.
            $table->foreignUlid('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();

            // One of AgentCapability. A string rather than an enum column so a
            // capability removed from the code does not make old rows
            // unreadable — the record of what happened outlives the feature.
            $table->string('capability', 40);

            /*
             * What exactly was proposed.
             *
             * Stored as given and never recomputed at execution time: approving
             * "block the 3rd to the 5th" must execute that, not whatever the
             * agent would propose if asked again now.
             */
            $table->json('arguments');

            // What the agent said it was doing, for the person approving it.
            $table->text('summary');

            // proposed | approved | executed | rejected | failed | expired
            $table->string('status', 16)->default('proposed');

            // True when it ran without anybody reading it first. Never inferred
            // from the other columns: it is the question somebody is actually
            // scanning for.
            $table->boolean('was_autonomous')->default(false);

            $table->text('outcome')->nullable();
            // What the channel gave back — a message id, a confirmation code.
            $table->string('external_reference')->nullable();

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'property_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
    }
};
