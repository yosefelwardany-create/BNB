<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Shared machinery for reports.
 *
 * Deliberately small: the base class supplies the tenant, the currency and the
 * column helpers, and nothing else. Anything more would tempt reports to share
 * query building, and a shared query builder is how a report ends up returning
 * rows its author never considered — including, eventually, another tenant's.
 */
abstract class AbstractReport implements ReportInterface
{
    public function __construct(protected readonly TenantContext $tenancy) {}

    public function description(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterRules(): array
    {
        return [];
    }

    protected function currency(): string
    {
        return $this->tenancy->organizationOrFail()->base_currency;
    }

    protected function organizationId(): string
    {
        return (string) $this->tenancy->organizationOrFail()->getKey();
    }

    /**
     * A money column's value.
     *
     * Reports carry money as the full value object rather than a bare integer,
     * so an exporter can format it and a reader can see the currency. A column
     * of unlabelled integers is a column somebody will read as dollars.
     */
    protected function money(int|float|string|null $minorUnits, ?string $currency = null): array
    {
        return Money::of((int) $minorUnits, $currency ?? $this->currency())->jsonSerialize();
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    protected function column(string $key, string $label, string $type = 'string'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /**
     * Percentage, guarded against the empty denominator.
     */
    protected function percentage(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
