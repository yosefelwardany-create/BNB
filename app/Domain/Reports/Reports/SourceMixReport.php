<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Support\Tenancy\TenantContext;

/**
 * Where the business came from.
 *
 * The report an operator reads before renegotiating with a channel, or before
 * deciding the direct booking engine has earned its keep. Grouped by source
 * rather than by channel account, because "how much of our business is Airbnb"
 * is a question about the channel, not about which of three logins it arrived
 * through.
 */
class SourceMixReport extends AbstractReport
{
    public function __construct(
        TenantContext $tenancy,
        private readonly RevenueAnalytics $analytics,
    ) {
        parent::__construct($tenancy);
    }

    public function key(): string
    {
        return 'source_mix';
    }

    public function name(): string
    {
        return 'Booking source mix';
    }

    public function description(): string
    {
        return 'Nights, bookings and revenue by the channel each booking came from.';
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
            $this->column('source', 'Source'),
            $this->column('reservations', 'Bookings', 'integer'),
            $this->column('nights_sold', 'Nights', 'integer'),
            $this->column('accommodation_revenue', 'Room revenue', 'money'),
            $this->column('adr', 'ADR', 'money'),
            $this->column('share_of_revenue', 'Share of revenue', 'percentage'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $rows = $this->analytics->bySource(
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
                'source' => 'All sources',
                'reservations' => $summary['reservations'],
                'nights_sold' => $summary['nights_sold'],
                'accommodation_revenue' => $summary['accommodation_revenue'],
                'adr' => $summary['adr'],
                'share_of_revenue' => 100.0,
            ],
            notes: [
                'Revenue is room revenue before channel commission. What a channel actually costs is on the owner statement.',
            ],
            meta: ['currency' => $summary['currency']],
        );
    }
}
