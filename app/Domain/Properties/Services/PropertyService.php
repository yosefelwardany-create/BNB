<?php

declare(strict_types=1);

namespace App\Domain\Properties\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Events\PropertyActivated;
use App\Domain\Properties\Events\PropertyArchived;
use App\Domain\Properties\Events\PropertyCreated;
use App\Domain\Properties\Exceptions\PropertyInUseException;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyRoom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and changing properties.
 *
 * The rules that live here rather than in a controller are the ones that must
 * hold no matter how a property is created — through the API, an import, or a
 * channel connection.
 */
class PropertyService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $amenityIds
     */
    public function create(array $attributes, array $amenityIds = []): Property
    {
        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($attributes, $amenityIds, $organization): Property {
            $property = new Property;

            $property->fill($attributes);

            // A property's timezone and currency are load-bearing: scheduled
            // work and every monetary amount depend on them. They default from
            // the organization rather than being left null.
            $property->timezone ??= $organization->timezone;
            $property->currency = strtoupper($attributes['currency'] ?? $organization->base_currency);
            $property->slug = $this->uniqueSlug($attributes['slug'] ?? $attributes['name']);
            $property->created_by_id = auth()->id();

            $property->save();

            if ($amenityIds !== []) {
                $this->syncAmenities($property, $amenityIds);
            }

            PropertyCreated::dispatch($property);

            return $property;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Property $property, array $attributes): Property
    {
        return DB::transaction(function () use ($property, $attributes): Property {
            // Changing the currency of a property that has already traded
            // would silently reinterpret every historical amount recorded
            // against it, so it is refused.
            if (isset($attributes['currency'])
                && strtoupper($attributes['currency']) !== $property->currency
                && $this->hasFinancialHistory($property)) {
                throw new PropertyInUseException(
                    'This property already has reservations or ledger entries in '
                    .$property->currency.'. Its currency can no longer be changed.'
                );
            }

            if (isset($attributes['slug']) && $attributes['slug'] !== $property->slug) {
                $attributes['slug'] = $this->uniqueSlug($attributes['slug'], $property->getKey());
            }

            if (isset($attributes['currency'])) {
                $attributes['currency'] = strtoupper($attributes['currency']);
            }

            $property->fill($attributes);
            $property->save();

            return $property;
        });
    }

    /**
     * Put a property on the market.
     *
     * Activation is gated on the things a guest-facing listing cannot do
     * without, so a property cannot silently go live half-configured.
     */
    public function activate(Property $property): Property
    {
        $missing = $this->activationBlockers($property);

        if ($missing !== []) {
            throw new PropertyInUseException(
                'This property is not ready to be activated: '.implode('; ', $missing)
            );
        }

        $property->status = PropertyStatus::Active;
        $property->activated_at ??= now();
        $property->save();

        PropertyActivated::dispatch($property);

        return $property;
    }

    /**
     * What still stands between a property and going live.
     *
     * @return list<string>
     */
    public function activationBlockers(Property $property): array
    {
        $missing = [];

        if (blank($property->address_line_1) || blank($property->city) || blank($property->country_code)) {
            $missing[] = 'a complete address is required';
        }

        if ($property->max_occupancy < 1) {
            $missing[] = 'maximum occupancy must be at least one';
        }

        if ((int) $property->base_rate <= 0) {
            $missing[] = 'a base nightly rate is required';
        }

        if ($property->is_multi_unit && $property->units()->sellable()->count() === 0) {
            $missing[] = 'a multi-unit property needs at least one sellable unit';
        }

        return $missing;
    }

    public function deactivate(Property $property, ?string $reason = null): Property
    {
        $property->status = PropertyStatus::Inactive;
        $property->save();

        $this->audit->record(
            action: 'property.deactivated',
            subject: $property,
            description: $reason ?? 'Property taken off the market',
        );

        return $property;
    }

    /**
     * Retire a property.
     *
     * Deletion is never the answer for a property that has traded: its
     * reservations, ledger entries and owner statements must stay resolvable
     * for years. Archiving takes it out of every operational surface while
     * keeping the history intact.
     */
    public function archive(Property $property, ?string $reason = null): Property
    {
        $future = $property->reservations()
            ->whereIn('status', ['confirmed', 'checked_in', 'tentative'])
            ->where('check_out_date', '>=', now()->toDateString())
            ->count();

        if ($future > 0) {
            throw new PropertyInUseException(sprintf(
                'This property has %d upcoming reservation(s). Move or cancel them before archiving it.',
                $future,
            ));
        }

        return DB::transaction(function () use ($property, $reason): Property {
            $property->status = PropertyStatus::Archived;
            $property->save();

            // Listings for an archived property must stop being sold.
            $property->listings()
                ->whereIn('status', ['published', 'paused'])
                ->update(['status' => 'archived']);

            $this->audit->record(
                action: 'property.archived',
                subject: $property,
                description: $reason ?? 'Property archived',
                context: ['reason' => $reason],
            );

            PropertyArchived::dispatch($property);

            return $property;
        });
    }

    /**
     * @param  list<string>  $amenityIds
     */
    public function syncAmenities(Property $property, array $amenityIds): void
    {
        $valid = Amenity::query()
            ->availableTo($property->organization_id)
            ->whereIn('id', $amenityIds)
            ->pluck('id')
            ->all();

        $property->amenities()->sync($valid);
    }

    /**
     * Replace the sleeping arrangement and keep the property's bed and bedroom
     * counts in step with it, since the two disagreeing is a common source of
     * channel rejections.
     *
     * @param  list<array{name: string, room_type: string, beds?: list<array{type: string, count: int}>, has_ensuite?: bool}>  $rooms
     */
    public function syncRooms(Property $property, array $rooms): void
    {
        DB::transaction(function () use ($property, $rooms): void {
            $property->rooms()->delete();

            $bedrooms = 0;
            $beds = 0;

            foreach (array_values($rooms) as $position => $room) {
                $record = PropertyRoom::query()->create([
                    'organization_id' => $property->organization_id,
                    'property_id' => $property->getKey(),
                    'name' => $room['name'],
                    'room_type' => $room['room_type'],
                    'position' => $position,
                    'beds' => $room['beds'] ?? [],
                    'has_ensuite' => $room['has_ensuite'] ?? false,
                ]);

                if ($room['room_type'] === 'bedroom') {
                    $bedrooms++;
                }

                $beds += $record->bedCount();
            }

            $property->forceFill(['bedrooms' => $bedrooms, 'beds' => $beds])->save();
        });
    }

    /**
     * Whether anything financial has been recorded against the property.
     */
    private function hasFinancialHistory(Property $property): bool
    {
        if ($property->reservations()->exists()) {
            return true;
        }

        return DB::table('journal_lines')
            ->where('organization_id', $property->organization_id)
            ->where('property_id', $property->getKey())
            ->exists();
    }

    private function uniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'property';
        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?string $ignoreId): bool
    {
        return Property::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
