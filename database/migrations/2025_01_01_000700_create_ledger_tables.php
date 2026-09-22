<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger.
 *
 * Every financial figure the platform reports is derived from these tables.
 * Nothing stores "the total" on its own; totals are always the sum of posted
 * journal lines, which is what makes the numbers reproducible and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // Self-referencing keys are added after the table exists, because
            // the primary key it points at is not yet in place mid-CREATE.
            $table->ulid('parent_id')->nullable();

            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);          // asset|liability|equity|revenue|expense

            // Accounts the posting engine resolves by name rather than by code,
            // so customers can renumber their chart freely.
            $table->string('system_key', 64)->nullable();

            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'system_key']);
            $table->index(['organization_id', 'type']);
        });

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('ledger_accounts')->nullOnDelete();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // Human-readable sequential reference, unique per organization.
            $table->string('reference', 32);

            $table->date('entry_date');
            $table->string('description');

            // What caused the entry: reservation.confirmed, payment.captured,
            // expense.recorded, statement.approved, manual, ...
            $table->string('source', 64);
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();

            $table->char('currency', 3);

            // Posted entries are immutable. Corrections are made by posting a
            // reversing entry that points back at the original.
            $table->string('status', 16)->default('draft'); // draft|posted|reversed
            $table->timestampTz('posted_at')->nullable();
            $table->foreignUlid('posted_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Self-referencing; constraints added below.
            $table->ulid('reverses_entry_id')->nullable();
            $table->ulid('reversed_by_entry_id')->nullable();

            // Attribution so owner statements and property P&L can be derived
            // straight from the ledger.
            $table->ulid('property_id')->nullable();
            $table->ulid('owner_id')->nullable();
            $table->ulid('reservation_id')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'entry_date']);
            $table->index(['organization_id', 'status', 'entry_date']);
            $table->index(['source_type', 'source_id']);
            $table->index(['organization_id', 'property_id', 'entry_date']);
            $table->index(['organization_id', 'owner_id', 'entry_date']);
            $table->index(['organization_id', 'reservation_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('reversed_by_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();

            // Amounts are integer minor units. Exactly one of debit/credit is
            // non-zero on any line.
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->char('currency', 3);

            // When the transaction currency differs from the organization's
            // reporting currency, the original amount is preserved here along
            // with the rate that was used. The original is never overwritten.
            $table->bigInteger('base_debit')->default(0);
            $table->bigInteger('base_credit')->default(0);
            $table->char('base_currency', 3);
            $table->decimal('exchange_rate', 20, 10)->default(1);

            $table->string('memo')->nullable();

            $table->ulid('property_id')->nullable();
            $table->ulid('owner_id')->nullable();
            $table->ulid('reservation_id')->nullable();
            $table->ulid('unit_id')->nullable();

            // Set once the line has been included in a finalised owner
            // statement, so it cannot be swept into a second one.
            $table->ulid('owner_statement_id')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['journal_entry_id']);
            $table->index(['organization_id', 'ledger_account_id']);
            $table->index(['organization_id', 'owner_id', 'owner_statement_id']);
            $table->index(['organization_id', 'property_id']);
            $table->index(['organization_id', 'reservation_id']);
        });

        // A line is either a debit or a credit, never both and never neither.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
            ADD CONSTRAINT journal_lines_single_sided
            CHECK (
                (debit = 0 AND credit <> 0) OR (debit <> 0 AND credit = 0)
            )
        SQL);

        // Amounts are always non-negative; direction is expressed by the side.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
            ADD CONSTRAINT journal_lines_non_negative
            CHECK (debit >= 0 AND credit >= 0)
        SQL);

        // Sequence used to generate per-organization journal references.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);          // journal, invoice, statement, reservation
            $table->string('prefix', 16)->nullable();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedSmallInteger('padding')->default(6);
            $table->timestampsTz();

            $table->unique(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
