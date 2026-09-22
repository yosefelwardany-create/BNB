<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable message with placeholders.
 *
 * Templates are localised: a guest who booked in French gets the French
 * version, falling back to the organization's default language. The fallback
 * is explicit rather than silent so a missing translation is visible.
 */
class MessageTemplate extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'code', 'description', 'category',
        'subject', 'body', 'language', 'translation_of_id',
        'transport', 'property_ids', 'channels', 'is_active', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'property_ids' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'language' => 'en',
        'transport' => 'channel',
        'is_active' => true,
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'translation_of_id');
    }

    public function translationOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translation_of_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Pick the right language variant, preferring the guest's.
     */
    public function forLanguage(?string $language): self
    {
        if ($language === null || $language === $this->language) {
            return $this;
        }

        $translation = $this->translations()
            ->where('language', $language)
            ->where('is_active', true)
            ->first();

        return $translation ?? $this;
    }

    public function appliesToProperty(?string $propertyId): bool
    {
        $properties = $this->property_ids;

        if ($properties === null || $properties === [] || $propertyId === null) {
            return true;
        }

        return in_array($propertyId, $properties, true);
    }
}
