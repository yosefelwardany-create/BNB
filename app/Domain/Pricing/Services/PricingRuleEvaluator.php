<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\PricingRule;
use Carbon\CarbonImmutable;

/**
 * Decides whether a pricing rule applies to a given night.
 *
 * The condition vocabulary is deliberately fixed and small. There is no
 * expression language and nothing is ever evaluated as code: a pricing rule is
 * data entered by a user, and turning user data into executable code would be
 * both a security hole and an unreproducible pricing model.
 *
 * Supported condition keys:
 *
 *   nights_min / nights_max           length of the whole stay
 *   guests_min / guests_max           occupancy (infants excluded)
 *   lead_time_min / lead_time_max     days between booking and arrival
 *   pets_min                          number of pets
 *   day_of_week                       [0..6], 0 = Sunday
 *   months                            [1..12]
 *   stay_includes_weekend             bool
 *
 * An unknown key causes the rule not to match, rather than being ignored: a
 * typo must not silently widen a rule's reach.
 */
class PricingRuleEvaluator
{
    private const SUPPORTED = [
        'nights_min', 'nights_max',
        'guests_min', 'guests_max',
        'lead_time_min', 'lead_time_max',
        'pets_min',
        'day_of_week', 'months',
        'stay_includes_weekend',
    ];

    public function matches(PricingRule $rule, PricingContext $context, CarbonImmutable $night): bool
    {
        // Stay-date window.
        if ($rule->stay_from !== null && $night->lt($rule->stay_from)) {
            return false;
        }

        if ($rule->stay_to !== null && $night->gt($rule->stay_to)) {
            return false;
        }

        // Day-of-week restriction on the rule itself.
        $daysOfWeek = $rule->days_of_week;

        if ($daysOfWeek !== null && $daysOfWeek !== [] && ! in_array((int) $night->dayOfWeek, array_map('intval', $daysOfWeek), true)) {
            return false;
        }

        return $this->conditionsSatisfied($rule->conditions ?? [], $context, $night);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function conditionsSatisfied(array $conditions, PricingContext $context, CarbonImmutable $night): bool
    {
        foreach ($conditions as $key => $value) {
            if (! in_array($key, self::SUPPORTED, true)) {
                // An unrecognised condition means the rule cannot be evaluated
                // safely, so it does not apply.
                return false;
            }

            if (! $this->conditionSatisfied($key, $value, $context, $night)) {
                return false;
            }
        }

        return true;
    }

    private function conditionSatisfied(string $key, mixed $value, PricingContext $context, CarbonImmutable $night): bool
    {
        return match ($key) {
            'nights_min' => $context->nights() >= (int) $value,
            'nights_max' => $context->nights() <= (int) $value,

            'guests_min' => $context->guests() >= (int) $value,
            'guests_max' => $context->guests() <= (int) $value,

            'lead_time_min' => $context->leadTimeDays() >= (int) $value,
            'lead_time_max' => $context->leadTimeDays() <= (int) $value,

            'pets_min' => $context->pets >= (int) $value,

            'day_of_week' => in_array((int) $night->dayOfWeek, array_map('intval', (array) $value), true),
            'months' => in_array((int) $night->month, array_map('intval', (array) $value), true),

            // A "weekend" night here is Friday or Saturday: the nights that
            // carry a weekend premium, rather than the calendar weekend.
            'stay_includes_weekend' => (bool) $value === $this->stayIncludesWeekend($context),

            default => false,
        };
    }

    private function stayIncludesWeekend(PricingContext $context): bool
    {
        foreach ($context->nightDates() as $date) {
            $dayOfWeek = (int) CarbonImmutable::parse($date)->dayOfWeek;

            if ($dayOfWeek === CarbonImmutable::FRIDAY || $dayOfWeek === CarbonImmutable::SATURDAY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a condition document before it is saved, so a rule that could
     * never match is rejected at the point of entry.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<string> errors; empty means valid
     */
    public function validate(array $conditions): array
    {
        $errors = [];

        foreach ($conditions as $key => $value) {
            if (! in_array($key, self::SUPPORTED, true)) {
                $errors[] = sprintf(
                    'Unknown condition [%s]. Supported conditions are: %s.',
                    $key,
                    implode(', ', self::SUPPORTED),
                );

                continue;
            }

            $errors = array_merge($errors, $this->validateValue($key, $value));
        }

        // Contradictory bounds would silently never match.
        foreach ([['nights_min', 'nights_max'], ['guests_min', 'guests_max'], ['lead_time_min', 'lead_time_max']] as [$min, $max]) {
            if (isset($conditions[$min], $conditions[$max]) && (int) $conditions[$min] > (int) $conditions[$max]) {
                $errors[] = sprintf('%s cannot be greater than %s; this rule could never apply.', $min, $max);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateValue(string $key, mixed $value): array
    {
        return match ($key) {
            'day_of_week' => $this->validateIntegerList($key, $value, 0, 6),
            'months' => $this->validateIntegerList($key, $value, 1, 12),
            'stay_includes_weekend' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true)
                ? []
                : [sprintf('%s must be true or false.', $key)],
            default => is_numeric($value)
                ? []
                : [sprintf('%s must be a number.', $key)],
        };
    }

    /**
     * @return list<string>
     */
    private function validateIntegerList(string $key, mixed $value, int $min, int $max): array
    {
        if (! is_array($value) || $value === []) {
            return [sprintf('%s must be a non-empty list.', $key)];
        }

        foreach ($value as $item) {
            if (! is_numeric($item) || (int) $item < $min || (int) $item > $max) {
                return [sprintf('%s values must be between %d and %d.', $key, $min, $max)];
            }
        }

        return [];
    }
}
