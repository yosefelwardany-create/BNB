<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Models\AgentActivity;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Closes proposals nobody decided on.
 *
 * The model already refuses to approve a lapsed proposal, so this is not what
 * stops a stale action running — that guard is in
 * {@see AgentAction::isOpen()} and holds whether or not this ever runs. This is
 * about the screen and the record: a queue that accumulates month-old proposals
 * nobody will ever press is a queue people stop reading, and the badge that says
 * three things are waiting has to be true for anybody to act on it.
 *
 * It writes a row to the activity log for each one rather than quietly flipping
 * a status, because "the agent proposed cancelling that booking and nobody
 * looked" is worth being able to find later. A proposal that expired is evidence
 * about how the queue is being watched.
 */
class ExpireAgentActions extends Command
{
    protected $signature = 'agents:expire-actions';

    protected $description = 'Close agent proposals whose approval window has passed';

    public function handle(TenantContext $tenancy): int
    {
        /*
         * Across every tenant.
         *
         * A scheduled command has no current organization, so the global scope
         * would silently match nothing — the failure mode where the sweeper runs
         * hourly, reports zero, and the queues fill up anyway.
         */
        $lapsed = $tenancy->withoutScope(
            fn () => AgentAction::query()->lapsed()->with('property')->get(),
        );

        foreach ($lapsed as $action) {
            $action->forceFill([
                'status' => AgentAction::STATUS_EXPIRED,
                'outcome' => 'Nobody decided within the approval window.',
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            AgentActivity::record([
                'organization_id' => $action->organization_id,
                'property_id' => $action->property_id,
                'reservation_id' => $action->reservation_id,
                'conversation_id' => $action->conversation_id,
                'kind' => AgentActivity::KIND_EXPIRED,
                'summary' => sprintf(
                    '%s — expired without a decision: %s',
                    $action->capability->label(),
                    $action->summary,
                ),
                'detail' => ['action_id' => $action->getKey()],
                // Nothing happened on the agent's own judgement here. Nothing
                // happened at all, which is the point of the row.
                'is_autonomous' => false,
            ]);
        }

        $this->info(sprintf('Expired %d proposal(s).', $lapsed->count()));

        return self::SUCCESS;
    }
}
