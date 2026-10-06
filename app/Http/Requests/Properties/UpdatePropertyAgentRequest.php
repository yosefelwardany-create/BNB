<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Support\BotAvatar;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validating one property's agent brief.
 *
 * `auto_send` is restricted to {@see AgentBrief::AUTO_SENDABLE} here so the
 * caller gets a 422 naming the problem rather than a silent narrowing. The
 * brief narrows it again when it is read back, and that duplication is
 * deliberate: this rule is a courtesy to whoever is using the API, and the one
 * in the brief is the control. A row written before this rule existed, or by a
 * future import, still cannot make refunds answer themselves.
 */
class UpdatePropertyAgentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'automatic_guest_replies' => ['sometimes', 'boolean'],
            'persona' => ['sometimes', 'string', 'max:600'],

            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['string', 'min:2', 'max:12'],

            'never' => ['sometimes', 'array', 'max:20'],
            'never.*' => ['string', 'max:200'],

            'escalate' => ['sometimes', 'array', 'max:40'],
            // Matched as a substring of the guest's message, so a one-character
            // entry would escalate almost everything.
            'escalate.*' => ['string', 'min:3', 'max:80'],

            'extra_knowledge' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'auto_send' => ['sometimes', 'array'],
            'auto_send.*' => ['string', Rule::in(AgentBrief::AUTO_SENDABLE)],

            'confidence_floor' => ['sometimes', 'numeric', 'min:0', 'max:1'],

            // Which provider answers for this property. Validated against what is
            // actually registered rather than a hard-coded list, so removing a
            // provider removes it from here too.
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(
                app(AIProviderRegistry::class)->keys(),
            )],

            /*
             * The property's own bot.
             *
             * The URL is checked for reachability by {@see BotEndpoint} rather
             * than by a rule here, because "is this a URL" and "is this a URL
             * Habitat will send a door code to" are different questions and only
             * the second one matters. The refusal names which address it resolved
             * to and why that is a problem.
             */
            'bot_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bot_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            // Write-only. Never returned; see the agent payload, which reports
            // whether one is set and not what it is.
            'bot_token' => ['sometimes', 'nullable', 'string', 'max:500'],

            /*
             * Where a question that will be answered later is fired.
             *
             * Checked by the same guard as `bot_url`, for the same reason: it is
             * a URL a customer types and this server then requests.
             */
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Also write-only, and separate from the bot's: the two credentials
            // are issued by different systems and rotated on different days.
            'webhook_token' => ['sometimes', 'nullable', 'string', 'max:500'],

            /*
             * How the agent appears on the property card.
             *
             * The scheme of both is checked again in the store, not only here:
             * these are rendered into an `img src` and an `href`, and a rule in
             * a form request is a courtesy to the caller rather than a control.
             */
            'bot_avatar_url' => ['sometimes', 'nullable', 'string', 'max:500'],

            /*
             * One of the faces that ship with the platform.
             *
             * A key from a fixed list, never a path or a URL — which is what
             * keeps it out of the scheme checking `bot_avatar_url` needs. An
             * unknown key is a 422 here and is dropped again when the brief is
             * read, so neither a typo nor an old import can put one on a card.
             */
            'bot_avatar' => ['sometimes', 'nullable', 'string', Rule::in(BotAvatar::keys())],
            'knowledge_base_url' => ['sometimes', 'nullable', 'string', 'max:500'],

            /*
             * What the agent may be asked to do, and what it may do unattended.
             *
             * Validated against the capabilities that exist and, for the second
             * list, against the ones that may ever run unattended — so a caller
             * gets a 422 naming the problem rather than a silent narrowing. The
             * brief narrows it again on read, and that duplication is
             * deliberate: this is a courtesy to the API's user, the one in the
             * brief is the control.
             */
            'may_do' => ['sometimes', 'array'],
            'may_do.*' => ['string', Rule::in(array_map(
                static fn (AgentCapability $c): string => $c->value,
                AgentCapability::all(),
            ))],

            'may_do_alone' => ['sometimes', 'array'],
            'may_do_alone.*' => ['string', Rule::in(array_map(
                static fn (AgentCapability $c): string => $c->value,
                array_filter(AgentCapability::all(), static fn (AgentCapability $c): bool => $c->mayEverBeAutonomous()),
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'auto_send.*.in' => 'Only questions about '
                .implode(', ', array_map(
                    static fn (string $intent): string => str_replace('_', ' ', $intent),
                    AgentBrief::AUTO_SENDABLE,
                ))
                .' can ever be answered without a person reading the reply first.',
            'escalate.*.min' => 'An escalation keyword that short would send almost every message to a person.',
            'may_do_alone.*.in' => 'Cancelling a booking is always confirmed by a person. A guest arranged '
                .'their travel around it, and there is no version of the agent misunderstanding that makes '
                .'doing it unattended acceptable.',
        ];
    }

    /**
     * The brief fields present on this request, ready to merge into settings.
     *
     * Only the top-level keys. `only('escalate.*')` does not mean "the escalate
     * array" — it resolves through `data_get` and yields `['escalate' => ['*' =>
     * [...]]]`, which would be written into the property's settings as a key
     * called `*` and blow up the next read of the brief. Deriving the list from
     * the rules is still right; filtering the item rules out of it is the part
     * that is easy to miss.
     *
     * @return array<string, mixed>
     */
    public function briefChanges(): array
    {
        $fields = array_values(array_filter(
            array_keys($this->rules()),
            static fn (string $key): bool => ! str_contains($key, '.'),
        ));

        return $this->only($fields);
    }
}
