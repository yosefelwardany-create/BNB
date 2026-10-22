<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Pricing\Models\PricingRule;
use Illuminate\Database\Eloquent\Model;

/**
 * A pricing rule changed: created, edited, or switched off.
 *
 * The window this event reports is `stay_from`..`stay_to` — the nights the
 * rule *prices* — and deliberately NOT `effective_from`..`effective_to`, which
 * is the period during which the rule is in force. The two are easy to
 * confuse and the consequence of confusing them is a channel left with stale
 * prices: a rule created today, effective today, that prices next August,
 * would push today's nights and leave August wrong.
 *
 * Both are nullable; null means the rule is unbounded in that direction, so
 * the listener widens to the full publishing horizon.
 *
 * Switching a rule off counts as a change: it moves the price on every night
 * it was adjusting.
 */
class PricingRuleChanged extends AbstractDomainEvent
{
    public const NAME = 'pricing_rule.changed';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DEACTIVATED = 'deactivated';

    public function __construct(
        public readonly PricingRule $rule,
        public readonly string $action,
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->rule;
    }

    public function payload(): array
    {
        return [
            'pricing_rule_id' => $this->rule->getKey(),
            'name' => $this->rule->name,
            'kind' => $this->rule->kind,
            'property_id' => $this->rule->property_id,
            'portfolio_id' => $this->rule->portfolio_id,
            'listing_id' => $this->rule->listing_id,
            'rate_plan_id' => $this->rule->rate_plan_id,
            // The nights priced, not the period in force. See the class docblock.
            'stay_from' => $this->rule->stay_from?->toDateString(),
            'stay_to' => $this->rule->stay_to?->toDateString(),
            'is_active' => (bool) $this->rule->is_active,
            'action' => $this->action,
        ];
    }
}
