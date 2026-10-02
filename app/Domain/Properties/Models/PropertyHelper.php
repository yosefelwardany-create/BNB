<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Domain\Operations\Models\Vendor;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody to call about one property.
 *
 * A helper is a **role at a property**, not a person. "The cleaner for Yellow"
 * is the thing being recorded; who fills it changes, and when it does, one row
 * is repointed rather than a list being rewritten.
 *
 * It points at a vendor, a member of staff, or neither. The third case is the
 * one that makes the list usable: at a small operator most helpers are a mobile
 * number in somebody's phone, and refusing to record one until a vendor record
 * exists would leave the list empty and the agent escalating to nobody. Where a
 * vendor or user *is* linked, their record is the truth and the local columns
 * are ignored — one phone number, one place to correct it.
 */
class PropertyHelper extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const ROLE_MANAGER = 'manager';

    public const ROLE_CLEANER = 'cleaner';

    public const ROLE_MAINTENANCE = 'maintenance';

    public const ROLE_ELECTRICIAN = 'electrician';

    public const ROLE_PLUMBER = 'plumber';

    public const ROLE_OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_MANAGER,
            self::ROLE_CLEANER,
            self::ROLE_MAINTENANCE,
            self::ROLE_ELECTRICIAN,
            self::ROLE_PLUMBER,
            self::ROLE_OTHER,
        ];
    }

    protected $fillable = [
        'organization_id', 'property_id', 'role', 'label',
        'vendor_id', 'user_id', 'name', 'phone', 'email',
        'notes', 'is_primary', 'position',
    ];

    protected $attributes = [
        'role' => self::ROLE_OTHER,
        'is_primary' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What to call this helper.
     *
     * The linked record first, because that is the one somebody maintains.
     */
    public function displayName(): string
    {
        if ($this->relationLoaded('vendor') && $this->vendor !== null) {
            return (string) ($this->vendor->contact_name ?: $this->vendor->name);
        }

        if ($this->relationLoaded('user') && $this->user !== null) {
            return $this->user->fullName();
        }

        return (string) ($this->name ?: $this->label ?: $this->roleLabel());
    }

    /**
     * The number to ring, or null when nobody recorded one.
     *
     * Null rather than an empty string, because "we have no number for the
     * electrician" is a thing a screen should be able to say out loud at two in
     * the morning.
     */
    public function contactNumber(): ?string
    {
        $number = match (true) {
            $this->relationLoaded('vendor') && $this->vendor !== null => $this->vendor->phone,
            $this->relationLoaded('user') && $this->user !== null => $this->user->phone ?? null,
            default => $this->phone,
        };

        return is_string($number) && trim($number) !== '' ? trim($number) : null;
    }

    public function contactEmail(): ?string
    {
        $email = match (true) {
            $this->relationLoaded('vendor') && $this->vendor !== null => $this->vendor->email,
            $this->relationLoaded('user') && $this->user !== null => $this->user->email,
            default => $this->email,
        };

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    public function roleLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->role));
    }
}
