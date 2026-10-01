<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Support\BotEndpoint;
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
        /*
         * The bot's token goes to its own encrypted column and never into
         * settings, which is plain JSON — readable in a dump, a backup, or a log
         * line that prints a model. It is a credential, and the platform already
         * has one place those go.
         *
         * An empty string clears it. That has to be distinguishable from the key
         * being absent, which means "leave it alone", or somebody saving a change
         * to the persona would wipe the token every time.
         */
        foreach (['bot_token' => 'agent_bot_token', 'webhook_token' => 'agent_webhook_token'] as $key => $column) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }

            $token = $changes[$key];

            $property->{$column} = is_string($token) && trim($token) !== ''
                ? trim($token)
                : null;

            unset($changes[$key]);
        }

        // Refused before anything is written, so a URL Habitat will not call
        // cannot be left behind on the record looking configured. The webhook
        // goes through the same guard as the bot: it is the same request forgery
        // primitive, pointed at a different endpoint.
        foreach (['bot_url', 'webhook_url'] as $key) {
            if (array_key_exists($key, $changes)
                && is_string($changes[$key])
                && trim($changes[$key]) !== '') {
                $changes[$key] = BotEndpoint::parse($changes[$key])->url;
            }
        }

        $settings = is_array($property->settings) ? $property->settings : [];
        $agent = is_array($settings['agent'] ?? null) ? $settings['agent'] : [];

        $settings['agent'] = [...$agent, ...$changes];

        $property->settings = $settings;
        $property->save();

        return $this->for($property);
    }
}
