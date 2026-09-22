<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Api\Models\ApiKey;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Credentials for the public API.
 *
 * Two rules shape this controller, and both exist because a key outlives the
 * person who made it.
 *
 * **A key is shown once.** Only a hash is stored, so there is nothing to
 * return afterwards even if somebody wanted it. An integrator who loses a key
 * issues a new one — a system that can show you your key can show it to
 * somebody else.
 *
 * **A key cannot exceed its creator.** Abilities are intersected with what the
 * person creating it actually holds, so an operations manager cannot mint a
 * key that moves money. Without that, an API key is a privilege escalation
 * with an audit trail that says somebody else did it.
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly AccessControl $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApiKey::class);

        $query = ApiKey::query();

        if ($request->boolean('usable_only')) {
            $query->usable();
        }

        return ApiKeyResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    /**
     * Mint a key.
     *
     * The response carries the token. This is the only time it exists in a
     * readable form anywhere.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ApiKey::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:64'],
            'allowed_ips' => ['sometimes', 'nullable', 'array'],
            'allowed_ips.*' => ['ip'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:6000'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $abilities = $this->permittedAbilities($data['abilities']);

        abort_if(
            $abilities === [],
            422,
            'None of the requested abilities are ones you hold. A key can never be more powerful than the person who created it.',
        );

        ['key' => $key, 'token' => $token] = ApiKey::issue([
            'organization_id' => $this->organization()->getKey(),
            'name' => $data['name'],
            'abilities' => $abilities,
            'allowed_ips' => $data['allowed_ips'] ?? null,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 120,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by_id' => auth()->id(),
        ]);

        return (new ApiKeyResource($key))
            ->additional([
                'meta' => [
                    'token' => $token,
                    'token_notice' => 'Store this now. Only a hash is kept, so it cannot be shown again or recovered.',
                    // Said plainly when it happens, rather than left for
                    // somebody to discover when a call 403s in production.
                    'abilities_granted' => $abilities,
                    'abilities_refused' => array_values(array_diff($data['abilities'], $abilities)),
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(ApiKey $apiKey): ApiKeyResource
    {
        $this->authorize('view', $apiKey);

        return new ApiKeyResource($apiKey);
    }

    /**
     * Change what a key may do, or where it may be used from.
     *
     * The token itself is untouched: rotating a credential and adjusting its
     * scope are different decisions, and conflating them would force every
     * integrator to redeploy whenever somebody narrowed a permission.
     */
    public function update(Request $request, ApiKey $apiKey): ApiKeyResource
    {
        $this->authorize('update', $apiKey);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:64'],
            'allowed_ips' => ['sometimes', 'nullable', 'array'],
            'allowed_ips.*' => ['ip'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:6000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (isset($data['abilities'])) {
            $data['abilities'] = $this->permittedAbilities($data['abilities']);

            abort_if($data['abilities'] === [], 422, 'None of the requested abilities are ones you hold.');
        }

        $apiKey->fill($data)->save();

        return new ApiKeyResource($apiKey->fresh());
    }

    /**
     * Revoke a key.
     *
     * The row survives revocation: when a key is revoked after an incident,
     * what it did before that is the whole of the investigation, and the
     * request count and last-used address are part of it.
     */
    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->authorize('delete', $apiKey);

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $apiKey->revoke($data['reason'] ?? null);

        return response()->json([
            'message' => 'The key has been revoked and stopped working immediately. Its usage history is unchanged.',
            'data' => new ApiKeyResource($apiKey->fresh()),
        ]);
    }

    /**
     * Which of the requested abilities the creator actually holds.
     *
     * `*` is deliberately not expandable by anybody but a platform
     * administrator: a wildcard key would keep working after its creator's
     * permissions were narrowed, which is precisely the escalation this
     * intersection exists to prevent.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function permittedAbilities(array $requested): array
    {
        $user = $this->currentUser();

        if (in_array('*', $requested, true)) {
            return $user->isPlatformAdmin() ? ['*'] : [];
        }

        return array_values(array_filter(
            $requested,
            fn (string $ability): bool => PermissionRegistry::exists($ability)
                && $this->access->allows($user, $ability),
        ));
    }
}
