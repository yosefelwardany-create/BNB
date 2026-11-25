<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invited person will see, kept until they accept.
 *
 * An invitation carried roles only, so a cleaner limited to one building
 * joined seeing every property until somebody remembered to narrow them.
 * The single permissions given or taken away and the properties they are
 * limited to now travel with the invitation and are applied on acceptance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->jsonb('access')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropColumn('access');
        });
    }
};
