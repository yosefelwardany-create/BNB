<?php

declare(strict_types=1);

namespace App\Domain\Availability\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Integrations\Contracts\KeepsStayRulesOnRates;
use App\Domain\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-date rates or restrictions changed on a listing's own calendar.
 *
 * One event covers one operator action over a date range, not one per night.
 * A season-long bulk edit touches hundreds of `calendar_days` rows and is a
 * single business act; emitting per row would flood the event store and make
 * every webhook subscriber and automation run process the same decision
 * hundreds of times.
 *
 * `$what` says which channel payloads the edit moved, so the listener can mark
 * only those dirty — see {@see self::pushAffectedBy()}.
 */
class CalendarDaysChanged extends AbstractDomainEvent
{
    public const NAME = 'calendar_days.changed';

    public function __construct(
        public readonly Listing $listing,
        public readonly string $from,
        public readonly string $to,
        public readonly string $what,
        /**
         * The `calendar_days` columns the operator supplied, so a channel that
         * reads its stay rules elsewhere can decide for itself
         * ({@see self::pushAffectedFor()}). Empty when the caller did not say.
         *
         * @var list<string>
         */
        public readonly array $columns = [],
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->listing;
    }

    public function payload(): array
    {
        return [
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->listing->property_id,
            'from' => $this->from,
            'to' => $this->to,
            'what' => $this->what,
        ];
    }

    /**
     * Which channel payloads a set of changed `calendar_days` columns moves.
     *
     * This mapping lives next to the event because it is a fact about the
     * channel payloads themselves, not about any one controller: a price and a
     * maximum stay ride on the rates payload, a closed night and the arrival
     * and departure rules ride on the availability payload, and a minimum stay
     * rides on **both** — which is the case that a hand-written check at the
     * call site always gets wrong.
     *
     * @param  list<string>  $columns
     * @return 'availability'|'rates'|'both'
     */
    public static function pushAffectedBy(array $columns): string
    {
        $rates = false;
        $availability = false;

        foreach ($columns as $column) {
            match ($column) {
                'rate_override', 'maximum_nights' => $rates = true,
                'is_blocked', 'closed_to_arrival', 'closed_to_departure' => $availability = true,
                'minimum_nights' => $rates = $availability = true,
                // Anything else (a note, say) moves no channel payload on its
                // own. Deliberately conservative rather than silent: the push
                // compares a content hash of what it last sent, so a mapping
                // marked for an edit that moved nothing is skipped at push
                // time instead of being re-sent. Under-marking has no such
                // safety net — it just leaves a channel wrong.
                default => $rates = $availability = true,
            };
        }

        return match (true) {
            $rates && $availability => 'both',
            $rates => 'rates',
            $availability => 'availability',
            // No columns at all. The calendar endpoint refuses an empty change
            // before it gets here, so this is only reachable from a future
            // caller — and for that caller the safe answer is the same one the
            // unrecognised-column case gets.
            default => 'both',
        };
    }

    /**
     * The same, for one channel's mapping.
     *
     * A channel that keeps every stay rule on the rates message
     * ({@see KeepsStayRulesOnRates}, Channex) needs a rates push for any of
     * them, a closed-to-arrival night included, and an availability push only
     * when what is left to sell can have changed: a night closed by hand.
     * Every other channel is answered as {@see self::pushAffectedBy()} does.
     *
     * @param  list<string>  $columns
     * @return 'availability'|'rates'|'both'
     */
    public static function pushAffectedFor(array $columns, bool $stayRulesOnRates): string
    {
        if (! $stayRulesOnRates) {
            return self::pushAffectedBy($columns);
        }

        $availability = false;

        foreach ($columns as $column) {
            match ($column) {
                'rate_override', 'maximum_nights', 'minimum_nights', 'closed_to_arrival', 'closed_to_departure' => null,
                // Nothing left to sell, and closed to sale as the decision
                // it was: both messages.
                'is_blocked' => $availability = true,
                // As above: an unknown column is treated as moving both.
                default => $availability = true,
            };
        }

        return $availability || $columns === [] ? 'both' : 'rates';
    }
}
