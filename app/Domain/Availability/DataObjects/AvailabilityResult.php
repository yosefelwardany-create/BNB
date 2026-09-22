<?php

declare(strict_types=1);

namespace App\Domain\Availability\DataObjects;

/**
 * The answer, with the reasoning attached.
 *
 * A refusal always carries the reasons and, where relevant, the specific dates
 * at fault — a booking form that can only say "unavailable" forces the guest
 * to guess.
 */
final class AvailabilityResult
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $candidateUnitIds
     * @param  list<string>  $blockedDates
     */
    private function __construct(
        public readonly bool $isAvailable,
        public readonly int $availableUnits = 0,
        public readonly array $candidateUnitIds = [],
        public readonly array $reasons = [],
        public readonly array $blockedDates = [],
    ) {}

    /**
     * @param  list<string>  $candidateUnitIds
     */
    public static function available(int $units = 1, array $candidateUnitIds = []): self
    {
        return new self(true, $units, $candidateUnitIds);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $blockedDates
     */
    public static function unavailable(array $reasons, array $blockedDates = []): self
    {
        return new self(false, 0, [], $reasons, $blockedDates);
    }

    /**
     * The unit to allocate, when the engine had a choice. Picks the first
     * candidate; callers wanting a smarter allocation (keeping a block of
     * identical units contiguous, say) use candidateUnitIds directly.
     */
    public function preferredUnitId(): ?string
    {
        return $this->candidateUnitIds[0] ?? null;
    }

    public function reasonSummary(): string
    {
        return implode(' ', $this->reasons);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->isAvailable,
            'available_units' => $this->availableUnits,
            'candidate_unit_ids' => $this->candidateUnitIds,
            'reasons' => $this->reasons,
            'blocked_dates' => $this->blockedDates,
        ];
    }
}
