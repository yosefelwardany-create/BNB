<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Properties\Models\PropertyDocument;
use App\Domain\Properties\Services\KnowledgeDocumentFetcher;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-reads the knowledge documents whose copies have gone stale.
 *
 * The document lives wherever its authors maintain it. Somebody correcting a
 * check-in time in Google Docs has no reason to come here and press a button,
 * and an agent quoting last month's answer because nobody did is the failure
 * this prevents.
 *
 * Due, not all: re-fetching every document every hour spends somebody's
 * bandwidth and Google's patience to learn that nothing changed. One pass an
 * hour over the ones older than the interval keeps a copy no more than an hour
 * behind, which is the right trade for a house manual.
 */
class RefreshKnowledgeDocuments extends Command
{
    protected $signature = 'properties:refresh-knowledge {--all : Re-read every document, however recently checked}';

    protected $description = 'Re-read the property knowledge documents whose copies have gone stale.';

    public function handle(TenantContext $tenancy, KnowledgeDocumentFetcher $fetcher): int
    {
        $staleBefore = CarbonImmutable::now()->subMinutes(
            (int) config('pms.agents.knowledge.refresh_minutes', 60),
        );

        $read = 0;

        $tenancy->withoutScope(function () use ($fetcher, $staleBefore, &$read): void {
            PropertyDocument::query()
                ->withoutGlobalScope('organization')
                ->when(! $this->option('all'), fn ($query) => $query
                    ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', $staleBefore)))
                ->orderBy('id')
                ->chunkById(50, function ($documents) use ($fetcher, &$read): void {
                    foreach ($documents as $document) {
                        $fetcher->refresh($document);
                        $read++;
                    }
                });
        });

        $this->info($read === 0
            ? 'Every knowledge document is current.'
            : sprintf('Re-read %d document%s.', $read, $read === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
