<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unified inbox, templates, automation and notifications.
 *
 * Guest conversations arrive over several transports — a channel's own
 * messaging, email, SMS, the guest portal — and an operator needs them in one
 * thread. So a conversation is transport-agnostic and each message records how
 * it travelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // A conversation is usually about a reservation, but may be with a
            // guest before they book, or with an owner.
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('owner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('listing_id')->nullable()->constrained()->nullOnDelete();

            $table->string('subject')->nullable();
            $table->string('participant_type', 16)->default('guest');  // guest|owner|vendor|internal

            // open|snoozed|archived|closed
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');

            $table->foreignUlid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('messages_count')->default(0);

            $table->timestampTz('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->string('last_message_direction', 16)->nullable();

            // When a guest last wrote. Response-time reporting measures from
            // here, and the inbox sorts unanswered guests to the top.
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->timestampTz('first_response_at')->nullable();
            $table->unsignedInteger('first_response_minutes')->nullable();

            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('archived_at')->nullable();

            // The channel thread this mirrors, where one exists.
            $table->string('channel', 48)->nullable();
            $table->ulid('channel_account_id')->nullable();
            $table->string('external_thread_id', 191)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'last_message_at']);
            $table->index(['organization_id', 'assigned_to_id', 'status']);
            $table->index(['organization_id', 'guest_id']);
            $table->index('reservation_id');
            $table->unique(['organization_id', 'channel', 'external_thread_id'], 'conversations_external_unique');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();

            $table->string('direction', 16);                    // inbound|outbound|internal
            $table->string('transport', 24);                    // channel|email|sms|portal|whatsapp|internal
            $table->string('channel', 48)->nullable();

            $table->text('body');
            $table->text('body_html')->nullable();
            $table->string('subject')->nullable();

            // Who wrote it. A message is from a user, a guest, an owner, or
            // the platform itself (automation).
            $table->string('author_type', 16);                  // user|guest|owner|system|ai
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->nullable();

            // An internal note is visible to staff only and never delivered.
            $table->boolean('is_internal_note')->default(false);

            // Delivery tracking.
            $table->string('status', 24)->default('sent');      // queued|sent|delivered|read|failed|bounced
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->string('external_message_id', 191)->nullable();

            // Provenance for anything the platform generated on a person's
            // behalf, so a manager can always tell what a human wrote.
            $table->foreignUlid('template_id')->nullable();
            $table->ulid('automation_rule_id')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider', 48)->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('language', 12)->nullable();
            $table->jsonb('attachments')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['organization_id', 'direction', 'created_at']);
            $table->unique(['organization_id', 'channel', 'external_message_id'], 'messages_external_unique');
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 48)->nullable();         // pre_arrival|in_stay|post_stay|owner|...

            $table->string('subject')->nullable();
            $table->text('body');

            // Templates can be localised; the platform picks the guest's
            // language and falls back to the organization's.
            $table->string('language', 12)->default('en');
            $table->ulid('translation_of_id')->nullable();

            $table->string('transport', 24)->default('channel');
            $table->jsonb('property_ids')->nullable();          // null = all properties
            $table->jsonb('channels')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'code', 'language']);
            $table->index(['organization_id', 'category']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('template_id')->references('id')->on('message_templates')->nullOnDelete();
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->foreign('translation_of_id')->references('id')->on('message_templates')->nullOnDelete();
        });

        // Canned replies an agent inserts by hand, distinct from templates
        // that automation sends.
        Schema::create('saved_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('shortcut', 32)->nullable();
            $table->text('body');
            $table->string('category', 48)->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'shortcut']);
        });

        /*
         * Automation.
         *
         * Rules are Event → Conditions → Actions, stored as data. Both the
         * conditions and the actions are evaluated by code with a fixed
         * vocabulary; nothing entered by a user is ever executed.
         */
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Either an event name (reservation.confirmed) or a schedule
            // relative to a reservation date (see trigger_offset_*).
            $table->string('trigger_type', 32);                 // event|schedule
            $table->string('trigger_event', 128)->nullable();

            // For scheduled triggers: "3 days before check_in at 10:00", in
            // the property's local time.
            $table->string('trigger_anchor', 32)->nullable();   // check_in|check_out|booked_at
            $table->integer('trigger_offset_minutes')->nullable();
            $table->time('trigger_time_of_day')->nullable();

            $table->jsonb('conditions')->nullable();
            $table->jsonb('actions');

            // Delay between the trigger firing and the actions running.
            $table->unsignedInteger('delay_minutes')->default(0);

            $table->jsonb('property_ids')->nullable();
            $table->jsonb('channels')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_run')->default(0);
            $table->timestampTz('last_run_at')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'is_active', 'trigger_type']);
            $table->index(['organization_id', 'trigger_event']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('automation_rule_id')->references('id')->on('automation_rules')->nullOnDelete();
        });

        /*
         * Every automation execution, successful or not.
         *
         * Kept because "why did this guest get that message?" and "why didn't
         * they?" are the two questions automation always raises, and neither
         * is answerable without a record of what ran and what it decided.
         */
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('automation_rule_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->ulid('domain_event_id')->nullable();

            // pending|skipped|running|completed|failed
            $table->string('status', 16)->default('pending');

            // Why the rule did or did not act, condition by condition.
            $table->jsonb('condition_results')->nullable();
            $table->jsonb('action_results')->nullable();
            $table->text('skip_reason')->nullable();
            $table->text('error')->nullable();

            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // One run per rule per subject per trigger occurrence, so a
            // redelivered event cannot message a guest twice.
            $table->string('idempotency_key', 191)->nullable();

            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'automation_rule_id', 'created_at']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['subject_type', 'subject_id']);
        });

        // In-app notifications, plus the per-user preferences that decide
        // which channels each notification type uses.
        Schema::create('notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('priority', 16)->default('normal');

            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();

            $table->timestampTz('read_at')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['organization_id', 'type']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('notification_type', 64);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('push')->default(false);
            $table->timestampsTz();

            $table->unique(['user_id', 'organization_id', 'notification_type'], 'notification_prefs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['automation_rule_id']);
            $table->dropForeign(['template_id']);
        });
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('saved_replies');
        Schema::table('message_templates', fn (Blueprint $t) => $t->dropForeign(['translation_of_id']));
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('conversations');
    }
};
