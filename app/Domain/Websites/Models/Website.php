<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Domain\Organization\Models\Organization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking website: the platform owner's brand site, or one client's own.
 *
 * Deliberately not tenant-scoped. A brand site lists properties from every
 * client account, so it belongs to the platform rather than to any one of
 * them; the organization a listing belongs to travels on its
 * {@see WebsiteListing} row instead.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string $kind
 * @property string|null $organization_id
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property int $hold_minutes
 * @property int $request_hours
 * @property array<string, mixed>|null $draft_content
 * @property array<string, mixed>|null $content
 * @property int $draft_version
 */
class Website extends BaseModel
{
    public const KIND_BRAND = 'brand';

    public const KIND_CLIENT = 'client';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_LIVE = 'live';

    /**
     * Words a site's address may not be, because they are the names of the
     * pages and files beneath every site.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['api', 'stay', 'booking', 'photos', 'media', 'sitemap', 'robots'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            // Pages, blocks, theme and style: what editors see, and what
            // guests see. {@see \App\Domain\Websites\Content\SiteContent}
            'draft_content' => 'array',
            'content' => 'array',
            'draft_version' => 'integer',
            'content_published_at' => 'datetime',
            'hold_minutes' => 'integer',
            'request_hours' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebsiteListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(WebsiteListing::class)->orderBy('position');
    }

    /**
     * @return HasMany<WebsiteImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(WebsiteImage::class)->orderBy('created_at');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isBrand(): bool
    {
        return $this->kind === self::KIND_BRAND;
    }

    /**
     * The public address of a page on this site.
     */
    public function url(string $path = ''): string
    {
        return url('/sites/'.$this->slug.($path === '' ? '' : '/'.ltrim($path, '/')));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
