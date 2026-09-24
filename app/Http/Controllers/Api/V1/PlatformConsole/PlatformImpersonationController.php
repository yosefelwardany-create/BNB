<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Services\Impersonation;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\ImpersonationSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only support sessions.
 *
 * The token this returns acts as a real member of the customer's organization,
 * so every tenant scope, policy and property restriction applies unchanged —
 * which is what makes it a faithful view of what the customer sees rather than a
 * privileged one. What it cannot do is write: the middleware refuses every
 * unsafe method for the life of the session.
 */
class PlatformImpersonationController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * Every session, so the log can be read by the platform and shown to a
     * customer who asks.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ImpersonationSession::query()
            ->with(['organization', 'platformUser', 'targetUser']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->string('organization_id')->toString());
        }

        if ($request->boolean('open_only')) {
            $query->open();
        }

        return ImpersonationSessionResource::collection(
            $query->orderByDesc('started_at')->paginate($this->perPage()),
        );
    }

    /**
     * Begin a session as a member of an organization.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            // Required, and recorded in the customer's own audit trail. An
            // impersonation with no stated reason is the one nobody can defend.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'user_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'minutes' => ['sometimes', 'integer', 'min:1'],
        ]);

        $cap = (int) app(PlatformSettings::class)->get('impersonation_max_minutes', 30);
        $minutes = min((int) ($data['minutes'] ?? $cap), $cap);

        $result = $this->impersonation->start(
            operator: $this->currentUser(),
            organization: $organization,
            reason: $data['reason'],
            target: isset($data['user_id']) ? User::query()->find($data['user_id']) : null,
            request: $request,
            minutes: $minutes,
        );

        return response()->json([
            'message' => 'A read-only support session has started. The customer has a record of it.',
            'data' => [
                // The only time this token exists in a readable form.
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
                'session' => (new ImpersonationSessionResource(
                    $result['session']->load(['organization', 'platformUser', 'targetUser']),
                ))->resolve(),
            ],
            'meta' => [
                'read_only' => true,
                'notice' => 'Every write is refused with this token. Use the platform console for '
                    .'actions that need to change something, so they are recorded against you.',
            ],
        ], 201);
    }

    /**
     * End a session before it expires.
     */
    public function destroy(ImpersonationSession $session): JsonResponse
    {
        $ended = $this->impersonation->end($session, ImpersonationSession::ENDED_REVOKED);

        return response()->json([
            'message' => 'The session has ended and its token has been revoked.',
            'data' => (new ImpersonationSessionResource(
                $ended->load(['organization', 'platformUser', 'targetUser']),
            ))->resolve(),
        ]);
    }
}
