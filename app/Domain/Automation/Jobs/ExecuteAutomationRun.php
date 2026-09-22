<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\ActionRunner;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries out one automation run, off the request that caused it.
 *
 * Queued for two reasons. A guest confirming a booking must not wait for an
 * email to be rendered and handed to an SMTP server; and a rule's delay ("three
 * days before check-in") is expressed as the job's own delay rather than as a
 * row somebody has to poll.
 *
 * The run id travels rather than the model: by the time this executes, minutes
 * or days later, a serialised model would be stale in ways that matter.
 */
class ExecuteAutomationRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Back off rather than hammer a transport that is refusing messages. */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $runId)
    {
        $this->onQueue(config('pms.queues.automation', 'automation'));
    }

    public function handle(
        AutomationEngine $engine,
        ActionRunner $actions,
        TenantContext $tenancy,
    ): void {
        $run = $tenancy->withoutScope(
            fn (): ?AutomationRun => AutomationRun::query()
                ->withoutGlobalScope('organization')
                ->with('rule')
                ->find($this->runId),
        );

        if ($run === null) {
            return;
        }

        // A run that already reached a terminal state is not repeated: this
        // job being delivered twice must not message a guest twice.
        if (! in_array($run->status, [AutomationRun::PENDING, AutomationRun::RUNNING], true)) {
            return;
        }

        $organization = $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($run->organization_id),
        );

        if ($organization === null) {
            return;
        }

        // Actions create tasks, messages and notifications, all of which are
        // tenant-scoped. The job has no ambient tenant, so it is rebuilt from
        // the run before anything is touched.
        $tenancy->runAs($organization, function () use ($engine, $run, $actions): void {
            $engine->execute($run, $actions);
        });
    }

    /**
     * A run that exhausted its attempts is recorded as failed rather than
     * vanishing into the failed-jobs table, so the rule's run log tells the
     * whole story.
     */
    public function failed(\Throwable $exception): void
    {
        $run = AutomationRun::query()
            ->withoutGlobalScope('organization')
            ->find($this->runId);

        $run?->forceFill([
            'status' => AutomationRun::FAILED,
            'error' => $exception->getMessage(),
            'completed_at' => now(),
        ])->save();
    }
}
