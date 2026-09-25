<?php

declare(strict_types=1);

namespace App\Domain\Agents\DataObjects;

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
        ];
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
