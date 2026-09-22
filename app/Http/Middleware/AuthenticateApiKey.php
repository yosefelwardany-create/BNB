<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\Models\ApiKey;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a machine caller and binds its tenant.
 *
 * Distinct from the user-session path on purpose. A key is not a person: it
 * has no membership, no roles and no ability to switch organizations. The
 * tenant comes from the key itself, so a machine caller cannot reach another
 * organization's data by sending a header — which is exactly the mistake the
 * `X-Organization` header would otherwise invite.
 *
 * Four checks, in increasing cost, so a wrong or revoked key is cheap to
 * reject: the token is present and looks like ours, it resolves to a usable
 * key, the caller's address is permitted, and the key is inside its rate
 * limit.
 *
 * Every failure returns the same shape and status. Distinguishing "no such
 * key" from "revoked" from "expired" would let anybody probe which keys once
 * existed.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFrom($request);

        if ($token === null) {
            return $this->unauthenticated();
        }

        $key = ApiKey::findByToken($token);

        if ($key === null) {
            return $this->unauthenticated();
        }

        if (! $key->allowsAddress($request->ip())) {
            return $this->unauthenticated();
        }

        if ($this->isRateLimited($key)) {
            return response()->json([
                'message' => 'Too many requests for this API key.',
            ], 429);
        }

        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->find($key->organization_id);

        if ($organization === null || ! $organization->isOperational()) {
            return $this->unauthenticated();
        }

        $this->tenancy->set($organization);

        // Carried on the request so controllers and the ability gate can read
        // what this particular key is allowed to do, without a second lookup.
        $request->attributes->set('api_key', $key);
        $request->attributes->set('organization', $organization);

        $key->markUsed($request->ip());

        return $next($request);
    }

    /**
     * The token, from an Authorization header or an X-Api-Key header.
     *
     * Never from the query string: URLs end up in access logs, browser
     * history, referrer headers and bug reports, and a credential that leaks
     * into all four is not a credential.
     */
    private function tokenFrom(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);

            return $token !== '' ? $token : null;
        }

        $alternate = $request->header('X-Api-Key');

        return is_string($alternate) && $alternate !== '' ? $alternate : null;
    }

    /**
     * Per key rather than per address.
     *
     * An integrator behind one NAT is one caller, and several integrators
     * behind the same cloud provider's addresses are not. Limiting by address
     * would throttle the wrong thing in both directions.
     */
    private function isRateLimited(ApiKey $key): bool
    {
        $limit = max(1, (int) $key->rate_limit_per_minute);
        $bucket = 'api-key:'.$key->getKey();

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            return true;
        }

        RateLimiter::hit($bucket, 60);

        return false;
    }

    private function unauthenticated(): Response
    {
        // One message for every failure mode. Telling a caller that a key
        // exists but is revoked is telling them a key exists.
        return response()->json([
            'message' => 'The API key is missing, invalid or no longer usable.',
        ], 401);
    }
}
