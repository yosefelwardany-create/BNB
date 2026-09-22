<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\Owners\Exceptions\OwnershipConflictException;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who owns what, and for how long.
 *
 * Fractional and joint ownership are ordinary in this industry, and properties
 * change hands mid-year, so a share is never a simple column on the property —
 * it is a dated record, and several of them can overlap.
 *
 * The invariant this service exists to hold is that **no date is allocated more
 * than 100%**. Without it, an owner statement quietly pays out more than the
 * property earned, and the error surfaces months later as a reconciliation
 * mismatch nobody can trace. Checking it at the point a share is written is the
 * only place the answer is cheap and the cause is obvious.
 *
 * Under-allocation is allowed and deliberate: a manager may own the remainder,
 * or a share may simply not have been recorded yet. Refusing that would stop
 * people entering what they actually know.
 */
class OwnershipLedger
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Record or extend an owner's share of a property.
     *
     * @param  array{ownership_percentage?: float, is_primary?: bool, starts_on?: string|null, ends_on?: string|null, notes?: string|null}  $attributes
     *
     * @throws OwnershipConflictException
     */
    public function assign(Property $property, Owner $owner, array $attributes = []): PropertyOwnership
    {
        return DB::transaction(function () use ($property, $owner, $attributes): PropertyOwnership {
            $ownership = new PropertyOwnership;

            $ownership->fill($attributes);
            $ownership->organization_id = $this->tenancy->organizationOrFail()->getKey();
            $ownership->property_id = $property->getKey();
            $ownership->owner_id = $owner->getKey();

            $this->assertShareFits($property, $ownership);

            // The first share recorded for a property is its primary one
            // unless told otherwise: statements and correspondence need a
            // single addressee, and leaving that unset makes every later
            // screen guess.
            if (! $ownership->is_primary && ! $this->hasPrimary($property)) {
                $ownership->is_primary = true;
            }

            $ownership->save();

            if ($ownership->is_primary) {
                $this->demoteOtherPrimaries($property, $ownership);
            }

            return $ownership;
        });
    }

    /**
     * Change an existing share.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OwnershipConflictException
     */
    public function update(PropertyOwnership $ownership, array $attributes): PropertyOwnership
    {
        return DB::transaction(function () use ($ownership, $attributes): PropertyOwnership {
            $ownership->fill($attributes);

            $property = $ownership->property;

            if ($property !== null) {
                $this->assertShareFits($property, $ownership);
            }

            $ownership->save();

            if ($ownership->is_primary && $property !== null) {
                $this->demoteOtherPrimaries($property, $ownership);
            }

            return $ownership;
        });
    }

    /**
     * End an owner's share.
     *
     * Closed with an end date rather than deleted. Every statement, payout and
     * ledger entry already produced was attributed using this share, and a
     * deleted row makes all of that unexplainable — the money moved, and
     * nothing says why.
     */
    public function end(PropertyOwnership $ownership, ?CarbonImmutable $on = null): PropertyOwnership
    {
        $ownership->forceFill([
            'ends_on' => ($on ?? CarbonImmutable::today())->toDateString(),
        ])->save();

        return $ownership;
    }

    /**
     * Shares in force for a property on a date, with the remainder unallocated.
     *
     * @return array{shares: Collection<int, PropertyOwnership>, allocated: float, unallocated: float}
     */
    public function positionOn(Property $property, CarbonImmutable $date): array
    {
        $shares = PropertyOwnership::query()
            ->with('owner')
            ->where('property_id', $property->getKey())
            ->inForceOn($date)
            ->get();

        $allocated = round($shares->sum(fn (PropertyOwnership $share): float => $share->share()), 4);

        return [
            'shares' => $shares,
            'allocated' => $allocated,
            'unallocated' => round(max(0.0, 100.0 - $allocated), 4),
        ];
    }

    /**
     * Refuse a share that would over-allocate any date it covers.
     *
     * Checked at the boundaries of the overlapping periods rather than day by
     * day: the total can only change where a share starts or ends, so those
     * dates are the complete set of candidates however long the periods are.
     * A hundred-year share therefore costs the same to validate as a week.
     *
     * @throws OwnershipConflictException
     */
    private function assertShareFits(Property $property, PropertyOwnership $candidate): void
    {
        $existing = PropertyOwnership::query()
            ->where('property_id', $property->getKey())
            ->when($candidate->exists, fn ($q) => $q->whereKeyNot($candidate->getKey()))
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $conflicts = [];

        foreach ($this->boundaryDates($existing, $candidate) as $date) {
            if (! $candidate->isInForceOn($date)) {
                continue;
            }

            $total = $candidate->share() + $existing
                ->filter(fn (PropertyOwnership $share): bool => $share->isInForceOn($date))
                ->sum(fn (PropertyOwnership $share): float => $share->share());

            // A hundredth of a percent of slack, so decimal(7,4) rounding on
            // a three-way split cannot manufacture a conflict.
            if (round($total, 4) > 100.0001) {
                $conflicts[] = ['date' => $date->toDateString(), 'total' => round($total, 4)];
            }
        }

        if ($conflicts !== []) {
            throw new OwnershipConflictException($conflicts);
        }
    }

    /**
     * Every date on which the allocated total could change.
     *
     * @param  Collection<int, PropertyOwnership>  $existing
     * @return list<CarbonImmutable>
     */
    private function boundaryDates(Collection $existing, PropertyOwnership $candidate): array
    {
        $dates = [];

        $add = function (?CarbonImmutable $date) use (&$dates): void {
            if ($date !== null) {
                $dates[$date->toDateString()] = $date;
            }
        };

        $add($candidate->starts_on);
        $add($candidate->ends_on);

        foreach ($existing as $share) {
            $add($share->starts_on);
            $add($share->ends_on);

            // The day after an existing share ends is a boundary too: that is
            // where its contribution drops away.
            $add($share->ends_on?->addDay());
        }

        // An open-ended share on both sides has no boundary of its own, so
        // today stands in for "the ongoing position".
        if ($dates === []) {
            $add(CarbonImmutable::today());
        }

        return array_values($dates);
    }

    private function hasPrimary(Property $property): bool
    {
        return PropertyOwnership::query()
            ->where('property_id', $property->getKey())
            ->where('is_primary', true)
            ->exists();
    }

    private function demoteOtherPrimaries(Property $property, PropertyOwnership $keep): void
    {
        PropertyOwnership::query()
            ->where('property_id', $property->getKey())
            ->whereKeyNot($keep->getKey())
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
