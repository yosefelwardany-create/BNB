<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A smart lock's reported state.
 */
final class LockStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalLockId,
        public readonly string $name,
        public readonly bool $online,
        public readonly ?bool $locked = null,
        public readonly ?int $batteryPercent = null,
        public readonly ?string $model = null,
        public readonly ?\DateTimeImmutable $lastSeenAt = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Below this the lock should be visited before the next arrival.
     */
    public function batteryLow(): bool
    {
        return $this->batteryPercent !== null && $this->batteryPercent <= 20;
    }
}
