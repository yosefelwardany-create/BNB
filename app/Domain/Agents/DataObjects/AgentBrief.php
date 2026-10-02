<?php

declare(strict_types=1);

namespace App\Domain\Agents\DataObjects;

use App\Domain\Agents\Enums\AgentCapability;

/**
 * What one property's agent is allowed to be.
 *
 * Kept on the property rather than the organization because the things that
 * make an answer wrong are local: the lift that is out until March, the
 * neighbour who complains about noise after ten, the check-in that is
 * genuinely self-service at one flat and genuinely is not at another. An
 * organization-wide brief would be right about tone and wrong about facts.
 *
 * Stored in `properties.settings.agent`, so adding it needed no migration.
 *
 * The defaults are the cautious ones. An agent nobody has configured drafts
 * for a person to read and sends nothing, because the alternative — a new
 * property answering guests on day one with whatever it inferred — is the
 * failure this whole design is arranged to prevent.
 */
final class AgentBrief
{
    /**
     * Categories that may be answered without a person reading it first.
     *
     * Deliberately not a boolean. "Can the agent reply on its own" is the
     * wrong question; "can it reply on its own *about the wifi*" is the right
     * one, and the answer differs from "can it reply on its own about a
     * refund".
     */
    public const AUTO_SENDABLE = [
        self::INTENT_AMENITY,
        self::INTENT_DIRECTIONS,
        self::INTENT_HOUSE_RULES,
        self::INTENT_LOCAL_RECOMMENDATION,
    ];

    public const INTENT_AMENITY = 'amenity';

    public const INTENT_DIRECTIONS = 'directions';

    public const INTENT_HOUSE_RULES = 'house_rules';

    public const INTENT_LOCAL_RECOMMENDATION = 'local_recommendation';

    public const INTENT_ACCESS = 'access';

    public const INTENT_BOOKING_CHANGE = 'booking_change';

    public const INTENT_PAYMENT = 'payment';

    public const INTENT_COMPLAINT = 'complaint';

    public const INTENT_OTHER = 'other';

    /**
     * Every intent, whether or not it may ever be auto-sent.
     *
     * @return list<string>
     */
    public static function intents(): array
    {
        return [
            self::INTENT_AMENITY,
            self::INTENT_DIRECTIONS,
            self::INTENT_HOUSE_RULES,
            self::INTENT_LOCAL_RECOMMENDATION,
            self::INTENT_ACCESS,
            self::INTENT_BOOKING_CHANGE,
            self::INTENT_PAYMENT,
            self::INTENT_COMPLAINT,
            self::INTENT_OTHER,
        ];
    }

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $never  Things this agent must not say or offer.
     * @param  list<string>  $escalate  Subjects that always go to a person.
     * @param  list<string>  $autoSend  Intents that may be sent without review.
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $persona = 'Warm, brief and specific. Never effusive.',
        public readonly array $languages = ['en'],
        public readonly array $never = [],
        public readonly array $escalate = [],
        public readonly ?string $extraKnowledge = null,
        public readonly array $autoSend = [],
        /**
         * Below this, a draft is held even if its intent is auto-sendable. An
         * agent that is unsure and sends anyway is worse than one that waits:
         * the guest acts on the answer either way.
         */
        public readonly float $confidenceFloor = 0.75,
        /**
         * Which provider answers for this property, or null for the account's.
         *
         * Per property rather than per account because the operators this is for
         * run a bot named after each flat. One of them may be excellent and
         * another barely configured, and a single setting would force the worst
         * of them on every property or none.
         */
        public readonly ?string $provider = null,
        /** Where this property's own bot lives, when the provider is `bot`. */
        public readonly ?string $botUrl = null,
        /** What its operator calls it — "Yellow", "Den" — shown on the screen. */
        public readonly ?string $botName = null,
        /**
         * Where to fire a question that will be answered later.
         *
         * Separate from `botUrl`, and both can be set. A bot that answers in two
         * seconds and an agent run that takes two minutes are different things
         * reached in different ways: one is called and waited for, the other is
         * poked and calls back. A property can reasonably have both — the quick
         * one drafting guest replies, the slow one answering the operator's
         * harder questions — so this is not a mode switch.
         */
        public readonly ?string $webhookUrl = null,
        /**
         * The agent's face on the property card.
         *
         * An operator who runs a bot per flat thinks of them as people — Alex on
         * the third floor, David in the annexe — and a card showing a picture
         * and a name is how they find the right one at a glance. Optional: with
         * no picture the screen falls back to the initial of the bot's name,
         * which is what the people this is for were already drawing on a
         * whiteboard.
         */
        public readonly ?string $botAvatarUrl = null,
        /**
         * Where this property's knowledge base lives — usually a shared doc.
         *
         * A link rather than a copy. The document is maintained by the people
         * who know the property, and duplicating it here would create a second
         * version that is wrong by the end of the week.
         */
        public readonly ?string $knowledgeBaseUrl = null,
        /**
         * What this agent may be asked to do at all.
         *
         * Empty by default, which means an agent that answers and changes
         * nothing. Granting a capability is a deliberate act per property, and
         * an agent asked for something outside this list is refused rather than
         * given a best effort — however convincingly it was asked.
         *
         * @var list<string>
         */
        public readonly array $mayDo = [],
        /**
         * Which of those it may do before anybody looks.
         *
         * A subset of the above, intersected with what the capability itself
         * permits: {@see AgentCapability::mayEverBeAutonomous()} is a constant,
         * so nothing stored here can make cancelling a booking unattended.
         *
         * @var list<string>
         */
        public readonly array $mayDoAlone = [],
    ) {}

    /**
     * @param  array<string, mixed>  $settings  The property's settings column.
     */
    public static function fromSettings(?array $settings): self
    {
        $agent = $settings['agent'] ?? null;

        if (! is_array($agent)) {
            return new self;
        }

        return new self(
            enabled: (bool) ($agent['enabled'] ?? false),
            persona: self::string($agent, 'persona') ?? (new self)->persona,
            languages: self::strings($agent, 'languages') ?: ['en'],
            never: self::strings($agent, 'never'),
            escalate: self::strings($agent, 'escalate'),
            extraKnowledge: self::string($agent, 'extra_knowledge'),
            // Intersected with the allow-list rather than trusted: a stored
            // setting must not be able to make refunds auto-sendable, however
            // it got there.
            autoSend: array_values(array_intersect(
                self::strings($agent, 'auto_send'),
                self::AUTO_SENDABLE,
            )),
            confidenceFloor: isset($agent['confidence_floor']) && is_numeric($agent['confidence_floor'])
                ? max(0.0, min(1.0, (float) $agent['confidence_floor']))
                : 0.75,
            provider: self::string($agent, 'provider'),
            botUrl: self::string($agent, 'bot_url'),
            botName: self::string($agent, 'bot_name'),
            webhookUrl: self::string($agent, 'webhook_url'),
            botAvatarUrl: self::string($agent, 'bot_avatar_url'),
            knowledgeBaseUrl: self::string($agent, 'knowledge_base_url'),
            // Narrowed on read, not trusted: a stored setting must not be able
            // to grant a capability the code no longer has, however it got there.
            mayDo: array_values(array_intersect(
                self::strings($agent, 'may_do'),
                array_map(static fn (AgentCapability $c): string => $c->value, AgentCapability::all()),
            )),
            mayDoAlone: array_values(array_intersect(
                self::strings($agent, 'may_do_alone'),
                self::strings($agent, 'may_do'),
                array_map(
                    static fn (AgentCapability $c): string => $c->value,
                    array_filter(AgentCapability::all(), static fn (AgentCapability $c): bool => $c->mayEverBeAutonomous()),
                ),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'persona' => $this->persona,
            'languages' => $this->languages,
            'never' => $this->never,
            'escalate' => $this->escalate,
            'extra_knowledge' => $this->extraKnowledge,
            'auto_send' => $this->autoSend,
            'confidence_floor' => $this->confidenceFloor,
            'provider' => $this->provider,
            'bot_url' => $this->botUrl,
            'bot_name' => $this->botName,
            'webhook_url' => $this->webhookUrl,
            'bot_avatar_url' => $this->botAvatarUrl,
            'knowledge_base_url' => $this->knowledgeBaseUrl,
            'may_do' => $this->mayDo,
            'may_do_alone' => $this->mayDoAlone,
        ];
    }

    /**
     * The letter on the card when there is no picture.
     *
     * The bot's own name first, because that is what its operator calls it. Null
     * when nothing has been named — a card showing a stray letter would imply an
     * agent that is not there.
     */
    public function initial(): ?string
    {
        return $this->botName === null ? null : mb_strtoupper(mb_substr($this->botName, 0, 1));
    }

    /**
     * Whether this intent may be sent without a person reading it.
     */
    public function mayAutoSend(string $intent): bool
    {
        return $this->enabled
            && in_array($intent, $this->autoSend, true)
            && in_array($intent, self::AUTO_SENDABLE, true);
    }

    /**
     * Whether the guest's words touch anything this property escalates.
     */
    public function escalatedBy(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        foreach ($this->escalate as $subject) {
            if ($subject !== '' && str_contains($haystack, mb_strtolower($subject))) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private static function strings(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
