<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One owner record per client account is the account holder.
 *
 * A client organization may hold several real property owners with their own
 * dated shares and agreements; those are left exactly as they are. The account
 * holder is the owner record the client portal resolves to when a login has no
 * owner of its own, and the one whose blanket 10% agreement applies to every
 * property nobody else owns.
 *
 * Additive only: a nullable-free boolean with a false default, and a partial
 * unique index so there can never be two holders in one organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table): void {
            $table->boolean('is_account_holder')->default(false)->after('portal_permissions');
        });

        DB::statement(
            'CREATE UNIQUE INDEX owners_account_holder_unique ON owners (organization_id) WHERE is_account_holder'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS owners_account_holder_unique');

        Schema::table('owners', function (Blueprint $table): void {
            $table->dropColumn('is_account_holder');
        });
    }
};
