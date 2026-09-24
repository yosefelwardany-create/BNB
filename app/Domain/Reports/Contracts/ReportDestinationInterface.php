<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Domain\Reports\DataObjects\ReportArtifact;
use App\Domain\Reports\DataObjects\ReportDeliveryOutcome;
use App\Domain\Reports\Models\SavedReport;

/**
 * Where a finished report goes.
 *
 * Email was the only answer for a long time, and it is the wrong only answer.
 * An operator who wants last month's occupancy in their own spreadsheet, or
 * their accountant's system, or a warehouse somebody else queries, was told to
 * receive an attachment and forward it by hand — which is how a figure ends up
 * being retyped, and how a retyped figure ends up wrong.
 *
 * A destination declares what configuration it needs and validates it up
 * front, because a schedule that fails at three in the morning on a typo in a
 * URL is a schedule nobody finds out about for a week.
 */
interface ReportDestinationInterface
{
    /** Registry key: `email`, `webhook`, `storage`. */
    public function key(): string;

    public function displayName(): string;

    /**
     * What this destination needs, phrased for somebody filling in a form.
     */
    public function describe(): string;

    /**
     * Check a configuration before it is saved.
     *
     * @param  array<string, mixed>  $config
     * @return list<string> every problem, so a form can show them at once
     */
    public function problemsWith(array $config): array;

    /**
     * Send one rendered report.
     *
     * Never throws for an unreachable destination: a receiver that is down is
     * an ordinary outcome, recorded on the run so somebody can see it, not an
     * exception that would abandon the other destinations in the same run.
     *
     * @param  array<string, mixed>  $config
     */
    public function deliver(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        array $config,
    ): ReportDeliveryOutcome;
}
