<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Policies;

use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Webhooks\Models\WebhookEndpoint;

/**
 * Outbound webhooks.
 *
 * One permission, because every operation here is the same decision: may this
 * person cause the platform's data to be sent to an address of their choosing.
 * Registering an endpoint is a data-export decision dressed up as a
 * configuration one, which is why it is not folded in with general settings.
 */
class WebhookEndpointPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['webhooks.manage', 'integrations.view']);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'webhooks.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->create($user);
    }

    /**
     * Disabled rather than removed: the delivery history is the record of what
     * this platform told an integrator and when.
     */
    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->create($user);
    }
}
