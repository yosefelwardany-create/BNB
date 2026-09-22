<?php

declare(strict_types=1);

namespace App\Domain\Api\Policies;

use App\Domain\Api\Models\ApiKey;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Credentials for the public API.
 *
 * `api_keys.manage` covers everything, including reading the list. There is no
 * wider read permission on purpose: knowing which integrations exist, what
 * they are scoped to and where they are called from is reconnaissance, and it
 * is not something a general "view settings" role needs.
 *
 * What this policy does *not* do is bound a key's power. That is done in the
 * controller, by intersecting the requested abilities with what the creator
 * holds — a check that belongs there because it depends on the abilities being
 * asked for, not on the record being acted on.
 */
class ApiKeyPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'api_keys.manage');
    }

    public function view(User $user, ApiKey $key): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ApiKey $key): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Revoked, never deleted. When a key is revoked after an incident, what it
     * did beforehand is the whole of the investigation.
     */
    public function delete(User $user, ApiKey $key): bool
    {
        return $this->viewAny($user);
    }
}
