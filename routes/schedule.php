<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/**
 * The platform's scheduled work.
 *
 * Anything that fires "at a time of day" for a property must run frequently
 * and then filter by the *property's* timezone inside the job — a portfolio
 * routinely spans several timezones, so a single nightly run at server
 * midnight would be wrong for most of the estate.
 */
return function (Schedule $schedule): void {
    // Automation: time-based triggers ("3 days before arrival") are evaluated
    // every fifteen minutes against each property's local clock.
    $schedule->command('automation:dispatch-scheduled')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    // Reservation lifecycle housekeeping: expire stale holds, mark no-shows,
    // and roll reservations into checked-out.
    $schedule->command('reservations:advance-lifecycle')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    // Channel synchronisation: push any availability/pricing changes that were
    // queued, and pull remote changes for channels without webhooks.
    $schedule->command('channels:push-pending')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    $schedule->command('channels:poll')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Operations: create the cleaning and preparation tasks implied by
    // upcoming departures.
    $schedule->command('operations:generate-turnover-tasks')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Finance: retry failed payments that are due, and advance payment
    // schedules whose instalment date has arrived.
    $schedule->command('payments:process-due-schedules')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Owner accounting: generate draft statements for periods that have closed.
    $schedule->command('owner-statements:generate-due')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->onOneServer();

    // Outbound webhooks: re-queue attempts whose backoff has elapsed. The
    // schedule lives in the delivery rows rather than as delayed jobs, so it
    // survives a worker restart or a drained queue — and this is what picks it
    // back up.
    $schedule->command('webhooks:retry-due')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    // Reporting: run scheduled report deliveries.
    $schedule->command('reports:run-scheduled')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Housekeeping.
    $schedule->command('platform:prune-idempotency-keys')->daily();
    $schedule->command('locks:sync-access-codes')->hourly();
    $schedule->command('queue:prune-failed --hours=720')->daily();
    $schedule->command('sanctum:prune-expired --hours=24')->daily();
};
