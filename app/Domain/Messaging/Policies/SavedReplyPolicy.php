<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Policies;

use App\Domain\Messaging\Models\SavedReply;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Canned replies an agent inserts by hand.
 *
 * Deliberately looser than templates. A saved reply is never sent
 * automatically — somebody chooses it, reads it and presses send — so the
 * people who use them may also write them.
 */
class SavedReplyPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['messages.view', 'messages.send']);
    }

    public function view(User $user, SavedReply $reply): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'messages.send');
    }

    public function update(User $user, SavedReply $reply): bool
    {
        return $this->access->allowsAny($user, ['templates.manage', 'messages.send']);
    }

    public function delete(User $user, SavedReply $reply): bool
    {
        return $this->access->allowsAny($user, ['templates.manage', 'messages.send']);
    }
}
