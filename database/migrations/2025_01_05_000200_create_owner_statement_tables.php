<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner statements and payouts.
 *
 * A statement is the document that says what an owner earned in a period, what
 * was taken out, and what is owed. It is the single most scrutinised artefact
 * this product produces: owners read it line by line, and a disagreement about
 * one line is a disagreement about the relationship.
 *
 * Which is why a statement is **immutable once approved** and carries its own
 * lines rather than recomputing from the ledger on every read. Recomputation
 * would mean a statement sent in March could quietly say something different in
 * June — after a backdated expense, a corrected rate, an edited agreement — and
 * the owner would be right to stop trusting all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_statements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained()->cascadeOnDelete();

            // Null means the statement covers every property this owner holds.
            // A per-property statement is the other common shape, for owners
            // who want them separated.
            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 32);

            $table->date('period_start');
            $table->date('period_end');

            $table->char('currency', 3);

            // Every figure is stored, not derived on read. See the note above.
            $table->bigInteger('gross_revenue')->default(0);
            $table->bigInteger('accommodation_revenue')->default(0);
            $table->bigInteger('fee_revenue')->default(0);
            $table->bigInteger('taxes_collected')->default(0);
            $table->bigInteger('channel_commission')->default(0);
            $table->bigInteger('payment_fees')->default(0);
            $table->bigInteger('management_fee')->default(0);
            $table->bigInteger('expenses_total')->default(0);
            $table->bigInteger('adjustments_total')->default(0);

            // What the owner is owed before the reserve is withheld.
            $table->bigInteger('net_due')->default(0);

            // A float the manager keeps back to cover the next period's costs.
            $table->bigInteger('reserve_withheld')->default(0);

            // Carried in from the previous statement, so a period that ended in
            // deficit is recovered rather than written off.
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('closing_balance')->default(0);

            // What is actually to be paid.
            $table->bigInteger('payout_amount')->default(0);

            $table->unsignedInteger('nights_sold')->default(0);
            $table->unsignedInteger('reservations_count')->default(0);

            // draft|approved|sent|paid|void
            $table->string('status', 16)->default('draft');

            $table->timestampTz('approved_at')->nullable();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('paid_at')->nullable();

            // The agreement terms in force when this was produced, copied in.
            // An agreement edited later must never restate a statement the
            // owner has already agreed to.
            $table->jsonb('agreement_snapshot')->nullable();

            $table->text('notes')->nullable();
            $table->string('document_path')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);

            // One statement per owner, property scope and period. Regenerating
            // replaces the draft rather than accumulating duplicates.
            $table->unique(
                ['organization_id', 'owner_id', 'property_id', 'period_start', 'period_end'],
                'owner_statements_period_unique',
            );

            $table->index(['organization_id', 'status', 'period_end']);
            $table->index(['organization_id', 'owner_id', 'period_end']);
        });

        Schema::create('owner_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_statement_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('expense_id')->nullable();

            // revenue|fee|tax|commission|management_fee|expense|adjustment|
            // reserve|opening_balance
            $table->string('category', 32);

            $table->date('line_date');
            $table->string('description');
            $table->text('explanation')->nullable();

            // Signed: positive adds to what the owner is due, negative takes
            // away. Storing the sign rather than a separate direction column
            // makes the statement sum to its own total with no rules to
            // remember, which is exactly what an owner checks first.
            $table->bigInteger('amount');
            $table->char('currency', 3);

            // The owner's share of the property on the night this line covers,
            // recorded so a jointly-owned property's split is visible rather
            // than implied.
            $table->decimal('ownership_percentage', 7, 4)->default(100);
            $table->bigInteger('full_amount')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['owner_statement_id', 'category', 'sort_order']);
            $table->index(['organization_id', 'reservation_id']);
        });

        Schema::table('owner_statement_lines', function (Blueprint $table) {
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });

        Schema::create('owner_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_statement_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 32);

            $table->bigInteger('amount');
            $table->char('currency', 3);

            $table->date('scheduled_for')->nullable();
            $table->timestampTz('paid_at')->nullable();

            // pending|approved|processing|paid|failed|cancelled
            $table->string('status', 16)->default('pending');

            $table->string('method', 32)->nullable();
            $table->string('external_reference', 191)->nullable();

            // Banking details as they stood when the payout was made, so a
            // later change of account does not rewrite where money went.
            $table->jsonb('destination_snapshot')->nullable();

            $table->text('notes')->nullable();
            $table->string('failure_message')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'scheduled_for']);
            $table->index(['organization_id', 'owner_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE owner_payouts
            ADD CONSTRAINT owner_payouts_amount_positive
            CHECK (amount > 0)
        SQL);

        // Close the loop from the ledger and from expenses, so a line already
        // swept into a finalised statement cannot be swept into a second one.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreign('owner_statement_id')->references('id')->on('owner_statements')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign('owner_statement_id')->references('id')->on('owner_statements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $t) => $t->dropForeign(['owner_statement_id']));
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropForeign(['owner_statement_id']));
        Schema::dropIfExists('owner_payouts');
        Schema::table('owner_statement_lines', fn (Blueprint $t) => $t->dropForeign(['expense_id']));
        Schema::dropIfExists('owner_statement_lines');
        Schema::dropIfExists('owner_statements');
    }
};
