<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Support\ICalendar;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Two-way iCal synchronisation.
 *
 * This is a real, fully working adapter — iCal is an open protocol and needs
 * no commercial agreement. It is how smaller channels, owner calendars and
 * one-off listing sites are kept in step.
 *
 * Its limits are the protocol's, not the implementation's: an iCal feed
 * carries dates and a label, never prices, guest details or money. Imported
 * events therefore become calendar blocks rather than reservations with
 * financials, which is exactly what the data supports.
 */
class IcalChannelAdapter extends AbstractChannelAdapter
{
    public function key(): string
    {
        return 'ical';
    }

    public function displayName(): string
    {
        return 'iCal feed';
    }

    public function isLive(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return [
            self::CAPABILITY_IMPORT_RESERVATIONS,
            self::CAPABILITY_AVAILABILITY,
        ];
    }

    public function testConnection(ChannelAccount $account): ChannelSyncResult
    {
        $url = $account->credential('import_url');

        if (! is_string($url) || $url === '') {
            return ChannelSyncResult::permanentFailure(
                'missing_url',
                'No iCal import URL has been configured for this connection.',
            );
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $exception) {
            return ChannelSyncResult::transientFailure('unreachable', $exception->getMessage());
        }

        if ($response->failed()) {
            return $response->serverError()
                ? ChannelSyncResult::transientFailure('http_'.$response->status(), 'The feed returned a server error.')
                : ChannelSyncResult::permanentFailure('http_'.$response->status(), 'The feed could not be read.');
        }

        if (! str_contains($response->body(), 'BEGIN:VCALENDAR')) {
            return ChannelSyncResult::permanentFailure(
                'not_icalendar',
                'That URL did not return an iCalendar document.',
            );
        }

        return ChannelSyncResult::success(data: ['events' => count(ICalendar::parse($response->body()))]);
    }

    /**
     * Read the feed and return each event as a reservation payload.
     *
     * The feed has no financial information, so every amount is zero and the
     * importer records these as external blocks rather than inventing revenue.
     *
     * @return list<ChannelReservationPayload>
     */
    public function importReservations(ChannelAccount $account, ?DateTimeImmutable $since = null): array
    {
        $url = $account->credential('import_url');

        if (! is_string($url) || $url === '') {
            return [];
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $externalListingId = (string) ($account->credential('external_listing_id') ?? $account->getKey());
        $payloads = [];

        foreach (ICalendar::parse($response->body()) as $event) {
            if ($event->isCancelled()) {
                continue;
            }

            // Ignore history: a feed usually contains years of past stays and
            // re-importing them on every poll is wasted work.
            if ($since !== null && $event->end < $since) {
                continue;
            }

            $payloads[] = new ChannelReservationPayload(
                externalReservationId: $event->uid,
                externalListingId: $externalListingId,
                status: 'confirmed',
                checkIn: $event->start,
                checkOut: $event->end,
                currency: $account->organization->base_currency ?? 'USD',
                totalAmount: 0,
                guestFirstName: $this->guestNameFromSummary($event->summary),
                confirmationCode: Str::limit($event->uid, 32, ''),
                notes: $event->description,
                raw: [
                    'summary' => $event->summary,
                    'source' => 'ical',
                    // Recorded explicitly so nothing downstream mistakes a
                    // zero total for a free stay.
                    'financials_available' => false,
                ],
            );
        }

        return $payloads;
    }

    /**
     * Availability flows outward through the feed the platform publishes, which
     * the channel polls; there is nothing to push.
     */
    public function pushAvailability($listing, $update): ChannelSyncResult
    {
        return ChannelSyncResult::success(
            data: ['note' => 'iCal is pull-based; the published feed is always current.'],
        );
    }

    /**
     * iCal summaries carry at most a name, and often only "Reserved" or
     * "Blocked". Anything that is clearly not a name is discarded rather than
     * stored as a guest.
     */
    private function guestNameFromSummary(string $summary): ?string
    {
        $summary = trim($summary);

        $generic = ['reserved', 'blocked', 'busy', 'not available', 'unavailable', 'closed', 'airbnb (not available)'];

        if ($summary === '' || in_array(mb_strtolower($summary), $generic, true)) {
            return null;
        }

        // Feeds commonly use "Reserved - Jane Doe" or "CLOSED - Jane Doe".
        if (preg_match('/^(?:reserved|closed|booked)\s*[-–]\s*(.+)$/i', $summary, $matches)) {
            return trim($matches[1]);
        }

        return Str::limit($summary, 80, '');
    }
}
