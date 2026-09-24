<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform operator's own audit trail.
 *
 * Separate from `audit_logs`, which is each tenant's trail and is scoped to one
 * — a row there without an organization is a bug, and the tenancy trait refuses
 * to write one. Rather than weaken that guard, actions belonging to no
 * organization get their own table: granting platform administration, editing a
 * plan, changing a platform setting.
 *
 * An action *on* a tenant (suspending it, moving its plan) is still written into
 * that tenant's own trail, because the customer is entitled to the record. Some
 * of those are written here too, so the operator has one place to read what the
 * platform did without querying across every tenant.
 *
 * Append-only. Nothing updates or deletes a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Nullable so a row survives the operator's account being removed.
            // The email is copied alongside for exactly that case: an audit row
            // that says "somebody deleted" answers nothing.
            $table->foreignUlid('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();

            $table->string('action', 96)->index();

            // The tenant this concerned, when it concerned one. Null for actions
            // about the platform itself.
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('organization_name')->nullable();

            $table->string('subject_type', 96)->nullable();
            $table->string('subject_id', 96)->nullable();

            $table->text('description')->nullable();
            $table->jsonb('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->timestampTz('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
