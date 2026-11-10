<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Availability\Events\CalendarDaysChanged;

/**
 * A channel that reads every stay rule off the rates message, and only the
 * count of units left off the availability one.
 *
 * Channex keys minimum and maximum stay, the arrival and departure rules and
 * stop sell to a rate plan, on its restrictions endpoint; its availability
 * endpoint takes a number per room type and nothing else. So a night closed
 * to arrival must go out as a rates push to reach Channex at all, and a new
 * minimum stay has no reason to send an availability call.
 *
 * Other channels read some of these rules with the availability, which is
 * what {@see CalendarDaysChanged::pushAffectedBy()} is written for.
 */
interface KeepsStayRulesOnRates {}
