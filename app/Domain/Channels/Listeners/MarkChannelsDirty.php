<?php

declare(strict_types=1);

namespace App\Domain\Channels\Listeners;

use App\Domain\Availability\Events\CalendarBlockChanged;
use App\Domain\Availability\Events\CalendarDaysChanged;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Events\PricingRuleChanged;
use App\Domain\Pricing\Events\RatePlanChanged;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the channels in step with the calendar.
 *
 * Deliberately synchronous and deliberately cheap: it sets a flag and a date
 * window, nothing more. The actual push is drained by the scheduler.
 *
 * That split is the whole point. Pushing inline would mean a guest's booking
 * waits on however many OTAs the listing is published to, and an OTA being
 * slow — or down — could fail a reservation that has already taken the guest's
 * money. Marking is a single update; the consequences are somebody else's
 * problem, a few seconds later.
 *
 * A booking arriving *from* a channel still marks every channel dirty,
 * including the one it came from. The others need to know the dates are gone,
 * and the originating channel already knows but will simply skip an unchanged
 * calendar.
 */
class MarkChannelsDirty
{
    public function __construct(
        private readonly ChannelSynchroniser $sync,
        private readonly TenantContext $tenancy,
    ) {}

    public function handleConfirmed(ReservationConfirmed $event): void
    {
        $this->mark($event->reservation);
    }

    public function handleCreated(ReservationCreated $event): void
    {
        // An inquiry holds no inventory, so there is nothing for a channel to
        // know about yet.
        if (! $event->reservation->blocksInventory()) {
            return;
        }

        $this->mark($event->reservation);
    }

    public function handleModified(ReservationModified $event): void
    {
        $payload = $event->payload();

        // A guest adding a toddler does not change what any channel can sell.
        if (! ($payload['dates_changed'] ?? false) && ! ($payload['unit_changed'] ?? false)) {
            return;
        }

        $this->mark($event->reservation, $payload['previous'] ?? null);
    }

    public function handleCancelled(ReservationCancelled $event): void
    {
        // The most urgent case of all: dates that just became sellable again,
        // and every hour they stay closed on a channel is revenue lost.
        $this->mark($event->reservation);
    }

    /**
     * A price, a minimum stay, an arrival rule or a night closed by hand.
     *
     * The event has already worked out which channel payloads moved, because
     * that is a fact about the payloads rather than about this listener.
     */
    public function handleCalendarDays(CalendarDaysChanged $event): void
    {
        $this->guard($event->listing, function () use ($event): void {
            $this->sync->markListingDirty(
                $event->listing->getKey(),
                $event->what,
                $event->from,
                $event->to,
            );
        });
    }

    /**
     * An owner stay, a maintenance closure or a manual hold.
     *
     * Rates are untouched — a block changes what can be sold, not what it
     * costs. Resolved through the scope rather than a single listing because a
     * block hangs off a property and closes every listing on it unless it
     * names one.
     */
    public function handleCalendarBlock(CalendarBlockChanged $event): void
    {
        $block = $event->block;

        $this->guard($block, function () use ($block): void {
            $this->sync->markScopeDirty(
                'availability',
                $block->property_id,
                null,
                $block->listing_id,
                $block->start_date->toDateString(),
                $block->end_date->toDateString(),
            );
        });
    }

    /**
     * A plan created, repriced or taken off sale.
     *
     * A plan carries no date window, so this widens to the full horizon. The
     * content hash on each mapping means the nights whose derived price did
     * not actually move are skipped at push time rather than re-sent.
     */
    public function handleRatePlan(RatePlanChanged $event): void
    {
        $plan = $event->ratePlan;

        $this->guard($plan, function () use ($plan): void {
            [$from, $to] = $this->fullHorizon();

            $this->sync->markScopeDirty(
                'rates',
                $plan->property_id,
                $plan->portfolio_id,
                null,
                $from,
                $to,
            );
        });
    }

    /**
     * A rule created, edited or switched off.
     *
     * The window is the nights the rule prices (`stay_from`..`stay_to`), not
     * the period it is in force. Either end may be unbounded, in which case
     * the horizon stands in for it.
     */
    public function handlePricingRule(PricingRuleChanged $event): void
    {
        $rule = $event->rule;

        $previous = $event->previousStayWindow;

        $this->guard($rule, function () use ($rule, $previous): void {
            [$horizonFrom, $horizonTo] = $this->fullHorizon();

            $from = $rule->stay_from?->toDateString() ?? $horizonFrom;
            $to = $rule->stay_to?->toDateString() ?? $horizonTo;

            // A rule that moved has to push both windows. The mapping's own
            // dirty range widens as flags accumulate, but it cannot know about
            // a window this rule has already stopped covering, so the union is
            // computed here. Null either side meant unbounded, which is the
            // horizon.
            if ($previous !== null) {
                $from = min($from, $previous['from'] ?? $horizonFrom);
                $to = max($to, $previous['to'] ?? $horizonTo);
            }

            $this->sync->markScopeDirty(
                'rates',
                $rule->property_id,
                $rule->portfolio_id,
                $rule->listing_id,
                $from,
                $to,
            );
        });
    }

    /**
     * @param  array<string, mixed>|null  $previous  Dates before a change, so a
     *                                               moved booking marks both windows.
     */
    private function mark(object $reservation, ?array $previous = null): void
    {
        if ($reservation->listing_id === null) {
            return;
        }

        $organization = $reservation->organization;

        if ($organization === null) {
            return;
        }

        try {
            $this->tenancy->runAs($organization, function () use ($reservation, $previous): void {
                $from = $reservation->check_in_date->toDateString();
                $to = $reservation->check_out_date->toDateString();

                // A moved booking frees its old dates and takes new ones, so
                // both windows need pushing. Marking only the new ones leaves
                // the old dates closed on every channel.
                if (isset($previous['check_in_date'], $previous['check_out_date'])) {
                    $from = min($from, (string) $previous['check_in_date']);
                    $to = max($to, (string) $previous['check_out_date']);
                }

                $this->sync->markListingDirty($reservation->listing_id, 'availability', $from, $to);
            });
        } catch (\Throwable $exception) {
            // Channel bookkeeping must never be able to fail a booking. The
            // scheduler's full-horizon sweep is the safety net.
            Log::error('Could not mark channels dirty for a reservation.', [
                'reservation_id' => $reservation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Run channel bookkeeping inside the record's own tenant, and never let it
     * escape.
     *
     * Both halves matter. The tenant is bound explicitly because these events
     * are also dispatched from queued jobs and console commands, where no
     * organization is bound and the mapping query would otherwise read from
     * the wrong tenant or refuse outright.
     *
     * And it logs rather than throws, because marking a channel dirty is
     * bookkeeping about a save that has already happened. An operator who
     * corrected a price must not see that correction fail because an unrelated
     * mapping row was wrong — the scheduler's full-horizon sweep will catch
     * what this missed, and a lost flag is recoverable where a lost edit is
     * not.
     */
    private function guard(Model $record, Closure $work): void
    {
        // Loaded explicitly rather than read off the relation. Strict model
        // mode is on everywhere but production, and most of these records
        // arrive route-bound and already persisted — reading `->organization`
        // on one is a lazy-loading violation, which would throw out here
        // *before* the try below could contain it and so fail the very save
        // this listener exists not to endanger. loadMissing() is an explicit
        // eager load and allowed; it is also a no-op when the caller already
        // has the relation.
        $record->loadMissing('organization');

        // Safe to read now that the relation is loaded, and null when the
        // record's organization has gone — in which case there is nothing to
        // mark and nowhere to mark it.
        $organization = $record->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        try {
            $this->tenancy->runAs($organization, $work);
        } catch (\Throwable $exception) {
            Log::error('Could not mark channels dirty after a local change.', [
                'record' => $record::class,
                'record_id' => $record->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The whole publishing window, for changes that carry no dates of their
     * own.
     *
     * @return array{0: string, 1: string}
     */
    private function fullHorizon(): array
    {
        $today = CarbonImmutable::today();

        return [
            $today->toDateString(),
            $today->addDays((int) config('pms.availability.horizon_days', 730))->toDateString(),
        ];
    }
}
