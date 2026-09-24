<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes audit entries.
 *
 * The logger records who did what to which entity, along with the before and
 * after state of the attributes that actually changed. Attributes listed in
 * `config('audit.redacted')` are replaced with a placeholder so that secrets
 * never reach the audit table.
 */
class AuditLogger
{
    private ?string $requestId = null;

    /** Actor label used when nothing is authenticated (jobs, webhooks, CLI). */
    private ?string $systemActor = null;

    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Record a change against a model.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        array $context = [],
        ?string $organizationId = null,
    ): ?AuditLog {
        $organizationId ??= $this->resolveOrganizationId($subject);

        if ($organizationId === null) {
            // Nothing to attribute the change to; skip rather than write a
            // dangling row that no tenant can ever read.
            //
            // This table is the *tenant's* audit trail, and that is why it can
            // refuse. Platform actions that belong to no organization — granting
            // platform administration, editing a plan, changing a setting — are
            // recorded in `platform_audit_logs` by
            // {@see \App\Domain\Platform\Services\PlatformAuditLogger} instead.
            return null;
        }

        $actor = $this->currentUser();

        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $actor?->getKey(),
            'actor_type' => $this->actorType($actor),
            'actor_label' => $actor?->fullName() ?? $this->systemActor ?? 'System',
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * Record the creation of a model.
     */
    public function created(Model $subject, ?string $description = null, array $context = []): ?AuditLog
    {
        return $this->record(
            action: $this->actionName($subject, 'created'),
            subject: $subject,
            newValues: $this->auditableAttributes($subject, $subject->getAttributes()),
            description: $description,
            context: $context,
        );
    }

    /**
     * Record an update, storing only the attributes that changed.
     */
    public function updated(Model $subject, ?string $description = null, array $context = []): ?AuditLog
    {
        $changes = $subject->getChanges();
        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        $original = [];
        foreach (array_keys($changes) as $key) {
            $original[$key] = $subject->getOriginal($key);
        }

        return $this->record(
            action: $this->actionName($subject, 'updated'),
            subject: $subject,
            oldValues: $this->auditableAttributes($subject, $original),
            newValues: $this->auditableAttributes($subject, $changes),
            description: $description,
            context: $context,
        );
    }

    public function deleted(Model $subject, ?string $description = null, array $context = []): ?AuditLog
    {
        return $this->record(
            action: $this->actionName($subject, 'deleted'),
            subject: $subject,
            oldValues: $this->auditableAttributes($subject, $subject->getAttributes()),
            description: $description,
            context: $context,
        );
    }

    /**
     * Set the label used for the actor when no user is authenticated.
     */
    public function actingAsSystem(string $label): static
    {
        $this->systemActor = $label;

        return $this;
    }

    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::ulid();
    }

    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    private function currentUser(): ?User
    {
        if (! app()->bound('auth')) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function actorType(?User $user): string
    {
        if ($user !== null) {
            return $user->isPlatformAdmin() ? 'platform_admin' : 'user';
        }

        return $this->systemActor !== null ? 'integration' : 'system';
    }

    private function resolveOrganizationId(?Model $subject): ?string
    {
        // Read from the loaded attributes rather than through getAttribute().
        // Models here run with strict mode on, so asking for a column the model
        // does not have throws MissingAttributeException — and plenty of
        // auditable subjects have no organization_id at all: a User belongs to
        // several, and the platform's own models belong to none. Auditing one of
        // those used to take the whole request down with a 500.
        $fromSubject = $subject?->getAttributes()['organization_id'] ?? null;

        if (is_string($fromSubject)) {
            return $fromSubject;
        }

        return $this->tenancy->id();
    }

    /**
     * Strip attributes that must never be persisted into the audit trail and
     * drop anything the model marks as hidden (passwords, tokens, secrets).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditableAttributes(Model $subject, array $attributes): array
    {
        $hidden = method_exists($subject, 'getHidden') ? $subject->getHidden() : [];

        foreach (array_keys($attributes) as $key) {
            if (in_array($key, $hidden, true)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = config('audit.redacted', []);

        foreach ($values as $key => $value) {
            foreach ($redacted as $pattern) {
                if (fnmatch($pattern, (string) $key)) {
                    $values[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $values;
    }

    private function actionName(Model $subject, string $verb): string
    {
        $base = Str::snake(class_basename($subject));

        return $base.'.'.$verb;
    }

    private function ipAddress(): ?string
    {
        return app()->runningInConsole() ? null : request()?->ip();
    }

    private function userAgent(): ?string
    {
        return app()->runningInConsole() ? null : substr((string) request()?->userAgent(), 0, 500);
    }
}
