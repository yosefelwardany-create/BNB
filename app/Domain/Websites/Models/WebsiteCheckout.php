<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to book on a website: the stay held for the guest, what they
 * owe now, and how far it has got.
 *
 * Not tenant-scoped, like the site it belongs to: the guest reads it by the
 * secret link and the scheduler releases it, neither inside an account. It
 * carries the listing's organization, and the booking it holds is made in
 * that account.
 *
 * @property string $id
 * @property string $access_token
 * @property string $website_id
 * @property string $organization_id
 * @property string|null $listing_id
 * @property string|null $reservation_id
 * @property string|null $quote_id
 * @property string $mode
 * @property array<string, mixed> $guest
 * @property string $currency
 * @property int $total_amount
 * @property int $due_now_amount
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_customer_id
 * @property string $status
 * @property CarbonImmutable $hold_expires_at
 * @property string $client_fingerprint
 */
class WebsiteCheckout extends BaseModel
{
    public const MODE_INSTANT = 'instant';

    public const MODE_REQUEST = 'request';

    public const STATUS_HOLDING = 'holding';

    public const STATUS_PAID = 'paid';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_FAILED = 'failed';

    protected $hidden = ['access_token', 'client_fingerprint'];

    protected function casts(): array
    {
        return [
            'guest' => 'array',
            'total_amount' => 'integer',
            'due_now_amount' => 'integer',
            'hold_expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Holds still waiting for the guest whose time is up.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HOLDING)->where('hold_expires_at', '<=', now());
    }

    /**
     * Holds still waiting for the guest and still in time.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HOLDING)->where('hold_expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * The guest's secret link.
     */
    public function url(): string
    {
        return $this->website->url('booking/'.$this->access_token);
    }
}
