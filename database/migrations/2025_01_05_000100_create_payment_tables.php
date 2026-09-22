<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, refunds and the schedules that drive them.
 *
 * A payment here is the platform's record of an attempt to move money, not the
 * processor's. The two are linked by `provider_reference` and kept separate
 * deliberately: the processor is the authority on whether money moved, we are
 * the authority on what it was for, and conflating them makes reconciliation
 * impossible when they disagree.
 *
 * Authorization and capture are distinct states rather than a flag, because a
 * card authorised at booking and captured on arrival is the normal shape of
 * this business, and a refund is its own record rather than a negative payment
 * so that "how much was taken" and "how much was given back" stay separately
 * answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 32);

            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('owner_id')->nullable();
            $table->ulid('invoice_id')->nullable();
            $table->ulid('property_id')->nullable();

            // What the money is for. A security deposit is never revenue, so
            // it posts to a liability account and must be distinguishable.
            $table->string('kind', 24)->default('booking');   // booking|deposit|security_deposit|upsell|damage|other
            $table->string('direction', 16)->default('inbound'); // inbound|outbound

            $table->bigInteger('amount');
            $table->char('currency', 3);

            // The organization's reporting-currency view, with the rate used.
            $table->bigInteger('base_amount')->nullable();
            $table->char('base_currency', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->default(1);

            // What the processor kept. Recorded separately from the amount so
            // gross and net are both answerable without inference.
            $table->bigInteger('fee_amount')->default(0);

            $table->bigInteger('captured_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);

            // pending|authorized|captured|partially_refunded|refunded|failed|
            // voided|requires_action
            $table->string('status', 24)->default('pending');

            $table->string('method', 32)->nullable();          // card|bank_transfer|cash|channel_collected|...
            $table->string('provider', 48)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_status', 48)->nullable();

            // Whether the money actually moved through us. A channel that
            // collects from the guest itself produces a payment record with
            // this false, so revenue is recognised without inventing cash we
            // never held.
            $table->boolean('is_collected_by_us')->default(true);

            // True when the provider that handled this is a simulated one. It
            // travels with the record rather than being derived later, because
            // the provider in use can change after the fact.
            $table->boolean('is_simulated')->default(false);

            $table->string('instrument_brand', 24)->nullable();
            $table->string('instrument_last4', 4)->nullable();
            $table->string('instrument_token')->nullable();

            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();

            $table->string('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);

            // One payment per provider reference. This is what makes a webhook
            // delivered twice — which every processor does — harmless.
            $table->unique(['organization_id', 'provider', 'provider_reference'], 'payments_provider_unique');

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'reservation_id']);
            $table->index(['organization_id', 'property_id', 'created_at']);
        });

        // A capture can never exceed what was authorised, and a refund can
        // never exceed what was captured. Enforced by the database because
        // these are the two arithmetic mistakes that cost real money.
        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_amounts_non_negative
            CHECK (amount >= 0 AND captured_amount >= 0 AND refunded_amount >= 0 AND fee_amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_capture_within_authorization
            CHECK (captured_amount <= amount)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
            ADD CONSTRAINT payments_refund_within_capture
            CHECK (refunded_amount <= captured_amount)
        SQL);

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 32);
            $table->bigInteger('amount');
            $table->char('currency', 3);

            $table->string('status', 24)->default('pending');  // pending|completed|failed
            $table->string('reason', 64)->nullable();           // cancellation|goodwill|overcharge|damage_release
            $table->text('notes')->nullable();

            $table->string('provider', 48)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->boolean('is_simulated')->default(false);

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_message')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'provider', 'provider_reference'], 'refunds_provider_unique');
            $table->index(['organization_id', 'payment_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT refunds_amount_positive
            CHECK (amount > 0)
        SQL);

        /*
         * When money is due.
         *
         * A schedule is the plan; payments are what actually happened against
         * it. Keeping them apart means a guest who pays early, late or in an
         * unexpected instalment does not corrupt the plan, and the plan can be
         * changed without rewriting history.
         */
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('label');                            // Deposit, Balance, Instalment 2
            $table->bigInteger('amount');
            $table->char('currency', 3);

            $table->date('due_on');

            // pending|paid|partially_paid|overdue|failed|waived|cancelled
            $table->string('status', 24)->default('pending');
            $table->bigInteger('paid_amount')->default(0);

            // Whether the platform should try to take this automatically when
            // it falls due, using a stored instrument.
            $table->boolean('auto_charge')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('last_failure_message')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['reservation_id', 'sequence']);
            $table->index(['organization_id', 'status', 'due_on']);
        });

        /*
         * Money going out to vendors: the cost side of operations.
         *
         * An expense is separate from the task that caused it because not every
         * cost has a task (a utility bill) and not every task has a cost (a
         * checklist that found nothing).
         */
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 32);

            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('owner_id')->nullable();
            $table->ulid('task_id')->nullable();
            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete();

            $table->date('expense_date');
            $table->string('category', 48);                     // cleaning|maintenance|supplies|utilities|other
            $table->string('description');

            $table->bigInteger('amount');
            $table->bigInteger('tax_amount')->default(0);
            $table->char('currency', 3);

            // Who ultimately bears it. This decides whether the cost appears on
            // an owner statement or stays with the manager, and it is the most
            // consequential field on the record.
            $table->string('billable_to', 16)->default('manager'); // owner|guest|manager

            // A manager may add a handling margin to contractor work. Recorded
            // separately so an owner can always see the underlying cost.
            $table->decimal('markup_percent', 7, 4)->default(0);
            $table->bigInteger('markup_amount')->default(0);

            // draft|approved|rejected|paid
            $table->string('status', 16)->default('draft');
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            $table->boolean('is_paid')->default(false);
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_method', 32)->nullable();

            // Set once swept into a finalised owner statement, so it cannot be
            // billed to the owner twice.
            $table->ulid('owner_statement_id')->nullable();

            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'expense_date']);
            $table->index(['organization_id', 'property_id', 'expense_date']);
            $table->index(['organization_id', 'owner_id', 'owner_statement_id']);
            $table->index(['organization_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_amounts_non_negative
            CHECK (amount >= 0 AND tax_amount >= 0 AND markup_amount >= 0)
        SQL);

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        // Close the loop from the task the expense came from.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->dropForeign(['expense_id']));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['property_id']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['owner_id']);
        });
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
