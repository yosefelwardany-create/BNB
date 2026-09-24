<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Services\Impersonation;
use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Makes a support session read-only, and counts what it read.
 *
 * Applied to the whole tenant API, not to the platform console: an
 * impersonation token *is* a tenant token — that is the point of it, since it
 * makes the operator see exactly what the customer sees, with every scope,
 * policy and property restriction applying unchanged.
 *
 * What it must not do is write. A platform operator writing into a customer's
 * data under the customer's name produces records nobody can later attribute,
 * and leaves the platform unable to prove what it did or did not touch. So
 * every unsafe method is refused here, in one place, rather than in each of the
 * two hundred controllers that would otherwise have to remember.
 *
 * Refused with 403 and a message that names the reason. An operator who meant
 * to act should act as themselves through the platform console, where the
 * action signs its own name.
 */
class RestrictImpersonatedSession
{
    /**
     * Methods that do not change anything. HEAD and OPTIONS included because
     * a client may send either and neither writes.
     */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! Impersonation::isImpersonationToken($token)) {
            return $next($request);
        }

        if (! in_array($request->method(), self::SAFE, true)) {
            throw new AccessDeniedHttpException(
                'This is a read-only platform support session. '
                .'Changes must be made by the account holder, or through the platform console '
                .'where the action is recorded against the operator who made it.',
            );
        }

        $session = $this->impersonation->sessionForToken($token->getKey());

        if ($session === null) {
            // A token carrying the ability with no open session behind it is
            // either a session somebody ended or a token that should not exist.
            // Either way it stops working here.
            throw new AccessDeniedHttpException('This support session has ended.');
        }

        $request->attributes->set('impersonation_session', $session);

        $response = $next($request);

        // Counted after the fact, so a refused request is not recorded as
        // something the operator read.
        if ($response->getStatusCode() < 400) {
            $this->impersonation->noteRequest($session);
        }

        return $response;
    }

    /**
     * Whether the current request is running under a support session.
     *
     * Used by anything that needs to present itself differently — the `me`
     * endpoint tells the interface so it can show a banner rather than leaving
     * an operator to forget whose account they are looking at.
     */
    public static function sessionFor(Request $request): ?ImpersonationSession
    {
        $session = $request->attributes->get('impersonation_session');

        return $session instanceof ImpersonationSession ? $session : null;
    }
}
