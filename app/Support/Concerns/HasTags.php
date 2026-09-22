<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\Platform\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * Adds organization-scoped tagging to a model.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasTags
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * Attach tags by name, creating any that do not yet exist.
     *
     * @param  list<string>  $names
     */
    public function tagWith(array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $tag = Tag::query()->firstOrCreate(
                [
                    'organization_id' => $this->getAttribute('organization_id'),
                    'slug' => Str::slug($name),
                    'taggable_type' => null,
                ],
                ['name' => $name],
            );

            $ids[] = $tag->getKey();
        }

        $this->tags()->syncWithoutDetaching($ids);
    }

    /**
     * Replace the model's tags with exactly the given names.
     *
     * @param  list<string>  $names
     */
    public function syncTags(array $names): void
    {
        $slugs = array_filter(array_map(fn (string $n): string => Str::slug(trim($n)), $names));

        $this->tagWith($names);

        $keep = Tag::query()
            ->where('organization_id', $this->getAttribute('organization_id'))
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->all();

        $this->tags()->sync($keep);
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return $this->tags->pluck('name')->all();
    }

    /**
     * Models carrying every one of the given tag slugs.
     *
     * @param  list<string>  $slugs
     */
    public function scopeTaggedWithAll(Builder $query, array $slugs): Builder
    {
        foreach ($slugs as $slug) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('slug', $slug));
        }

        return $query;
    }

    /**
     * Models carrying at least one of the given tag slugs.
     *
     * @param  list<string>  $slugs
     */
    public function scopeTaggedWithAny(Builder $query, array $slugs): Builder
    {
        return $query->whereHas('tags', fn (Builder $q) => $q->whereIn('slug', $slugs));
    }
}
