<?php

declare(strict_types=1);

namespace App\Domain\Listings\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time snapshot of a listing's content.
 *
 * Channels review and sometimes reject content changes, so an operator needs
 * to see exactly what was published and when, and to put a previous version
 * back. A full snapshot is stored rather than a diff so restoring is a direct
 * operation with no replay.
 *
 * Versions are append-only.
 */
class ListingVersion extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'listing_id',
        'version',
        'snapshot',
        'changed_fields',
        'reason',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changed_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException('Listing versions are immutable.');
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
