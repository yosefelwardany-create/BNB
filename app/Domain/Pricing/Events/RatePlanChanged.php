<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Pricing\Models\RatePlan;
use Illuminate\Database\Eloquent\Model;

/**
 * A commercial product changed: created, repriced, or taken off sale.
 *
 * Carries no date window, because a plan has none — it prices every night it
 * applies to, for as long as it is on sale. The listener therefore widens to
 * the full publishing horizon.
 *
 * A plan's scope is a property, a portfolio, or the whole organization when
 * both are null. It is never a single listing, which is why the listener
 * resolves it through
 * {@see \App\Domain\Channels\Services\ChannelSynchroniser::markScopeDirty()}
 * rather than marking one listing's mappings.
 *
 * Withdrawal counts as a change: taking a plan off sale moves the price on
 * every night it was pricing.
 */
class RatePlanChanged extends AbstractDomainEvent
{
    public const NAME = 'rate_plan.changed';

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly RatePlan $ratePlan,
        public readonly string $action,
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->ratePlan;
    }

    public function payload(): array
    {
        return [
            'rate_plan_id' => $this->ratePlan->getKey(),
            'name' => $this->ratePlan->name,
            'property_id' => $this->ratePlan->property_id,
            'portfolio_id' => $this->ratePlan->portfolio_id,
            'parent_rate_plan_id' => $this->ratePlan->parent_rate_plan_id,
            'is_active' => (bool) $this->ratePlan->is_active,
            'is_default' => (bool) $this->ratePlan->is_default,
            'action' => $this->action,
        ];
    }
}
