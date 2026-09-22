<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();

            // The email is stored even for failed attempts where no user
            // matched, so brute force attempts are visible.
            $table->string('email')->index();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();

            // 'user', 'platform_admin', 'integration' or 'system'.
            $table->string('actor_type', 32)->default('system');
            $table->string('actor_label')->nullable();

            $table->string('action', 128);
            $table->string('auditable_type')->nullable();
            $table->ulid('auditable_id')->nullable();
            $table->text('description')->nullable();

            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 40)->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['organization_id', 'action']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('login_histories');
    }
};
