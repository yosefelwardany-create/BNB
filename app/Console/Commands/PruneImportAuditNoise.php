<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Remove old audit entries written by the automatic import's bookkeeping.
 *
 * Until the import stopped auditing its own progress, every pull wrote around
 * ten audit rows: "pull started", "stage finished", and a full before-and-after
 * copy of the property's settings because a sync timestamp inside them had
 * moved. Every five minutes, all day, that grew the audit trail to most of the
 * database.
 *
 * Exactly two kinds of row are removed, and only when older than the
 * retention period (30 days by default):
 *
 * - a channel account update made by the system (no person) whose recorded
 *   changes are only import bookkeeping: pull results and timestamps;
 * - a property update made by the system whose only recorded change is the
 *   property's settings, which is what the import's timestamp stamping wrote.
 *
 * Anything a person did is kept. So is any system change to a channel
 * account's status, credentials or settings, and any system change to a
 * property beyond its settings. Deleted in batches so no single statement
 * holds the table for long. `--dry-run` counts without deleting.
 */
class PruneImportAuditNoise extends Command
{
    protected $signature = 'audit:prune-import-noise
        {--days=30 : Keep everything newer than this many days}
        {--dry-run : Count what would be removed, remove nothing}';

    protected $description = 'Delete old audit rows written by the automatic import\'s bookkeeping';

    /** The channel account columns the import rewrites on every pull. */
    private const BOOKKEEPING = [
        'last_pull_result',
        'last_pull_attempted_at',
        'last_pull_succeeded_at',
        'last_synced_at',
        'last_imported_at',
        'last_error',
    ];

    private const BATCH = 1000;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $total = (clone $this->noise($cutoff))->count();

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d audit row(s) older than %d days would be removed. Nothing was deleted.', $total, $days));

            return self::SUCCESS;
        }

        $deleted = 0;

        // Each batch is one statement run entirely inside the database: the
        // rows to delete are chosen by a subquery, so no ids travel back to
        // the application.
        do {
            $batch = DB::table('audit_logs')
                ->whereIn('id', (clone $this->noise($cutoff))->limit(self::BATCH))
                ->delete();

            $deleted += $batch;
        } while ($batch === self::BATCH);

        $this->info(sprintf('Removed %d automatic-import audit row(s) older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }

    private function noise(\DateTimeInterface $cutoff): Builder
    {
        $bookkeeping = implode(',', array_map(
            static fn (string $column): string => DB::getPdo()->quote($column),
            self::BOOKKEEPING,
        ));

        return DB::table('audit_logs')
            ->select('id')
            ->whereNull('user_id')
            ->where('created_at', '<', $cutoff)
            ->where(function (Builder $query) use ($bookkeeping): void {
                $query
                    // Channel account rows whose every recorded change is
                    // import bookkeeping.
                    ->where(function (Builder $q) use ($bookkeeping): void {
                        $q->where('action', 'channel_account.updated')
                            ->whereRaw("case when jsonb_typeof(new_values) = 'object' then not exists (select 1 from jsonb_object_keys(new_values) as k where k not in ({$bookkeeping})) else false end");
                    })
                    // Property rows whose only recorded change is settings.
                    ->orWhere(function (Builder $q): void {
                        $q->where('action', 'property.updated')
                            ->whereRaw("case when jsonb_typeof(new_values) = 'object' then (select array_agg(k) from jsonb_object_keys(new_values) as k) = array['settings'] else false end");
                    });
            })
            ->orderBy('created_at');
    }
}
