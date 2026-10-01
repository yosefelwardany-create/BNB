<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question put to a property's bot that will be answered later.
 *
 * The synchronous bot provider holds the request open and reads the reply out of
 * the response. That works for a bot that answers in two seconds and does not
 * work at all for an agent run that reads a few files and thinks for two
 * minutes: somebody is watching a spinner, the HTTP client gives up at twenty
 * seconds, and the work the agent did is thrown away.
 *
 * So the question becomes a record. Habitat writes this row, fires a webhook
 * carrying a callback URL, and stops waiting. Whenever the bot is finished it
 * posts its answer back, Habitat runs the same four gates against it, and the
 * answer appears on the screen of whoever asked.
 *
 * Three things about the shape are deliberate:
 *
 *  - **The callback token is stored hashed.** It is the only credential on the
 *    inbound request — there is no account behind it — so the row that would let
 *    somebody answer on a bot's behalf must not be readable out of a dump or a
 *    replica. The guest portal's token is stored in the clear because it is
 *    long-lived and regenerable and the operator can read it to resend a link;
 *    nobody ever needs to read this one, which makes hashing free.
 *
 *  - **The facts sent are recorded by name, never by value.** The outbound
 *    payload can carry a door code where the booking is entitled to one.
 *    Copying that into a second table so an async flow could have a record of
 *    itself would spread a secret for the convenience of an audit trail. The
 *    key names answer "was it entitled to the code" without storing it.
 *
 *  - **It expires.** A pending ask is a live credential; one left open for a
 *    week is a bot nobody is running any more holding a key to a write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_asks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            // Entitlement is decided against this booking, and it is decided
            // when the question is asked rather than when the answer arrives.
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('asked_by_id')->nullable()->constrained('users')->nullOnDelete();

            // pending | answered | failed | expired
            $table->string('status', 16)->default('pending');

            $table->text('question');
            $table->json('history')->nullable();
            $table->string('guest_name')->nullable();

            /*
             * sha256 of the token, hex. Unique so a lookup is an index hit and
             * so a collision is a write error rather than two asks sharing one
             * credential.
             */
            $table->string('callback_token_hash', 64)->unique();
            $table->timestampTz('expires_at');

            // For the screen. The bot's name as its operator wrote it, and the
            // host it was asked at — not the full URL, which belongs in one
            // place and is already there.
            $table->string('bot_name')->nullable();
            $table->string('endpoint_host')->nullable();

            // Names only. See the note above.
            $table->json('sent_fact_keys')->nullable();
            $table->json('withheld')->nullable();

            $table->text('reply')->nullable();
            $table->string('intent', 40)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('would_auto_send')->default(false);
            $table->text('held_because')->nullable();

            // Why there is no answer: the bot said so, the webhook never left,
            // or the ask ran out of time.
            $table->text('failure')->nullable();

            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestamps();

            // The screen's query: this property's asks, newest first.
            $table->index(['organization_id', 'property_id', 'created_at']);
            // The sweep's query: everything still open and past its time.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_asks');
    }
};
