<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The in-product notification bell.
 *
 * Every endpoint here is scoped to the authenticated user rather than
 * authorised by permission: a notification is addressed to one person, and no
 * permission makes somebody else's bell yours to read or clear.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Notification::query()->forUser($this->currentUser()->getKey());

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        return NotificationResource::collection(
            $query->orderByDesc('created_at')->paginate($this->perPage(20)),
        );
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'data' => [
                'unread' => Notification::query()
                    ->forUser($this->currentUser()->getKey())
                    ->unread()
                    ->count(),
            ],
        ]);
    }

    public function markRead(Notification $notification): NotificationResource
    {
        abort_unless($notification->user_id === $this->currentUser()->getKey(), 404);

        $notification->markRead();

        return new NotificationResource($notification->fresh());
    }

    public function markAllRead(): JsonResponse
    {
        $updated = Notification::query()
            ->forUser($this->currentUser()->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['marked_read' => $updated]);
    }

    /**
     * How this user wants to hear about each kind of event.
     */
    public function preferences(): JsonResponse
    {
        $preferences = NotificationPreference::query()
            ->where('user_id', $this->currentUser()->getKey())
            ->where('organization_id', $this->organization()->getKey())
            ->get()
            ->map(fn (NotificationPreference $preference): array => [
                'notification_type' => $preference->notification_type,
                'in_app' => (bool) $preference->in_app,
                'email' => (bool) $preference->email,
                'sms' => (bool) $preference->sms,
                'push' => (bool) $preference->push,
            ]);

        return response()->json([
            'data' => $preferences,
            // Types with no row use these, so the client can render the
            // defaults rather than showing everything as switched off.
            'defaults' => ['in_app' => true, 'email' => true, 'sms' => false, 'push' => false],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.notification_type' => ['required', 'string', 'max:64'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
            'preferences.*.sms' => ['sometimes', 'boolean'],
            'preferences.*.push' => ['sometimes', 'boolean'],
        ]);

        foreach ($data['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $this->currentUser()->getKey(),
                    'organization_id' => $this->organization()->getKey(),
                    'notification_type' => $preference['notification_type'],
                ],
                [
                    'in_app' => $preference['in_app'] ?? true,
                    'email' => $preference['email'] ?? true,
                    'sms' => $preference['sms'] ?? false,
                    'push' => $preference['push'] ?? false,
                ],
            );
        }

        return $this->preferences();
    }
}
