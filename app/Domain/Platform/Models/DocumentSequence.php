<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Per-organization counters for human-readable document references
 * (confirmation codes, invoice numbers, journal references, statement numbers).
 *
 * Numbers are allocated under a row lock so two concurrent bookings can never
 * receive the same confirmation code.
 */
class DocumentSequence extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'kind',
        'prefix',
        'next_value',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
            'padding' => 'integer',
        ];
    }
}
