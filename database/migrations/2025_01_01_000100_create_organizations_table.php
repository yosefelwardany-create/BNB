<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();
            $table->string('status', 32)->default('trial')->index();

            // The currency the organization reports in. Individual properties,
            // reservations and owners may transact in other currencies; those
            // amounts are converted for reporting with a recorded rate and the
            // original values are never overwritten.
            $table->char('base_currency', 3)->default('USD');

            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 12)->default('en');
            $table->char('country_code', 2)->nullable();

            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('tax_identifier', 64)->nullable();

            $table->jsonb('branding')->nullable();
            $table->jsonb('settings')->nullable();

            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
