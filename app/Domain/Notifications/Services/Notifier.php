<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Raises in-product notifications, honouring each user's preferences.
 *
 * Preferences default to *on* for in-app and email and *off* for SMS and push.
 * That asymmetry is deliberate: a notification a user has not thought about
 * should appear somewhere they will see it without costing money or waking
 * them up.
 *
 * A notification is always written in-app when the type is enabled, even if
 * other channels fail, so the product's own bell is the record of what the
 * user was told.
 */
class Notifier
{
    /**
     * Notify one user.
     *
     * @param  array<string, mixed>  $data
     */
    public function notify(
        User|Membership|string $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        ?string $url = null,
        string $priority = 'normal',
        ?string $icon = null,
        array $data = [],
        ?string $organizationId = null,
    ): ?Notification {
        [$userId, $organizationId] = $this->resolveRecipient($recipient, $organizationId);

        if ($userId === null || $organizationId === null) {
            return null;
        }

        $preference = $this->preferenceFor($userId, $organizationId, $type);

        if (! $preference['in_app']) {
            return null;
        }

        return Notification::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $icon,
            'priority' => $priority,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ]);
    }

    /**
     * Notify everyone in the organization holding a permission.
     *
     * This is how operational alerts reach the right people without a rule
     * naming individuals: "tell whoever can manage tasks" survives somebody
     * leaving, which a hard-coded user id does not.
     *
     * @param  array<string, mixed>  $data
     * @return list<Notification>
     */
    public function notifyPermissionHolders(
        string $organizationId,
        string $permission,
        string $type,
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        ?string $url = null,
        string $priority = 'normal',
        array $data = [],
    ): array {
        $access = app(\App\Domain\Users\Services\AccessControl::class);

        $notifications = [];

        foreach ($this->activeMemberships($organizationId) as $membership) {
            $user = $membership->user;

            if ($user === null || ! $access->allows($user, $permission, $organizationId)) {
                continue;
            }

            $notification = $this->notify(
                recipient: $user,
                type: $type,
                title: $title,
                body: $body,
                subject: $subject,
                url: $url,
                priority: $priority,
                data: $data,
                organizationId: $organizationId,
            );

            if ($notification !== null) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }

    /**
     * How a user wants to hear about a type of event.
     *
     * @return array{in_app: bool, email: bool, sms: bool, push: bool}
     */
    public function preferenceFor(string $userId, string $organizationId, string $type): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('notification_type', $type)
            ->first();

        if ($preference === null) {
            return ['in_app' => true, 'email' => true, 'sms' => false, 'push' => false];
        }

        return [
            'in_app' => (bool) $preference->in_app,
            'email' => (bool) $preference->email,
            'sms' => (bool) $preference->sms,
            'push' => (bool) $preference->push,
        ];
    }

    /**
     * @return Collection<int, Membership>
     */
    private function activeMemberships(string $organizationId): Collection
    {
        return Membership::query()
            ->withoutGlobalScope('organization')
            ->with('user')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveRecipient(User|Membership|string $recipient, ?string $organizationId): array
    {
        if ($recipient instanceof Membership) {
            return [$recipient->user_id, $recipient->organization_id];
        }

        if ($recipient instanceof User) {
            return [$recipient->getKey(), $organizationId];
        }

        return [$recipient, $organizationId];
    }
}
