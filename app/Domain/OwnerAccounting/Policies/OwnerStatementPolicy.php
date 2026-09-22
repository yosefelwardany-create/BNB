<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Policies;

use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Owner statements.
 *
 * Generate, approve and send are three permissions rather than one because
 * they are three commitments of increasing finality: a draft can be rebuilt
 * freely, approval consumes the underlying revenue and expenses so they can
 * never be billed twice, and sending puts a figure in front of the owner that
 * the business is then answerable for.
 *
 * An owner with portal access reads their own statements here too, which is
 * why `view` asks whether the statement is theirs before asking what the role
 * allows: an owner is a user of this system with exactly one legitimate
 * subject.
 */
class OwnerStatementPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['owner_statements.view', 'financials.view'])
            || $user->ownerId() !== null;
    }

    public function view(User $user, OwnerStatement $statement): bool
    {
        $ownerId = $user->ownerId();

        // Being an owner *narrows* what this login may read; it never widens
        // it. The owner role carries `owner_statements.view` — it has to, or
        // the portal could not show anything — so falling through to the
        // permission check would hand every owner their neighbours' revenue.
        // This branch therefore returns in both directions rather than only
        // on a match.
        if ($ownerId !== null) {
            return $ownerId === $statement->owner_id
                // And only once it has been sent: a draft is the manager's
                // working figure, not a statement of account.
                && in_array($statement->status, [
                    OwnerStatement::STATUS_SENT,
                    OwnerStatement::STATUS_PAID,
                ], true);
        }

        return $this->access->allowsAny($user, ['owner_statements.view', 'financials.view']);
    }

    /**
     * Building or rebuilding a draft.
     */
    public function create(User $user): bool
    {
        return $this->access->allows($user, 'owner_statements.generate');
    }

    public function update(User $user, OwnerStatement $statement): bool
    {
        return $statement->isEditable() && $this->create($user);
    }

    /**
     * Freezing the figures and consuming what they were built from.
     */
    public function approve(User $user, OwnerStatement $statement): bool
    {
        return $statement->status === OwnerStatement::STATUS_DRAFT
            && $this->access->allows($user, 'owner_statements.approve');
    }

    /**
     * Withdrawing a statement that should not have been issued.
     *
     * Kept apart from `approve`, which only ever applies to a draft. Voiding
     * is by definition something done to a statement that has already left the
     * draft stage, so sharing the ability would make it permanently
     * unreachable.
     */
    public function void(User $user, OwnerStatement $statement): bool
    {
        return $statement->status !== OwnerStatement::STATUS_PAID
            && $this->access->allows($user, 'owner_statements.approve');
    }

    public function send(User $user, OwnerStatement $statement): bool
    {
        return $statement->status !== OwnerStatement::STATUS_DRAFT
            && $this->access->allows($user, 'owner_statements.send');
    }

    /**
     * Statements are voided, never deleted: an owner who has been sent one
     * keeps their copy, and the system must be able to say what it said.
     */
    public function delete(User $user, OwnerStatement $statement): bool
    {
        return false;
    }
}
