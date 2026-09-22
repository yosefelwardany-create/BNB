<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization-defined extra attribute on a core record.
 *
 * Values are stored in typed columns on {@see CustomFieldValue} rather than in
 * a JSON blob, so they stay queryable, indexable and reportable.
 */
class CustomField extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const TYPES = ['text', 'textarea', 'number', 'date', 'boolean', 'select', 'multiselect', 'url', 'email'];

    protected $fillable = [
        'organization_id',
        'entity_type',
        'key',
        'label',
        'type',
        'options',
        'is_required',
        'show_in_list',
        'position',
        'help_text',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'show_in_list' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType)->orderBy('position');
    }

    /**
     * The column on `custom_field_values` that stores this field's value.
     */
    public function valueColumn(): string
    {
        return match ($this->type) {
            'number' => 'value_number',
            'date' => 'value_date',
            'boolean' => 'value_boolean',
            'multiselect' => 'value_json',
            default => 'value_text',
        };
    }
}
