<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\DataObjects\AutomationContext;
use App\Domain\Automation\Jobs\ExecuteAutomationRun;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Guests\Models\Guest;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Turns events into automated work.
 *
 * The engine reads the domain event stream — the same stream webhooks and
 * analytics consume — which is why adding automation required no change to any
 * service that produces an event.
 *
 * Three properties matter more than anything else here:
 *
 * **Every decision is recorded.** A run row is written whether the rule acted,
 * declined to act, or failed, along with the result of each condition. The two
 * questions automation always raises are "why did this guest get that message?"
 * and "why didn't they?", and neither is answerable after the fact without it.
 *
 * **Running twice is not a second message.** Each run carries an idempotency
 * key of (rule, subject, trigger occurrence) with a unique index behind it.
 * A redelivered webhook, a retried job or two workers racing produce one run.
 *
 * **Conditions are evaluated before the delay, actions after it.** A rule that
 * says "three hours after checkout, if the booking is still confirmed" is
 * ambiguous otherwise; the conditions are re-checked at execution time so a
 * booking cancelled during the delay does not still get its message.
 */
class AutomationEngine
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * React to a domain event.
     *
     * Returns the runs created — one per matching rule. Rules that did not
     * match the event's property or channel produce no run at all, because a
     * rule scoped to other properties never had an opinion about this booking.
     *
     * @return list<AutomationRun>
     */
    public function handleEvent(DomainEventContract $event, ?string $domainEventId = null): array
    {
        $context = $this->contextFor($event, $domainEventId);

        $rules = $this->tenancy->withoutScope(fn (): array => AutomationRule::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $context->organizationId)
            ->forEvent($context->eventName)
            ->get()
            ->all());

        $runs = [];

        foreach ($rules as $rule) {
            $run = $this->schedule($rule, $context);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * Create the run for one rule, and dispatch it.
     *
     * Returns null when the rule's scope excludes this event, or when this
     * exact occurrence has already been run.
     */
    public function schedule(AutomationRule $rule, AutomationContext $context): ?AutomationRun
    {
        if (! $rule->appliesToProperty($context->propertyId())) {
            return null;
        }

        if (! $rule->appliesToChannel($context->channel())) {
            return null;
        }

        $key = $this->idempotencyKey($rule, $context);

        $run = $this->createRun($rule, $context, $key);

        if ($run === null) {
            // Already ran for this occurrence. That is the key doing its job,
            // not an error.
            return null;
        }

        ExecuteAutomationRun::dispatch($run->getKey())
            ->delay($rule->delay_minutes > 0 ? now()->addMinutes($rule->delay_minutes) : null);

        return $run;
    }

    /**
     * Evaluate and carry out one run.
     *
     * Called from the queue after any configured delay, and directly by tests.
     */
    public function execute(AutomationRun $run, ActionRunner $actions): AutomationRun
    {
        $rule = $run->rule;

        if ($rule === null || ! $rule->is_active) {
            return $this->skip($run, 'The rule was deleted or switched off before this run executed.');
        }

        $context = $this->rebuildContext($run);

        if ($context === null) {
            return $this->skip($run, 'The record this run was about no longer exists.');
        }

        // Re-evaluated now rather than at scheduling time: a booking cancelled
        // during the delay must not still receive its arrival instructions.
        $evaluation = $this->conditions->evaluate($rule->conditions, $context->payload);

        $run->forceFill([
            'condition_results' => $evaluation['trace'],
            'started_at' => now(),
            'attempts' => $run->attempts + 1,
        ])->save();

        if (! $evaluation['passed']) {
            return $this->skip($run, 'The rule\'s conditions were not met.');
        }

        $run->forceFill(['status' => AutomationRun::RUNNING])->save();

        $results = [];
        $failed = false;

        foreach ((array) $rule->actions as $action) {
            $result = $actions->run((array) $action, $context, $rule);

            $results[] = $result;

            // One failing action does not silently abandon the rest: a rule
            // that both messages a guest and raises a clean should still raise
            // the clean when the message bounces.
            if (($result['status'] ?? null) === 'failed') {
                $failed = true;
            }
        }

        $run->forceFill([
            'status' => $failed ? AutomationRun::FAILED : AutomationRun::COMPLETED,
            'action_results' => $results,
            'completed_at' => now(),
            'error' => $failed ? 'One or more actions failed; see the action results.' : null,
        ])->save();

        $rule->forceFill([
            'times_run' => $rule->times_run + 1,
            'last_run_at' => now(),
        ])->save();

        return $run;
    }

    /**
     * Build the context a rule is evaluated against.
     */
    public function contextFor(DomainEventContract $event, ?string $domainEventId = null): AutomationContext
    {
        $payload = $event->payload();
        $subject = $event->subject();

        return $this->tenancy->withoutScope(function () use ($event, $payload, $subject, $domainEventId): AutomationContext {
            $reservation = $subject instanceof Reservation
                ? $subject
                : $this->find(Reservation::class, $payload['reservation_id'] ?? null);

            $property = $subject instanceof Property
                ? $subject
                : ($reservation?->property ?? $this->find(Property::class, $payload['property_id'] ?? null));

            $guest = $subject instanceof Guest
                ? $subject
                : ($reservation?->guest ?? $this->find(Guest::class, $payload['guest_id'] ?? null));

            return new AutomationContext(
                organizationId: $event->organizationId(),
                eventName: $event->eventName(),
                payload: $payload,
                subject: $subject,
                reservation: $reservation,
                property: $property,
                guest: $guest,
                domainEventId: $domainEventId,
            );
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Write the run row, or return null if this occurrence already ran.
     */
    private function createRun(AutomationRule $rule, AutomationContext $context, string $key): ?AutomationRun
    {
        try {
            return $this->tenancy->withoutScope(fn (): AutomationRun => AutomationRun::query()->create([
                'organization_id' => $context->organizationId,
                'automation_rule_id' => $rule->getKey(),
                'subject_type' => $context->subjectType(),
                'subject_id' => $context->subject?->getKey(),
                'domain_event_id' => $context->domainEventId,
                'status' => AutomationRun::PENDING,
                'scheduled_for' => $rule->delay_minutes > 0
                    ? now()->addMinutes($rule->delay_minutes)
                    : null,
                'idempotency_key' => $key,
            ]));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Rebuild the evaluation context from a stored run.
     *
     * The payload comes from the run's own domain event where one is recorded,
     * so a delayed run is evaluated against what actually happened rather than
     * against a summary assembled now.
     */
    private function rebuildContext(AutomationRun $run): ?AutomationContext
    {
        return $this->tenancy->withoutScope(function () use ($run): ?AutomationContext {
            $event = $run->domain_event_id === null
                ? null
                : \App\Domain\Events\Models\DomainEvent::query()
                    ->withoutGlobalScope('organization')
                    ->find($run->domain_event_id);

            $subject = $run->subject;

            if ($subject === null && $run->subject_id !== null) {
                // The record was deleted between scheduling and execution.
                return null;
            }

            $payload = $event?->payload ?? [];

            $reservation = $subject instanceof Reservation
                ? $subject
                : $this->find(Reservation::class, $payload['reservation_id'] ?? null);

            $property = $subject instanceof Property
                ? $subject
                : ($reservation?->property ?? $this->find(Property::class, $payload['property_id'] ?? null));

            return new AutomationContext(
                organizationId: $run->organization_id,
                eventName: $event?->name ?? ($run->rule?->trigger_event ?? 'unknown'),
                payload: $payload,
                subject: $subject,
                reservation: $reservation,
                property: $property,
                guest: $reservation?->guest,
                domainEventId: $run->domain_event_id,
            );
        });
    }

    private function skip(AutomationRun $run, string $reason): AutomationRun
    {
        $run->forceFill([
            'status' => AutomationRun::SKIPPED,
            'skip_reason' => $reason,
            'completed_at' => now(),
        ])->save();

        return $run;
    }

    /**
     * One run per rule per subject per occurrence.
     *
     * The event id is part of the key where there is one, so a rule on a
     * repeatable event ("a message arrived") fires once per message rather
     * than once ever, while a redelivery of the same event does not.
     */
    private function idempotencyKey(AutomationRule $rule, AutomationContext $context): string
    {
        return sprintf(
            '%s:%s:%s',
            $rule->getKey(),
            $context->subjectKey(),
            $context->domainEventId ?? $context->eventName,
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel|null
     */
    private function find(string $class, mixed $id): ?object
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        try {
            return $class::query()->withoutGlobalScope('organization')->find($id);
        } catch (\Throwable $exception) {
            Log::warning('Automation could not resolve a record it needed.', [
                'model' => $class,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23505', '23000'], true);
    }
}
