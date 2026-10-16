<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the guest agent may use a fact the team wrote down.
 *
 * A guest asked when the pool opens. The agent answered everything else about
 * the flat, found nothing about the pool, said it would check, and held the
 * whole reply. The manager wrote the pool hours into the property's knowledge
 * base — which is exactly the repair the product invites — asked again, and the
 * agent held again, because it had never read them.
 *
 * The entries only ever reached {@see OperatorKnowledge}, the facts the manager
 * assistant answers from. The guest agent answers from {@see PropertyKnowledge},
 * which is deliberately a narrower set, and nothing connected the two.
 *
 * They cannot simply be joined. The same table holds what a manager told the
 * assistant in chat — the cleaner's name and mobile number, which contractor to
 * ring about the boiler — and a guest must never be told any of it.
 *
 * So each entry says whether a guest may be told, exactly as a property
 * document already does. Existing rows default to false: they were written when
 * nothing could reach a guest, nobody writing them was deciding otherwise, and
 * a migration that made a cleaner's phone number answerable to a stranger would
 * be the worst possible way to fix this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_knowledge_entries', function (Blueprint $table): void {
            $table->boolean('guest_safe')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('property_knowledge_entries', function (Blueprint $table): void {
            $table->dropColumn('guest_safe');
        });
    }
};
