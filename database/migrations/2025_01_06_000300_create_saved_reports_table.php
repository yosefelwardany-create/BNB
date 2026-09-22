<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports somebody set up once and wants again.
 *
 * A saved report stores a report *key* and its parameters. It never stores a
 * query. That is the security boundary of the whole reporting module: saving
 * or scheduling a report must not become a way to run arbitrary SQL against a
 * multi-tenant database, so the only thing a user controls is which of the
 * platform's hand-written reports to run and what to pass it.
 *
 * Periods are stored relatively where the user chose one — "last month" stays
 * "last month". Freezing the dates at save time is how a scheduled monthly
 * report ends up emailing the same January figures every month for a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The report to run: a key from the registry, never a query.
            $table->string('report_key', 64);

            // Period and filters, exactly as the report will receive them.
            $table->jsonb('parameters')->nullable();

            // Whether colleagues can see it. Private by default: a report is
            // usually somebody's working view before it is anybody else's.
            $table->boolean('is_shared')->default(false);

            // Delivery. Null cron means it is run on demand only.
            $table->string('schedule_cron', 64)->nullable();
            $table->string('schedule_timezone', 64)->nullable();
            $table->jsonb('recipients')->nullable();
            $table->string('format', 16)->default('csv');   // csv|json

            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->string('last_error')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'report_key']);
            $table->index(['organization_id', 'created_by_id']);
            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
