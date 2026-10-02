<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Listings\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Discovering what a channel already has, and attaching it to what we have.
 *
 * Every other part of the channel machinery takes the mapping as given: a
 * reservation arrives, the mapping says which listing it belongs to, and the
 * booking lands. Nothing created the mappings. This does.
 *
 * ## Why matching is deliberately timid
 *
 * The cost of a wrong match is not a tidy-up. A mapping is what decides which
 * calendar a booking lands on, so one that points at the wrong listing
 * double-books one property and leaves another empty, and nobody finds out
 * until a guest is standing outside. Against that, the cost of a *missing*
 * match is somebody picking from a dropdown once.
 *
 * So this links a channel listing to one of ours only when the name matches
 * exactly, once, with nothing else close. Everything else is imported and left
 * **unmapped**, which is a visible state asking to be resolved, rather than a
 * guess that looks resolved. The import is idempotent and never overwrites a
 * mapping a person made: a human decision outranks a string comparison.
 */
class ChannelListingImporter
{
    public function __construct(private readonly ChannelAdapterRegistry $adapters) {}

    /**
     * Pull the channel's listings and reconcile them with ours.
     *
     * @return array{discovered: int, created: int, updated: int, matched: int, unmapped: int}
     */
    public function importFor(ChannelAccount $account): array
    {
        $adapter = $this->adapters->make($account->channel);

        $payloads = $adapter->importListings($account);

        $counts = ['discovered' => count($payloads), 'created' => 0, 'updated' => 0, 'matched' => 0, 'unmapped' => 0];

        // Loaded once rather than queried per payload: an estate of a hundred
        // listings would otherwise be a hundred lookups to match a hundred
        // names.
        $ours = Listing::query()->with('property')->get();

        foreach ($payloads as $payload) {
            if ($payload->externalListingId === null) {
                continue;
            }

            $this->reconcile($account, $payload, $ours, $counts);
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Listing>  $ours
     * @param  array{discovered: int, created: int, updated: int, matched: int, unmapped: int}  $counts
     */
    private function reconcile(
        ChannelAccount $account,
        ChannelListingPayload $payload,
        $ours,
        array &$counts,
    ): void {
        DB::transaction(function () use ($account, $payload, $ours, &$counts): void {
            $mapping = ChannelListing::query()
                ->where('channel_account_id', $account->getKey())
                ->where('external_listing_id', $payload->externalListingId)
                ->lockForUpdate()
                ->first();

            $fresh = $mapping === null;

            $mapping ??= new ChannelListing([
                'organization_id' => $account->organization_id,
                'channel_account_id' => $account->getKey(),
                'external_listing_id' => $payload->externalListingId,
            ]);

            // Always refreshed: the name on the channel is how a person
            // recognises the row they are being asked to map, and a stale one
            // sends them looking for a listing that was renamed last month.
            $mapping->external_name = $payload->title;
            $mapping->external_url = $payload->extra['url'] ?? $mapping->external_url;
            $mapping->status = $payload->status ?? $mapping->status;
            $mapping->metadata = array_filter([
                'property_type' => $payload->propertyType,
                'max_guests' => $payload->maxGuests,
                'bedrooms' => $payload->bedrooms,
                'city' => $payload->city,
                'country_code' => $payload->countryCode,
                'currency' => $payload->currency,
            ], static fn (mixed $value): bool => $value !== null);

            /*
             * A mapping somebody has already made is never touched.
             *
             * Re-running an import must not relink a listing a person corrected
             * by hand, and it must not relink one it matched itself either —
             * that would make the result depend on how many times the button was
             * pressed.
             */
            if ($mapping->listing_id === null) {
                $match = $this->unambiguousMatch($payload, $ours);

                if ($match !== null) {
                    $mapping->listing_id = $match->getKey();
                    $mapping->property_id = $match->property_id;
                    $counts['matched']++;
                }
            }

            $mapping->is_active = $mapping->listing_id !== null;
            $mapping->save();

            $counts[$fresh ? 'created' : 'updated']++;

            if ($mapping->listing_id === null) {
                $counts['unmapped']++;
            }
        });
    }

    /**
     * The one listing of ours this obviously is, or null.
     *
     * Exactly one candidate, matched on a normalised name. Two listings called
     * "Blue Room" is not a near miss to be broken by ordering — it is the case
     * where a person has to choose, and returning either would be a coin toss
     * that decides where somebody's booking lands.
     *
     * @param  Collection<int, Listing>  $ours
     */
    private function unambiguousMatch(ChannelListingPayload $payload, $ours): ?Listing
    {
        $wanted = $this->normalise($payload->title);

        if ($wanted === '') {
            return null;
        }

        $candidates = $ours->filter(function (Listing $listing) use ($wanted): bool {
            return $this->normalise((string) $listing->name) === $wanted
                || $this->normalise((string) $listing->property?->name) === $wanted;
        });

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * A name reduced to what two humans would call the same.
     *
     * Case, punctuation and runs of whitespace go; the words stay in order.
     * Nothing cleverer, because a fuzzy match that is right nine times in ten is
     * a wrong calendar once in ten.
     */
    private function normalise(string $name): string
    {
        $plain = mb_strtolower(trim($name));
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $plain) ?? $plain;

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }
}
