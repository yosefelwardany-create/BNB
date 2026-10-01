<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;

/**
 * The four gates between an answer and a guest, and nothing else.
 *
 * Extracted because there is now more than one way an answer arrives. A bot that
 * replies inside the request and an agent that calls back ten minutes later
 * produce the same thing — some text, a claimed intent, a claimed confidence —
 * and the rules about whether a person reads it first must not depend on which
 * road it came down. Two copies of these rules would drift, and the drift would
 * be silent and in the direction of sending more.
 *
 * Each gate catches something the others cannot:
 *
 *  1. **Entitlement** is not here, because it does not happen here. It happens
 *     in {@see PropertyKnowledge}, before the question is read, by deciding what
 *     goes into the prompt at all. What reaches this class is the *list of what
 *     was withheld*, which is used by the last check below.
 *  2. **The property's own escalation list** — a keyword match on the guest's
 *     words, in code rather than asked of the model. "Send anything about the
 *     neighbours to a person" has to hold even when the model disagrees.
 *  3. **Intent** — only four categories are ever auto-sendable, and that list is
 *     a constant, not a setting. Nothing a customer types into their own
 *     configuration can make a refund question answer itself.
 *  4. **Confidence** — an agent that is unsure and sends anyway is worse than one
 *     that waits, because the guest acts on the answer either way.
 *
 * Failing a gate holds the draft with the reason recorded. It never discards it:
 * that is the difference between a system somebody can improve and one they
 * learn to distrust.
 */
class AgentGates
{
    /**
     * What older providers call these categories.
     *
     * The keyword classifier in `EchoAIProvider` predates the agent and has its
     * own vocabulary. Mapping it keeps the simulated path exercising the real
     * gates instead of collapsing every question to `other` and proving nothing.
     *
     * Everything here maps to a category that is *not* auto-sendable, which is
     * the safe direction for a guess.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'billing' => AgentBrief::INTENT_PAYMENT,
        'maintenance' => AgentBrief::INTENT_COMPLAINT,
        'cleaning' => AgentBrief::INTENT_COMPLAINT,
        'upsell' => AgentBrief::INTENT_BOOKING_CHANGE,
        'review' => AgentBrief::INTENT_OTHER,
        'general' => AgentBrief::INTENT_OTHER,
    ];

    /**
     * Why this draft is not being sent on its own, or null if it may be.
     *
     * @param  list<string>  $withheld  Facts the guest was not entitled to.
     */
    public function reasonToHold(
        AgentBrief $brief,
        string $intent,
        string $question,
        float $confidence,
        array $withheld,
    ): ?string {
        if (! $brief->enabled) {
            return 'This property\'s agent is not turned on.';
        }

        $escalated = $brief->escalatedBy($question);

        if ($escalated !== null) {
            return sprintf('This property escalates anything mentioning "%s".', $escalated);
        }

        if (! in_array($intent, AgentBrief::AUTO_SENDABLE, true)) {
            return sprintf('A question about %s is always read by a person first.', str_replace('_', ' ', $intent));
        }

        if (! $brief->mayAutoSend($intent)) {
            return sprintf('This property has not enabled sending %s answers on their own.', str_replace('_', ' ', $intent));
        }

        if ($confidence < $brief->confidenceFloor) {
            return sprintf(
                'The agent was %d%% sure, below this property\'s floor of %d%%.',
                (int) round($confidence * 100),
                (int) round($brief->confidenceFloor * 100),
            );
        }

        // If the agent had to refuse a fact, a person should see how it phrased
        // that. A clumsy refusal about a door code is the message most likely to
        // produce a second, angrier question.
        if ($withheld !== []) {
            return 'The agent could not share some details, so the wording is worth a look.';
        }

        return null;
    }

    /**
     * Whatever the provider called the intent, mapped onto the fixed set.
     *
     * Unknown becomes `other`, which is never auto-sendable — so a provider
     * inventing a category cannot accidentally open a gate.
     */
    public function normaliseIntent(string $intent): string
    {
        $candidate = str_replace([' ', '-'], '_', mb_strtolower(trim($intent)));

        if (in_array($candidate, AgentBrief::intents(), true)) {
            return $candidate;
        }

        return self::ALIASES[$candidate] ?? AgentBrief::INTENT_OTHER;
    }
}
