<?php

declare(strict_types=1);

namespace App\Domain\Agents\DataObjects;

/**
 * One answer an agent produced, and everything needed to judge it.
 *
 * The reply text is the least interesting field. What makes this reviewable —
 * and what makes the eval suite possible — is the rest: what the agent thought
 * it was being asked, how sure it was, what it was not allowed to know, and
 * whether it would have sent this on its own.
 *
 * `wouldAutoSend` is computed here and is deliberately separate from whether
 * anything *was* sent. A bench run and a live reply produce the same object;
 * only the caller differs. That is what lets the same scenarios be scored
 * without sending anything to anybody.
 */
final class AgentAnswer
{
    /**
     * @param  list<string>  $withheld  Facts the guest was not entitled to.
     * @param  list<string>  $usedFacts  Keys of the knowledge given to the model.
     */
    public function __construct(
        public readonly string $reply,
        public readonly string $intent,
        public readonly float $confidence,
        public readonly bool $wouldAutoSend,
        public readonly ?string $heldBecause,
        public readonly array $withheld = [],
        public readonly array $usedFacts = [],
        public readonly bool $isSimulated = true,
        public readonly ?string $simulationReason = null,
        public readonly string $provider = 'unknown',
        public readonly ?string $model = null,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'intent' => $this->intent,
            'confidence' => round($this->confidence, 2),
            'would_auto_send' => $this->wouldAutoSend,
            'held_because' => $this->heldBecause,
            'withheld' => $this->withheld,
            'used_facts' => $this->usedFacts,
            // The same honesty flags as every other integration point: a draft
            // produced by the echo provider must never look like one a model
            // wrote.
            'is_simulated' => $this->isSimulated,
            'simulation_reason' => $this->simulationReason,
            'provider' => $this->provider,
            'model' => $this->model,
            'tokens' => $this->promptTokens + $this->completionTokens,
        ];
    }
}
