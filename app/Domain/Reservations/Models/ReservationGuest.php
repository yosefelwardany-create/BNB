<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Models;

use App\Domain\Guests\Models\Guest;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional named guest on a booking.
 *
 * Many jurisdictions require every adult occupant to be registered, and
 * building access control often needs names per person. Identity document
 * numbers are encrypted at rest.
 */
class ReservationGuest extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'reservation_id',
        'guest_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'guest_type',
        'date_of_birth',
        'document_type',
        'document_number',
        'nationality',
        'is_primary',
    ];

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'is_primary' => 'boolean',
            'document_number' => 'encrypted',
        ];
    }

    protected $attributes = [
        'guest_type' => 'adult',
        'is_primary' => false,
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
