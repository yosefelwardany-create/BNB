<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Exceptions\BotEndpointRefusedException;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Properties\Jobs\FetchKnowledgeDocument;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyDocument;

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

        /*
         * These two end up in an `href` and an `img src` on the property card,
         * which makes an unchecked scheme stored cross-site scripting: a
         * `javascript:` link typed by one member of staff and clicked by another
         * runs in that second person's session. Not run through BotEndpoint,
         * because Habitat never fetches these — a browser does — so what matters
         * is the scheme a browser will act on, not whether the address is
         * routable from this server.
         */
        foreach (['bot_avatar_url', 'knowledge_base_url'] as $key) {
            if (array_key_exists($key, $changes)) {
                $changes[$key] = $this->browsableLink($changes[$key]);
            }
        }

        $settings = is_array($property->settings) ? $property->settings : [];
        $agent = is_array($settings['agent'] ?? null) ? $settings['agent'] : [];

        if (array_key_exists('automatic_guest_replies', $changes)) {
            if ($changes['automatic_guest_replies'] && empty($agent['automatic_guest_replies'])) {
                $changes['automatic_replies_since'] = now()->toISOString();
                $changes['automatic_replies_authorized_by'] = auth()->id();
            } elseif (! $changes['automatic_guest_replies']) {
                $changes['automatic_replies_since'] = null;
                $changes['automatic_replies_authorized_by'] = null;
            }
        }
        // Keep the chosen provider on workers with a different default.
        if (($changes['automatic_guest_replies'] ?? $agent['automatic_guest_replies'] ?? false)
            && empty(array_key_exists('provider', $changes) ? $changes['provider'] : ($agent['provider'] ?? null))) {
            $changes['provider'] = app(AIProviderRegistry::class)->default()->key();
        }

        $settings['agent'] = [...$agent, ...$changes];

        $property->settings = $settings;
        $property->save();

        /*
         * A link the agent can actually read.
         *
         * Until now this was a link and nothing more: a person could open it and
         * the agent could not. Saving one now creates the document row behind
         * it and queues a fetch, so the thing an operator thought they were
         * configuring is the thing that happens.
         */
        if (array_key_exists('knowledge_base_url', $changes)) {
            $this->syncKnowledgeDocument($property, $changes['knowledge_base_url']);
        }

        return $this->for($property);
    }

    /**
     * Keep the knowledge document in step with the link on the brief.
     *
     * Clearing the link removes the document, because a copy of a document
     * nobody is pointing at any more is a copy that will be quoted at a guest
     * next week with no way to correct it.
     */
    private function syncKnowledgeDocument(Property $property, mixed $url): void
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '') {
            $property->documents()
                ->where('kind', PropertyDocument::KIND_KNOWLEDGE_BASE)
                ->delete();

            return;
        }

        $document = $property->documents()->firstOrNew([
            'kind' => PropertyDocument::KIND_KNOWLEDGE_BASE,
        ]);

        $changed = $document->url !== $url;

        $document->organization_id ??= $property->organization_id;
        $document->url = $url;

        if ($changed) {
            // A different document is a different document. Keeping the old
            // text against the new address would have the agent quoting the
            // wrong house.
            $document->forceFill([
                'content' => null,
                'content_bytes' => 0,
                'content_hash' => null,
                'was_truncated' => false,
                'status' => PropertyDocument::STATUS_PENDING,
                'failure' => null,
                'fetched_at' => null,
            ]);
        }

        $document->save();

        if ($changed) {
            FetchKnowledgeDocument::dispatch($document->getKey(), (string) $property->organization_id);
        }
    }

    /**
     * A link a browser may be pointed at, or null.
     *
     * An allow-list of two schemes rather than a block-list of the dangerous
     * ones: `javascript:` and `data:` are the famous two, and a block-list has
     * been wrong about the rest every time it has been tried.
     */
    private function browsableLink(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new BotEndpointRefusedException(
                'That link has to start with https:// (or http://). It is opened in somebody\'s '
                .'browser, and any other scheme there is a way to run code in their session.',
            );
        }

        return $url;
    }
}
