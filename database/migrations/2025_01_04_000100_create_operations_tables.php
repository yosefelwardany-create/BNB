<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations: cleaning, maintenance, inspections and the people who do them.
 *
 * One `tasks` table serves every kind of work rather than separate tables per
 * discipline. A cleaner's turnover and a plumber's callout share almost
 * everything that matters — a property, a window, an assignee, a status, a
 * cost — and separating them would mean duplicating scheduling, assignment,
 * notification and reporting three times over. What differs is captured in
 * `kind`, the checklist and a small set of kind-specific columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Teams group staff for assignment and workload balancing.
        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('kind', 32)->default('cleaning');   // cleaning|maintenance|inspection|general
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->timestampsTz();

            $table->primary(['team_id', 'membership_id']);
            $table->index('membership_id');
        });

        // External contractors: cleaning companies, plumbers, electricians.
        Schema::create('vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('category', 48);                    // cleaning|plumbing|electrical|...
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('city')->nullable();
            $table->char('country_code', 2)->nullable();

            $table->string('tax_identifier', 64)->nullable();
            $table->bigInteger('hourly_rate')->nullable();
            $table->bigInteger('callout_fee')->nullable();
            $table->char('currency', 3)->nullable();

            // Insurance and certification expiry, so a vendor cannot be
            // dispatched with lapsed cover.
            $table->date('insurance_expires_on')->nullable();
            $table->jsonb('certifications')->nullable();

            $table->decimal('rating', 3, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'category', 'is_active']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 32);

            // cleaning|maintenance|inspection|restocking|preparation|
            // guest_request|custom
            $table->string('kind', 32);
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();

            // pending|assigned|accepted|in_progress|blocked|completed|cancelled
            $table->string('status', 24)->default('pending');
            $table->string('priority', 16)->default('normal');  // low|normal|high|urgent

            // Assignment is to a person, a team or an external vendor.
            $table->foreignUlid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete();

            // The window the work must happen in, as absolute instants derived
            // from the *property's* local clock. Storing instants means a
            // cleaner's app in another timezone still shows the right time.
            $table->timestampTz('scheduled_start')->nullable();
            $table->timestampTz('scheduled_end')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();

            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();

            $table->string('blocked_reason')->nullable();
            $table->string('cancellation_reason')->nullable();

            // What the work cost, and who bears it.
            $table->bigInteger('estimated_cost')->nullable();
            $table->bigInteger('actual_cost')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('billable_to', 16)->nullable();      // owner|guest|manager
            $table->boolean('is_billed')->default(false);
            $table->ulid('expense_id')->nullable();

            // Maintenance specifics.
            $table->string('issue_category', 48)->nullable();
            $table->string('severity', 16)->nullable();          // minor|moderate|major|critical
            $table->boolean('affects_availability')->default(false);
            $table->timestampTz('sla_due_at')->nullable();

            // Recurrence: a weekly deep clean, a quarterly boiler service.
            $table->ulid('recurrence_id')->nullable();
            $table->boolean('is_recurring_template')->default(false);

            // Set when the task was generated automatically from a checkout,
            // so re-running generation does not duplicate it.
            $table->string('generated_by', 48)->nullable();
            $table->string('generation_key', 191)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'reference']);
            // Generation is idempotent: one cleaning per checkout, however
            // many times the generator runs.
            $table->unique(['organization_id', 'generation_key']);

            $table->index(['organization_id', 'status', 'scheduled_start']);
            $table->index(['organization_id', 'property_id', 'scheduled_start']);
            $table->index(['organization_id', 'assigned_to_id', 'status']);
            $table->index(['organization_id', 'kind', 'status']);
            $table->index('reservation_id');
        });

        // Reusable checklists: the standard turnover, the quarterly inspection.
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 32);
            $table->text('description')->nullable();
            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();

            // [{"label": "...", "requires_photo": true, "section": "Kitchen"}]
            $table->jsonb('items');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['organization_id', 'kind', 'is_active']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();

            $table->string('section')->nullable();
            $table->string('label');
            $table->integer('position')->default(0);

            $table->string('status', 16)->default('pending');   // pending|passed|failed|skipped
            $table->boolean('requires_photo')->default(false);
            $table->text('notes')->nullable();
            $table->string('severity', 16)->nullable();

            $table->foreignUlid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();

            // A failed item can raise follow-up work.
            $table->ulid('follow_up_task_id')->nullable();

            $table->timestampsTz();

            $table->index(['task_id', 'position']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->timestampsTz();

            $table->index(['task_id', 'created_at']);
        });

        Schema::create('task_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('checklist_item_id')->nullable()
                ->constrained('task_checklist_items')->cascadeOnDelete();

            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('caption')->nullable();
            $table->string('stage', 16)->nullable();            // before|during|after|damage

            $table->foreignUlid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['task_id', 'stage']);
        });

        // Recurring work definitions.
        Schema::create('task_recurrences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('kind', 32);
            $table->string('frequency', 16);                   // daily|weekly|monthly|quarterly|yearly
            $table->unsignedSmallInteger('interval')->default(1);
            $table->jsonb('days_of_week')->nullable();
            $table->unsignedSmallInteger('day_of_month')->nullable();
            $table->time('time_of_day')->nullable();

            $table->foreignUlid('checklist_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('last_generated_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('recurrence_id')->references('id')->on('task_recurrences')->nullOnDelete();
        });

        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->foreign('follow_up_task_id')->references('id')->on('tasks')->nullOnDelete();
        });

        Schema::table('calendar_blocks', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });

        // Staff working patterns, used for assignment and workload.
        Schema::create('staff_availability', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week');         // 0 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('timezone', 64);
            $table->boolean('is_available')->default(true);
            $table->timestampsTz();

            $table->unique(['membership_id', 'day_of_week', 'starts_at']);
        });

        // One-off absences that override the working pattern.
        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('approved');
            $table->timestampsTz();

            $table->index(['membership_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');
        Schema::dropIfExists('staff_availability');
        Schema::table('calendar_blocks', fn (Blueprint $t) => $t->dropForeign(['task_id']));
        Schema::table('task_checklist_items', fn (Blueprint $t) => $t->dropForeign(['follow_up_task_id']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropForeign(['recurrence_id']));
        Schema::dropIfExists('task_recurrences');
        Schema::dropIfExists('task_photos');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
