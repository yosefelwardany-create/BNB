<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named, evaluatable cancellation policy.
 *
 * Tiers are stored as `[{"days_before": 30, "refund_percent": 100}, ...]` and
 * evaluated from the largest `days_before` downwards: the guest gets the
 * refund of the first tier whose threshold they are still inside.
 *
 * The evaluation is deliberately explainable — {@see quote()} returns the tier
 * that applied and the arithmetic that followed from it — because a guest
 * disputing a refund is answered with the calculation, not with a number.
 */
class CancellationPolicy extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'free_cancellation_days',
        'tiers',
        'refund_cleaning_fee',
        'refund_taxes',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'refund_cleaning_fee' => 'boolean',
            'refund_taxes' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'free_cancellation_days' => 0,
        'refund_cleaning_fee' => true,
        'refund_taxes' => true,
        'is_default' => false,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (CancellationPolicy $policy): void {
            if (blank($policy->slug)) {
                $policy->slug = Str::slug($policy->name);
            }

            // Keep tiers ordered from the most generous threshold downwards so
            // evaluation can stop at the first match.
            $tiers = $policy->tiers ?? [];
            usort($tiers, fn (array $a, array $b): int => ($b['days_before'] ?? 0) <=> ($a['days_before'] ?? 0));
            $policy->tiers = array_values($tiers);
        });
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'cancellation_policy_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * What a guest is owed if they cancel now.
     *
     * @param  Money  $accommodation  Nightly value of the stay.
     * @param  Money  $cleaningFee  Cleaning fee charged.
     * @param  Money  $taxes  Taxes charged.
     * @return array{
     *     refund: Money,
     *     retained: Money,
     *     refund_percent: float,
     *     days_before_arrival: int,
     *     tier: ?array<string, mixed>,
     *     breakdown: list<array{label: string, amount: string, refunded: string}>,
     *     explanation: string
     * }
     */
    public function quote(
        CarbonImmutable $cancellationMoment,
        CarbonImmutable $arrival,
        Money $accommodation,
        Money $cleaningFee,
        Money $taxes,
    ): array {
        $currency = $accommodation->currency;

        // Whole days between the cancellation and arrival. A cancellation
        // after arrival yields a negative number, which no tier will match.
        $daysBefore = (int) floor(
            $cancellationMoment->startOfDay()->diffInDays($arrival->startOfDay(), false)
        );

        [$percent, $tier] = $this->resolveRefundPercent($daysBefore);

        $accommodationRefund = $accommodation->percentage($percent);
        $cleaningRefund = $this->refund_cleaning_fee
            ? $cleaningFee
            : $cleaningFee->percentage($percent);

        // Taxes normally follow whatever is actually refunded, because tax is
        // only due on money the business keeps.
        $taxRefund = $this->refund_taxes
            ? $taxes
            : $taxes->percentage($percent);

        $refund = $accommodationRefund->add($cleaningRefund)->add($taxRefund);
        $charged = $accommodation->add($cleaningFee)->add($taxes);

        return [
            'refund' => $refund,
            'retained' => $charged->subtract($refund),
            'refund_percent' => $percent,
            'days_before_arrival' => $daysBefore,
            'tier' => $tier,
            'breakdown' => [
                [
                    'label' => 'Accommodation',
                    'amount' => $accommodation->toDecimal(),
                    'refunded' => $accommodationRefund->toDecimal(),
                ],
                [
                    'label' => 'Cleaning fee',
                    'amount' => $cleaningFee->toDecimal(),
                    'refunded' => $cleaningRefund->toDecimal(),
                ],
                [
                    'label' => 'Taxes',
                    'amount' => $taxes->toDecimal(),
                    'refunded' => $taxRefund->toDecimal(),
                ],
            ],
            'explanation' => $this->explain($daysBefore, $percent, $tier),
            'currency' => $currency,
        ];
    }

    /**
     * @return array{0: float, 1: ?array<string, mixed>}
     */
    private function resolveRefundPercent(int $daysBefore): array
    {
        if ($daysBefore >= $this->free_cancellation_days && $this->free_cancellation_days > 0) {
            return [100.0, ['days_before' => $this->free_cancellation_days, 'refund_percent' => 100, 'free_window' => true]];
        }

        foreach ($this->tiers ?? [] as $tier) {
            if ($daysBefore >= (int) ($tier['days_before'] ?? 0)) {
                return [(float) ($tier['refund_percent'] ?? 0), $tier];
            }
        }

        // Past every threshold: nothing is refundable.
        return [0.0, null];
    }

    private function explain(int $daysBefore, float $percent, ?array $tier): string
    {
        if ($daysBefore < 0) {
            return sprintf(
                'Cancelled %d day(s) after arrival, so the %s policy refunds %s%%.',
                abs($daysBefore),
                $this->name,
                rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
            );
        }

        if (($tier['free_window'] ?? false) === true) {
            return sprintf(
                'Cancelled %d day(s) before arrival, within the %d-day free cancellation window, so the full amount is refunded.',
                $daysBefore,
                $this->free_cancellation_days,
            );
        }

        if ($tier === null) {
            return sprintf(
                'Cancelled %d day(s) before arrival, past every refund threshold in the %s policy, so nothing is refundable.',
                $daysBefore,
                $this->name,
            );
        }

        return sprintf(
            'Cancelled %d day(s) before arrival. The %s policy refunds %s%% from %d day(s) before arrival.',
            $daysBefore,
            $this->name,
            rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
            (int) ($tier['days_before'] ?? 0),
        );
    }

    /**
     * The policies every new organization starts with. Named after the
     * industry-standard shapes so they are recognisable, but defined here
     * rather than copied from any particular platform.
     *
     * @return list<array{name: string, slug: string, description: string, free_cancellation_days: int, tiers: list<array{days_before: int, refund_percent: int}>}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Flexible',
                'slug' => 'flexible',
                'description' => 'Full refund up to 1 day before arrival.',
                'free_cancellation_days' => 1,
                'tiers' => [
                    ['days_before' => 1, 'refund_percent' => 100],
                    ['days_before' => 0, 'refund_percent' => 0],
                ],
            ],
            [
                'name' => 'Moderate',
                'slug' => 'moderate',
                'description' => 'Full refund up to 5 days before arrival, then 50%.',
                'free_cancellation_days' => 5,
                'tiers' => [
                    ['days_before' => 5, 'refund_percent' => 100],
                    ['days_before' => 1, 'refund_percent' => 50],
                    ['days_before' => 0, 'refund_percent' => 0],
                ],
            ],
            [
                'name' => 'Firm',
                'slug' => 'firm',
                'description' => 'Full refund up to 30 days before arrival, 50% up to 7 days, then none.',
                'free_cancellation_days' => 30,
                'tiers' => [
                    ['days_before' => 30, 'refund_percent' => 100],
                    ['days_before' => 7, 'refund_percent' => 50],
                    ['days_before' => 0, 'refund_percent' => 0],
                ],
            ],
            [
                'name' => 'Strict',
                'slug' => 'strict',
                'description' => 'Full refund within 48 hours of booking if made 14 days before arrival, otherwise 50% up to 7 days before.',
                'free_cancellation_days' => 0,
                'tiers' => [
                    ['days_before' => 7, 'refund_percent' => 50],
                    ['days_before' => 0, 'refund_percent' => 0],
                ],
            ],
            [
                'name' => 'Non-refundable',
                'slug' => 'non-refundable',
                'description' => 'No refund after booking.',
                'free_cancellation_days' => 0,
                'tiers' => [
                    ['days_before' => 0, 'refund_percent' => 0],
                ],
            ],
        ];
    }
}
