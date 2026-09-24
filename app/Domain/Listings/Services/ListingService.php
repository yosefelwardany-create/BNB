<?php

declare(strict_types=1);

namespace App\Domain\Listings\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Listings\Events\ListingPublished;
use App\Domain\Listings\Events\ListingUnpublished;
use App\Domain\Listings\Events\ListingUpdated;
use App\Domain\Listings\Exceptions\ListingNotPublishableException;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingVersion;
use App\Domain\Platform\Services\PlanEnforcement;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Creating, versioning and publishing listings.
 *
 * Every content change is snapshotted before it takes effect, because channels
 * review published content and an operator has to be able to see what was live
 * at any point and put it back.
 */
class ListingService
{
    /**
     * Fields whose change is a content change worth versioning. Purely
     * internal fields (the listing's own label, its ordering) are not.
     *
     * @var list<string>
     */
    private const VERSIONED_FIELDS = [
        'title', 'summary', 'description', 'space_description',
        'neighbourhood_description', 'transit_description', 'house_rules',
        'check_in_instructions', 'check_out_instructions',
        'max_occupancy', 'bedrooms', 'bathrooms', 'beds',
        'base_rate', 'cleaning_fee', 'extra_guest_fee', 'extra_guest_after',
        'minimum_nights', 'maximum_nights', 'check_in_time', 'check_out_time',
        'cancellation_policy_id', 'instant_book',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Property $property, array $attributes): Listing
    {
        return DB::transaction(function () use ($property, $attributes): Listing {
            $listing = new Listing;

            $listing->fill($attributes);
            $listing->organization_id = $property->organization_id;
            $listing->property_id = $property->getKey();
            // A listing always transacts in its property's currency; allowing
            // them to differ would mean a rate that means two things.
            $listing->currency = $property->currency;
            $listing->created_by_id = auth()->id();
            $listing->name = $attributes['name'] ?? $property->name;

            // The first listing for a property becomes the primary one.
            if (! $property->listings()->exists()) {
                $listing->is_primary = true;
            }

            $listing->save();

            $this->snapshot($listing, reason: 'Listing created');

            return $listing;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Listing $listing, array $attributes, ?string $reason = null): Listing
    {
        return DB::transaction(function () use ($listing, $attributes, $reason): Listing {
            // The currency is the property's, never the listing's own choice.
            unset($attributes['currency'], $attributes['property_id'], $attributes['organization_id']);

            $listing->fill($attributes);

            $changed = array_values(array_intersect(
                array_keys($listing->getDirty()),
                self::VERSIONED_FIELDS,
            ));

            $listing->save();

            if ($changed !== []) {
                $this->snapshot($listing, $changed, $reason);

                ListingUpdated::dispatch($listing, $changed);
            }

            return $listing;
        });
    }

    /**
     * Put a listing on sale.
     */
    public function publish(Listing $listing): Listing
    {
        // Counted on publication rather than creation: a draft costs nothing
        // to hold, and a tenant at their cap should still be able to prepare
        // the next listing and swap which one is live.
        if ($listing->published_at === null) {
            app(PlanEnforcement::class)->assertCanAdd('max_listings');
        }

        $blockers = $this->publicationBlockers($listing);

        if ($blockers !== []) {
            throw new ListingNotPublishableException(
                'This listing cannot be published: '.implode('; ', $blockers)
            );
        }

        return DB::transaction(function () use ($listing): Listing {
            $listing->status = ListingStatus::Published;
            $listing->published_at ??= now();
            $listing->save();

            $this->snapshot($listing, reason: 'Listing published');

            ListingPublished::dispatch($listing);

            return $listing;
        });
    }

    /**
     * Take a listing off sale while keeping its channel mappings, so it can be
     * brought back in one action.
     */
    public function pause(Listing $listing, ?string $reason = null): Listing
    {
        $listing->status = ListingStatus::Paused;
        $listing->save();

        $this->audit->record(
            action: 'listing.paused',
            subject: $listing,
            description: $reason ?? 'Listing paused',
        );

        ListingUnpublished::dispatch($listing, $reason);

        return $listing;
    }

    public function archive(Listing $listing, ?string $reason = null): Listing
    {
        $listing->status = ListingStatus::Archived;
        $listing->save();

        $this->audit->record(
            action: 'listing.archived',
            subject: $listing,
            description: $reason ?? 'Listing archived',
        );

        ListingUnpublished::dispatch($listing, $reason);

        return $listing;
    }

    /**
     * What stands between a listing and being published.
     *
     * Channels reject on these, so catching them here saves an operator a
     * round trip through a channel's review queue.
     *
     * @return list<string>
     */
    public function publicationBlockers(Listing $listing): array
    {
        $blockers = [];
        $property = $listing->property;

        if ($property === null) {
            return ['the listing is not attached to a property'];
        }

        if (! $property->isBookable()) {
            $blockers[] = 'the property is '.$property->status->value.' rather than active';
        }

        if (blank($listing->displayTitle())) {
            $blockers[] = 'a title is required';
        }

        if (blank($listing->resolved('description'))) {
            $blockers[] = 'a description is required';
        }

        if ($listing->effectivePhotos() === []) {
            $blockers[] = 'at least one photo is required';
        }

        if ($listing->baseRate()->isZero() || $listing->baseRate()->isNegative()) {
            $blockers[] = 'a positive base rate is required';
        }

        if ($listing->maxOccupancy() < 1) {
            $blockers[] = 'maximum occupancy must be at least one';
        }

        if ($listing->inventoryScope() === 'unit_type' && $listing->unitType?->sellableUnitCount() === 0) {
            $blockers[] = 'the unit type has no sellable units';
        }

        if ($listing->inventoryScope() === 'unit' && ! ($listing->unit?->isSellable() ?? false)) {
            $blockers[] = 'the unit is not sellable';
        }

        return $blockers;
    }

    /**
     * Restore a previous version's content. The restore is itself recorded as
     * a new version, so the history remains append-only.
     */
    public function restoreVersion(Listing $listing, ListingVersion $version): Listing
    {
        if ($version->listing_id !== $listing->getKey()) {
            throw new ListingNotPublishableException('That version belongs to a different listing.');
        }

        return DB::transaction(function () use ($listing, $version): Listing {
            $snapshot = array_intersect_key(
                $version->snapshot,
                array_flip(self::VERSIONED_FIELDS),
            );

            $listing->fill($snapshot);
            $listing->save();

            $this->snapshot(
                $listing,
                array_keys($snapshot),
                sprintf('Restored version %d', $version->version),
            );

            ListingUpdated::dispatch($listing, array_keys($snapshot));

            return $listing;
        });
    }

    /**
     * Record the listing's current content as a new version.
     *
     * @param  list<string>  $changedFields
     */
    public function snapshot(Listing $listing, array $changedFields = [], ?string $reason = null): ListingVersion
    {
        $next = (int) ListingVersion::query()
            ->where('listing_id', $listing->getKey())
            ->max('version') + 1;

        return ListingVersion::query()->create([
            'organization_id' => $listing->organization_id,
            'listing_id' => $listing->getKey(),
            'version' => $next,
            'snapshot' => $this->contentSnapshot($listing),
            'changed_fields' => $changedFields === [] ? null : $changedFields,
            'reason' => $reason,
            'created_by_id' => auth()->id(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contentSnapshot(Listing $listing): array
    {
        $snapshot = [];

        foreach (self::VERSIONED_FIELDS as $field) {
            $value = $listing->getAttribute($field);

            $snapshot[$field] = $value instanceof \DateTimeInterface
                ? $value->format('H:i:s')
                : $value;
        }

        $snapshot['status'] = $listing->status->value;
        $snapshot['resolved'] = $listing->resolvedAttributes();

        return $snapshot;
    }
}
