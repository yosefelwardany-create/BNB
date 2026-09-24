<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform operator's own tables.
 *
 * Everything else in this schema belongs to a tenant. These five do not: they
 * are how whoever runs the platform governs the tenants on it, so none of them
 * carries an `organization_id` and none is reachable through the tenant API.
 *
 * Two decisions worth stating, because both are load-bearing:
 *
 *  - **Plan limits are stored to be enforced, not displayed.** A cap that only
 *    appears on a dashboard is decoration; these columns are read by the
 *    service that refuses the 51st property. A null means unlimited, which is
 *    different from zero.
 *
 *  - **Impersonation is recorded as its own session.** A platform operator
 *    looking at a customer's data is an event the customer is entitled to see a
 *    record of, so it gets a row with a reason and a duration rather than a
 *    line buried in a log. The token those sessions issue is read-only; see
 *    the impersonation service for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What a tenant can be sold. Platform-owned, so no organization_id.
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();

            // Minor units, like every other amount in this schema.
            $table->bigInteger('price_amount')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('billing_interval', 16)->default('monthly'); // monthly|yearly
            $table->unsignedSmallInteger('trial_days')->default(30);

            // Null means unlimited. Zero would mean "none allowed", which is a
            // different and occasionally useful thing.
            $table->unsignedInteger('max_properties')->nullable();
            $table->unsignedInteger('max_units')->nullable();
            $table->unsignedInteger('max_listings')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_reservations_per_month')->nullable();

            // Feature keys from the registry. Absent means not included.
            $table->jsonb('features')->nullable();

            // Whether it is offered at sign-up. A grandfathered or bespoke plan
            // stays active and stops being public.
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUlid('plan_id')->nullable()->after('status')
                ->constrained()->nullOnDelete();

            // Why and when, not just that. "Suspended" with no reason is a
            // support ticket nobody can answer.
            $table->timestampTz('suspended_at')->nullable()->after('trial_ends_at');
            $table->string('suspension_reason')->nullable()->after('suspended_at');

            // A bespoke deal, so one negotiated exception does not require a
            // whole plan nobody else is on. Merged over the plan's limits.
            $table->jsonb('limit_overrides')->nullable()->after('suspension_reason');
            $table->jsonb('feature_overrides')->nullable()->after('limit_overrides');

            // Visible to the platform operator only, never to the tenant.
            $table->text('platform_notes')->nullable()->after('feature_overrides');
        });

        // Messages the platform shows inside the product.
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('title');
            $table->text('body');
            $table->string('level', 16)->default('info'); // info|warning|critical

            // all | trial | active | past_due | specific
            $table->string('audience', 16)->default('all');

            // Used when the audience is `specific`. A list rather than a pivot
            // table: an announcement's audience is written once and read as a
            // whole, and it is never joined against.
            $table->jsonb('organization_ids')->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_published')->default(false);

            // A critical notice about scheduled downtime should not be
            // dismissable; a feature announcement should.
            $table->boolean('is_dismissible')->default(true);

            $table->foreignUlid('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['is_published', 'starts_at', 'ends_at']);
        });

        // Global configuration a platform operator can change without a deploy.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 96)->primary();
            $table->jsonb('value')->nullable();
            $table->text('description')->nullable();

            $table->foreignUlid('updated_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();
        });

        // Every time the platform operator looked at a tenant's data as a user.
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('platform_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('target_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Required by the service. An impersonation with no stated reason
            // is the one you cannot defend afterwards.
            $table->string('reason');

            // The token issued for the session, so ending the session can
            // revoke exactly that token and nothing else.
            $table->unsignedBigInteger('access_token_id')->nullable();

            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_reason', 32)->nullable(); // revoked|expired|completed

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // How much was actually done under it, so a long session that read
            // one page is distinguishable from one that read everything.
            $table->unsignedInteger('request_count')->default(0);

            $table->timestampsTz();

            $table->index(['organization_id', 'started_at']);
            $table->index(['platform_user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_announcements');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn([
                'suspended_at',
                'suspension_reason',
                'limit_overrides',
                'feature_overrides',
                'platform_notes',
            ]);
        });

        Schema::dropIfExists('plans');
    }
};
