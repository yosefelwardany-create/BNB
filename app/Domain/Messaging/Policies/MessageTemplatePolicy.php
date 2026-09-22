<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Policies;

use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Message templates.
 *
 * Anyone who can send a message may read the templates — they are the thing an
 * agent reaches for mid-conversation. Editing them is restricted, because a
 * template is sent automatically to guests nobody is looking at: a careless
 * change reaches everyone booked for the next six months.
 */
class MessageTemplatePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['templates.manage', 'messages.send', 'messages.view']);
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'templates.manage');
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return $this->access->allows($user, 'templates.manage');
    }

    public function delete(User $user, MessageTemplate $template): bool
    {
        return $this->access->allows($user, 'templates.manage');
    }
}
