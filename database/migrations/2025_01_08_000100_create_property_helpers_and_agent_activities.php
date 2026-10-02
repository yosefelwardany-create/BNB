<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people behind a property, and the record of what its agent did.
 *
 * Two tables from the same requirement: an agent that manages a flat has to know
 * who to call when it cannot fix something itself, and somebody has to be able to
 * check afterwards what it did without being told.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who to call about this property.
         *
         * A helper is a *role at a property*, not a person: "the cleaner for
         * Yellow" is the thing being recorded, and who fills it changes. So the
         * row carries the role and points at whoever currently fills it — a
         * vendor, a member of staff, or neither.
         *
         * That last case is why `name` and `phone` exist at all. Most helpers at
         * a small operator are a mobile number in somebody's phone and nothing
         * else, and refusing to record one until a vendor record exists would
         * mean the list stays empty and the agent escalates to nobody. Where a
         * vendor or user *is* linked, their record is the truth and these
         * columns are not read — one phone number, one place to correct it.
         */
        Schema::create('property_helpers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            // manager | cleaner | maintenance | electrician | plumber | other
            $table->string('role', 32);
            // What this one is called when the role is not enough — "Night
            // cleaner", "Out-of-hours electrician".
            $table->string('label')->nullable();

            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();

            // Used only when neither of the above is set.
            $table->string('name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();

            $table->text('notes')->nullable();
            // Who the agent names first when it has to escalate.
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['organization_id', 'property_id', 'position']);
        });

        /*
         * What the agent did, in order.
         *
         * Separate from `audit_logs`, which records what *people* changed. This
         * records what the agent did on its own: which question it was asked,
         * what it answered, whether a person had to read it first. The two
         * answer different questions and mixing them would make both harder to
         * read.
         *
         * `is_autonomous` is the column that matters. "The agent replied" and
         * "the agent drafted something a person then sent" are different events
         * and must not be summarised into one, because the whole safety story of
         * this feature is about which of the two happened.
         */
        Schema::create('agent_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            // Nullable so an activity outlives the ask it came from, and so
            // activity can be recorded for things that are not asks at all.
            $table->foreignUlid('agent_ask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            // Null when nobody asked — which is precisely what autonomous means.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // asked | answered | drafted | held | failed | expired
            $table->string('kind', 32);
            $table->string('summary', 500);
            $table->json('detail')->nullable();

            // The bot's own name, kept at the time it happened. An operator who
            // renames an agent next month should still be able to read what the
            // one called Alex did in October.
            $table->string('agent_name')->nullable();

            $table->boolean('is_autonomous')->default(false);
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['organization_id', 'property_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activities');
        Schema::dropIfExists('property_helpers');
    }
};
