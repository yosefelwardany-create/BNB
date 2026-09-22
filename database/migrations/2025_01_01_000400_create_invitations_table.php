<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('job_title')->nullable();

            // Only the hash is stored; the plaintext token exists solely in the
            // invitation email.
            $table->string('token_hash', 64)->unique();

            $table->foreignUlid('invited_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'email']);
        });

        Schema::create('invitation_role', function (Blueprint $table) {
            $table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['invitation_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_role');
        Schema::dropIfExists('invitations');
    }
};
