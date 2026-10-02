<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostex_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->foreignUlid('property_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('data');
            $table->timestampTz('synced_at');
            $table->unique(['channel_account_id', 'external_id']);
        });
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->timestampTz('last_pull_attempted_at')->nullable();
            $table->timestampTz('last_pull_succeeded_at')->nullable();
            $table->jsonb('last_pull_result')->nullable();
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('hostex_reservation_code', 191)->nullable()->index();
            $table->dropUnique('reservations_external_unique');
        });
        DB::statement("CREATE UNIQUE INDEX reservations_external_unique ON reservations (organization_id, source, COALESCE(channel_account_id, ''), external_reservation_id)");
        Schema::table('conversations', fn (Blueprint $table) => $table->dropUnique('conversations_external_unique'));
        DB::statement("CREATE UNIQUE INDEX conversations_external_unique ON conversations (organization_id, channel, COALESCE(channel_account_id, ''), external_thread_id)");
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_external_unique');
            $table->unique(['organization_id', 'conversation_id', 'channel', 'external_message_id'], 'messages_external_unique');
        });
        // Unknown source values must not read as zero in SQL reports either.
        foreach (['accommodation_total', 'fees_total', 'taxes_total', 'discounts_total', 'grand_total', 'channel_commission', 'expected_payout', 'paid_total', 'refunded_total', 'balance_due', 'base_grand_total'] as $column) {
            DB::statement("ALTER TABLE reservations ALTER COLUMN {$column} DROP NOT NULL");
        }
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->foreignUlid('channel_listing_id')->nullable()->constrained('channel_listings')->nullOnDelete();
            $table->string('source_key', 64)->nullable();
            $table->text('external_url')->nullable();
            $table->unique(['channel_listing_id', 'source_key']);
        });
        Schema::create('channel_guest_links', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_guest_id', 191);
            $table->foreignUlid('guest_id')->constrained()->cascadeOnDelete();
            $table->unique(['channel_account_id', 'external_guest_id']);
        });
    }

    public function down(): void
    {
        // Refuse before dropping any evidence: older code cannot represent these
        // source semantics or account-scoped identities after data is imported.
        if (DB::table('channel_guest_links')->exists() || DB::table('hostex_transactions')->exists()
            || DB::table('reservations')->where('source', 'hostex')->exists()
            || DB::table('property_photos')->whereNotNull('channel_listing_id')->exists()) {
            throw new RuntimeException('Hostex source data exists. Restore a pre-migration backup or deploy a forward repair; rollback would discard source evidence.');
        }
        Schema::dropIfExists('hostex_transactions');
        // A rollback must not turn unknown amounts back into invented zeroes.
        Schema::dropIfExists('channel_guest_links');
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->dropUnique(['channel_listing_id', 'source_key']);
            $table->dropConstrainedForeignId('channel_listing_id');
            $table->dropColumn(['source_key', 'external_url']);
        });
        Schema::table('channel_accounts', fn (Blueprint $table) => $table->dropColumn(['last_pull_attempted_at', 'last_pull_succeeded_at', 'last_pull_result']));
        DB::statement('DROP INDEX reservations_external_unique');
        DB::statement('DROP INDEX conversations_external_unique');
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('hostex_reservation_code');
            $table->unique(['organization_id', 'source', 'external_reservation_id'], 'reservations_external_unique');
        });
        Schema::table('conversations', fn (Blueprint $table) => $table->unique(['organization_id', 'channel', 'external_thread_id'], 'conversations_external_unique'));
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_external_unique');
            $table->unique(['organization_id', 'channel', 'external_message_id'], 'messages_external_unique');
        });
        // Nullable monetary fields remain compatible with earlier application code.
    }
};
