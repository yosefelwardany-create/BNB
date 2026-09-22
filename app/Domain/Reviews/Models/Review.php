<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Models;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a guest said, and what we said back.
 *
 * The review itself is not ours to edit. Changing a guest's words would be
 * fabrication, and the public copy on the channel would disagree with ours
 * anyway; only the response belongs to the operator. `hidden` therefore means
 * "suppressed in our interface" and nothing more — it changes nothing on the
 * channel, which is the only honest thing it could mean.
 *
 * Ratings carry their own scale. Airbnb rates out of 5 and Booking.com out of
 * 10, and averaging them raw produces a number that means nothing — so a
 * normalised percentage is stored alongside the raw score, and every
 * comparison uses the percentage.
 */
class Review extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const GUEST_TO_HOST = 'guest_to_host';

    public const HOST_TO_GUEST = 'host_to_guest';

    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const RESPONDED = 'responded';

    /** Suppressed here. Untouched on the channel. */
    public const HIDDEN = 'hidden';

    protected $fillable = [
        'organization_id', 'property_id', 'listing_id', 'reservation_id', 'guest_id',
        'direction', 'source', 'external_id', 'external_url',
        'rating', 'rating_scale', 'category_ratings',
        'title', 'public_comment', 'private_comment',
        'status', 'submitted_at', 'stay_date', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'rating_scale' => 'integer',
            'rating_percent' => 'integer',
            'category_ratings' => 'array',
            'metadata' => 'array',
            'submitted_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'stay_date' => 'immutable_date',
        ];
    }

    protected $attributes = [
        'direction' => self::GUEST_TO_HOST,
        'source' => 'direct',
        'status' => self::PUBLISHED,
        'rating_scale' => 5,
    ];

    protected static function booted(): void
    {
        // Kept in step on every write rather than computed on read, so a query
        // can sort and average by it without loading every row.
        static::saving(function (Review $review): void {
            $review->rating_percent = $review->rating === null || (int) $review->rating_scale <= 0
                ? null
                : (int) round((int) $review->rating / (int) $review->rating_scale * 100);
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }

    public function scopeFromGuests(Builder $query): Builder
    {
        return $query->where('direction', self::GUEST_TO_HOST);
    }

    public function scopeAboutGuests(Builder $query): Builder
    {
        return $query->where('direction', self::HOST_TO_GUEST);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNot('status', self::HIDDEN);
    }

    /**
     * Reviews still waiting for a reply.
     *
     * The operational queue. A poor review answered within a day reads very
     * differently from the same review answered in a month.
     */
    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->fromGuests()
            ->visible()
            ->whereNull('response');
    }

    /**
     * Reviews a reasonable person would call bad.
     *
     * Defined on the normalised percentage rather than the raw score, because
     * 6 is excellent out of 10 and dreadful out of 5.
     */
    public function scopeNegative(Builder $query, int $below = 70): Builder
    {
        return $query->whereNotNull('rating_percent')->where('rating_percent', '<', $below);
    }

    public function hasResponse(): bool
    {
        return ! blank($this->response);
    }

    public function isNegative(int $below = 70): bool
    {
        return $this->rating_percent !== null && $this->rating_percent < $below;
    }

    /**
     * How long the guest waited for a reply.
     */
    public function responseHours(): ?float
    {
        if ($this->responded_at === null || $this->submitted_at === null) {
            return null;
        }

        return round($this->submitted_at->diffInMinutes($this->responded_at) / 60, 1);
    }
}
