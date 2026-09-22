<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Policies;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Guest and owner threads.
 *
 * Reading and writing are separate permissions. A reservations agent answers
 * guests; an analyst reading response times should be able to open a thread
 * without being able to send anything from the company's name.
 */
class ConversationPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'messages.view');
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->access->allows($user, 'messages.view')
            && $this->withinScope($user, $conversation);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'messages.send');
    }

    /**
     * Sending is what reaches a guest, so it is the permission that matters.
     */
    public function send(User $user, Conversation $conversation): bool
    {
        return $this->access->allows($user, 'messages.send')
            && $this->withinScope($user, $conversation);
    }

    /**
     * An internal note never leaves the building, so anyone who can read the
     * thread may annotate it. Making colleagues unable to add context to a
     * conversation they can see helps nobody.
     */
    public function note(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $this->access->allows($user, 'messages.assign')
            && $this->withinScope($user, $conversation);
    }

    /**
     * Filing a thread away is ordinary inbox work, not an administrative act:
     * nothing is destroyed and a guest writing again reopens it.
     */
    public function manage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * A thread about a property outside a restricted member's estate is not
     * theirs to read. Threads with no property — a general enquiry — are open
     * to anyone who can read the inbox.
     */
    private function withinScope(User $user, Conversation $conversation): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        if ($allowed === null || $conversation->property_id === null) {
            return true;
        }

        return in_array($conversation->property_id, $allowed, true);
    }
}
