<?php

declare(strict_types=1);

namespace App\Domain\Owners\Models;

use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A property owner.
 *
 * Owners are the manager's clients: they are owed money, they receive
 * statements, and many of them have portal access. Banking details are
 * encrypted at rest and never returned in full by the API.
 */
class Owner extends BaseModel
{
    use Auditable, BelongsToOrganization, HasCustomFields, HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'organization_id', 'type',
        'first_name', 'last_name', 'company_name', 'display_name',
        'email', 'phone', 'country_code', 'language', 'timezone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code',
        'tax_identifier', 'vat_number', 'payout_currency', 'payout_method',
        'bank_account_name', 'bank_account_number', 'bank_routing_number',
        'bank_iban', 'bank_swift', 'bank_name', 'bank_country',
        'statement_frequency', 'statement_day', 'reserve_amount',
        'status', 'notes', 'metadata',
        'user_id', 'portal_enabled', 'portal_permissions', 'created_by_id',
    ];

    protected $hidden = [
        'bank_account_number', 'bank_routing_number', 'bank_iban', 'bank_swift',
    ];

    protected function casts(): array
    {
        return [
            'bank_account_name' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_routing_number' => 'encrypted',
            'bank_iban' => 'encrypted',
            'bank_swift' => 'encrypted',
            'portal_enabled' => 'boolean',
            'portal_permissions' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'type' => 'individual',
        'status' => 'active',
        'statement_frequency' => 'monthly',
        'statement_day' => 1,
        'reserve_amount' => 0,
        'portal_enabled' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Owner $owner): void {
            if (blank($owner->display_name)) {
                $owner->display_name = $owner->type === 'company'
                    ? (string) $owner->company_name
                    : trim($owner->first_name.' '.$owner->last_name);
            }
        });
    }

    public function ownerships(): HasMany
    {
        return $this->hasMany(PropertyOwnership::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_ownerships')
            ->withPivot(['ownership_percentage', 'is_primary', 'starts_on', 'ends_on'])
            ->withTimestamps();
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(ManagementAgreement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', trim($term)).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('display_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like);
        });
    }

    public function reserve(string $currency): Money
    {
        return Money::of((int) $this->reserve_amount, $this->payout_currency ?? $currency);
    }

    /**
     * The agreement in force for a property on a given date.
     *
     * A property-specific agreement wins over a blanket one, which is how a
     * portfolio with one differently-negotiated property is modelled.
     */
    public function agreementFor(Property|string $property, ?\DateTimeInterface $on = null): ?ManagementAgreement
    {
        $propertyId = $property instanceof Property ? $property->getKey() : $property;
        $date = CarbonImmutable::parse($on ?? now())->toDateString();

        return $this->agreements()
            ->where('status', 'active')
            ->where('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->where(fn (Builder $q) => $q->where('property_id', $propertyId)->orWhereNull('property_id'))
            // A property-specific agreement takes precedence over a blanket one.
            ->orderByRaw('property_id IS NULL')
            ->first();
    }

    /**
     * Banking details, masked. The full values are only ever used inside the
     * payout process and never leave the server.
     *
     * @return array<string, ?string>
     */
    public function maskedBankDetails(): array
    {
        return [
            'bank_name' => $this->bank_name,
            'account_name' => $this->bank_account_name,
            'account_number' => $this->mask($this->bank_account_number),
            'iban' => $this->mask($this->bank_iban),
            'swift' => $this->bank_swift,
            'country' => $this->bank_country,
        ];
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4);
    }
}
