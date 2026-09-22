<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32)->default('active');
            $table->string('job_title')->nullable();
            $table->string('employment_type', 32)->nullable();
            $table->string('default_portal', 32)->default('admin');

            // When true the member only sees the properties listed in
            // membership_property. Used for on-site staff and building
            // managers.
            $table->boolean('restricted_to_properties')->default(false);

            $table->foreignUlid('invited_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('membership_role', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['membership_id', 'role_id']);
            $table->index('role_id');
        });

        Schema::create('membership_permission', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();

            // 'allow' grants a permission the roles do not provide;
            // 'deny' removes one the roles do provide. Deny always wins.
            $table->string('effect', 8)->default('allow');
            $table->timestampsTz();

            $table->primary(['membership_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_permission');
        Schema::dropIfExists('membership_role');
        Schema::dropIfExists('memberships');
    }
};
