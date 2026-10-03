<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use Carbon\CarbonImmutable;
use Throwable;

/** Reads the property master calendar, which is distinct from OTA inventory. */
class HostexAvailabilitySynchronizer
{
    public function __construct(private readonly HostexChannelAdapter $hostex) {}

    public function sync(ChannelAccount $account): array
    {
        $report = ['updated' => 0, 'days' => 0, 'unavailable_days' => 0, 'failed' => 0, 'issues' => []];
        $from = CarbonImmutable::now('UTC')->subDay()->toDateString();
        $to = CarbonImmutable::now('UTC')->addDays(366)->toDateString();
        foreach ($account->listings()->get()->chunk(100) as $mappings) {
            try {
                $response = $this->hostex->client($account)->get('availabilities', [
                    'property_ids' => $mappings->pluck('external_listing_id')->implode(','),
                    'start_date' => $from, 'end_date' => $to,
                ]);
                $rows = collect($response['properties'] ?? [])->keyBy(fn ($row) => (string) ($row['id'] ?? ''));
                foreach ($mappings as $mapping) {
                    $row = $rows->get((string) $mapping->external_listing_id);
                    if (! is_array($row['availabilities'] ?? null)) {
                        $report['failed']++;
                        $report['issues'][] = 'A property availability response was missing; its previous calendar was retained.';

                        continue;
                    }
                    $metadata = $mapping->metadata ?? [];
                    $days = collect($metadata['hostex']['availability'] ?? [])->keyBy('date')->all();
                    foreach ($row['availabilities'] as $day) {
                        if (! is_array($day) || ! is_string($day['date'] ?? null)
                            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day['date']) || ! is_bool($day['available'] ?? null)) {
                            $report['failed']++;
                            $report['issues'][] = 'An unsupported availability entry was skipped.';

                            continue;
                        }
                        if ($day['date'] < $from || $day['date'] > $to) {
                            continue;
                        }
                        $days[$day['date']] = [
                            'date' => $day['date'], 'available' => $day['available'],
                            // Remarks may contain guest details or access codes;
                            // the availability display does not need them.
                            'synced_at' => now()->toIso8601String(),
                        ];
                        $report['days']++;
                        $report['unavailable_days'] += $day['available'] ? 0 : 1;
                    }
                    ksort($days);
                    $metadata['hostex']['availability'] = array_values(array_filter($days, fn ($day) => $day['date'] >= $from && $day['date'] <= $to));
                    $metadata['hostex']['availability_coverage'] = ['from' => $from, 'to' => $to, 'synced_at' => now()->toIso8601String()];
                    $mapping->forceFill(['metadata' => $metadata])->save();
                    app(HostexPropertySynchronizer::class)->apply($mapping);
                    $report['updated']++;
                }
            } catch (Throwable) {
                $report['failed']++;
                $report['issues'][] = 'Hostex property availability could not be refreshed. Previous dates were retained; retry Pull.';
            }
        }

        return $report;
    }
}
