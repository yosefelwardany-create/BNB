<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Reusable checklists.
 *
 * Reading is open to anyone who can see tasks — the checklist is the work.
 * Editing is separate, because a template defines what "clean" means for a
 * property and quietly dropping an item changes what everybody is held to.
 */
class ChecklistTemplatePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['checklists.manage', 'tasks.view', 'tasks.view_own']);
    }

    public function view(User $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'checklists.manage');
    }

    public function update(User $user, ChecklistTemplate $template): bool
    {
        return $this->access->allows($user, 'checklists.manage');
    }

    public function delete(User $user, ChecklistTemplate $template): bool
    {
        return $this->access->allows($user, 'checklists.manage');
    }
}
