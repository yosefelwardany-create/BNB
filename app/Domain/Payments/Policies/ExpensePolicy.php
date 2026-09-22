<?php

declare(strict_types=1);

namespace App\Domain\Payments\Policies;

use App\Domain\Payments\Models\Expense;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Costs incurred against a property.
 *
 * Recording a cost and approving one are separated for the ordinary reason:
 * the person who took the photograph of the receipt should not also be the
 * person who decides the owner pays it. `expenses.manage` covers raising them;
 * approval additionally needs financial authority.
 *
 * An expense already on a finalised owner statement is frozen regardless of
 * permission — it is the evidence behind a figure the owner has been shown,
 * and no role makes rewriting that acceptable.
 */
class ExpensePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['expenses.manage', 'financials.view']);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsAny($user, ['expenses.manage', 'financials.create']);
    }

    public function update(User $user, Expense $expense): bool
    {
        if (! $expense->isEditable()) {
            return false;
        }

        return $this->access->allowsAny($user, ['expenses.manage', 'financials.update']);
    }

    /**
     * Deciding that an owner, or the business, actually bears this.
     */
    public function approve(User $user, Expense $expense): bool
    {
        return $this->access->allowsAny($user, ['financials.update', 'expenses.manage'])
            && $expense->owner_statement_id === null;
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $this->access->allowsAny($user, ['financials.update', 'expenses.manage']);
    }

    /**
     * Withdrawn rather than removed, and only before it has been billed.
     */
    public function delete(User $user, Expense $expense): bool
    {
        return $expense->owner_statement_id === null
            && $this->access->allowsAny($user, ['expenses.manage', 'financials.update']);
    }
}
