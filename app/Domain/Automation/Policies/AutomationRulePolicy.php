<?php

declare(strict_types=1);

namespace App\Domain\Automation\Policies;

use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Automation rules.
 *
 * Viewing and managing are deliberately separate. A rule can message every
 * guest in the portfolio and raise work against every property, so writing one
 * is closer to deploying code than to editing a record — while *reading* the
 * rules, and the log of what they did, is something anyone investigating "why
 * did this guest get that message?" needs.
 */
class AutomationRulePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['automations.view', 'automations.manage']);
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'automations.manage');
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $this->access->allows($user, 'automations.manage');
    }

    /**
     * Rules are deactivated rather than deleted where they have run, because
     * every run references the rule that produced it and the run log is the
     * only answer to what a guest was sent and why.
     */
    public function delete(User $user, AutomationRule $rule): bool
    {
        return $this->access->allows($user, 'automations.manage');
    }
}
