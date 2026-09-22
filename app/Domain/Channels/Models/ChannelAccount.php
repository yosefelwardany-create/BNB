<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One connection to one distribution channel.
 *
 * Credentials are a single encrypted blob rather than named columns: every
 * channel needs a different set, and a column per channel would mean a
 * migration for each integration and a schema that advertises which OTAs a
 * customer uses.
 *
 * `collects_payment` is the field with the most consequence. It decides
 * whether a booking from this channel produces cash we hold or a receivable
 * from the channel, and getting it wrong misstates the bank balance by every
 * booking the channel sends.
 */
class ChannelAccount extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'organization_id', 'channel', 'name', 'credentials', 'external_account_id',
        'status', 'sync_availability', 'sync_rates', 'import_reservations',
        'export_reservations', 'sync_messages', 'commission_basis_points',
        'collects_payment', 'webhook_secret', 'settings', 'metadata', 'created_by_id',
    ];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'sync_availability' => 'boolean',
            'sync_rates' => 'boolean',
            'import_reservations' => 'boolean',
            'export_reservations' => 'boolean',
            'sync_messages' => 'boolean',
            'collects_payment' => 'boolean',
            'connected_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_imported_at' => 'immutable_datetime',
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sync_availability' => true,
        'sync_rates' => true,
        'import_reservations' => true,
        'export_reservations' => false,
        'sync_messages' => false,
        'collects_payment' => false,
        'commission_basis_points' => 0,
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class)->latest();
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * One value out of the encrypted credential blob.
     *
     * Adapters ask for what they need by name — `api_key`, `import_url` — so
     * each channel can require a different set without a column or a migration
     * per integration, and without the schema advertising which OTAs a
     * customer uses.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    /**
     * The channel's cut, as a percentage.
     *
     * Stored in basis points so a 15.5% commission is exact rather than a
     * float that drifts.
     */
    public function commissionPercent(): float
    {
        return $this->commission_basis_points / 100;
    }
}
