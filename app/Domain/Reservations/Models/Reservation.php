<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Models;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Models\Payment;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Models\UnitType;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking.
 *
 * Dates are calendar dates in the property's timezone, and `check_out_date` is
 * exclusive: a stay from the 3rd to the 5th occupies the nights of the 3rd and
 * 4th. Every overlap test in the availability engine relies on that
 * convention, which is also what every channel and every hotelier uses.
 *
 * The monetary columns on this row are a maintained cache. The authoritative
 * amounts are the per-night rows in {@see ReservationNight} and the lines in
 * {@see ReservationCharge}; {@see recalculateTotals()} folds them up and is
 * always called inside the same transaction that changed them.
 *
 * A reservation is never deleted. Cancelling changes its status so the
 * financial and operational history survives.
 *
 * @property ReservationStatus $status
 * @property CarbonImmutable $check_in_date
 * @property CarbonImmutable $check_out_date
 */
class Reservation extends BaseModel
{
    use BelongsToOrganization, HasCustomFields, HasFactory, HasTags;

    protected $fillable = [
        'organization_id',
        'confirmation_code',
        'property_id',
        'listing_id',
        'unit_type_id',
        'unit_id',
        'guest_id',
        'status',
        'source',
        'channel_account_id',
        'external_reservation_id',
        'external_confirmation_code',
        'check_in_date',
        'check_out_date',
        'nights',
        'check_in_time',
        'check_out_time',
        'adults',
        'children',
        'infants',
        'pets',
        'currency',
        'base_currency',
        'exchange_rate',
        'rate_plan_id',
        'cancellation_policy_id',
        'cancellation_policy_snapshot',
        'guest_notes',
        'internal_notes',
        'booked_at',
        'hold_expires_at',
        'source_metadata',
        'metadata',
        'created_by_id',
        'security_deposit',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'check_in_date' => 'immutable_date',
            'check_out_date' => 'immutable_date',
            'booked_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',

            // Guest portal. `online_check_in_completed_at` is deliberately not
            // `checked_in_at`: a guest filling in their details a week early
            // has not arrived, and conflating the two would show an operations
            // board a house full of guests who are still at home.
            'portal_token_expires_at' => 'immutable_datetime',
            'portal_last_viewed_at' => 'immutable_datetime',
            'online_check_in_completed_at' => 'immutable_datetime',
            'check_in_details' => 'array',

            'exchange_rate' => 'decimal:10',
            'cancellation_policy_snapshot' => 'array',
            'source_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => 'inquiry',
        'source' => 'direct',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'pets' => 0,
        'exchange_rate' => 1,
    ];

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function stayNights(): HasMany
    {
        return $this->hasMany(ReservationNight::class)->orderBy('stay_date');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ReservationCharge::class);
    }

    public function additionalGuests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ReservationStatusChange::class)->orderByDesc('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /** Reservations that occupy inventory. */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::blockingValues());
    }

    public function scopeRevenueBearing(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::revenueValues());
    }

    /**
     * Reservations overlapping a date range.
     *
     * Both bounds are half-open (`[from, to)`), which is what makes two stays
     * that merely touch — one checking out on the day the next checks in —
     * correctly *not* overlap.
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from);
    }

    public function scopeArrivingOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('check_in_date', $date);
    }

    public function scopeDepartingOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('check_out_date', $date);
    }

    public function scopeInHouseOn(Builder $query, string $date): Builder
    {
        return $query->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date)
            ->whereIn('status', [
                ReservationStatus::Confirmed->value,
                ReservationStatus::CheckedIn->value,
            ]);
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function accommodationTotal(): Money
    {
        return Money::of((int) $this->accommodation_total, $this->currency);
    }

    public function feesTotal(): Money
    {
        return Money::of((int) $this->fees_total, $this->currency);
    }

    public function taxesTotal(): Money
    {
        return Money::of((int) $this->taxes_total, $this->currency);
    }

    public function discountsTotal(): Money
    {
        return Money::of((int) $this->discounts_total, $this->currency);
    }

    public function grandTotal(): Money
    {
        return Money::of((int) $this->grand_total, $this->currency);
    }

    public function paidTotal(): Money
    {
        return Money::of((int) $this->paid_total, $this->currency);
    }

    public function refundedTotal(): Money
    {
        return Money::of((int) $this->refunded_total, $this->currency);
    }

    public function balanceDue(): Money
    {
        return Money::of((int) $this->balance_due, $this->currency);
    }

    /**
     * Money actually retained: what was paid, less what was given back.
     */
    public function netPaid(): Money
    {
        return $this->paidTotal()->subtract($this->refundedTotal());
    }

    /**
     * Recompute the cached totals from the authoritative lines.
     *
     * Always called inside the transaction that changed the lines, so the
     * cache can never be observed out of step with them.
     */
    public function recalculateTotals(bool $persist = true): static
    {
        $currency = $this->currency;

        $accommodation = Money::zero($currency);
        foreach ($this->stayNights()->get() as $night) {
            $accommodation = $accommodation->add(Money::of((int) $night->rate_amount, $currency));
        }

        $fees = Money::zero($currency);
        $taxes = Money::zero($currency);
        $discounts = Money::zero($currency);
        $upsells = Money::zero($currency);
        $commission = Money::zero($currency);
        $deposit = Money::zero($currency);

        foreach ($this->charges()->get() as $charge) {
            $amount = Money::of((int) $charge->amount, $currency);

            match ($charge->kind) {
                ReservationCharge::KIND_FEE => $fees = $fees->add($amount),
                ReservationCharge::KIND_TAX => $taxes = $taxes->add($amount),
                // Discounts are stored as negative amounts; the total is
                // reported as a positive magnitude.
                ReservationCharge::KIND_DISCOUNT => $discounts = $discounts->add($amount->absolute()),
                ReservationCharge::KIND_UPSELL => $upsells = $upsells->add($amount),
                ReservationCharge::KIND_COMMISSION => $commission = $commission->add($amount->absolute()),
                ReservationCharge::KIND_DEPOSIT => $deposit = $deposit->add($amount),
                ReservationCharge::KIND_DAMAGE,
                ReservationCharge::KIND_ADJUSTMENT => $fees = $fees->add($amount),
                default => null,
            };
        }

        $grand = $accommodation
            ->add($fees)
            ->add($taxes)
            ->add($upsells)
            ->subtract($discounts);

        [$paid, $refunded] = $this->collectedTotals($currency);

        $this->paid_total = $paid->minorUnits;
        $this->refunded_total = $refunded->minorUnits;

        $this->accommodation_total = $accommodation->minorUnits;
        $this->fees_total = $fees->minorUnits;
        $this->taxes_total = $taxes->minorUnits;
        $this->discounts_total = $discounts->minorUnits;
        $this->upsells_total = $upsells->minorUnits;
        $this->grand_total = $grand->minorUnits;
        $this->channel_commission = $commission->minorUnits;
        $this->security_deposit = $deposit->minorUnits;

        // What the channel will actually remit.
        $this->expected_payout = $grand->subtract($commission)->minorUnits;

        // A refund reduces what has been collected, so the balance reflects
        // money still owed rather than money once received.
        $this->balance_due = $grand->subtract($paid->subtract($refunded))->minorUnits;

        $this->base_currency ??= $this->organization?->base_currency ?? $currency;
        $this->base_grand_total = $this->convertToBase($grand)->minorUnits;

        if ($persist) {
            $this->save();
        }

        return $this;
    }

    /**
     * What has actually been collected against this booking, and returned.
     *
     * Derived from the payments rather than accumulated into a column, so a
     * correction to one payment cannot leave the booking's balance permanently
     * wrong — a running total that drifts is worse than no total, because it
     * looks authoritative.
     *
     * Two exclusions matter. A **security deposit** is the guest's money held
     * against damage, not payment for the stay: counting it would show a
     * booking as paid when the accommodation is still owed. And only payments
     * in the booking's own currency are summed, because converting at today's
     * rate would make the balance move on its own.
     *
     * @return array{0: Money, 1: Money}
     */
    private function collectedTotals(string $currency): array
    {
        if (! $this->exists) {
            return [Money::zero($currency), Money::zero($currency)];
        }

        // An explicit query rather than a lazy relation load: this runs inside
        // save paths where the relation may not be loaded, and lazy loading is
        // disabled outside production for good reason.
        $totals = $this->payments()
            ->captured()
            ->where('currency', $currency)
            ->whereNot('kind', PaymentKind::SecurityDeposit->value)
            ->selectRaw('COALESCE(SUM(captured_amount), 0) AS paid, COALESCE(SUM(refunded_amount), 0) AS refunded')
            ->first();

        return [
            Money::of((int) ($totals->paid ?? 0), $currency),
            Money::of((int) ($totals->refunded ?? 0), $currency),
        ];
    }

    private function convertToBase(Money $amount): Money
    {
        $baseCurrency = $this->base_currency ?? $amount->currency;

        if ($baseCurrency === $amount->currency) {
            return $amount;
        }

        return Money::of(
            (int) round($amount->minorUnits * (float) $this->exchange_rate),
            $baseCurrency,
        );
    }

    // ------------------------------------------------------------------
    // Stay
    // ------------------------------------------------------------------

    /**
     * The calendar nights this stay occupies, as Y-m-d strings.
     *
     * The checkout date is excluded, because nobody sleeps there that night.
     *
     * @return list<string>
     */
    public function nightDates(): array
    {
        $dates = [];

        foreach (CarbonPeriod::create(
            $this->check_in_date,
            '1 day',
            $this->check_out_date->subDay(),
        ) as $date) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    public function totalGuests(): int
    {
        // Infants are conventionally excluded from occupancy limits.
        return (int) $this->adults + (int) $this->children;
    }

    /**
     * The absolute instant the guest may arrive, in the property's timezone.
     */
    public function arrivalMoment(): CarbonImmutable
    {
        $property = $this->property;

        $time = $this->check_in_time ?? $property->check_in_time;

        return $property->localDateTime(
            $this->check_in_date->toDateString(),
            $this->formatTime($time, '15:00'),
        );
    }

    public function departureMoment(): CarbonImmutable
    {
        $property = $this->property;

        $time = $this->check_out_time ?? $property->check_out_time;

        return $property->localDateTime(
            $this->check_out_date->toDateString(),
            $this->formatTime($time, '11:00'),
        );
    }

    /**
     * Whole days between now and arrival, in the property's local time.
     * Negative once the stay has started.
     */
    public function daysUntilArrival(): int
    {
        $today = $this->property->localNow()->startOfDay();

        return (int) floor($today->diffInDays($this->check_in_date->startOfDay(), false));
    }

    public function isInHouse(): bool
    {
        return $this->status === ReservationStatus::CheckedIn;
    }

    public function isActive(): bool
    {
        return ! $this->status->isTerminal();
    }

    public function blocksInventory(): bool
    {
        return $this->status->blocksInventory();
    }

    /**
     * Average nightly rate: accommodation only, excluding fees and taxes.
     * This is the ADR definition used throughout the product's reporting.
     */
    public function averageDailyRate(): Money
    {
        if ($this->nights < 1) {
            return Money::zero($this->currency);
        }

        return Money::of(
            (int) round((int) $this->accommodation_total / (int) $this->nights),
            $this->currency,
        );
    }

    /**
     * How long before arrival the booking was made. A core revenue metric.
     */
    public function bookingLeadTimeDays(): ?int
    {
        if ($this->booked_at === null) {
            return null;
        }

        return (int) floor(
            $this->booked_at->startOfDay()->diffInDays($this->check_in_date->startOfDay(), false)
        );
    }

    private function formatTime(mixed $value, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('H:i:s')
            : (string) $value;
    }
}
