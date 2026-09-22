<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of a reservation's state transitions.
 *
 * Separate from the general audit log because this history is queried
 * constantly — booking-to-confirmation time, who cancelled what and why — and
 * should not require scanning every change in the system.
 */
class ReservationStatusChange extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'reservation_id',
        'from_status',
        'to_status',
        'reason',
        'actor_type',
        'user_id',
        'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException('Reservation status history is immutable.');
        });

        static::deleting(function (): bool {
            throw new \RuntimeException('Reservation status history is immutable.');
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
