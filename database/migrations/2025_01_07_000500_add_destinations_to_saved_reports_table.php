<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a scheduled report goes.
 *
 * `recipients` held a list of email addresses, and email was therefore the
 * only place a report could ever arrive. An operator who wanted last month's
 * occupancy in their own system was told to receive an attachment and forward
 * it by hand, which is how a figure ends up retyped and how a retyped figure
 * ends up wrong.
 *
 * A list of destinations rather than a second column per transport, because
 * the useful case is several at once: emailed to the accountant, posted to the
 * warehouse, and kept as a file so somebody can answer "what did this say in
 * March?" six months later.
 *
 * `recipients` stays. Every schedule saved before today keeps its addresses
 * there, an empty `destinations` still means "email those people", and nothing
 * anybody configured has to be re-entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_reports', function (Blueprint $table): void {
            // [{"type": "webhook", "url": "...", "secret": "..."}, ...]
            $table->jsonb('destinations')->nullable()->after('recipients');
        });
    }

    public function down(): void
    {
        Schema::table('saved_reports', function (Blueprint $table): void {
            $table->dropColumn('destinations');
        });
    }
};
