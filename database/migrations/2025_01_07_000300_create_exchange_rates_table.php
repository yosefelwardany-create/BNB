<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates, by day.
 *
 * Stored rather than fetched on demand, and the reason is reproducibility: a
 * booking taken in March and reported on in September must convert at March's
 * rate, every time, however many times the report is run. A system that asks a
 * live API each time produces accounts that change while nobody is editing them.
 *
 * `rate` is a high-precision decimal rather than a float. Rates are multiplied
 * into money, and a binary float's rounding error becomes somebody's missing
 * cent — small per row and not small across a year of a portfolio.
 *
 * Platform-owned: rates are a fact about the world, not about a tenant, so there
 * is no organization_id and every organization reads the same numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->date('rate_date');

            // 20 digits with 10 after the point: enough for any real pair,
            // including the ones quoted in tens of thousands to one.
            $table->decimal('rate', 20, 10);

            // Which implementation supplied it, so a mistaken import can be
            // found and replaced without touching rates from another source.
            $table->string('source', 32)->default('manual');

            $table->timestampsTz();

            // One rate per pair per day. A second import of the same day must
            // update rather than accumulate, or a conversion becomes a coin toss
            // between two rows.
            $table->unique(['base_currency', 'quote_currency', 'rate_date']);

            $table->index(['base_currency', 'quote_currency', 'rate_date'], 'exchange_rates_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
