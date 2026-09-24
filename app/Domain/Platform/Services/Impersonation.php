<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Letting the platform operator see what a customer sees.
 *
 * The single most dangerous capability in an admin console, so it is built with
 * four constraints that are not negotiable and are enforced here rather than
 * left to whoever writes the interface:
 *
 *  1. **Read-only.** The token issued carries one ability and the middleware
 *     refuses every write with it. A platform operator writing into a
 *     customer's data under a customer's name produces records that are
 *     indistinguishable from the customer's own — which destroys the value of
 *     the audit trail for both of them and leaves the platform unable to prove
 *     what it did or did not do. Support needs to *see* the problem; the fix
 *     goes through the customer or through an explicit platform action that
 *     signs its own name.
 *
 *  2. **A reason is required.** Not validated for content, but recorded, and an
 *     impersonation with no stated reason is the one nobody can defend later.
 *
 *  3. **Short-lived.** Thirty minutes by default. A support session that
 *     outlives the ticket is a standing key to somebody's business.
 *
 *  4. **Visible.** Every session is a row the customer is entitled to be shown,
 *     with who, when, why and how much was read.
 */
class Impersonation
{
    /**
     * The ability the issued token carries. Checked by the middleware that
     * refuses writes, and by anything else that needs to know it is looking at
     * a borrowed session rather than a real one.
     */
    public const ABILITY = 'platform:impersonate-read';

    public const DEFAULT_MINUTES = 30;

    /**
     * Begin a session and return the token that acts as the target user.
     *
     * @return array{session: ImpersonationSession, token: string, expires_at: string}
     */
    public function start(
        User $operator,
        Organization $organization,
        string $reason,
        ?User $target = null,
        ?Request $request = null,
        int $minutes = self::DEFAULT_MINUTES,
    ): array {
        if (! $operator->isPlatformAdmin()) {
            throw new HttpException(403, 'Only a platform administrator may do this.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'A reason is required to view a customer account.');
        }

        $target ??= $this->defaultTargetFor($organization);

        if ($target === null) {
            throw new HttpException(
                422,
                'This organization has no active member to view it as.',
            );
        }

        // A platform admin must not impersonate another platform admin: that
        // would launder one operator's actions through another's identity and
        // defeat the point of recording who did what.
        if ($target->isPlatformAdmin()) {
            throw new HttpException(
                422,
                'A platform administrator cannot be impersonated.',
            );
        }

        $this->assertMember($target, $organization);

        return DB::transaction(function () use (
            $operator, $organization, $target, $reason, $request, $minutes
        ): array {
            $expiresAt = now()->addMinutes(max(1, min($minutes, 240)));

            // The token belongs to the *target* user, so the application sees a
            // normal member of that organization and every tenant scope, policy
            // and property restriction applies exactly as it does for them.
            // Nothing is bypassed; that is what makes this a faithful view.
            $token = $target->createToken(
                sprintf('Platform support session (%s)', $operator->email),
                [self::ABILITY],
                $expiresAt,
            );

            $session = ImpersonationSession::query()->create([
                'platform_user_id' => $operator->getKey(),
                'organization_id' => $organization->getKey(),
                'target_user_id' => $target->getKey(),
                'reason' => $reason,
                'access_token_id' => $token->accessToken->getKey(),
                'started_at' => now(),
                'expires_at' => $expiresAt,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 512),
            ]);

            // Written into the *customer's* audit trail, not only the
            // platform's. They are the ones entitled to see it.
            app(AuditLogger::class)->record(
                action: 'platform.impersonation_started',
                subject: $session,
                newValues: [
                    'platform_user' => $operator->email,
                    'viewed_as' => $target->email,
                    'reason' => $reason,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                description: sprintf(
                    '%s began a read-only support session as %s: %s',
                    $operator->email,
                    $target->email,
                    $reason,
                ),
                organizationId: $organization->getKey(),
            );

            Log::warning('[platform] impersonation started', [
                'organization_id' => $organization->getKey(),
                'operator' => $operator->email,
                'viewed_as' => $target->email,
                'reason' => $reason,
            ]);

            return [
                'session' => $session,
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * End a session and revoke exactly its token.
     *
     * Exactly its token, by id: deleting the target user's tokens would sign
     * the customer out of their own account because somebody closed a support
     * tab.
     */
    public function end(
        ImpersonationSession $session,
        string $endedReason = ImpersonationSession::ENDED_COMPLETED,
    ): ImpersonationSession {
        if ($session->ended_at !== null) {
            return $session;
        }

        if ($session->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($session->access_token_id)->delete();
        }

        $session->forceFill([
            'ended_at' => now(),
            'ended_reason' => $endedReason,
        ])->save();

        app(AuditLogger::class)->record(
            action: 'platform.impersonation_ended',
            subject: $session,
            description: sprintf(
                'Support session ended after %d request(s): %s',
                (int) $session->request_count,
                $endedReason,
            ),
            organizationId: $session->organization_id,
        );

        return $session->fresh();
    }

    /**
     * Close sessions whose window has passed.
     *
     * Their tokens have already expired — Sanctum refuses an expired token — so
     * this is about the record being accurate rather than about access. A list
     * of sessions that all look open is a list nobody can audit.
     */
    public function closeExpired(): int
    {
        $closed = 0;

        ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('expires_at', '<=', now())
            ->each(function (ImpersonationSession $session) use (&$closed): void {
                $this->end($session, ImpersonationSession::ENDED_EXPIRED);
                $closed++;
            });

        return $closed;
    }

    /**
     * Note that a request was made under a session.
     *
     * Called by the middleware. A count is enough: the audit log already holds
     * what was read, and duplicating every request here would make the
     * impersonation record larger than the thing it documents.
     */
    public function noteRequest(ImpersonationSession $session): void
    {
        ImpersonationSession::query()
            ->whereKey($session->getKey())
            ->increment('request_count');
    }

    /**
     * The session a token belongs to, if it is an impersonation token.
     */
    public function sessionForToken(int|string $tokenId): ?ImpersonationSession
    {
        return ImpersonationSession::query()
            ->where('access_token_id', $tokenId)
            ->whereNull('ended_at')
            ->first();
    }

    /**
     * Whoever the operator should be shown as when they did not choose.
     *
     * The organization's longest-standing active administrator, because a
     * support session needs to see everything the customer can see, and an
     * account with a narrow role would show a misleading half of the problem.
     */
    private function defaultTargetFor(Organization $organization): ?User
    {
        $membership = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->with(['user', 'roles'])
            ->get()
            ->sortByDesc(fn (Membership $m): int => $m->roles
                ->contains(fn ($role): bool => in_array(
                    $role->slug,
                    ['organization-admin', 'super-admin'],
                    true,
                )) ? 1 : 0)
            ->first();

        $user = $membership?->user;

        return $user !== null && ! $user->isPlatformAdmin() ? $user : null;
    }

    private function assertMember(User $user, Organization $organization): void
    {
        $isMember = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw new HttpException(
                422,
                'That person is not an active member of this organization.',
            );
        }
    }
}
