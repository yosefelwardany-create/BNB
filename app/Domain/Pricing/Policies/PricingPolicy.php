<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Policies;

use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Shared gate for everything that decides what a night costs.
 *
 * Rate plans, pricing rules, promotions, fees and taxes are five tables and
 * one decision: may this person change what guests are charged. Splitting them
 * into five permissions would produce roles that can set a 40% seasonal uplift
 * but not a 5% cleaning fee, which is a distinction no operator has ever
 * wanted to make.
 *
 * Reading is deliberately wider than writing. A reservations agent has to be
 * able to explain a price to the guest on the phone, which means seeing the
 * rules behind it.
 */
abstract class PricingPolicy
{
    public function __construct(protected readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['pricing.view', 'revenue.view', 'rate_plans.manage']);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsAny($user, ['pricing.update', 'rate_plans.manage']);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $this->create($user);
    }

    /**
     * Deactivated rather than removed, throughout.
     *
     * A pricing rule that priced last summer's bookings is the explanation for
     * what those guests were charged. Deleting it does not un-charge them; it
     * only makes the charge unexplainable.
     */
    public function delete(User $user, mixed $model = null): bool
    {
        return $this->create($user);
    }
}
