<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One custom field value for one record.
 */
class CustomFieldValue extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'custom_field_id',
        'entity_type',
        'entity_id',
        'value_text',
        'value_number',
        'value_date',
        'value_boolean',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:6',
            'value_date' => 'date',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
        ];
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The typed value, whichever column holds it.
     */
    public function value(): mixed
    {
        return $this->value_json
            ?? $this->value_boolean
            ?? $this->value_date
            ?? $this->value_number
            ?? $this->value_text;
    }
}
