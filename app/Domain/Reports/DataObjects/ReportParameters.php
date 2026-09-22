<?php

declare(strict_types=1);

namespace App\Domain\Reports\DataObjects;

use Carbon\CarbonImmutable;

/**
 * What a report was asked for.
 *
 * A value object rather than a loose array so that a saved report, a scheduled
 * run and an ad-hoc request all hand the report the same shape — and so a
 * report stored in January and run in June is unambiguous about which period
 * it means.
 */
final class ReportParameters
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $filters = [],
    ) {}

    /**
     * Build from request or stored input.
     *
     * Relative periods are resolved at run time, not at save time. A report
     * saved as "last month" must mean last month whenever it runs, and
     * freezing the dates at save time is how a scheduled monthly report ends
     * up sending the same January figures every month for a year.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $today = CarbonImmutable::today();

        [$from, $to] = match ($input['period'] ?? null) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'next_30_days' => [$today, $today->addDays(29)],
            'next_90_days' => [$today, $today->addDays(89)],
            default => [
                isset($input['from']) ? CarbonImmutable::parse($input['from'])->startOfDay() : $today->startOfMonth(),
                isset($input['to']) ? CarbonImmutable::parse($input['to'])->startOfDay() : $today,
            ],
        };

        return new self(
            from: $from->startOfDay(),
            to: $to->startOfDay(),
            filters: array_diff_key($input, array_flip(['period', 'from', 'to'])),
        );
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function propertyIds(): array
    {
        return array_values((array) ($this->filters['property_ids'] ?? []));
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'filters' => $this->filters,
        ];
    }
}
