<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Services\HostexAgentPublisher;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Services\AccessControl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** One attempt: a timeout can mean the remote write succeeded. Never replay it. */
class PublishPropertyAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $actionId, public string $organizationId)
    {
        $this->onQueue(config('pms.queues.ai', 'default'));
    }

    public function handle(TenantContext $tenancy, HostexAgentPublisher $publisher): void
    {
        $organization = $tenancy->withoutScope(fn () => Organization::findOrFail($this->organizationId));
        $tenancy->runAs($organization, function () use ($publisher): void {
            $action = AgentAction::with(['property', 'requestedBy', 'approvedBy'])->findOrFail($this->actionId);
            if (! $action->capability->isLivePropertyWrite() || $action->status !== AgentAction::STATUS_APPROVED) {
                return;
            }
            // Atomic claim also protects duplicate queue deliveries. A worker
            // dying after the claim leaves an explicit uncertain receipt.
            if (AgentAction::whereKey($action->id)->where('status', AgentAction::STATUS_APPROVED)->whereNull('executed_at')
                ->update(['executed_at' => now(), 'outcome' => 'Submission started. If this remains unchanged, check Hostex before retrying; delivery may be uncertain.']) !== 1) {
                return;
            }
            $lock = Cache::lock('agent-property-push:'.$action->property_id, 200);
            try {
                if (! $lock->get()) {
                    throw new RuntimeException('Another change is being submitted for this property. Wait for its result before requesting another change.');
                }
                $brief = AgentBrief::fromSettings($action->property->settings);
                $actor = $action->approvedBy ?? $action->requestedBy;
                if (! $brief->enabled || ! in_array($action->capability->value, $brief->mayDo, true)
                    || ($action->was_autonomous && ! in_array($action->capability->value, $brief->mayDoAlone, true))
                    || $actor === null || ! Gate::forUser($actor)->allows('update', $action->property)
                    || ! app(AccessControl::class)->allows($actor, $action->capability->permission(), $action->organization_id)) {
                    throw new RuntimeException('The property agent or manager no longer has permission for this action. Nothing was pushed.');
                }
                $outcome = $publisher->publish($action);
                $action->update(['status' => AgentAction::STATUS_EXECUTED, 'outcome' => $outcome, 'executed_at' => now()]);
            } catch (Throwable $e) {
                $action->update(['status' => AgentAction::STATUS_FAILED, 'outcome' => Str::limit($e->getMessage(), 800).' No automatic retry was made. If the connection timed out, check Hostex before retrying.', 'executed_at' => now()]);
            } finally {
                $lock->release();
            }
            AgentActivity::record(['organization_id' => $action->organization_id, 'property_id' => $action->property_id,
                'actor_id' => $action->approved_by_id ?? $action->requested_by_id, 'kind' => AgentActivity::KIND_ACTED,
                'summary' => $action->summary, 'detail' => ['action_id' => $action->id, 'outcome' => $action->outcome], 'is_autonomous' => $action->was_autonomous]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->withoutScope(function (): void {
            AgentAction::whereKey($this->actionId)->where('organization_id', $this->organizationId)
                ->where('status', AgentAction::STATUS_APPROVED)->update([
                    'status' => AgentAction::STATUS_FAILED,
                    'outcome' => 'The worker stopped before confirming the result. Delivery may be uncertain. Check Hostex before requesting this change again; it was not automatically retried.',
                    'executed_at' => now(),
                ]);
        });
    }
}
