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

    // `channels:poll` was named here for months and never existed, so nothing
    // was ever pulled on a timer: a connection produced a row in a table and no
    // data, which is the worst kind of integration — it looks connected.
    // Proposals nobody decided on. Hourly rather than nightly because the queue
    // is read during the day and a badge that over-counts by eighteen hours is a
    // badge people learn to ignore.
    $schedule->command('agents:expire-actions')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    /*
     * Inbound, frequently, and the one a waiting guest depends on.
     *
     * A guest message does not exist in Habitat until a pull brings it over, so
     * the cadence here is the delay before anybody — a person or an agent — can
     * answer. It was hourly, while the connection screen told operators their
     * data arrived every five minutes from a literal 5 typed into the resource
     * with nothing tying it to this line. Somebody watching an empty inbox
     * twelve minutes after a guest wrote in concluded the integration was
     * broken, and they were right to.
     *
     * `--automatic` rather than a bare pull. The flag already existed and was
     * never scheduled: it takes connected Hostex accounts whose last attempt is
     * older than the interval, skips the outbound work, and reports `not_due`
     * for the rest. That is what makes a five-minute cadence affordable — the
     * full sweep below still runs hourly, because re-reading every listing,
     * photo and transaction twelve times an hour would spend the API budget on
     * data that changes weekly.
     */
    $schedule->command('channels:pull --automatic')
        ->cron('*/'.max(1, (int) config('pms.channels.pull_interval_minutes', 5)).' * * * *')
        ->withoutOverlapping()
        ->onOneServer();

    // The whole picture — listings, photos, availability, transactions —
    // reconciled on a slower clock than the inbox needs.
    $schedule->command('channels:pull')
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

    // Agent questions waiting on a bot that answers later: close the ones
    // nobody answered. Frequent, because a pending ask holds a live callback
    // token and a screen that says "waiting" should stop saying it promptly.
    $schedule->command('agents:expire-asks')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    // Property knowledge: re-read the documents agents answer from. The
    // document lives where its authors maintain it, and somebody correcting a
    // check-in time has no reason to come here and press a button.
    $schedule->command('properties:refresh-knowledge')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Housekeeping.
    $schedule->command('platform:prune-idempotency-keys')->daily();

    // Old audit rows written by the automatic import's own bookkeeping, kept
    // 30 days. Nothing a person did is touched; see the command.
    $schedule->command('audit:prune-import-noise')
        ->dailyAt('03:30')
        ->withoutOverlapping()
        ->onOneServer();

    $schedule->command('locks:sync-access-codes')->hourly();
    $schedule->command('queue:prune-failed --hours=720')->daily();
    $schedule->command('sanctum:prune-expired --hours=24')->daily();
};
