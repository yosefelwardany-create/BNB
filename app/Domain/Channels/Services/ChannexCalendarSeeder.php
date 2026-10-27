<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Exceptions\ChannexRequestException;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A room type linked from Channex starts from Channex's prices, once.
 *
 * Habitat owns the calendar: it sends prices and stay rules to Channex, which
 * is what Channex certifies, and never reads them back. But a property made
 * from a Channex room type arrived with no price — £0.00 — and a calendar flat
 * where Channex's was not, so its first push would have overwritten every
 * price somebody had set there. So the first time a room type is linked, its
 * coming year is copied from Channex, and then never again.
 *
 * What is copied:
 *
 * - a nightly price for the property, where it has none: the price most of
 *   Channex's nights carry, so the calendar is not three hundred overrides of
 *   the same number;
 * - then, night by night, only what differs from that: the price, the
 *   minimum stay on arrival, the maximum stay, closed to arrival and to
 *   departure, and a stop-sell as a night closed by hand.
 *
 * Not copied: availability. That is Habitat's own count of its bookings and
 * blocks, and the bookings come across as bookings.
 *
 * A listing whose calendar already has nights set in the coming year is left
 * alone, and marked as done: somebody has priced it here, and Channex does
 * not get to overwrite that.
 */
class ChannexCalendarSeeder
{
    /** How far ahead the copy reaches. */
    private const DAYS = 365;

    public function __construct(private readonly ChannexChannelAdapter $adapter) {}

    /**
     * @return array{imported: int, skipped: int, nights: int, failed: int, issues: list<string>}
     */
    public function seed(ChannelAccount $account): array
    {
        $report = ['imported' => 0, 'skipped' => 0, 'nights' => 0, 'failed' => 0, 'issues' => []];

        $from = CarbonImmutable::today();
        $to = $from->addDays(self::DAYS - 1);

        $due = ChannelListing::query()
            ->with('listing.property')
            ->where('channel_account_id', $account->getKey())
            ->where('is_active', true)
            ->whereNotNull('listing_id')
            ->get()
            ->filter(fn (ChannelListing $mapping): bool => $mapping->listing !== null
                && ! isset($mapping->metadata['channex']['calendar_imported_at'])
                && is_string($mapping->metadata['channex']['property_id'] ?? null)
                && is_string($mapping->metadata['channex']['rate_plan_id'] ?? null));

        // One read per Channex property, which holds every rate plan's nights.
        foreach ($due->groupBy(fn (ChannelListing $mapping): string => $mapping->metadata['channex']['property_id']) as $propertyId => $mappings) {
            try {
                $calendar = $this->adapter->calendarFor($account, (string) $propertyId, $from, $to);
            } catch (ChannexRequestException $e) {
                // Not marked as done, so the next pull tries again.
                $report['failed'] += $mappings->count();
                $report['issues'][] = 'Channex\'s prices could not be read: '.$e->getMessage();

                continue;
            }

            foreach ($mappings as $mapping) {
                try {
                    $written = $this->seedOne($mapping, $calendar[$mapping->metadata['channex']['rate_plan_id']] ?? [], $from, $to);
                } catch (Throwable) {
                    $report['failed']++;
                    $report['issues'][] = sprintf('%s could not be given Channex\'s prices. Retry Pull.', $mapping->external_name ?? 'A room type');

                    continue;
                }

                if ($written === null) {
                    $report['skipped']++;
                } else {
                    $report['imported']++;
                    $report['nights'] += $written;
                }
            }
        }

        return $report;
    }

    /**
     * Copy one rate plan's nights onto one listing.
     *
     * @param  array<string, mixed>  $nights  date => Channex's values for it
     * @return int|null nights written, or null when the listing was left alone
     */
    private function seedOne(ChannelListing $mapping, array $nights, CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $listing = $mapping->listing;
        $currency = (string) $listing->currency;

        return DB::transaction(function () use ($mapping, $listing, $nights, $from, $to, $currency): ?int {
            $alreadyPriced = CalendarDay::query()
                ->where('listing_id', $listing->getKey())
                ->whereBetween('calendar_date', [$from->toDateString(), $to->toDateString()])
                ->exists();

            $planCurrency = $mapping->metadata['channex']['currency'] ?? null;
            $sameCurrency = ! is_string($planCurrency) || strtoupper($planCurrency) === strtoupper($currency);

            if ($alreadyPriced || ! $sameCurrency) {
                $this->markDone($mapping);

                return null;
            }

            $rates = [];

            foreach ($nights as $date => $night) {
                $rate = is_array($night) ? ($night['rate'] ?? null) : null;

                if (is_string($rate) && is_numeric($rate) && (float) $rate > 0) {
                    $rates[(string) $date] = Money::fromDecimal($rate, $currency)->minorUnits;
                }
            }

            // The price most nights carry, as the property's own where it has
            // none. Listings inherit it, so every night at that price needs no
            // override at all.
            if ($rates !== [] && $listing->baseRate()->minorUnits <= 0) {
                $counts = array_count_values($rates);
                arsort($counts);
                $listing->property?->forceFill(['base_rate' => (int) array_key_first($counts)])->save();
                $listing->unsetRelation('property')->load('property');
            }

            $base = $listing->baseRate()->minorUnits;
            $minimum = $listing->minimumNights();
            $maximum = $listing->maximumNights();
            $written = 0;

            foreach ($nights as $date => $night) {
                if (! is_array($night)) {
                    continue;
                }

                $attributes = array_filter([
                    'rate_override' => isset($rates[$date]) && $rates[$date] !== $base ? $rates[$date] : null,
                    'minimum_nights' => is_numeric($night['min_stay_arrival'] ?? null) && (int) $night['min_stay_arrival'] > 0 && (int) $night['min_stay_arrival'] !== $minimum
                        ? (int) $night['min_stay_arrival'] : null,
                    // Zero is Channex's "no maximum".
                    'maximum_nights' => is_numeric($night['max_stay'] ?? null) && (int) $night['max_stay'] > 0 && (int) $night['max_stay'] !== $maximum
                        ? (int) $night['max_stay'] : null,
                    'closed_to_arrival' => ($night['closed_to_arrival'] ?? false) === true ? true : null,
                    'closed_to_departure' => ($night['closed_to_departure'] ?? false) === true ? true : null,
                    'is_blocked' => ($night['stop_sell'] ?? false) === true ? true : null,
                ], static fn (mixed $value): bool => $value !== null);

                if ($attributes === []) {
                    continue;
                }

                CalendarDay::query()->create($attributes + [
                    'organization_id' => $listing->organization_id,
                    'listing_id' => $listing->getKey(),
                    'calendar_date' => (string) $date,
                    'note' => 'Copied from Channex when this room type was linked.',
                ]);
                $written++;
            }

            $this->markDone($mapping);

            return $written;
        });
    }

    private function markDone(ChannelListing $mapping): void
    {
        $metadata = $mapping->metadata ?? [];
        $metadata['channex']['calendar_imported_at'] = now()->toIso8601String();

        $mapping->forceFill(['metadata' => $metadata])->save();
    }
}
