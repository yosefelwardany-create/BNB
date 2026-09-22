<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Policies;

use App\Domain\Pricing\Models\Quote;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Quoting a price.
 *
 * Deliberately wider than changing one. Anybody who can take a booking has to
 * be able to tell a guest what it costs, and refusing to quote would mean an
 * agent on the phone guessing. Nothing here changes what anything costs; the
 * rules behind the number are governed by {@see PricingPolicy}.
 */
class QuotePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, [
            'pricing.view', 'reservations.view', 'reservations.create',
        ]);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsAny($user, ['reservations.create', 'pricing.view']);
    }

    /**
     * A quote is a record of what somebody was told. It is superseded by a
     * newer quote, never edited or removed.
     */
    public function update(User $user, Quote $quote): bool
    {
        return false;
    }

    public function delete(User $user, Quote $quote): bool
    {
        return false;
    }
}
