<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Support\Tenancy\TenantContext;

/**
 * Occupancy, ADR and RevPAR per property.
 *
 * Delegates to the same analytics service the dashboard uses rather than
 * running its own query. Two implementations of "what is occupancy" is two
 * numbers, and the one on the report is the one somebody takes to a meeting.
 */
class OccupancyReport extends AbstractReport
{
    public function __construct(
        TenantContext $tenancy,
        private readonly RevenueAnalytics $analytics,
    ) {
        parent::__construct($tenancy);
    }

    public function key(): string
    {
        return 'occupancy';
    }

    public function name(): string
    {
        return 'Occupancy and rate performance';
    }

    public function description(): string
    {
        return 'Nights sold against nights available, with average daily rate and revenue per available night, per property.';
    }

    public function category(): string
    {
        return 'Revenue';
    }

    public function permission(): string
    {
        return 'revenue.view';
    }

    public function columns(): array
    {
        return [
            $this->column('property_name', 'Property'),
            $this->column('nights_sold', 'Nights sold', 'integer'),
            $this->column('nights_available', 'Nights available', 'integer'),
            $this->column('occupancy_rate', 'Occupancy', 'percentage'),
            $this->column('reservations', 'Bookings', 'integer'),
            $this->column('accommodation_revenue', 'Room revenue', 'money'),
            $this->column('adr', 'ADR', 'money'),
            $this->column('revpar', 'RevPAR', 'money'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $rows = $this->analytics->byProperty(
            $parameters->from,
            $parameters->to,
            $parameters->propertyIds(),
        );

        $summary = $this->analytics->summary(
            $parameters->from,
            $parameters->to,
            $parameters->propertyIds(),
        );

        return new ReportResult(
            rows: $rows,
            totals: [
                'property_name' => 'All properties',
                'nights_sold' => $summary['nights_sold'],
                'nights_available' => $summary['nights_available'],
                'occupancy_rate' => $summary['occupancy_rate'],
                'reservations' => $summary['reservations'],
                'accommodation_revenue' => $summary['accommodation_revenue'],
                'adr' => $summary['adr'],
                'revpar' => $summary['revpar'],
            ],
            notes: [
                'Room revenue excludes cleaning fees, other fees and tax.',
                'Only confirmed, checked-in and checked-out bookings count. Cancellations earn nothing.',
                'Nights available is the active property count for the period, so blocking a property does not improve its occupancy.',
            ],
            meta: ['currency' => $summary['currency']],
        );
    }
}
