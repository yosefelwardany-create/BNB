<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A user-defined label that can be attached to guests, owners, reservations,
 * properties, tasks and conversations.
 *
 * `taggable_type` optionally restricts a tag to one entity type so that, for
 * example, a "VIP" guest tag does not clutter the property tag picker.
 */
class Tag extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'color',
        'taggable_type',
        'description',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            if (blank($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function scopeFor(Builder $query, string $taggableType): Builder
    {
        return $query->where(function (Builder $q) use ($taggableType): void {
            $q->whereNull('taggable_type')->orWhere('taggable_type', $taggableType);
        });
    }
}
