<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\DataObjects\AutomationContext;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Reservations\Events\ReservationPayload;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Fires time-based rules: "three days before check-in, at 10am".
 *
 * The whole difficulty here is the clock. A portfolio spanning Lisbon, Cairo
 * and Mexico City has no single 10am, so the moment a rule fires is computed
 * in **the property's own timezone**, never the server's. Sending arrival
 * instructions at 10am server time would reach a third of the guests overnight.
 *
 * The planner is called once a minute and asks a narrow question: which
 * reservations have a rule whose firing moment falls inside this minute? It is
 * idempotent through the run's unique key, so a minute processed twice — a
 * retried cron, an overlapping run — still sends one message.
 */
class ScheduledAutomationPlanner
{
    /**
     * Timezone slop when narrowing the candidate date range. The widest real
     * offsets span about 26 hours, so two days is generous and cheap.
     */
    private const DATE_SLOP_DAYS = 2;

    public function __construct(
        private readonly AutomationEngine $engine,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Schedule everything due in a window.
     *
     * The window is half-open, `[from, to)`, so consecutive minutes neither
     * skip a moment nor process one twice.
     *
     * @return array{examined: int, scheduled: int}
     */
    public function plan(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $examined = 0;
        $scheduled = 0;

        $rules = $this->tenancy->withoutScope(fn (): array => AutomationRule::query()
            ->withoutGlobalScope('organization')
            ->scheduled()
            ->get()
            ->all());

        foreach ($rules as $rule) {
            foreach ($this->candidates($rule, $from, $to) as $reservation) {
                $examined++;

                $fireAt = $this->firingMoment($rule, $reservation);

                if ($fireAt === null) {
                    continue;
                }

                if ($fireAt->lessThan($from) || $fireAt->greaterThanOrEqualTo($to)) {
                    continue;
                }

                $run = $this->engine->schedule($rule, $this->contextFor($rule, $reservation));

                if ($run !== null) {
                    $scheduled++;
                }
            }
        }

        return ['examined' => $examined, 'scheduled' => $scheduled];
    }

    /**
     * Reservations whose anchor date could plausibly fire in this window.
     *
     * Narrowed by date before the per-property timezone arithmetic, because
     * doing that arithmetic across a whole portfolio's history would be a
     * table scan every minute.
     *
     * @return iterable<Reservation>
     */
    private function candidates(AutomationRule $rule, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $offsetMinutes = (int) $rule->trigger_offset_minutes;

        // The anchor sits `offset` before the firing moment, so to fire now
        // the anchor must be around now minus the offset.
        $anchorFrom = $from->subMinutes($offsetMinutes)->subDays(self::DATE_SLOP_DAYS);
        $anchorTo = $to->subMinutes($offsetMinutes)->addDays(self::DATE_SLOP_DAYS);

        $query = Reservation::query()
            ->withoutGlobalScope('organization')
            ->with(['property', 'guest'])
            ->where('organization_id', $rule->organization_id)
            // Time-based guest communication is only ever about a live
            // booking. An inquiry has no arrival to prepare for and a
            // cancelled stay must not receive its welcome message.
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out']);

        if ($rule->property_ids !== null && $rule->property_ids !== []) {
            $query->whereIn('property_id', $rule->property_ids);
        }

        if ($rule->channels !== null && $rule->channels !== []) {
            $query->whereIn('source', $rule->channels);
        }

        match ($rule->trigger_anchor) {
            AutomationRule::ANCHOR_CHECK_OUT => $query->whereBetween('check_out_date', [
                $anchorFrom->toDateString(), $anchorTo->toDateString(),
            ]),
            AutomationRule::ANCHOR_BOOKED_AT => $query->whereBetween('booked_at', [$anchorFrom, $anchorTo]),
            default => $query->whereBetween('check_in_date', [
                $anchorFrom->toDateString(), $anchorTo->toDateString(),
            ]),
        };

        return $this->tenancy->withoutScope(fn (): iterable => $query->cursor());
    }

    /**
     * The exact instant a rule fires for one reservation.
     *
     * Derived from the property's local clock throughout: the anchor is the
     * guest's own arrival or departure moment, and an explicit time of day is
     * applied in the property's timezone before being converted back.
     */
    public function firingMoment(AutomationRule $rule, Reservation $reservation): ?CarbonImmutable
    {
        $property = $reservation->property;

        if ($property === null) {
            return null;
        }

        $timezone = $property->timezone ?: config('app.timezone');

        $anchor = match ($rule->trigger_anchor) {
            AutomationRule::ANCHOR_CHECK_OUT => $reservation->departureMoment(),
            AutomationRule::ANCHOR_BOOKED_AT => $reservation->booked_at,
            default => $reservation->arrivalMoment(),
        };

        if ($anchor === null) {
            return null;
        }

        $moment = CarbonImmutable::parse($anchor)
            ->setTimezone($timezone)
            ->addMinutes((int) $rule->trigger_offset_minutes);

        // "Three days before check-in **at 10am**": the offset picks the day,
        // the time of day replaces the hour, both in the property's clock.
        if ($rule->trigger_time_of_day !== null) {
            [$hour, $minute] = array_pad(
                array_map('intval', explode(':', (string) $rule->trigger_time_of_day)),
                2,
                0,
            );

            $moment = $moment->setTime($hour, $minute);
        }

        return $moment->utc();
    }

    /**
     * A scheduled rule's context.
     *
     * The payload is the reservation's standard event shape, so a condition
     * written for `reservation.confirmed` means the same thing on a scheduled
     * rule — an operator should not have to learn two vocabularies.
     */
    private function contextFor(AutomationRule $rule, Reservation $reservation): AutomationContext
    {
        return new AutomationContext(
            organizationId: $reservation->organization_id,
            eventName: sprintf('schedule.%s', $rule->trigger_anchor ?? 'check_in'),
            payload: ReservationPayload::build($reservation),
            subject: $reservation,
            reservation: $reservation,
            property: $reservation->property,
            guest: $reservation->guest,
            // Deliberately null. A scheduled rule fires from the calendar
            // rather than from something that happened, so there is no stored
            // event to cite — and leaving it out makes the run's idempotency
            // key (rule, reservation, event name) constant for this booking.
            // That is the behaviour wanted: "three days before check-in"
            // happens once per stay. If the dates move, the guest must not
            // receive their arrival instructions a second time.
            domainEventId: null,
        );
    }
}
