<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\Contracts\ImportsConversations;
use App\Domain\Integrations\Exceptions\HostexRequestException;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings a channel's world into the platform.
 *
 * The piece that was missing. Every part of this existed — an adapter that could
 * read listings, an importer that could map them, a reservation importer, a
 * message importer — and nothing called any of them on a schedule. Connecting a
 * channel produced a row in a table and no data, which is the worst possible
 * version of an integration: it looks connected.
 *
 * ## The order is the design
 *
 * Listings first, always. A reservation arrives naming a listing, and the
 * mapping is what says which of ours that is; pulling bookings before the
 * mapping exists produces a log full of "a channel sent a booking for an
 * unmapped listing" and no bookings. Messages last, because a thread attaches
 * to a booking where there is one.
 *
 * ## What it does not do
 *
 * It does not push. Availability and rates go out through
 * {@see ChannelSynchroniser}, which has its own dirty-tracking and its own
 * backoff, and running both from one place would mean a slow pull delaying every
 * price change.
 *
 * It does not stop on the first failure. A channel that cannot serve reviews is
 * not a reason to abandon the bookings it just served — each stage records what
 * happened and the next one runs.
 */
class ChannelPuller
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly ChannelListingImporter $listings,
        private readonly ReservationImporter $reservations,
        private readonly ChannelMessageImporter $messages,
    ) {}

    /**
     * Pull everything this account is configured to receive.
     *
     * @return array<string, mixed>
     */
    public function pull(ChannelAccount $account, bool $full = false): array
    {
        $adapter = $this->adapters->make($account->channel);

        $startedAt = CarbonImmutable::now();

        /*
         * Where to resume from.
         *
         * A full pull asks for everything, which is what a first connection
         * wants: a property connected this morning has months behind it. After
         * that, an incremental pull from the last run with a day of overlap —
         * because a channel's idea of "changed at" and ours are not the same
         * clock, and re-reading a day costs a few requests while missing a
         * booking costs a guest.
         */
        $since = $full || $account->last_synced_at === null
            ? null
            : CarbonImmutable::parse($account->last_synced_at)->subDay();

        $outcome = [
            'listings' => $this->pullListings($account),
            'reservations' => $this->pullReservations($account, $since),
            'messages' => $this->pullMessages($account, $adapter, $since),
        ];

        // Written only after every stage has run, and set to when the pull
        // *started*: anything that changed while it was running must be picked
        // up next time rather than skipped as already seen.
        $account->forceFill(['last_synced_at' => $startedAt])->save();

        return $outcome + ['since' => $since?->toIso8601String(), 'at' => $startedAt->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullListings(ChannelAccount $account): array
    {
        return $this->attempt('listings', fn (): array => $this->listings->importFor($account));
    }

    /**
     * @return array<string, mixed>
     */
    private function pullReservations(ChannelAccount $account, ?CarbonImmutable $since): array
    {
        if (! $account->import_reservations) {
            return ['skipped' => 'This connection is not set to import reservations.'];
        }

        return $this->attempt(
            'reservations',
            fn (): array => $this->reservations->importFor($account, $since),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pullMessages(
        ChannelAccount $account,
        ChannelAdapterInterface $adapter,
        ?CarbonImmutable $since,
    ): array {
        if (! $account->sync_messages) {
            return ['skipped' => 'This connection is not set to sync messages.'];
        }

        if (! $adapter instanceof ImportsConversations) {
            // Said rather than silently counted as zero: "this channel cannot
            // hand over its threads" and "there were no messages" look the same
            // in a count and are nothing alike.
            return ['skipped' => sprintf('%s cannot hand over its conversations.', $adapter->displayName())];
        }

        return $this->attempt('messages', function () use ($account, $adapter, $since): array {
            $payloads = $adapter->importConversations($account, $since?->toDateTimeImmutable());

            $recorded = 0;

            foreach ($payloads as $payload) {
                $role = mb_strtolower((string) ($payload->attachments['sender_role'] ?? 'guest'));

                $message = $this->messages->record(
                    $account,
                    $payload,
                    fromGuest: ! in_array($role, ['host', 'outbound', 'owner', 'manager'], true),
                );

                if ($message !== null) {
                    $recorded++;
                }
            }

            return ['seen' => count($payloads), 'recorded' => $recorded];
        });
    }

    /**
     * Run one stage, keeping its failure rather than letting it end the pull.
     *
     * A channel that cannot serve reviews is not a reason to abandon the
     * bookings it just served.
     *
     * @param  callable(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function attempt(string $stage, callable $work): array
    {
        try {
            return $work();
        } catch (HostexRequestException $e) {
            // Its own words, and whether it is worth trying again — the
            // difference between a rate limit and a revoked token, which is the
            // first thing somebody debugging this needs to know.
            return ['failed' => $e->getMessage(), 'retryable' => $e->retryable];
        } catch (Throwable $e) {
            Log::warning('A channel pull stage failed.', [
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return ['failed' => $e->getMessage(), 'retryable' => false];
        }
    }
}
