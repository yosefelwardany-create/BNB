<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a channel told us, and what we did about it.
 *
 * Two jobs, and the first one is not optional. Every webhook sender retries,
 * and a delivery that timed out on our side after the work was done arrives
 * again looking identical. Without somewhere to remember the event id, a
 * redelivered `reservation_created` is a second booking and a redelivered
 * `message_created` is a duplicate in a guest's thread.
 *
 * The second is debugging. When a booking does not appear, the only useful
 * question is whether the channel ever sent it, and the only honest answer
 * comes from a record written before anything was interpreted. So the payload
 * is stored as it arrived, and the processing outcome beside it.
 *
 * Kept, not pruned on success: "the channel never sent it" and "we received it
 * and got it wrong" are the two explanations, and only this tells them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_account_id')->constrained()->cascadeOnDelete();

            $table->string('type', 80);

            /*
             * The sender's own id for the event.
             *
             * Unique per account rather than globally: two channels may well
             * number their events from one, and a collision between them would
             * silently discard one customer's booking.
             */
            $table->string('provider_event_id', 191);

            $table->json('payload');

            // pending | processed | ignored | failed
            $table->string('status', 16)->default('pending');
            $table->text('outcome')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_account_id', 'provider_event_id']);
            $table->index(['organization_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_events');
    }
};
