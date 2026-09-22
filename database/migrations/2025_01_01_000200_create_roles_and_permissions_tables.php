<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('module');
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->index('module');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Null means a system role shared by every organization.
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('slug');
            $table->string('name');
            $table->string('description')->nullable();

            // Which product surface the role is intended for: admin, owner
            // portal or staff app. Drives the default landing screen.
            $table->string('portal', 32)->default('admin');

            $table->boolean('is_system')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            // A slug is unique within an organization, and system roles (null
            // organization) are unique globally.
            $table->unique(['organization_id', 'slug']);
            $table->index('portal');
        });

        // Postgres treats NULLs as distinct in unique indexes, so system role
        // slugs need their own partial unique index.
        DB::statement('CREATE UNIQUE INDEX roles_system_slug_unique ON roles (slug) WHERE organization_id IS NULL');

        // Pure pivot: the pair is the key, so no surrogate id is needed.
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
