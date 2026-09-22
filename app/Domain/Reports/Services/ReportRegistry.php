<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\Reports\ArrivalsAndDeparturesReport;
use App\Domain\Reports\Reports\HousekeepingReport;
use App\Domain\Reports\Reports\OccupancyReport;
use App\Domain\Reports\Reports\OwnerProfitAndLossReport;
use App\Domain\Reports\Reports\PaymentReconciliationReport;
use App\Domain\Reports\Reports\SourceMixReport;
use App\Domain\Reports\Reports\TaxLiabilityReport;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The catalogue of reports.
 *
 * A fixed list of classes rather than stored queries. That is the security
 * boundary of this whole module: a saved report holds a *key* and some
 * parameters, never SQL, so saving or scheduling a report can never become a
 * way to run arbitrary statements against a multi-tenant database.
 *
 * Adding a report means adding a class here. That is more friction than a
 * query builder and it is the right amount.
 */
class ReportRegistry
{
    /**
     * @var array<string, class-string<ReportInterface>>
     */
    private const REPORTS = [
        'occupancy' => OccupancyReport::class,
        'source_mix' => SourceMixReport::class,
        'payment_reconciliation' => PaymentReconciliationReport::class,
        'tax_liability' => TaxLiabilityReport::class,
        'owner_profit_and_loss' => OwnerProfitAndLossReport::class,
        'housekeeping' => HousekeepingReport::class,
        'arrivals_departures' => ArrivalsAndDeparturesReport::class,
    ];

    /**
     * @var array<string, ReportInterface>
     */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function has(string $key): bool
    {
        return isset(self::REPORTS[$key]);
    }

    public function make(string $key): ReportInterface
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException(sprintf(
                'There is no report named [%s]. Available: %s.',
                $key,
                implode(', ', array_keys(self::REPORTS)),
            ));
        }

        return $this->resolved[$key] ??= $this->container->make(self::REPORTS[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::REPORTS);
    }

    /**
     * Every report, for a catalogue screen.
     *
     * @return list<ReportInterface>
     */
    public function all(): array
    {
        return array_map(fn (string $key): ReportInterface => $this->make($key), $this->keys());
    }
}
