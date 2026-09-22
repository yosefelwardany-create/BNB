<?php

declare(strict_types=1);

namespace App\Domain\Guests\Models;

use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A guest profile.
 *
 * A guest who returns is one record with several stays, not a new record each
 * time — which is what makes repeat-guest recognition, lifetime value and
 * marketing consent mean anything.
 *
 * `email_normalised` and `phone_normalised` exist so duplicates can be found
 * reliably: channels deliver the same person as "J.Smith@Example.com " and
 * "jsmith@example.com", and phone numbers arrive in half a dozen formats.
 */
class Guest extends BaseModel
{
    use Auditable, BelongsToOrganization, HasCustomFields, HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'first_name', 'last_name', 'display_name',
        'email', 'phone', 'secondary_phone',
        'country_code', 'language', 'timezone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code',
        'date_of_birth', 'company', 'vat_number',
        'document_type', 'document_number', 'document_expiry',
        'verification_status', 'verified_at',
        'marketing_consent', 'marketing_consent_at', 'marketing_consent_source',
        'communication_preferences',
        'notes', 'metadata', 'source', 'created_by_id',
    ];

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'document_expiry' => 'immutable_date',
            'document_number' => 'encrypted',
            'verified_at' => 'immutable_datetime',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'immutable_datetime',
            'communication_preferences' => 'array',
            'metadata' => 'array',
            'first_stay_date' => 'immutable_date',
            'last_stay_date' => 'immutable_date',
        ];
    }

    protected $attributes = [
        'verification_status' => 'unverified',
        'marketing_consent' => false,
        'reservations_count' => 0,
        'nights_count' => 0,
        'lifetime_value' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Guest $guest): void {
            $guest->email_normalised = self::normaliseEmail($guest->email);
            $guest->phone_normalised = self::normalisePhone($guest->phone);

            if (blank($guest->display_name)) {
                $guest->display_name = trim($guest->first_name.' '.$guest->last_name) ?: ($guest->email ?? 'Guest');
            }
        });
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->orderByDesc('check_in_date');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * When duplicates are merged, the losing profile points here. It is kept
     * rather than deleted so that any external reference to the old id — a
     * channel's guest identifier, a printed confirmation — still resolves.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /** Profiles that have not been merged away. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $term = trim($term);
        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like, $term): void {
            $q->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('display_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('company', 'ilike', $like)
                ->orWhere('phone_normalised', 'like', '%'.self::normalisePhone($term).'%');
        });
    }

    /** Guests who have stayed more than once. */
    public function scopeReturning(Builder $query): Builder
    {
        return $query->where('reservations_count', '>', 1);
    }

    public function scopeWithMarketingConsent(Builder $query): Builder
    {
        return $query->where('marketing_consent', true);
    }

    // ------------------------------------------------------------------
    // Behaviour
    // ------------------------------------------------------------------

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function lifetimeValue(): Money
    {
        return Money::of(
            (int) $this->lifetime_value,
            $this->lifetime_value_currency ?? $this->organization?->base_currency ?? 'USD',
        );
    }

    public function isReturning(): bool
    {
        return (int) $this->reservations_count > 1;
    }

    public function hasBeenMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /**
     * Only the last few characters of an identity document are ever shown.
     */
    public function maskedDocumentNumber(): ?string
    {
        $number = $this->document_number;

        if ($number === null || $number === '') {
            return null;
        }

        $visible = substr($number, -4);

        return str_repeat('•', max(0, strlen($number) - 4)).$visible;
    }

    /**
     * Lower-cased and trimmed, which catches the overwhelming majority of
     * duplicate profiles arriving from different channels.
     */
    public static function normaliseEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    /**
     * Digits only, keeping the last 12 — enough to match the same number
     * written with and without a country code or separators, without treating
     * two genuinely different numbers as one.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, -12);
    }
}
