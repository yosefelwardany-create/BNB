<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Imported transaction evidence is kept apart from this platform's ledger. */
class HostexTransactionImporter
{
    public function __construct(private readonly HostexChannelAdapter $hostex) {}

    public function sync(ChannelAccount $account): array
    {
        $this->hostex->readIssues = [];
        $from = CarbonImmutable::parse($account->settings['hostex_transactions_from'] ?? now()->subYears(2)->toDateString())->startOfDay();
        $to = CarbonImmutable::today();
        $report = ['updated' => 0, 'failed' => 0, 'issues' => [], 'coverage' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => 'action_at']];
        for ($start = $from; $start <= $to; $start = $end->addDay()) {
            $end = $start->addDays(365)->min($to);
            try {
                $rows = $this->hostex->paged($account, 'transactions', 'transactions', ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
            } catch (Throwable) {
                $report['failed']++;
                $report['issues'][] = 'Source transactions unavailable for '.$start->toDateString().' through '.$end->toDateString().'. Existing snapshots retained.';

                continue;
            }
            foreach ($rows as $row) {
                try {
                    $id = HostexData::text($row['id'] ?? null);
                    if ($id === null) {
                        throw new \RuntimeException('Missing transaction identity.');
                    }
                    $existing = DB::table('hostex_transactions')->where('organization_id', $account->organization_id)
                        ->where('channel_account_id', $account->id)->where('external_id', $id)->first();
                    $previous = $existing ? json_decode($existing->data, true, 512, JSON_THROW_ON_ERROR) : [];
                    $property = ChannelListing::query()->where('channel_account_id', $account->id)
                        ->where('external_listing_id', (string) ($row['property_id'] ?? ''))->value('property_id');
                    $data = array_intersect_key($row, array_flip(['direction', 'status', 'action_at', 'item_name', 'payment_method_name', 'link_type', 'reservation_code', 'property_id']));
                    if (array_key_exists('amount', $row)) {
                        $data['money'] = HostexData::money($row);
                    }
                    $data = array_replace($previous, $data);
                    DB::table('hostex_transactions')->updateOrInsert([
                        'organization_id' => $account->organization_id, 'channel_account_id' => $account->id, 'external_id' => $id,
                    ], ['property_id' => $property ?? (isset($row['property_id']) ? null : $existing?->property_id), 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'synced_at' => now()]);
                    $report['updated']++;
                } catch (Throwable) {
                    $report['failed']++;
                    $report['issues'][] = 'One source transaction could not be stored. Retry this date range.';
                }
            }
        }
        $report['failed'] += count($this->hostex->readIssues);
        $report['issues'] = array_merge($report['issues'], $this->hostex->readIssues);
        $report['unavailable'] = ['Entries are source snapshots, not reconciled payouts. Deleted source entries cannot be inferred from omissions and are retained with their last-seen timestamp.'];

        return $report;
    }
}
