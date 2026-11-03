<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Domain\Websites\Support\SiteOrigin;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The client's Shopify store a booking site takes payment through.
 *
 * Each store has its own Shopify app, made for it alone (custom
 * distribution installs on one store): its Client ID and secret are kept
 * here, and the store's access token once someone with admin rights on the
 * store approves it. The secret and the token are encrypted and never shown.
 *
 * @property string $id
 * @property string $website_id
 * @property string $shop_domain
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $shop_name
 * @property string|null $currency
 * @property list<string>|null $domains
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $scopes
 * @property string $status
 * @property Carbon|null $connected_at
 * @property Carbon|null $broken_at
 * @property string|null $last_error
 */
class WebsiteShopifyConnection extends BaseModel
{
    /** The app's keys are saved; the store has not approved it yet. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    /** The store stopped accepting the app: removed, or its token refused. */
    public const STATUS_BROKEN = 'broken';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'website_id', 'shop_domain', 'client_id', 'client_secret', 'shop_name', 'currency', 'domains',
        'access_token', 'refresh_token', 'access_token_expires_at', 'scopes',
        'status', 'connected_at', 'broken_at', 'last_error',
    ];

    protected $hidden = ['client_secret', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'broken_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function hasKeys(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && filled($this->access_token) && $this->hasKeys();
    }

    /**
     * The store's front door: its own domain, else its myshopify address.
     * Kept when the connection breaks, so a "store only" site still points
     * guests to it.
     */
    public function storeFront(): string
    {
        foreach ($this->domains ?? [] as $domain) {
            $origin = is_string($domain) ? SiteOrigin::normalize($domain) : null;

            if ($origin !== null) {
                return $origin;
            }
        }

        return 'https://'.$this->shop_domain;
    }

    /**
     * Where the store's pages are, and its checkout: its myshopify address
     * and its own domains, as https origins.
     *
     * @return list<string>
     */
    public function origins(): array
    {
        return array_values(array_unique(array_filter([
            'https://'.$this->shop_domain,
            ...array_map(fn (mixed $domain): ?string => is_string($domain) ? SiteOrigin::normalize($domain) : null, $this->domains ?? []),
        ])));
    }
}
