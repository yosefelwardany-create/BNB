<?php

declare(strict_types=1);

namespace App\Domain\Owners\Models;

use App\Domain\Properties\Models\Property;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The commercial terms between a manager and an owner.
 *
 * Everything that decides what an owner is paid lives here and is dated, so a
 * statement can be reproduced against the terms that were in force for the
 * period it covers — not the terms as they stand today.
 *
 * The settings that look fussy are the ones that get disputed: whether the
 * management fee is charged before or after channel commission, whether
 * cleaning fees count as revenue the manager takes a share of, and who pays
 * for a broken boiler.
 */
class ManagementAgreement extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const PERCENT_OF_REVENUE = 'percent_of_revenue';

    public const PERCENT_OF_NET = 'percent_of_net';

    public const FIXED_MONTHLY = 'fixed_monthly';

    public const FIXED_PER_BOOKING = 'fixed_per_booking';

    public const PER_NIGHT = 'per_night';

    public const TIERED = 'tiered';

    protected $fillable = [
        'organization_id', 'owner_id', 'property_id', 'name', 'reference',
        'commission_model', 'commission_rate', 'commission_amount', 'commission_tiers', 'currency',
        'commission_on_accommodation', 'commission_on_fees', 'commission_on_taxes',
        'deduct_channel_commission_first', 'deduct_payment_fees_first',
        'owner_pays_cleaning', 'owner_pays_maintenance', 'owner_pays_supplies',
        'maintenance_markup_percent', 'maintenance_approval_threshold',
        'owner_stay_nights_included', 'charge_cleaning_for_owner_stays',
        'starts_on', 'ends_on', 'notice_period_days', 'status', 'terms', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:4',
            'commission_tiers' => 'array',
            'commission_on_accommodation' => 'boolean',
            'commission_on_fees' => 'boolean',
            'commission_on_taxes' => 'boolean',
            'deduct_channel_commission_first' => 'boolean',
            'deduct_payment_fees_first' => 'boolean',
            'owner_pays_cleaning' => 'boolean',
            'owner_pays_maintenance' => 'boolean',
            'owner_pays_supplies' => 'boolean',
            'maintenance_markup_percent' => 'decimal:4',
            'charge_cleaning_for_owner_stays' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'commission_model' => self::PERCENT_OF_REVENUE,
        'commission_on_accommodation' => true,
        'commission_on_fees' => false,
        'commission_on_taxes' => false,
        'deduct_channel_commission_first' => true,
        'deduct_payment_fees_first' => true,
        'owner_pays_cleaning' => false,
        'owner_pays_maintenance' => true,
        'owner_pays_supplies' => true,
        'maintenance_markup_percent' => 0,
        'owner_stay_nights_included' => 0,
        'charge_cleaning_for_owner_stays' => true,
        'status' => 'active',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->where('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    /**
     * The management fee for one reservation.
     *
     * @param  Money  $accommodation  Nightly revenue.
     * @param  Money  $fees  Cleaning and other fees charged to the guest.
     * @param  Money  $taxes  Taxes collected.
     * @param  Money  $channelCommission  What the channel kept.
     * @param  Money  $paymentFees  What the processor kept.
     * @return array{fee: Money, base: Money, explanation: string}
     */
    public function commissionFor(
        Money $accommodation,
        Money $fees,
        Money $taxes,
        Money $channelCommission,
        Money $paymentFees,
        int $nights = 1,
    ): array {
        $currency = $accommodation->currency;
        $base = Money::zero($currency);
        $components = [];

        if ($this->commission_on_accommodation) {
            $base = $base->add($accommodation);
            $components[] = 'accommodation';
        }

        if ($this->commission_on_fees) {
            $base = $base->add($fees);
            $components[] = 'fees';
        }

        if ($this->commission_on_taxes) {
            $base = $base->add($taxes);
            $components[] = 'taxes';
        }

        // Whether the manager's share is taken before or after the channel's
        // is the most commonly disputed line on a statement, so it is an
        // explicit setting and the explanation says which way it went.
        if ($this->deduct_channel_commission_first && $channelCommission->isPositive()) {
            $base = $base->subtract($channelCommission);
            $components[] = 'less channel commission';
        }

        if ($this->deduct_payment_fees_first && $paymentFees->isPositive()) {
            $base = $base->subtract($paymentFees);
            $components[] = 'less payment fees';
        }

        if ($base->isNegative()) {
            $base = Money::zero($currency);
        }

        [$fee, $description] = match ($this->commission_model) {
            self::PERCENT_OF_REVENUE, self::PERCENT_OF_NET => [
                $base->percentage((float) $this->commission_rate),
                sprintf('%s%% of %s', $this->formatRate(), implode(' + ', $components)),
            ],

            self::FIXED_PER_BOOKING => [
                Money::of((int) $this->commission_amount, $currency),
                'a fixed amount per booking',
            ],

            self::PER_NIGHT => [
                Money::of((int) $this->commission_amount, $currency)->multiply($nights),
                sprintf('a fixed amount for each of %d night(s)', $nights),
            ],

            self::TIERED => $this->tieredCommission($base, $components),

            // A fixed monthly fee is charged once per statement period, not
            // per booking, so it contributes nothing here.
            self::FIXED_MONTHLY => [Money::zero($currency), 'a fixed monthly fee, charged per statement period'],

            default => [Money::zero($currency), 'no commission configured'],
        };

        return [
            'fee' => $fee,
            'base' => $base,
            'explanation' => sprintf(
                'Management fee of %s: %s (base %s).',
                $fee->toDecimal(),
                $description,
                $base->toDecimal(),
            ),
        ];
    }

    /**
     * Tiered commission: the rate depends on the revenue band.
     *
     * Tiers are `[{"up_to": 500000, "rate": 20}, {"up_to": null, "rate": 15}]`
     * and are applied as a flat rate for the band the base falls in, which is
     * how these agreements are written in practice.
     *
     * @param  list<string>  $components
     * @return array{0: Money, 1: string}
     */
    private function tieredCommission(Money $base, array $components): array
    {
        $tiers = $this->commission_tiers ?? [];

        usort($tiers, function (array $a, array $b): int {
            $left = $a['up_to'] ?? PHP_INT_MAX;
            $right = $b['up_to'] ?? PHP_INT_MAX;

            return $left <=> $right;
        });

        foreach ($tiers as $tier) {
            $ceiling = $tier['up_to'] ?? null;

            if ($ceiling === null || $base->minorUnits <= (int) $ceiling) {
                $rate = (float) ($tier['rate'] ?? 0);

                return [
                    $base->percentage($rate),
                    sprintf(
                        '%s%% of %s (tier %s)',
                        rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'),
                        implode(' + ', $components),
                        $ceiling === null ? 'above all thresholds' : 'up to '.$ceiling,
                    ),
                ];
            }
        }

        return [Money::zero($base->currency), 'no matching commission tier'];
    }

    private function formatRate(): string
    {
        return rtrim(rtrim(number_format((float) $this->commission_rate, 4, '.', ''), '0'), '.');
    }

    public function isInForceOn(\DateTimeInterface $date): bool
    {
        $day = \Carbon\CarbonImmutable::parse($date)->startOfDay();

        if ($this->starts_on !== null && $day->lt($this->starts_on)) {
            return false;
        }

        return ! ($this->ends_on !== null && $day->gt($this->ends_on));
    }
}
