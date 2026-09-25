<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Properties\Models\Property;

/**
 * Reading and writing one property's agent brief.
 *
 * Kept out of the controller because two things about it are easy to get wrong
 * and expensive to get wrong twice:
 *
 *  - The brief lives inside `properties.settings`, a column other features
 *    also use. Writing it means merging, not replacing, or turning the agent on
 *    would quietly discard whatever else was in there.
 *  - A partial update must leave absent keys alone. `PATCH {"enabled": true}`
 *    means "turn it on", not "turn it on and forget the persona and the
 *    escalation list" — which is what assigning a freshly-built brief would do.
 *
 * Saving goes through the model, so the change lands in the audit trail like
 * any other property edit. Turning an agent on is exactly the sort of change
 * somebody will later want to know the author of.
 */
class AgentBriefStore
{
    public function for(Property $property): AgentBrief
    {
        return AgentBrief::fromSettings($property->settings);
    }

    /**
     * Merge changes into the stored brief and return what is now in force.
     *
     * Returns the brief as re-read from what was saved rather than as
     * requested, so a caller sees the narrowing the brief applies — asking for
     * `auto_send: ["payment"]` comes back without it.
     *
     * @param  array<string, mixed>  $changes  Only the keys being changed.
     */
    public function save(Property $property, array $changes): AgentBrief
    {
        $settings = is_array($property->settings) ? $property->settings : [];
        $agent = is_array($settings['agent'] ?? null) ? $settings['agent'] : [];

        $settings['agent'] = [...$agent, ...$changes];

        $property->settings = $settings;
        $property->save();

        return $this->for($property);
    }
}
