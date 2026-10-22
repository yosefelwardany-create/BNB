<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channel's own id for a push, kept rather than thrown away.
 *
 * Every adapter already returns one: `ChannelSyncResult::externalReference` is
 * the task, batch or message id the channel answered with. `closeJob()` wrote
 * the status, the timings, the error and the raw result and silently dropped
 * that one field, so the id existed for the length of one method call and was
 * then unrecoverable.
 *
 * It is the handle for every conversation about a push that went wrong. Asked
 * "what happened to the rate update you sent at 14:05?", a channel's support
 * team wants its id, and the raw `result` blob is not an answer: it is not
 * indexed, its shape differs per adapter, and it is nullable. Channex's PMS
 * certification form asks for these ids directly, which is what surfaced this.
 *
 * Indexed because the lookup is always "find the job for this id", never a
 * scan. Nullable because a simulated push has no remote id to report, and
 * inventing one would make a local no-op look like a delivered update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table): void {
            $table->string('external_reference', 191)->nullable()->after('result');

            $table->index(['organization_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
