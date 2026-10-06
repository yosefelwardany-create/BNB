<?php

declare(strict_types=1);

namespace App\Domain\Properties\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Properties\Enums\UnitStatus;
use App\Domain\Properties\Exceptions\PropertyInUseException;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating and maintaining units.
 *
 * Taking a unit off the market has consequences beyond a status flag: the
 * nights already sold in it have to go somewhere, so the service refuses to
 * strand them silently.
 */
class UnitService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Property $property, array $attributes): Unit
    {
        $unit = new Unit;

        $unit->fill($attributes);
        $unit->organization_id = $property->organization_id;
        $unit->property_id = $property->getKey();
        $unit->save();

        // A property with units tracks availability per unit, so creating the
        // first one flips the property into multi-unit mode.
        if (! $property->is_multi_unit && $property->units()->count() > 1) {
            $property->forceFill(['is_multi_unit' => true])->save();
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Unit $unit, array $attributes): Unit
    {
        // A unit cannot be its own ancestor, or availability grouping would
        // recurse forever.
        if (isset($attributes['parent_unit_id']) && $attributes['parent_unit_id'] !== null) {
            $this->assertNoCycle($unit, (string) $attributes['parent_unit_id']);
        }

        $unit->fill($attributes);
        $unit->save();

        return $unit;
    }

    /**
     * Change a unit's operational status.
     *
     * Taking a unit out of service does not cancel its existing reservations —
     * those guests still have a booking — so the caller is told how many are
     * affected and must move them deliberately.
     */
    public function changeStatus(
        Unit $unit,
        string $status,
        ?string $reason = null,
        ?CarbonImmutable $until = null,
    ): Unit {
        $newStatus = UnitStatus::from($status);
        $previous = $unit->status;

        if (! $newStatus->isSellable()) {
            $affected = $unit->reservations()
                ->whereIn('status', ['confirmed', 'checked_in', 'tentative'])
                ->where('check_out_date', '>=', now()->toDateString())
                ->count();

            if ($affected > 0) {
                throw new PropertyInUseException(sprintf(
                    'This unit has %d upcoming reservation(s). Reassign or cancel them before taking it out of service.',
                    $affected,
                ));
            }
        }

        $unit->status = $newStatus;
        $unit->save();

        $this->audit->record(
            action: 'unit.status_changed',
            subject: $unit,
            oldValues: ['status' => $previous->value],
            newValues: ['status' => $newStatus->value],
            description: $reason ?? sprintf('Unit moved from %s to %s', $previous->value, $newStatus->value),
            context: ['until' => $until?->toDateString()],
        );

        return $unit;
    }

    /**
     * Create a run of units from a pattern, e.g. "Room {n}" from 101 for 20
     * units. This is how a building is actually set up; doing it one at a time
     * is not practical for an aparthotel.
     *
     * @param  array{name_pattern: string, start: int, count: int, unit_type_id?: ?string, floor?: ?string, max_occupancy?: ?int, base_rate?: ?int}  $options
     * @return list<Unit>
     */
    public function createFromPattern(Property $property, array $options): array
    {
        return DB::transaction(function () use ($property, $options): array {
            $created = [];
            $existingCodes = $property->units()->pluck('code')->filter()->all();

            for ($i = 0; $i < $options['count']; $i++) {
                $number = (string) ($options['start'] + $i);
                $name = str_replace(['{n}', '{number}'], $number, $options['name_pattern']);

                // Skip rather than fail on a collision, so re-running the
                // helper to fill gaps is safe.
                if (in_array($number, $existingCodes, true)) {
                    continue;
                }

                $created[] = $this->create($property, [
                    'name' => $name,
                    'code' => $number,
                    'unit_type_id' => $options['unit_type_id'] ?? null,
                    'floor' => $options['floor'] ?? null,
                    'max_occupancy' => $options['max_occupancy'] ?? null,
                    'base_rate' => $options['base_rate'] ?? null,
                    'position' => $i,
                ]);
            }

            if ($created !== []) {
                $property->forceFill(['is_multi_unit' => true])->save();

                $this->audit->record(
                    action: 'unit.bulk_created',
                    subject: $property,
                    newValues: ['count' => count($created), 'pattern' => $options['name_pattern']],
                    description: sprintf('Created %d units from a pattern', count($created)),
                );
            }

            return $created;
        });
    }

    /**
     * Retire a unit. Its reservation history is preserved.
     */
    public function archive(Unit $unit): Unit
    {
        $upcoming = $unit->reservations()
            ->whereIn('status', ['confirmed', 'checked_in', 'tentative'])
            ->where('check_out_date', '>=', now()->toDateString())
            ->count();

        if ($upcoming > 0) {
            throw new PropertyInUseException(sprintf(
                'This unit has %d upcoming reservation(s) and cannot be removed yet.',
                $upcoming,
            ));
        }

        $unit->is_bookable = false;
        $unit->status = UnitStatus::OutOfService;
        $unit->save();

        // Soft delete keeps the row resolvable from historical reservations.
        $unit->delete();

        return $unit;
    }

    private function assertNoCycle(Unit $unit, string $parentId): void
    {
        if ($parentId === $unit->getKey()) {
            throw new PropertyInUseException('A unit cannot be its own parent.');
        }

        $seen = [];
        $current = Unit::query()->find($parentId);

        while ($current !== null) {
            if ($current->getKey() === $unit->getKey()) {
                throw new PropertyInUseException(
                    'That would make the unit its own ancestor.'
                );
            }

            if (in_array($current->getKey(), $seen, true)) {
                break;
            }

            $seen[] = $current->getKey();
            $current = $current->parent_unit_id === null
                ? null
                : Unit::query()->find($current->parent_unit_id);
        }
    }
}
