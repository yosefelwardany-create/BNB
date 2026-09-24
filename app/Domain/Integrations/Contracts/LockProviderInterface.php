<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DataObjects\AccessCodeRequest;
use App\Domain\Integrations\DataObjects\AccessCodeResult;
use App\Domain\Integrations\DataObjects\LockStatus;
use App\Domain\Locks\Models\SmartLock;

/**
 * Smart lock control.
 *
 * The platform owns the schedule — which guest may enter which unit between
 * which instants — and the provider only programmes the hardware. That split
 * means a lock outage never loses the access schedule, and switching vendors
 * does not require rebuilding it.
 */
interface LockProviderInterface
{
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether the implementation drives real hardware.
     */
    public function isLive(): bool;

    /**
     * Why this implementation is not live, phrased for a person.
     *
     * Null when {@see isLive()} is true. Declared alongside it so that every
     * integration point in the platform can explain itself and not merely admit
     * to being a simulation — "no API credentials are configured" is actionable
     * and "not live" is not.
     */
    public function simulationReason(): ?string;

    /**
     * @return list<string> e.g. ['codes', 'remote_unlock', 'battery', 'schedule']
     */
    public function capabilities(): array;

    /**
     * Discover the locks available on the account so they can be mapped to
     * properties and units.
     *
     * @return list<LockStatus>
     */
    public function listLocks(string $connectionId): array;

    public function status(SmartLock $lock): LockStatus;

    /**
     * Programme a time-bounded access code.
     */
    public function issueAccessCode(SmartLock $lock, AccessCodeRequest $request): AccessCodeResult;

    /**
     * Remove a previously issued code before its natural expiry.
     */
    public function revokeAccessCode(SmartLock $lock, string $externalCodeId): AccessCodeResult;

    public function unlock(SmartLock $lock): bool;

    public function lock(SmartLock $lock): bool;
}
