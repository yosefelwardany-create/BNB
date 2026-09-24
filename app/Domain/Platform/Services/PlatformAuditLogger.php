<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\PlatformAuditLog;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Records what the platform operator did.
 *
 * Writes two places on purpose, and the duplication is the point:
 *
 *  - here, so the operator has one trail covering every tenant and the platform
 *    itself, readable without querying across a hundred organizations;
 *  - to the application log, so a platform action survives a database the audit
 *    write could not reach — which is exactly the situation in which somebody
 *    most needs to know what was attempted.
 *
 * An action *on* a tenant is additionally written into that tenant's own trail by
 * {@see AuditLogger}, because the customer is entitled
 * to the record and must not have to ask the platform for it.
 *
 * The actor's email and the organization's name are copied in rather than left to
 * a join. A row saying "user 01H… suspended organization 01J…" answers nothing
 * once either has been removed, and these rows outlive both.
 */
class PlatformAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?Organization $organization = null,
        ?Model $subject = null,
        ?string $description = null,
        array $context = [],
    ): PlatformAuditLog {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        $log = PlatformAuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'action' => $action,
            'organization_id' => $organization?->getKey(),
            'organization_name' => $organization?->name,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'description' => $description,
            'context' => $context === [] ? null : $context,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
            'request_id' => request()->attributes->get('request_id'),
        ]);

        Log::notice('[platform] '.$action, array_filter([
            'actor' => $actor?->email,
            'organization' => $organization?->name,
            'description' => $description,
        ]));

        return $log;
    }
}
