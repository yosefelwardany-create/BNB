<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room and its sleeping arrangement.
 *
 * Channels require this in structured form ("bedroom 1: one king bed"), and
 * guests filter on it, so it is modelled properly rather than left to prose in
 * the description.
 */
class PropertyRoom extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const BED_TYPES = [
        'single', 'double', 'queen', 'king', 'super_king', 'bunk',
        'sofa_bed', 'futon', 'air_mattress', 'crib', 'toddler_bed', 'floor_mattress',
    ];

    protected $fillable = [
        'organization_id',
        'property_id',
        'unit_id',
        'name',
        'room_type',
        'position',
        'beds',
        'has_ensuite',
    ];

    protected function casts(): array
    {
        return [
            'beds' => 'array',
            'has_ensuite' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Total sleeping places in this room.
     */
    public function bedCount(): int
    {
        $total = 0;

        foreach ($this->beds ?? [] as $bed) {
            $total += (int) ($bed['count'] ?? 1);
        }

        return $total;
    }
}
